# side-chat

A Discord/Slack-style chat app. API-first Laravel backend, Nuxt/Vue/shadcn frontend,
real-time over WebSockets — all containerized.

## Stack

| Layer      | Tech                                              |
| ---------- | ------------------------------------------------- |
| Backend    | Laravel 13 (PHP 8.5) served by **FrankenPHP**     |
| Frontend   | Nuxt 4 (Vue 3) + Tailwind v4 + **shadcn-vue**     |
| Database   | PostgreSQL 17                                     |
| Cache/Queue| Redis 7                                           |
| WebSockets | Laravel Reverb                                    |
| Background | supervisord → `queue:work` + `schedule:work`      |

## Containers

| Service    | Build dir        | Role                                        | URL / Port                     |
| ---------- | ---------------- | ------------------------------------------- | ------------------------------ |
| `app`      | `docker/php`     | FrankenPHP (Caddy + PHP) serving the API    | http://localhost:8000          |
| `reverb`   | `docker/reverb`  | Reverb WebSocket server                     | ws://localhost:8080            |
| `worker`   | `docker/worker`  | Queue worker + scheduler (supervisord)      | —                              |
| `postgres` | *(official)*     | Database                                    | localhost:5432                 |
| `redis`    | *(official)*     | Cache + queue backend                       | localhost:6379                 |
| `frontend` | `docker/frontend`| Nuxt dev server                             | http://localhost:3000          |

`reverb` and `worker` are built **FROM** the `app` image (`sidechat/php:local`), so they
share one PHP runtime — only the command differs.

## Prerequisites

- Docker + Docker Compose
- `make` (optional, but every command below assumes it)

## Quick start

```bash
make up
```

This builds the images (in the right order), starts everything, and runs migrations.
On the **first** boot the frontend installs its npm deps into a volume — give it a minute,
then:

- Frontend → http://localhost:3000
- API      → http://localhost:8000/api/ping
- Health   → http://localhost:8000/up

Stop with `make down` (data in Postgres/Redis volumes is preserved).

## Common commands

```bash
make up           # build + start + migrate
make down         # stop containers (keep data)
make logs         # tail all logs
make ps           # service status
make migrate      # run migrations
make fresh        # drop + re-migrate (DESTROYS data)
make shell        # shell in the app container
make tinker       # Laravel Tinker
make artisan c="make:model Message -m"
make composer c="require spatie/laravel-permission"
make npm c="run build"
```

## Configuration

- **`.env`** (repo root) — Compose settings: host ports, Postgres creds, uid/gid, Reverb keys.
- **`backend/.env`** — Laravel config. Already points at the `postgres`, `redis`, and
  `reverb` service hostnames. Compose also injects the connection vars so the two stay in sync.

The `UID`/`GID` in `.env` are baked into the PHP image so bind-mounted files stay writable
(no root-owned files). They default to `1000` — match your host user if different (`id -u`).

## Adding shadcn-vue components

shadcn is wired up (Tailwind v4, `components.json`, `cn()` helper). Add components with:

```bash
make npm c="dlx shadcn-vue@latest add button card input"
```

They land in `frontend/app/components/ui/` and are auto-imported by Nuxt.

## Runtime modes (classic vs. worker mode)

The default `app` container runs **FrankenPHP in classic mode** — Laravel boots per request,
just like php-fpm. Two opt-in **Laravel Octane worker-mode** variants are available for
benchmarking (app stays resident in memory → much faster, at the cost of watching for state
leakage). They're gated behind the `octane` Compose profile, so they never start by default.

```bash
make octane-up      # build + start both variants
make octane-logs    # tail their logs
make octane-down    # stop/remove them (classic app untouched)
```

| Runtime                | URL                     | Notes                                    |
| ---------------------- | ----------------------- | ---------------------------------------- |
| Classic (default)      | http://localhost:8000   | FrankenPHP `php_server`, boots per request |
| FrankenPHP worker mode | http://localhost:8001   | `octane:start --server=frankenphp --watch` |
| Swoole                 | http://localhost:8002   | `octane:start --server=swoole --watch`     |

