# Proposal: App Channels

**Status:** proposal, nothing built. Written 2026-08-10, revised against Root's own docs.

A fourth channel type — `app` — whose body is an application instead of a timeline. Where
`text` gives you messages, `voice` a call and `space` a walkable room, an app channel gives
you *one app, full-bleed, shared by everyone who opens it*.

## What Root actually does

Sourced from [docs.rootapp.com](https://docs.rootapp.com/docs/app-docs/get-started/app-overview/),
[support.rootapp.com](https://support.rootapp.com/docs/leader/apps/install-apps/) and
[their blog](https://www.rootapp.com/blogs/why-were-building-root). Worth stating plainly,
because it's more ambitious than "embed a web page in a channel":

- **The pitch is apps *instead of* chat.** "Not Just Chat, Built for Action." Their CEO's
  framing: existing tools "weren't built for what we actually use them for." Chat is the
  fallback, not the centre.
- **An app is a real client/server program, in TypeScript.** The **client** is a full GUI
  (React/Vue/whatever) running in a Chromium-like sandbox inside their native client — most
  browser features, but no filesystem, no webcam. The **server** is Node, with **SQLite** for
  persistence, backed up and restored by Root. The two talk over **Protobuf**, and their build
  tooling generates both sides' networking code.
- **Root hosts all of it.** "Every app on Root runs directly on the platform. No hosting, no
  scaling, no security overhead." That is the entire developer promise.
- **Each community gets its own instance of the app's server**, with its own SQLite database.
  Data isolation is structural, not a query filter.
- **Apps and bots are the same thing minus a channel.** Installing an App creates a dedicated
  channel; installing a Bot "runs in the background without a channel." Installing an app is
  literally: right-click a channel group → Create channel → **App channel** → pick from the
  store → name it, set visibility, assign roles.
- **An app acts with a member's permissions, not with API scopes.** "Your code runs with
  permissions just like a human member" — it needs *Manage Channel* to make a channel, *Kick
  Members* to kick. It cannot send push notifications, accept join invites, or touch friends
  lists. Only *Manage Apps* holders can install.
- **Uninstalling is destructive and says so:** removing an app "permanently deletes the App,
  its channel, and all associated data."
- **Installs are metered by slots** tied to community level; over the limit, apps suspend and
  are deleted after three days.

The reference video ([Ask Root Ep. 1, "Can we replace Discord?"](https://www.youtube.com/watch?v=teVLql3gVpE))
had no transcript I could retrieve — nothing below is drawn from it.

### What this means for us

My first draft guessed this was a sandboxed iframe pointing at a third-party URL. **That was
wrong in the way that matters**: the whole value is that developers don't host anything. An
iframe-plus-your-own-backend is the thing Root is explicitly selling against.

The good news is that two of their central ideas already exist in our codebase, and one of them
we got right by accident:

| Root | Us, today |
| --- | --- |
| An app acts with a member's permissions | A bot **is** a `User` with `is_bot` — so channel permissions and `scopeVisibleTo` already apply to it, with no new code |
| Apps = bots + a channel | We have the bot half already: tokens, webhooks, signing secrets, audit log |
| App = a shared surface in a channel | `Widget` is shared JSON state per channel; `DESK_APPS` is a registry of app surfaces |
| A non-timeline channel type | Side Space proved the slot exists — a map on top of a timeline that nothing below is aware of |

So the honest summary: **we can have Root's *product* in three phases without much new
machinery, and their *platform* only if we're willing to run untrusted Node per community.**
Those are separable, and I'd separate them.

## The core idea

An app channel is a channel with `type = 'app'` and one row saying which app it is. The
timeline still exists underneath, exactly as it does for a Side Space, where the map sits on
the same timeline and everything below it stays unaware
([`Channel::TYPES`](backend/app/Models/Channel.php#L25)). That inheritance is why this is
cheap: reads, mentions, search, notifications, threads and E2EE keep working because none of
them ever knew what a channel *looked* like.

## Data model

One enum value, one table.

```php
public const TYPES = ['text', 'voice', 'space', 'app'];
```

```
channel_apps
  id
  channel_id      unique, cascade delete   — one app per channel; that's the point of it
  app_id          string  — 'kanban' | 'board' | 'raid-planner' | 'ext:<slug>'
  bot_id          nullable → bots.id       — the identity the app acts as (phase 3)
  config          json nullable            — per-app settings; shape owned by the handler
  installed_by    user id nullable
  timestamps
```

`config` as untyped JSON copies [`Widget::state`](backend/app/Models/Widget.php) deliberately:
the shape belongs to the app's handler, so a new app is a handler plus a Vue component and no
schema change. Mind [the `validated()` gotcha](backend/app/Models/Channel.php) — read
free-form JSON off `input()`, not `validated()`, or the parent array vanishes when its nested
rule is absent.

Add `Channel::isApp()` and `Channel::app(): HasOne` alongside `isSpace()`/`spaceMap()`.
`allowsCalls()` returns false for `app`. `scopeVisibleTo` is untouched — all visibility keeps
flowing through it, which is what keeps search correct.

**Follow Root on destructive uninstall.** Deleting the channel deletes the app's data, and the
confirm dialog must say so in those words. The alternative — orphaned app data no UI can reach
— is worse.

## The app registry

We already maintain a registry of apps with declared capabilities: `DESK_APPS` in
[useDeskApps.ts](frontend/app/composables/useDeskApps.ts), where each entry declares `family`,
`removable`, `canvasable`, `group`. Add one field:

```ts
/** Can this app be the entire content of a channel? */
channelable: boolean
```

Then the create-channel app picker, the Side Desk tab picker and the canvas card picker are
all *filters over one list* — the same collapse that file already performed once ("those were
two lists of the same idea, and the seam showed"). Following that precedent is the reason
phase 1 is small.

## Frontend

[`ChannelView.vue`](frontend/app/components/ChannelView.vue#L398) branches on `isSpace`; it
gains a sibling branch for `type === 'app'` rendering `<AppChannel>` in place of the timeline,
with chat as a collapsible side panel. Staying inside `ChannelView` is what gets the app
channel its chat wiring free — [bubbles are fed from `ChannelView`, not the stage](frontend/app/components/ChannelView.vue).

`AppChannel.vue` is a thin dispatcher: look up `app_id`, render the component the Side Desk
tab already renders, at full size. For phase 1 that component exists and is already wired to
the right endpoints.

**Native shells:** app channels sit inside the existing allowlist because they're still
`/channels/:id` — no `native-scope.global.ts` change. What needs real work is the sub-768px
layout, the same fitting exercise the Side Space needed. Root wrote a whole blog post on
bringing apps to mobile; treat that as a warning, not a footnote.

## Phasing

| Phase | Scope | Size |
| --- | --- | --- |
| **1** | `app` type, `channel_apps`, `channelable` flag, `AppChannel.vue`, first-party board/kanban/canvas/calendar channels | Small |
| **2** | Widget-family apps as channels (music, poll, poker, skribbl); per-app config UI; channel icon reflects its app; **new first-party apps that only make sense full-screen** — a task tracker, an event planner | Medium |
| **3** | Third-party apps: install flow on top of bots, client bundle hosting, sandbox, storage API | Large |
| **4** | App store / per-server catalogue, install slots, app-authored slash commands via the existing bot command path | Medium |

Phases 1–2 stand on their own even if 3 never ships: they turn "the channel where the team's
board lives" from a tab you navigate to into a place in the sidebar. **Note that Root's own
headline apps — Task Tracker, Raid Planner, Stickerwall — are all first-party.** The store is
the long game; the product is those three. We should ship phase 2 and judge it before
committing to a platform.

## Phase 3: the part that needs a real decision

Root's model is: developer ships TS client + TS server, Root runs both, one server instance
with its own SQLite per community. Reproducing that means running untrusted Node in
per-community containers with backup, restore, quotas and isolation. That is a platform
engineering programme, not a feature.

**My recommendation is the 80% version:**

- **Client:** a static JS bundle we host and serve from a **separate origin**, rendered in a
  sandboxed iframe (`allow-scripts allow-forms`, *not* `allow-same-origin`), with a per-app
  CSP `frame-src`. We host the bundle, so "no hosting" holds for the part users see.
- **Server:** for most apps, **none**. We provide a scoped **storage API** — per-install
  key-value plus a small structured store — that the client calls directly with its install
  token. That's Root's SQLite promise minus the compute, and it covers a task tracker, a
  planner, a stickerwall.
- **When an app needs real compute:** it registers a webhook. We already have that —
  [`Bot`](backend/app/Models/Bot.php) carries `token_hash`, `webhook_url`, `webhook_secret`
  and hashed-token lookup. The developer hosts that piece, which is a real gap versus Root,
  and an honest one to state.
- **Identity and permissions:** the install creates a bot `User`, and the app acts as it.
  This is Root's "runs with permissions just like a human member," and we get it free because
  our bots are already users. No new permission system.
- **`postMessage` bridge, deny by default.** The app gets context (channel id, display name,
  locale). It never gets a session.

**E2EE is a hard boundary.** An encrypted channel refuses third-party apps outright. There's
no coherent story where third-party code sits in a room whose whole promise is that the server
can't read it, and "reversible but never retroactive" makes a half-answer worse than a refusal.
First-party apps in phase 1–2 can be allowed case by case.

## Open questions

1. **Does an app channel carry an unread badge?** It has a timeline so it can — but a board
   changing isn't a message. My inclination: unread follows the timeline only in phase 1, and
   we revisit once apps can raise activity.
2. **One app per channel, or a set?** One. "A set of apps in a channel" is the Side Desk, and
   rebuilding it here would reopen the seam `useDeskApps` closed.
3. **Can a discussion be an app channel?** A discussion *is* a channel with `parent_id` set, so
   it falls out free — but it's more rope than it's worth on day one. Gate it off initially.
4. **Do we want install slots?** Root meters them. That's a monetisation decision, not a
   technical one, and it only becomes real in phase 4.
