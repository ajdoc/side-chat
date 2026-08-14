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
filters over **one** list. `App\Support\Apps\AppCatalogue` mirrors the channelable set
server-side — the client decides what an app *looks* like, the server decides what may be
stored. The games are deliberately not channelable: a game is something a room starts, plays and
finishes, and a permanent channel for one would be an empty table most of the time.

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

Adding them to a model is one `use HasAppActivity` line plus a resolver in `AppSubjects`.
Already wired: `tracker_task`, `calendar_event`, `canvas_item`. The routes are generic —
`channels/{channel}/apps/{type}/{id}/comments`.

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
| Tests | `tests/Feature/AppChannelTest.php`, `tests/Feature/TrackerTest.php` |

## Known gaps

- **Canvas cards are commentable server-side but not in the UI.** A default card is 240×180; a
  comment thread inside one is a scrollbar in a postage stamp. It needs a card detail view first.
- **Task reordering within a status group** has a `position` column and no drag handle yet.
- **The Tracker has no mobile-specific layout pass.** It's responsive and usable, but the detail
  pane's two columns stack rather than having been designed for a phone.
