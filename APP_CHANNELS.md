# App channels

A fourth channel type — `app` — whose body is an application instead of a timeline. Where
`text` gives you messages, `voice` a call and `space` a walkable room, an app channel gives you
**one app, full-bleed, shared by everyone who opens it**.

Modelled on [Root](https://www.rootapp.com)'s app channels, where installing an app creates a
dedicated channel and each app "runs inside its own channel". This is that idea on our own
architecture — see [Relation to Root](#relation-to-root) for what we took and what we didn't.

## The shape

An app channel is a channel with `type = 'app'` and one row saying which app it is. **The
timeline still exists underneath**, exactly as it does for a Side Space, where the map sits over
a real timeline and everything below it is unaware. That inheritance is why this was cheap:
reads, mentions, search, threads, side chats, notifications and E2EE all keep working because
none of them ever knew what a channel *looked* like.

```
channel_apps
  channel_id   unique — one app per channel
  app_id       'tracker' | 'board' | 'notes' | 'calendar' | 'docs' | 'canvas' | widgets
  config       json, free-form — shape belongs to whatever renders the app
  installed_by
```

**The row hangs off the discussion, not the container** — the same place a Side Space's map
hangs. That is the whole of the grouping story: a discussion *is* a channel with a parent, so
one app container with three discussions is three apps under one sidebar entry ("Design" holding
a tracker, a board and a doc shelf), and none of it needed a second mechanism. A new discussion
inherits its siblings' app unless it names another.

### One registry, three questions

`DESK_APPS` in [useDeskApps.ts](frontend/app/composables/useDeskApps.ts) already declared where
each app may appear. It gained one field:

```ts
canvasable: boolean   // may it be a card on the Open Canvas?
channelable: boolean  // may it be an entire channel?     ← new
```

So the create-channel picker, the Side Desk tab picker and the canvas card toolbar are three
filters over **one** list.

Server-side, `App\Support\Apps\AppRegistry` is the single mirror of it — one row per app with
`desk` and `channel` flags. It replaced two overlapping lists (`AppCatalogue` and the old
`DeskApps`), and it did so because **the duplication bit twice**: the Tracker was added as a
channel app and to the client registry but not to the desk-strip validation list, so adding the
Tracker tab 422'd; and `app` was added as a channel type but not to the sidebar's section list,
so app channels rendered nowhere. Neither failed loudly. One registry now answers both
questions, and adding an app is one row.

The client still decides what an app *looks* like; the server decides what may be stored.

`AppChannel.vue` is a thin dispatcher that renders the component the Side Desk already renders,
full-size. An app id this client doesn't know draws an honest "update to open it" notice rather
than an empty panel — a real state for a client a release behind the server.

## The Tracker

The one app built *for* the channel slot rather than adapted into it. Three screens with one
Back button: **home** (your tasks, your projects) → **board** (a project's tasks grouped by
status) → **task** (description, activity, comments, and the fields down the right).

- **Task keys** are `PROJECT-N` (`HRIP-2`). The number comes from `tracker_projects.next_number`
  under a row lock, and is **never reused** — task keys get quoted in chat and in commits, so a
  number that could come back would be a reference that silently changes meaning.
- **Statuses** are `backlog → todo → in_progress → in_review → done`, drawn as collapsible
  groups down the page rather than columns across it. Columns are right when you drag between
  them all day and wrong here, where the common act is opening a task to read it — and a phone
  would get five columns of one-word wrapping.
- **`done` is the only status with behaviour**: it stamps `completed_at`, and leaving it *clears*
  the stamp. A reopened task isn't finished, and a stale stamp would leave every progress bar
  counting it forever.
- **Filtering happens client-side.** Everything on screen is already held, so a keystroke that
  re-queried would be slower and would flicker.

## Comments, tags and history — for every app

The part worth reusing. Three polymorphic tables, built that way from the first line rather than
as `tracker_comments` that later grows siblings:

| Table | What it holds |
| --- | --- |
| `app_comments` | A discussion under any work item |
| `app_tags` + `app_taggables` | A **channel-wide** vocabulary, and what wears it |
| `app_activity` | Append-only history — `kind` + `data`, never a rendered sentence |
| `app_reactions` | Emoji chips, toggled — one endpoint, because reacting and un-reacting are one gesture |

Adding them to a model is one `use HasAppActivity` line plus a resolver in `AppSubjects`. The
routes are generic — `channels/{channel}/apps/{type}/{id}/comments`, `.../reactions`,
`.../tags/{tag}`.

**Every app whose items are database rows has them, in the API and in the UI**:

| App | Item | Where the thread lives |
| --- | --- | --- |
| Tracker | task | the task detail pane |
| Polls | poll | under the results |
| Calendar | event | in the event editor |
| Sticker Wall | sticker | a panel beside the wall |
| Canvas | card | a panel beside the board |
| Docs | shelf file | under the viewer |
| Notes | the note | under the editor |
| Kanban | card | a panel beside the board |

One test walks the whole set — posting a comment, a reaction and a tag to each — so an app
added without a resolver fails there rather than as a 404 somebody finds in the UI.

**Reactions are on all of them too.** A fixed five-emoji row lives in `AppItemDiscussion`, so
adding it to an app is the same one tag that brings comments and tags. Chips are toggles, and
the server answers with the whole row rather than "added"/"removed" so a click never leaves a
count to be guessed at.

Two placements are deliberate. A **canvas card** and a **sticker** get a *panel* rather than an
in-card thread — a default canvas card is 240×180, and a comment thread in that is a scrollbar
in a postage stamp. A **chat-sourced** file in Docs gets nothing: it's an attachment on a
message that already has its own timeline thread, and a second discussion here would split one
conversation across two places.

The ones that still *can't* are **Music and Video**. Their items aren't rows — a queue entry is
a position in the widget's JSON blob — so there is nothing for `commentable_id` to point at.

The Kanban board used to be in that list, and got out of it the only way there is: **its cards
were promoted to tables** (see [The kanban board](#the-kanban-board) below). That is the general
answer for a widget-backed app that wants a discussion — not a special case in the comments
layer.

Three deliberate choices:

- **Not `App\Models\Comment`.** That one is the reaction-shaped comment on a timeline message —
  keyed to `message_id`, grouped by normalized body, carrying an emoji. Same word, different
  feature; merging them would be one table where half the columns are null for half the rows.
- **Tags belong to the channel, not the project.** That's what makes a tag worth filtering on:
  "blocked" means the same thing on a tracker task as on a calendar entry.
- **Activity stores `kind` + `data`, never wording.** The client owns the phrasing, so a history
  written today still reads in whatever copy a later release uses.

Deleting cascades **in PHP, not in the database** — `commentable_id` points at whichever table
owns the row, so there's no key to cascade along. Model events cover deleting one item;
anything deleting a *parent* whose children go in the database must call
`purgeAppActivityFor()` first. `TrackerProjectController::destroy` does.

## The kanban board

Three columns of cards in a widget's JSON blob, until two asks arrived that a blob can't hold:
**editable columns** and **a discussion on a card**. Both are now in, and the board is tables —
`kanban_boards` (one per channel, its columns as an ordered JSON list) and `kanban_cards`.

This is the same promotion the Poll widget made, and the widget did the same thing with it: the
`k!` widget's whole state is now `{"board_id": 7}`. There is one board per channel, so the
timeline card, the Side Desk tab, the Open Canvas card and a kanban app channel are four views
of one row — which is what they always were, back when they were four views of one blob.

- **A card's id is the number people type.** `k!done 12` names the row minted as 12. Row ids are
  never reused, so the guarantee the old `seq` counter kept by hand now comes from the database.
- **A column's `key` is minted once from its label and never rewritten.** Renaming "Doing" to
  "In Progress" is a label edit; a key that tracked the label would orphan every card in it.
- **Removing a column rehomes its cards** to the column beside it rather than deleting them.
  Deleting twenty cards because somebody tidied a column is the one destructive thing this app
  could do by accident, and there's no undo for it.
- **Columns stay JSON; cards do not.** The columns are a small list read and written as a whole
  and referenced only by their own cards' `column` string. Cards are addressed one at a time, by
  other tables — which is exactly what a blob can't be.
- `k!col` is the new command family: `k!col`, `k!col add <name>`, `k!col rename <column> <name>`,
  `k!col rm <column>`. Every other `k!` command is unchanged.

Enter commits a card and **Shift+Enter starts a new line**, in the quick-add and the inline
editor alike.

**The board broadcast is a reference, not a state.** A websocket message has a size ceiling no
app can raise from the inside, and a board is unbounded — importing 84 cards built one payload of
all of them and the broker refused it, so the change reached nobody. `kanban_board` now carries
the columns (bounded by `MAX_COLUMNS`) plus `cards_stale`, and clients re-read the cards over
HTTP, where a big response is only a big response. Single-card events still carry their card:
one card is bounded, a board is not. This is the same shape `WidgetUpdated` has always had, for
the same reason — a broadcast that can outgrow the wire fails on precisely the busiest board on
the server.

## @mentions in the Notes app

Typing `@` in the shared note offers the channel roster, the preview renders the names as chips,
and whoever was named is told. The note is the first app outside chat to carry mentions, and all
three halves reuse what messages already had.

- **The picker is `useMentionPicker`**, not the composer's menu. The composer fuses its `@` menu
  with its slash-command menu and its Enter-sends handling — in a document Enter is a newline,
  which is why Notes was never a `MarkdownEditor` in the first place. The composable owns the
  token, the list and the insertion; the caller decides who owns Enter, and the menu only claims
  it while it is open.
- **Chips come from the same roster the timeline uses.** `SideDeskNotes` provides
  `mentionNamesKey` itself rather than inheriting it, because a note in a floating window or an
  app channel has no timeline above it — without that, `@Ada` renders as plain text in exactly
  the places the note gets read.
- **The notification is a system message** in the surface's own timeline
  (`AnnounceNoteMentionsAction`), which then rides the badge, the mention highlight and the push
  that already exist. A notification inbox built for one app would be a second delivery path to
  keep in step with the first — and the first is the one people's mute settings are written
  against.

The trigger is **"named now, and not in the body this save replaced"**, not "the body contains
the name". A note saves every ~700ms while somebody types, so anything less specific would
announce a paragraph one keystroke at a time. Deleting a name and typing it again announces
again; that is a person being named twice. A save the server refuses as stale announces nothing,
since its body was never stored.

A side chat's note announces into the side chat, where the badge is `SideChatActivity` — which
carries no mention flag, so it lands as an ordinary unread with the naming message in it rather
than a faked highlight.

## Chat → app: "Add to app"

The apps and the timeline were two places, and the thing being tracked is nearly always *said
first*. Every message's overflow menu now has **Add to app**, which files it as a card, a task, a
poll, a calendar entry, a canvas card, a line in the notes, or a file on the Docs shelf.

**Any channel is a target.** The dialog groups them — this chat, app channels running that app,
every other channel — but the server has no such concept: every app's storage is scoped to a
channel, so all three are one `target_channel_id`. A text channel, a DM, a voice room and a Side
Space all carry a Side Desk, and their board is the same storage an app channel's is. (The first
version listed only app channels, which quietly made "file it on this team's board" the one thing
you couldn't do.)

**Filing into a conversation surfaces the widget.** A board somebody can't see is not a place
they'll look, so filing onto a *conversation* channel's board drops the widget card in its
timeline — once, when that channel has no card yet, since a widget card renders the live state
wherever it already sits. An app channel is skipped: it already *is* the board, full window.

**The message is read, not re-typed.** `App\Support\Apps\MessageParts` holds the whole rule:
the first line is the title (markdown decoration stripped — somebody who typed `## Retro` meant
the words), the rest is the description. The client previews from that same class through
`app-targets`, so the dialog can't promise a shape the create path then disagrees with.

**Polls read a markdown list**, because that is already what people type when they mean one:

```
Where are we eating?
- Thai
- Pizza
- Somewhere with chairs
```

The first non-list line is the question and the items are the options. A message with a question
and *no* list becomes a `yes_no` poll — that is what a bare question is, and it saves making
somebody pick a type they didn't know they were picking.

Per-app, only what a message genuinely can't answer is asked: which project a task goes under (or
"as a project" instead), which column a card lands in, when a calendar entry starts. **No date is
guessed out of prose** — "Tuesday" doesn't say which one, and on a calendar a confident wrong
answer is a meeting nobody attends; empty means the time the message was sent.

Two apps are deliberately absent and say why: a **sticker** is a drawing, and the **board** holds
strokes. Docs takes the message's *files* rather than its text, copied on the disk (a shared path
would make deleting either delete both) and refused for encrypted attachments, whose bytes the
server can't read.

**An encrypted message can't be filed.** The reader can see the words — their client decrypted
them — but the server holds only the envelope, so what it would file is base64 wearing a card's
clothes. And the apps aren't encrypted, so even given the plaintext this would move a message out
of the room whose promise is that the server can't read it. Both halves say no, and the second
would still say no if the first were solved.

Nothing is moved, quoted or deleted: the message stays exactly where it is, and the target
channel is never created — an app channel you meant to file into is one you make on purpose.
Authorisation is the same two-question split as the import: the request class settles the
message, `Channel::visibleTo` settles the target.

## App item → chat: "Discuss in chat"

The return trip. Any app item — a task, a card, a poll, an event, a sticker, a note — can open a
**side chat** about itself, from the same `AppItemDiscussion` panel that carries its comments.

- **A side chat, not a thread.** A side chat is the room this product already has for working
  something out: participants, decisions, a desk of its own. A thread is a tangent off one
  message. An item being *worked on* gets the room.
- **It's anchored in the timeline.** Opening one posts a short system message in the channel and
  starts the side chat from it. A side chat with no origin message is invisible to everyone who
  wasn't told about it, which would make this reachable only from the item — half a connection.
- **Once.** The unique index on `app_discussions` means the second person to press the button
  joins the first one's room. Two rooms about one task is the split this exists to prevent, so
  `store` is idempotent and the client shows one button whose label depends on whether a row
  exists.
- **The room outlives the item.** Deleting a card drops the pointer and leaves the side chat: the
  conversation happened, and the people in it didn't consent to losing it because a card was
  tidied. Deleting the *side chat* drops the row on the foreign key.
- The side chat is **named after the item** — `ONB-4 Rewrite the welcome email`, the card's text —
  by `AppSubjects::label()`, which is where the eight apps' different notions of "what is this
  called" are reconciled into one name and one excerpt.
- **The room says what it's about.** `SideChatResource.about` carries the *live* item — its kind
  in words ("Kanban card"), its title, and which app it lives in — and the panel header draws it
  above "Started from". Without it you arrive in a room titled with a card's words and no way to
  tell it from an ordinary post, which was the confusing half of the round trip. Live rather
  than a snapshot: a renamed card whose room still shows the old words is the same confusion
  wearing a different hat.
- The button says **"Start a side chat"** and warns that it navigates. It names the side chat
  rather than saying "chat", because a side chat is a thing this product already has and people
  who know what one is need only be told that's what they're getting.

Why a pointer table rather than a column: the link hangs off any of eight item kinds, so a
`side_chat_id` on each of those tables would be eight migrations to say one thing — the same
reasoning that made comments and tags polymorphic.

## Which apps work on a side chat's desk

A Side Desk sits on a channel *or* on a side chat, and its apps split in two:

| | Storage | On a side chat |
| --- | --- | --- |
| Board, Notes, Canvas, Calendar, Docs | per surface | its **own** — a side chat's whiteboard is its whiteboard |
| Tracker, Polls, Sticker Wall | per channel | the **parent channel's** |
| Every widget (Kanban, Music, Video, games) | per channel | the parent channel's, as always |

The middle row is the fix: those three have no side-chat endpoints at all, so their tabs used to
point at `/api/side-chats/3/tracker/...` — a 404, rendered as an app that silently never loads.
They now resolve to the parent channel, which is the rule the widgets always followed ("a side
chat's Kanban tab is its parent channel's board") and the honest one: a side chat is a room
inside a channel, and the channel's tracker is what its people are working from.

**Comments, tags, reactions and "Discuss in chat" are channel-only**, since
`channels/{channel}/apps/{type}/{id}/...` is the only address they have. On a side-chat-owned
item `AppItemDiscussion` therefore draws *nothing* rather than four requests that 404 and a
thread that never accepts a comment. Giving side chats their own half is a coherent feature and
simply isn't this one — it needs the side chat's roster as its gate, not the channel's.

The rule lives in `frontend/app/lib/deskScope.ts` with a spec, rather than as a regex inside a
component: getting it wrong doesn't throw, it renders an empty tab, which is exactly the failure
that shipped before it was written down.

## Apps in automations

The rules engine could only ever see and say things in **chat**. It now reaches the apps from
both directions, which took four triggers and two actions and no new architecture — the builder
renders itself from the server's catalogue, so the dashboard gained all six with no client
change.

| Triggers | Actions |
| --- | --- |
| `kanban.card_created`, `kanban.card_moved` | `create_kanban_card` |
| `tracker.task_created`, `tracker.task_status_changed` | `create_tracker_task` |

`kanban.card_moved` supplies **both** ends of the move, because "announce it when something
reaches Done" is the rule people build and `to = done` is how they write it.

**One item by a person fires; a bulk arrival doesn't.** The board UI, `k!add` and filing a
message all fire; an import of eighty-four cards fires nothing. That isn't a limitation dressed
up — a rule that posts "card added" is a rule somebody wants to read, and eighty-four of those is
a channel nobody can read. The import announces itself once, in its own way.

A card created *by* `create_kanban_card` deliberately doesn't fire `kanban.card_created`. The
depth counter would stop the loop, but the real reason is that a rule which files cards and a
rule which reacts to filed cards are a pair written by accident far more often than on purpose —
the same call chat makes by never letting a bot's own message trigger a rule.

Firing lives in `App\Support\Apps\AppAutomations`, called from the paths a person acts on
rather than from a model event, and it is best-effort throughout: a board must not fail to accept
a card because a rule engine is unhappy.

## Calendar reminders, and the room

The Calendar was a place to *write down* that something is at three o'clock: nobody who wasn't
looking at the tab found out. Two fields close that.

- **`remind_minutes`** — post a notice in the channel that many minutes before it starts. Opt-in
  per entry (0, 5, 10, 15, 30, 60, 120, 1440), because most calendar rows are records rather than
  appointments and a channel that announced all of them is a channel people mute.
- **`room_channel_id`** — the voice channel or Side Space it happens in. The reminder names it,
  which is what turns a notice into a way *in* rather than a fact. Validated against the rooms
  the author can see in this server, and offered by `calendar/rooms` — one query defines both, so
  the picker can't offer something the save then refuses.

`calendar:post-reminders` runs every minute and **stamps `reminded_at` before it posts**. A post
that throws therefore loses one notice; stamping afterwards would leave the row due and it would
fire again the next minute, and the minute after — a channel turning into a flood. Same trade
`RunBotSchedules` makes, for the same reason.

Two edges worth knowing:

- **Rescheduling re-arms it.** Changing `starts_at` or the reminder clears `reminded_at`: the
  notice that went out was about a time this no longer happens at.
- **A stale entry is never announced.** The query's lower bound is ten minutes before now, so a
  worker that was down for an hour can't wake up and announce a dozen meetings that already
  happened. Those rows are left *unstamped* — writing to a row to record that we chose not to
  post is work, and an index range that excludes it costs nothing.

The notice is a `system` message authored by whoever scheduled the entry (the room asks "who put
this in?" next), lands as an ordinary unread rather than a mention, and does go to push — for
"starting in ten minutes" that's the delivery that matters.

### Meetings, and recording one

A **scheduled meeting is a calendar entry with a room** — no meetings table. A second concept
would need its own reminders, its own editor and its own idea of "when", and would then disagree
with the calendar about all three.

What was missing was the *room's* half of that. `channels/{channel}/meetings` answers the question
people have while standing in a room — *is something happening here, and when* — by reading across
the server's calendars for entries pointing at it. Both the voice channel (a banner) and the Side
Space (a chip in the toolbar) draw it, and it shows **before** you join, which is exactly when the
calendar tab is the least convenient place to look. Entries are scoped to the calendars the viewer
can see: a private channel may schedule a meeting in a public room, and the room must not publish
its title to everybody who walks in.

**Where you make one.** From the room: the voice channel's banner and the Side Space's toolbar
chip both carry a **Schedule** button that opens the Calendar editor with *this room already
chosen*, and the banner's title opens the entry that's coming up. Before that the only route was
"open the desk, find Calendar, make an entry, then find this room in a dropdown" — four steps, none
of which mention meetings. The intent rides in the URL (`?desk=calendar&meet=1`, or `&event=<id>`)
like every other panel's state, and is cleared once acted on: left in place, a reload would reopen
the editor over whatever had since been typed. `SideDeskPanel` translates the URL and passes a prop
down, because the calendar also renders in a floating window and on the canvas, neither of which
should react to the page's query.

A **meeting link** is the room's path with the entry named — `/servers/3/channels/9?meeting=12`,
built client-side from the copy button in the editor. Deliberately not an auto-join link: arriving
in a room with your microphone already live is not something a pasted URL should be able to do.

**Recording** is client-side, and the split matters. The server never has the audio — in a mesh
the streams go peer to peer and behind an SFU it forwards packets it doesn't decode — so the mix
is a few nodes in the graph `useVoice` already owns and the encode is `MediaRecorder`. What the
server owns is the *fact* of it:

- a `recording` flag on the **participant**, like `screen_sharing`, so two people can record and
  one can stop while the other continues — and so the badge is already solved, since every client
  renders participant flags off the roster;
- a line in the timeline on start and stop, because "was this meeting recorded?" is asked
  afterwards and a badge that vanished with the call cannot answer it.

There is deliberately no path that records without saying so: capture only begins after the API
call that sets the flag and posts the line, and if that is refused nothing is captured. Who may:
staff in a server's room, a group's owner, either person in a DM — the same line `MuteVoiceParticipantRequest`
draws, because a recording leaves the room and outlives it.

The honest costs, stated in the code too: the recording is only as good as the recorder's
connection, it stops if they close the tab, it contains what *they* heard, and **screens are not
captured** (shared tab audio is). A server-side recording would be authoritative; this is a
good-faith copy made by someone in the room, which is what the announcement says it is. It's mixed
after the mic's effect chain, ignores per-listener volume and local mutes, and taps peers who
arrive mid-meeting — a snapshot at start would omit exactly the people who turn up when the
meeting begins.

### Meetings you create, and the link

A meeting is **a room, a link to it, and optionally a time**. `POST /api/meetings` decides where
it lands from what it's sent, not from a mode:

| Sent | Made |
| --- | --- |
| `server_id` | a voice channel or Side Space in that server |
| `channel_id` | nothing — a link to a room that already exists |
| neither | a **group conversation** whose channel is the room |

The type defaults to **voice**; a Side Space is a deliberate choice about how it should feel, and
it works by converting the channel (see below), which is why that had to exist first.

**Getting the link back.** `channels/{channel}/meeting-links` lists a room's meetings — distinct
from `channels/{channel}/meetings`, which lists what's *scheduled* there and answers in calendar
entries. Every room (voice, Side Space, and a group meeting's own channel) has a **Copy link**
button beside Schedule, and a room that has never had a meeting gets one pointing at itself when
somebody asks — because "get the link" is one question whether or not it has been asked before.
Any member may copy one; who *used* it is the audit, and is not. Expired links drop out of the
list, since an address that admits nobody isn't worth copying.

The link is `{origin}/meet/{token}`, composed by the client and opened by
`pages/meet/[token].vue`. **Joining is a button, never automatic** — following a link should not
silently add you to a group chat, and it certainly shouldn't put you in a live call before you've
read what it is.

**Pressing that button does put you straight into the call.** The room is reached with `?call=1`,
which the voice channel and the Side Space each act on in their own way (`connect`, `enter`) —
neither needs to know it came from a meeting. Two doors for one intention is the thing this
avoids, and the press that followed the link is also the *user gesture* a browser wants before it
will ask for a microphone: spending it on the navigation and then demanding a second click wastes
exactly what makes this possible.

Three guards, each a real case: the flag is **consumed as it's acted on** (so a reload doesn't
drag somebody back into a call they left), it is **ignored if you're already in another call**
(opening a link is choosing to look, not to be moved), and it is ignored if you're already in this
one. It's consumed *before* connecting, so a refused microphone can't leave it armed for the next
reload.

**There is a `meetings` table, reversing the earlier call that a meeting is only a calendar
entry.** Two requirements changed it, and neither is about time: a meeting can have **no
schedule** ("make me a link, we're starting now"), and **a link is a thing with a policy**. So the
row is the link and its policy, `scheduled_event_id` points at the calendar entry when there is
one, and the calendar still owns "when" — reminders, rescheduling and the room's agenda are
unchanged.

### People from outside

A meeting's door has **three settings**, not a boolean — "how far open is this" genuinely has
three answers, and a pair of booleans would have made a nonsense state expressible:

| `access` | Who gets in |
| --- | --- |
| `members` | only people already in the room; the link is just the address |
| `account` | anybody signed in, who is added to the meeting's group chat |
| `guest` | **anybody at all** — a name, and they're in, with no account |

**A guest is a `User` with `is_guest`, no password, and a use-by date** — the trick `is_bot`
already established. That isn't a shortcut around "real" anonymous access; it *is* the
implementation. Every seat here is a user row (`voice_participants.user_id`, `messages.user_id`,
the member pivot), so the alternative was making those nullable throughout to accommodate the
least trusted person in the building. Presence, the roster, push and the audit work unchanged
because nothing can tell the difference; the places that must ask `is_guest`.

A guest gets a real Passport token and uses the ordinary client. What they may *reach* is settled
by `ConfineGuests`, which **denies by default** and allows a short list. An allow-list can only
ever be too small — somebody hits a wall and it gets widened — where a deny-list is wrong the
moment anybody adds a route.

It narrows twice. First to their own conversation (resolved from the route's channel,
conversation or message binding), plus `auth/me`, `auth/logout` and the chat list. Then **within
that room**, to `messages`, `voice`, `space`, reads, members and reacting to a message. Being in
the meeting is not being able to work in the room: membership alone would have handed a stranger
the whole Side Desk — calendar, board, tracker, shelf — of a chat they were let into for half an
hour.

The client hides what a guest can't do (no servers section, no new chat, no new meeting, no
Schedule, no Side Desk — see `useGuest`), but that is **manners, not the boundary**. A guest
offered "Add a server" gets a 403, and a guest is the one visitor with no way to tell a refusal
from a broken app.

Three refusals hold whatever the setting says: **never a server room** (a link is not a door into
a server), **never an encrypted channel** (device keys belong to accounts that persist), and
**never an expired link**. The guest join endpoint is public and rate-limited per IP, since an
unauthenticated route that creates accounts is exactly the shape of thing that gets hammered.

**Guests are retired, not deleted.** `guests:prune` revokes tokens and gives up the seat, and
leaves the row standing — because deleting a user cascades into `messages` (cutting their side
out of a transcript the other people are still entitled to read) *and* into `meeting_joins`
(erasing the audit of who was admitted, which is the thing it exists for). What has to stop
existing is the credential, not the record. Their name carries a **Guest** marker everywhere it
appears: self-chosen, unverified, and nobody in a room should have to work that out.

**A link can never admit anybody to a server.** Being in one is that server's people's decision,
so `allow_external` + `server_id` is refused *at creation* rather than stored as a promise the
link can't keep, and an outsider opening a server meeting is told to ask for a server invite
rather than 404'd into thinking it doesn't exist.

Every admission writes a `meeting_joins` row — who, when, by link or as a member, and whether they
were an outsider. A link a stranger can follow makes *"who got in, and how"* a question that has to
survive the call ending, and the roster can't answer it: an hour later it's empty, and on it a
stranger and a colleague look identical. The audit is readable only by whoever answers for the
room, and the stored IP and user agent are **never shipped** — they exist for an operator after an
incident, not to make a guest list into a tracking record. The room is also told out loud when
somebody arrives from a link.

### Changing what a channel is

`PATCH channels/{channel}/type` — text ↔ voice ↔ Side Space, for staff (a group's owner, either
person in a DM). **A conversion moves the lid, not the contents**: a Side Space is a timeline with
a map over it and a voice channel a timeline with a call over it, so messages, threads, side
chats, pins and every Side Desk app hang off the channel either way and none of them move.

- Becoming a space **seeds a map** if there isn't one, and never replaces an existing one —
  converting away and back must not bulldoze the furniture somebody placed.
- Leaving a room **ends the call**: a server text channel doesn't allow calls, so anyone left
  seated would be a ghost in the sidebar nothing would clear.
- **App channels are refused both ways.** An app channel's body is an application with a row of
  its own; installing and uninstalling is the operation that means this.
- Discussions follow their container when they still match what it *was* — they inherited that
  type at creation — while one somebody deliberately made different is left alone.

## A bot as an app actor

A bot is a `User` with `is_bot`, so it needed no new permission system to reach the apps — only
routes. The `bot/` prefix now carries the productivity apps:

| | |
| --- | --- |
| Kanban | read the board, add a card, move a card |
| Tracker | list projects and tasks, open a task, update one |
| Calendar | read entries, add one |

**They are the same controllers, request classes and membership gates the people-facing routes
use.** The only additions are the token's server scope and a shorter list of verbs. A parallel
set of bot controllers would be the same logic twice, drifting apart on the first bug fixed in
one of them.

`EnsureBotChannel` is that scope: a bot's *account* can be on several servers' rosters, so a
token checked only against membership would reach every channel that account had ever been added
to. `BotSendMessageRequest` makes the same check inline for the send path; this is the rule as
middleware so the app routes can be plain reuse.

**Deleting is deliberately absent.** A long-lived credential in somebody's CI config should not
be able to remove other people's work, and nothing has asked for it. Note also that a bot could
already drive a board through chat — `k!add` from `bot/channels/{channel}/messages` — so these
routes make an existing reach *explicit and structured* rather than granting a new one.

## Importing from another channel

Every app whose content is scoped to a surface can be **copied in from another channel** —
`channels/{channel}/apps/import`, with `apps/import/sources` listing the channels you can see
that hold anything. One controller and one dialog for all of them, because import means the same
thing everywhere; the per-app difference lives in `App\Support\Apps\AppImports`, where adding
an importer is a row.

The rules are the same for every app, which is why they can be stated once in the dialog:

- **Copy, never move.** The source keeps everything.
- **Additive at the destination.** Nothing already there is replaced, so importing twice
  duplicates rather than destroys. The one app that can't be additive in the obvious way is
  Notes — a surface has exactly one note — so its import *appends* under a rule instead.
- **The discussion stays behind.** Comments, reactions, tags and history are a conversation that
  happened in the source channel, often by people who aren't members here.
- **An assignee comes across only if they're a member here.** Otherwise the card arrives
  unassigned, with its author's name kept as text.
- **Votes never come across.** An imported poll arrives open with no votes: a vote is a person's
  answer in the room they answered in, and on an anonymous poll copying it would move the one
  record the anonymity existed for.

Two importers do more than copy rows. A **canvas** card that places a widget is re-pointed at
*this* channel's widget of that type (minting it if needed) — a copied `widget_id` would put
another channel's music player on your canvas. A **docs** import copies the file on the disk;
two shelf rows sharing one stored path would make deleting either file delete both.

Authorisation is two questions, asked by different things: `TrackerRequest` settles the
destination, and `Channel::visibleTo` settles the source. Without the second, an import would be
a way to read a private channel by copying it into your own.

**One event, whatever arrived.** An import broadcasts `AppContentImported` — an app id and a
count, and none of the rows — and open clients re-read the app they already know how to read.
Firing each app's own per-row event instead would send eighty-four broadcasts for one gesture,
each carrying a full resource: the same too-much-on-the-wire failure as the old board broadcast,
arriving as a flood rather than as one oversized message. It fires after the transaction commits
(nobody is told to re-read a state that then rolls back) and not at all when nothing came across.

Notes are the exception: their import broadcasts `SpaceNoteUpdated` instead, because an open
editor *merges* a remote body against what's being typed, and a blunt "re-read" would discard
the paragraph somebody is mid-sentence in.

## Polls

A wall of a channel's questions, each with results, reactions and a thread. **Not** the `poll`
widget, which stays: that's the single card a `p!` command drops in a timeline, whose whole
state is one JSON blob. This is a *place* polls live, outliving the messages they'd have
scrolled past, answerable days later.

Real tables rather than a blob because those are what a blob is bad at: a vote must be unique
per person, results must be counted rather than recomputed from an array, and the comments hang
off the polymorphic tables.

- Three types — `yes_no`, `single`, `multiple`. Yes/No writes its own options server-side so
  every client spells them the same.
- **A vote is the full set you now stand behind, not a delta.** Changing your mind is one call,
  withdrawing is `[]`. A delta would need the client to know its own previous answer — exactly
  what goes stale when you vote from two tabs.
- **Raw votes never cross the wire.** Per-option counts plus your own picks, and nothing else.
  On an anonymous poll a vote list would be the answer to the question the anonymity was for.
- `vote_count` and `voter_count` both ship, because on a multiple-choice poll they differ and
  "27 votes" from 12 people is a number nobody can interpret.
- Closing refuses new votes and keeps the old ones. Closed polls stay on the wall under their
  own heading — a poll's answer is usually why it was asked.

### One poll, three ways in

There used to be two poll systems: the `p!` widget, whose whole state was a JSON blob in the
timeline, and this app. Two things called Poll that couldn't see each other's answers.

The widget is now a **pointer**: its entire state is `{"poll_id": 12}`, and every `p!` command
reads and writes that `AppPoll`. A `p!new` in the timeline puts a poll on the wall; voting on
the wall moves the timeline card; closing it in either place closes it in both. The commands are
unchanged, and `p!vote 2` still names a stable option — the number is the option's row id now
rather than a counter in a blob, which gives the same never-reused guarantee for free.

`poll` is no longer offered as a Side Desk tab or an app channel, because `polls` *is* that. It
stays **accepted** in stored desk strips (and keeps rendering) so that desks which already have
the tab don't break — see the `deprecated` flag in the client registry.

## Sticker Wall

A shared collage. Draw a sticker in a small editor, place it, drag it around; overlap is allowed
and wanted, because that's what makes a collage read as one picture.

**It does not reuse the Board**, which was the first plan. A whiteboard stroke belongs to the
*board*; a sticker is an object — drawn elsewhere, then placed, then moved, and the unit
somebody deletes when they want their own thing gone. Strokes can't be moved or owned as a group
without inventing exactly this row on top of them. What *is* reused is the geometry: a sticker's
paths are points in a 0–100 space rendered as SVG, so nothing is rasterised and a sticker is
sharp at 40px on the wall and at 400px in the editor. Composition, not a shared table.

Ownership is stricter than every other Side Desk app: **moving and deleting are yours-or-staff**,
not anyone-in-the-channel. A wall is a collage of individual contributions with a name on each,
so "anyone may move anyone's" makes it quietly vandalisable. Staff keep the override because a
wall also needs moderating.

## The dynamic catalogue

`installed_apps` is the half of the catalogue a PHP constant can't represent, and the
prerequisite for third-party apps — a dynamic id has to exist before there's anything to
sandbox. A row is a slug (sharing `channel_apps.app_id`'s namespace, checked against the
built-ins), a name, an `entry_url` and an `enabled` kill switch.

`entry_url` **must be an origin we don't serve the app from**: a sandboxed iframe is only a
boundary if the framed document can't reach our cookies. Nothing renders it yet — the column
exists so an install is describable before the renderer that honours it is written.

Disabling stops *new* channels picking the app while existing ones keep their timelines and
render the "app unavailable" notice. Deleting them would take their conversations too.

## Layers

Two implementations of one idea, deliberately different.

**Board layers** are shared state. Strokes carry a `layer` index; the names and visibility live
in `board_layers` on the surface (a name repeated across ten thousand strokes is ten thousand
copies of one fact) and broadcast, because hiding a layer has to hide it for everybody or "it's
on the Sketch layer" becomes untrue for whoever you said it to. Which layer *you* draw on is
per-person and never persisted.

Both are additive: `layer` defaults to 0, so every board that existed before them has its
strokes on one layer and nothing moved. A `bg` fill ignores the active layer and stays on 0 —
putting the ground on layer 3 would paint over the work beneath it. Hit-testing runs over
*visible* strokes only, so you can't erase something on a hidden layer by clicking empty space.

**Sticker layers** are part of the drawing — no endpoint, no sync, nothing to reconcile, because
a sticker is made by one person in one sitting. `stickerLayers()` is the only place that knows
about the pre-layers flat `paths` shape, so old stickers keep rendering and are converted on
first edit.

## Decisions taken

| Question | Answer |
| --- | --- |
| One app per channel, or a set? | **One.** A set of apps in a channel is the Side Desk, which already exists; rebuilding it here would reopen the seam `useDeskApps` closed. |
| Can a discussion be an app channel? | **Yes** — it falls out of `parent_id` for free, and it's how you group apps. |
| Install slots / metering? | **No.** Root meters them; that's a monetisation decision, not a technical one. |
| Unread badge? | **Deferred.** An app channel has a timeline so it *can* badge, but a board changing isn't a message. Unread follows the timeline only. |

## Relation to Root

What we took: the channel-is-an-app model, one app per channel, install-creates-a-channel, the
destructive-uninstall warning, and the Task Tracker as the first-party app worth leading with.

What we didn't, and why it matters for third-party apps later: **Root hosts the app itself** —
a TypeScript client in a Chromium sandbox plus a Node server with its own SQLite, one instance
per community, talking over Protobuf. That's their whole developer promise ("no hosting, no
scaling, no security overhead") and reproducing it means running untrusted Node in
per-community containers with backup, restore and quotas. A platform programme, not a feature.

Everything above is first-party apps, which is where Root's own product actually lives — Task
Tracker, Raid Planner, Stickerwall are all theirs. If third-party apps are ever wanted, the
groundwork that matters is already here: **a bot is a `User` with `is_bot`**, so an app
installed as a bot acts "with permissions just like a human member" (Root's model) through
`Channel::hasMember` and `scopeVisibleTo` with no new permission system. The remaining pieces
would be a sandboxed iframe client we host and a scoped per-install storage API. **An encrypted
channel must refuse third-party apps outright** — there is no coherent story where third-party
code sits in a room whose promise is that the server can't read it.

## Where things are

| | |
| --- | --- |
| Channel type, app row | `Channel::TYPES`, `App\Models\ChannelApp`, `AppCatalogue` |
| Creation | `CreateChannelData`, `CreateChannelAction`, `CreateDiscussionAction` |
| Tracker API | `TrackerProjectController`, `TrackerTaskController` |
| Shared comments/tags | `AppCommentController`, `AppTagController`, `AppSubjects`, `HasAppActivity` |
| Client state | `useTracker`, `useTrackerTask`, `useAppItem` |
| Client UI | `AppChannel.vue`, `TrackerApp.vue`, `TrackerBoard.vue`, `TrackerTaskDetail.vue`, `TrackerHome.vue`, `AppItemDiscussion.vue` |
| Presentation rules | `frontend/app/lib/tracker.ts` |
| Kanban | `KanbanController`, `KanbanBoards`, `KanbanWidget`, `useKanban`, `KanbanBoard.vue` |
| Import | `AppImportController`, `App\Support\Apps\AppImports`, `AppImportDialog.vue` |
| Note mentions | `AnnounceNoteMentionsAction`, `useMentionPicker`, `SideDeskNotes.vue` |
| Chat to app | `MessageToAppController`, `MessageToApp`, `MessageParts`, `MessageToAppDialog.vue` |
| App to chat | `AppDiscussionController`, `AppDiscussion`, `AppSubjects::label`, `AppItemDiscussion.vue` |
| Desk surface scoping | `frontend/app/lib/deskScope.ts` (+ spec), `SideDesk.vue` |
| Apps in rules | `AppAutomations`, `TriggerRegistry`, `CreateKanbanCardAction`, `CreateTrackerTaskAction` |
| Calendar reminders | `PostCalendarReminders`, `CalendarEvent::remindAt`, `SideDeskCalendar.vue` |
| Bots in apps | `EnsureBotChannel`, the `bot/` group in `routes/api.php` |
| Tests | `tests/Feature/AppChannelTest.php`, `tests/Feature/TrackerTest.php`, `tests/Feature/KanbanTest.php`, `tests/Feature/NoteMentionTest.php`, `tests/Feature/MessageToAppTest.php`, `tests/Feature/AppDiscussionTest.php`, `tests/Feature/CalendarReminderTest.php` |

## Known gaps

- **Canvas cards are commentable server-side but not in the UI.** A default card is 240×180; a
  comment thread inside one is a scrollbar in a postage stamp. It needs a card detail view first.
- **Task reordering within a status group** has a `position` column and no drag handle yet.
- **Stickers can't be resized or rotated from the wall.** The columns and the API accept both;
  the wall only offers drag, double-click-to-edit and delete.
- **No mobile-specific layout pass** on the Tracker, Polls or the Sticker Wall. All three are
  responsive and usable; none was designed for a phone.