All three run the **same** bind-mounted code against the same Postgres/Redis, so you can hit
each port and compare (e.g. `wrk`/`ab` against `/api/ping`). The worker variants use `--watch`
(Node + chokidar in their images) to auto-restart on file changes in dev. Recommendation
stands: stay on classic until you measure a bottleneck — these are here to benchmark, not to
adopt blindly.

## Authentication

OAuth2 via **Laravel Passport** (personal access tokens for the first-party frontend),
plus **Socialite** for Google/Facebook. Send `Authorization: Bearer <token>` on
authenticated requests.

| Method | Endpoint                        | Auth | Purpose                              |
| ------ | ------------------------------- | ---- | ------------------------------------ |
| POST   | `/api/auth/register`            | –    | Create account → returns user+token  |
| POST   | `/api/auth/login`               | –    | Email/password → returns user+token  |
| GET    | `/api/auth/me`                  | ✔    | Current user                         |
| POST   | `/api/auth/logout`              | ✔    | Revoke the current token             |
| GET    | `/api/auth/{provider}/redirect` | –    | Start Google/Facebook OAuth          |
| GET    | `/api/auth/{provider}/callback` | –    | Provider callback → redirects to SPA |

The social callback redirects to `FRONTEND_URL/auth/callback?token=<token>` (or
`?error=oauth_failed`); the SPA reads the token from the query string and stores it.

### Enabling Google / Facebook

