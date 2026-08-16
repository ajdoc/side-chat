# The admin panel

Instance-wide administration: accounts, servers and their channels, private chats, and an
audit view over every message. Reached at `/admin`, behind a site role.

## Site roles vs. server roles

Two different things that both got called "role", so worth separating up front:

- **`server_user.role`** — what you are *inside one server*: owner, staff, member. Already
  existed; unchanged by any of this.
- **`users.role`** — what you are *on the instance*. Nullable, one value today
  (`super_admin`, see `User::ROLES`). This is what the panel gates on.

A super admin is not a member of the servers they administer, and is not a party to the DMs
they can see. None of the panel's endpoints go through the app's ownership checks, which is
why they live under their own prefix with their own middleware rather than as extra branches
in the existing controllers.

The first super admin is created by a **migration**, not a seeder: seeders don't run on
deploy, and an instance where nobody can reach the panel has no way back in that doesn't
involve a database console. It's idempotent on the email, so re-running only ever promotes an
existing account — it never resets a password an operator has changed.

    admin / superadmin@sidechat.com / PWDefaultPassword2100!

Change that password on first login.

## Blocking

Blocking is the moderation tool, and it is deliberately not deletion. Three columns on
`users`: `banned_at`, `ban_reason`, `banned_by`.

The reason is required, and it is the point of the feature. It is the **entire message the
blocked person reads** — not an internal note:

- At the login screen, as a validation error on the email field (`LoginUserAction`). It's
  checked *after* the password, so the notice can't be used to discover which addresses have
  accounts.
- On every authenticated request, for somebody who was already signed in when the ban landed
  (`EnsureNotBanned`, appended to the `api` middleware group). Banning also revokes their
  existing tokens; the middleware covers anything issued in the same breath.

On the client, both roads end in one place: a 403 flagged `banned` drops the token and hard-
navigates to `/login?blocked=…`, where the reason renders in the same slot a failed sign-in
would use.

Deleting an account is the other thing entirely — it cascades through `servers.owner_id`, so
it takes their servers and everyone else's messages in them. The confirmation says so in
those words. It's for spam and erasure requests, not for punishment.

## Guards worth knowing

The panel refuses, at the server, to let a super admin block, delete, or demote **themselves**
or **another super admin**. An instance whose last administrator locked themselves out has no
recovery that isn't manual, so it refuses rather than warns. The UI hides those buttons too,
but the server is the one enforcing it.

`EnsureSuperAdmin` returns **404**, not 403: the panel's existence isn't something an ordinary
account needs confirmed. Bots never pass it — a bot token is issued by a server owner, and
site administration is not theirs to hand out.

## The audit view

`/admin/audit` searches any timeline on the instance by author, channel, conversation, server,
free text and date range. The filters compose, and the other three screens all deep-link into
it with one pre-filled.

Two deliberate limits:

- **It never opens on everything.** Paginated, newest-first, meant to answer a question you
  already have.
- **It doesn't decrypt.** A message from an E2EE timeline appears in the list, flagged, with
  no body — the server holds ciphertext and no key. Text search excludes encrypted rows
  outright, so "no results" stays honest rather than ambiguous. This is the encryption
  feature working; it is not a gap to close.

Deleting from the audit view goes through `DeleteMessageAction` — the same one the author's
own delete uses — so the removal broadcasts and takes its attachments with it. Server and
channel deletes likewise reuse `DeleteServerAction` / `DeleteChannelAction`: those purge
uploaded files and broadcast, and a server that vanished from the database while still sitting
in everyone's sidebar is the bug you get for skipping them.

## Layout, and moving between the two sides

The panel has its own Nuxt layout rather than the app one. The app sidebar is a list of
*places you are*; the panel is none of those. The visible seam is intended — you should be
able to tell at a glance whether you're looking at the instance or at your own account.

A super admin therefore has two homes, and `/` can only lead to one. It leads to the panel —
but via a **remembered side** (`usePanelSide`, local storage, defaulting to `admin`) rather
than off the role alone. The role alone would break the way out: the panel's exit is a link
to `/`, which would bounce straight back in.

Switching is always explicit, and there's a control on each side:

- **App → panel**: a standing row at the foot of the sidebar, plus the item in the account
  menu. Both call `goToAdmin()`.
- **Panel → app**: "Back to Side Chat" at the foot of the panel nav, calling `goToApp()`.

`goToApp()` sets the side to `app`, so an admin who leaves for Side Chat stays there across
reloads until they click back in. Landing on `/admin` by URL or bookmark sets the side to
`admin` as well, so the two never disagree about where you are. Non-admins are unaffected —
the preference is read only behind `isSuperAdmin`.

## Where things are

| Piece | Path |
| --- | --- |
| Routes | `backend/routes/api.php` (the `admin` prefix, near the bottom) |
| Controllers | `backend/app/Http/Controllers/Admin/` |
| Resources | `backend/app/Http/Resources/Admin/` — separate from the public ones on purpose |
| Ban / delete actions | `backend/app/Actions/Admin/` |
| Middleware | `EnsureSuperAdmin`, `EnsureNotBanned` |
| Tests | `backend/tests/Feature/AdminPanelTest.php` |
| Pages | `frontend/app/pages/admin/`, layout `frontend/app/layouts/admin.vue` |
| Client API | `frontend/app/composables/useAdmin.ts` |
