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

Nothing is moved, quoted or deleted: the message stays exactly where it is, and the target
channel is never created — an app channel you meant to file into is one you make on purpose.
Authorisation is the same two-question split as the import: the request class settles the
message, `Channel::visibleTo` settles the target.

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
| Tests | `tests/Feature/AppChannelTest.php`, `tests/Feature/TrackerTest.php`, `tests/Feature/KanbanTest.php`, `tests/Feature/NoteMentionTest.php`, `tests/Feature/MessageToAppTest.php` |

## Known gaps

- **Canvas cards are commentable server-side but not in the UI.** A default card is 240×180; a
  comment thread inside one is a scrollbar in a postage stamp. It needs a card detail view first.
- **Task reordering within a status group** has a `position` column and no drag handle yet.
- **Stickers can't be resized or rotated from the wall.** The columns and the API accept both;
  the wall only offers drag, double-click-to-edit and delete.
- **No mobile-specific layout pass** on the Tracker, Polls or the Sticker Wall. All three are
  responsive and usable; none was designed for a phone.