1. Create OAuth credentials:
   - **Google** → [Google Cloud Console](https://console.cloud.google.com/apis/credentials)
   - **Facebook** → [Facebook for Developers](https://developers.facebook.com/apps/)
2. Set the authorized redirect URI to `http://localhost:8000/api/auth/<provider>/callback`.
3. Fill in `GOOGLE_CLIENT_ID/SECRET` and `FACEBOOK_CLIENT_ID/SECRET` in `backend/.env`.

Passport encryption keys live in `backend/storage/oauth-*.key` (git-ignored) — generated
by `php artisan passport:install`. For production, set `PASSPORT_PRIVATE_KEY` /
`PASSPORT_PUBLIC_KEY` env vars instead.

## Servers, channels & chat

Discord/Slack-style model: **servers** contain **channels** (`text` or `voice`), text
channels contain **messages**. Creating a server makes you its owner and first member;
"your servers" = servers you belong to. Every endpoint below requires a Bearer token and
enforces server membership (non-members get `403`).

| Method | Endpoint                              | Purpose                                  |
| ------ | ------------------------------------- | ---------------------------------------- |
| GET    | `/api/servers`                        | Servers you belong to (the left rail)    |
| POST   | `/api/servers`                        | Create a server (you become owner)       |
| GET    | `/api/servers/{server}`               | A server with its channels               |
| PATCH  | `/api/servers/{server}`               | Rename a server — **owner only**         |
| DELETE | `/api/servers/{server}`               | Delete a server — **owner only**         |
| POST   | `/api/servers/{server}/leave`         | Leave a server (the owner can't — see below) |
| POST   | `/api/servers/{server}/channels`      | Create a `text` or `voice` channel       |
| PATCH  | `/api/channels/{channel}`             | Rename a channel — **owner only**        |
| DELETE | `/api/channels/{channel}`             | Delete a channel — **owner only**        |
| GET    | `/api/channels/{channel}/messages`    | Recent messages (chronological)          |
| POST   | `/api/channels/{channel}/messages`    | Post a message (broadcast to others)     |
| GET    | `/api/channels/{channel}/threads`     | Threads in a channel                     |
| POST   | `/api/channels/{channel}/threads`     | Start a thread (optional `message_id`)   |
| GET    | `/api/threads/{thread}`               | A thread with its parent message         |
| GET    | `/api/threads/{thread}/messages`      | Replies in a thread                      |
| POST   | `/api/threads/{thread}/messages`      | Post a reply (broadcast on the thread)   |
| POST   | `/api/messages/{message}/reactions`   | Toggle an emoji reaction                 |
| POST   | `/api/messages/{message}/pin`         | Pin the message, or unpin it (toggle)    |
| GET    | `/api/channels/{channel}/pins`        | The channel's pinned messages            |
| GET    | `/api/messages/{message}/info`        | Who saw it, who hasn't, who reacted      |
| GET    | `/api/channels/{channel}/links`       | Every link shared in the channel         |
| GET    | `/api/channels/{channel}/reads`       | Where each member has read up to         |
| POST   | `/api/channels/{channel}/read`        | Move your read marker (defaults to newest) |

Creating a channel is open to any member; **destroying or renaming** one is not. Creation is
additive and reversible; deletion takes a whole channel's history and files with it and has
no undo, and a name is shared state that every member reads. So the `PATCH`es and `DELETE`s
all sit behind `ServerOwnerRequest`.

A rename is the **name only**. A channel's type is what it *is* — flipping a voice channel
to text would strand whoever was mid-call in it — so `type` is ignored on `PATCH` even if
you send it.

### Onboarding flow (frontend)

`/` → if you have no servers you're sent to `/onboarding` (create your first server) →
then straight to `/servers/{id}/channels/new` (create your first channel) → text channels
drop you into the chat view. Once you have servers, `/` opens your first one directly.

Both steps can be backed out of: the channel form has a **Cancel**, and onboarding has a
**Skip for now** — plenty of people arrive holding an invite, not wanting to run a
community. Opting out has to be *remembered* (a cookie, `useOnboarding()`), because `/`
redirects the serverless to `/onboarding` — without it, "skip" would bounce you straight
back through the door you were trying to leave by. Skipping lands you on an empty state
that takes an invite link, and the flag is cleared the moment you do create a server, so
someone who later leaves every server gets the guided flow back rather than a dead end
they once dismissed.

### Renaming, leaving and deleting

| Action                    | Who        | What happens                                                        |
| ------------------------- | ---------- | ------------------------------------------------------------------- |
| Rename server / channel   | Owner      | Broadcast live to every member's sidebar                             |
| Leave server              | Any member | Membership, voice seat and read markers go. **Messages stay.**       |
| Delete channel            | Owner      | The channel, its threads, messages, reactions, reads — and its files |
| Delete server             | Owner      | Everything above, for every channel, plus memberships and invites    |

`ServerUpdated` and `ChannelUpdated` ride the server-wide stream (the sidebar is where a
name is *read*, and everyone has the sidebar open while only the people inside a channel are
on `channel.{id}`). Both carry the name and the client **patches** it in rather than swapping
the row wholesale — a broadcast has no single asker, so it cannot carry the per-viewer fields
(a channel's `unread_count`, a server's `is_owner`), and overwriting the row would blank
every member's unread badge on every rename.

**The owner can't leave.** A server whose owner has walked out has nobody who can delete it
or admit anyone to it, so rather than repair that state later we refuse to enter it: the
owner's exit is *delete the server*, a different button behind a different confirmation.
That's a `422` (with a message saying so), not a `403` — it isn't "this isn't yours", it's
precisely the opposite.

**Leaving keeps your messages.** Leaving a conversation has never meant unsaying what you
said, and a thread full of holes is worse for the people still in it than one with a name
they no longer recognise. Two things *do* go, because neither is a fact about the past —
both are claims about the present that would otherwise outlive the membership justifying
them: your **voice seat** (or the sidebar shows a ghost sitting in a call you've left) and
your **read markers** (or the seen-by row keeps drawing your avatar in a channel you can no
longer read).

**Deleting really deletes the files.** Every FK cascades, so dropping a server or channel
row takes the messages and attachment *rows* with it — but a DB cascade never fires an
Eloquent event, so nothing would ever call `deleteFile()` and every upload would be
stranded on disk with no row left pointing at it. So `AttachmentService::purgeForChannels()`
runs *first*, while the rows can still be seen: it deletes each attachment by its own
`disk`, chunked, then removes the channel's upload directory itself
(`attachments/{channel_id}` — which is where thread files live too, since a thread reply
carries its `channel_id`). That second pass is what makes it recursive: it takes the
now-empty folder, and sweeps anything that ever lost its row.

### Real-time

Posting a message fires a `MessageSent` event that **Laravel Reverb** broadcasts on the
private channel `channel.{id}`. The Nuxt client subscribes via **Laravel Echo**, which
authenticates to `/broadcasting/auth` with the Passport token (only server members are
authorized). Sends use `->toOthers()` so the author doesn't receive a duplicate of their
own message. Voice channels are listed but a placeholder for now.

### Threads

A message can branch into a **thread** (Discord-style). A message either lives on the main
channel timeline (`thread_id = null`) or inside a thread. Start one from the hover action on
a message (or the channel's **Threads** button); the parent message then shows a thread
indicator. Thread replies broadcast on a private `thread.{id}` channel (same membership
auth), and a `ThreadCreated` event on `channel.{id}` makes new threads appear live. Each
reply also fires a lightweight `ThreadActivity` event on `channel.{id}`, so the reply count
on the parent message and the Threads list tick up in real time even when the thread panel
is closed. The UI opens threads in a right-hand panel via a `?thread=<id>` query param.

### Reactions

Hover a message → 🙂 to pick from a curated emoji set; click an existing pill to add or
remove your own. One `POST` **toggles** (react again with the same emoji to take it back),
enforced by a unique index on `(message_id, user_id, emoji)`. Only actual emoji are
accepted — the validator matches pictographic graphemes (including ZWJ sequences, skin
tones and keycaps) and rejects plain text.

`ReactionToggled` broadcasts the message's **whole** reaction summary, not the delta, so a
client that missed an event still converges on the right counts. The payload deliberately
ships *who* reacted rather than an "is this mine" flag — one flag can't be correct for
every subscriber of a broadcast — and the client matches the ids against itself.

### Pinned messages

Hover a message → 📌. One `POST` **toggles**, for the same reason reactions do: two people
pinning the same message at the same moment should converge rather than race, and the
broadcast carries the resulting state rather than the delta, so a client that missed an
event still lands in the right place. Pinned messages are listed in the channel **Info →
Pinned** tab, newest *pin* first — not newest message, because the one someone just pinned
is the one they've decided the channel needs to see.

**Any member can unpin, including what somebody else pinned.** A pin is a statement about
the channel, not about the person who made it; needing to chase the original pinner is how
channels end up with a stale pinned list that nobody is able to clear. System messages
("X joined the server") can't be pinned at all — they're generated, and nobody wrote them.

`MessagePinToggled` is the only event that goes out on **both** streams, because a pin is
the one thing that's simultaneously true in two places. It always reaches `channel.{id}` —
the Pinned tab belongs to the channel and lists thread messages too, so a pin made three
levels deep still has to reach everyone watching the channel — and when the message lives
in a thread it also goes to `thread.{id}`, or the people with that thread open would be the
only ones who never saw it happen. (Contrast `MessageSent`, which goes to one *or* the
other: a reply is read in exactly one place, so it's delivered to exactly one.)

### Read receipts & unread badges

`channel_reads` holds one row per (channel, user): how far that person has read. The marker
only ever moves **forwards** — clients mark as they read and those calls arrive out of
order often enough that honouring a lower id would drag everyone's avatars backwards.

- **Seen by** — tiny avatars under the last message each person has read (not under every
  message they've read, which would plaster the backlog with faces). `ChannelReadUpdated`
  on `channel.{id}` moves them live.
- **Unread badges** — the channel list carries an `unread_count` per channel (messages from
  other people, newer than your marker; thread replies don't count). Keeping it current
  needs `ChannelActivity`, a bodyless ping on `server.{id}`: you're only subscribed to the
  channel you're *looking at*, so a message in any other channel — exactly the one a badge
  is for — would otherwise never reach the client.

The client marks read on open, when a message arrives while the tab is visible, and when you
return to a backgrounded tab.

### Message info

Hover a message → **ⓘ** for a Viber-style breakdown: **Seen by** (with the time each person
read it), **Not seen by**, and every reaction with the people behind it. The sender is left
out of both seen lists — they obviously saw their own message.

"Seen" here means *read past*: the avatars in the timeline sit on the message a marker rests
on, but "did Bob see message 40" is true for any marker at 40 or beyond. Fetched on demand
(`GET /api/messages/{message}/info`) rather than shipped with every message, since most
messages are never asked about.

Thread replies report `receipts_tracked: false` and show reactions only. Read markers are
per *channel* and clients only ever advance them to main-timeline messages, so a marker says
nothing about whether anyone opened the thread — a "Seen by" there would be a guess dressed
up as a fact.

### Typing indicators

"Alice is typing…", then "Alice and Bob are typing…", then — past two — "3 people are
typing…", because names stop being useful and start overflowing the line.

Sent as Reverb **client events** (whispers): they go straight from one subscriber to the
others via the websocket server and never reach Laravel. That's the point — a keystroke
notification is worthless a few seconds later, and routing every one through an HTTP
request, a queue and a broadcast would cost more than the messages themselves. Nothing is
persisted; a typist who goes quiet (or closes the tab) expires after a few seconds. Reverb
only accepts client events from channel members (`accept_client_events_from`), so the
private-channel auth we already do is what stops someone whispering into a channel they
can't see.

## Link previews (unfurling)

Post a URL and it unfurls: a card with the page's Open Graph title, description, site and
thumbnail — or, for a link straight to an image, the image itself. Up to 3 links per
message.

Fetching happens **on the queue**, not on the request: the sender shouldn't wait on someone
else's slow server to see their own message. The message lands with no card, and
`MessagePreviewsUpdated` drops it in over the websocket a moment later. Previews are cached
per-URL in `link_previews` (keyed by a hash of the URL) and shared by every message that
links to it, so a link doing the rounds in a channel is fetched once, not once per message;
successful ones go stale after `LinkPreview::TTL_DAYS`, and failures are remembered so a
dead link isn't retried on every mention.

### The Links tab

**Info → Links** lists every link shared in the channel, newest first, with the thumbnail,
title, who posted it and when — and a **Jump** button back to the message.

One row per *sharing*, not per URL: the same link posted twice is two entries pointing at
two different messages, even though they share one cached preview row. Links shared inside
threads are included (they're still links in the channel) but are marked `in a thread` and
offer no Jump, because the timeline jump pages the *channel* backwards and would never
surface a message that lives in a thread.

Failed and pending unfurls are listed too, falling back to the site name or host. A site
that blocks bots still had its link shared, and hiding it would make the tab lie by omission.

### Unfurling is SSRF, so `SafeUrlFetcher` guards it

Unfurling means our server makes an HTTP request to whatever a user pastes into a chat
message. Left unguarded, `http://169.254.169.254/…` or `http://redis:6379/…` would be
fetched with all the trust of an inside-the-perimeter caller. The defences, in order:

1. **http/https and ports 80/443 only** — no `file://`, no probing internal ports.
2. **No `user:pass@`** — a URL that reads as one host but connects to another.
3. **Every address the hostname resolves to must be public.** One private answer
   disqualifies the name, since a name can round-robin between them.
4. **The validated IP is pinned into curl** (`CURLOPT_RESOLVE`) for the actual connection,
   so the name can't resolve to something public for the check and private for the request.
   This is the step a naive "validate, then fetch" misses (DNS rebinding).
5. **Redirects are followed by hand**, re-running all of the above on every hop — otherwise
   a public URL could simply 302 us to localhost.
6. **The body is read to a cap**, so a huge response can't exhaust memory.

`tests/Unit/SafeUrlFetcherTest.php` asserts not just that these return nothing, but that
they never put a packet on the wire.

## Project layout

```
side-chat/
├── backend/            # Laravel API
├── frontend/           # Nuxt app
├── docker/
│   ├── php/            # FrankenPHP base image (Dockerfile, Caddyfile, php.ini)
│   ├── reverb/         # WebSocket server (FROM base)
│   ├── worker/         # supervisord: queue + scheduler (FROM base)
│   └── frontend/       # Node/Nuxt image
├── docker-compose.yml
├── Makefile
└── .env
```
