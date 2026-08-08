# Discussions

A channel is no longer one conversation. It is a **container** holding one or more
**discussions**, and a discussion is itself a channel — same `type`, same everything —
with `parent_id` set.

That equivalence is the whole design. Everything a discussion is supposed to own
separately (its timeline, its side chats, its Side Desk, its threads, its pins, its call,
its map) already hangs off `channel_id` in twelve different tables. Making a discussion a
channel means none of those tables move house and `Channel::visibleTo` stays the single
lock on every door. A separate `Discussion` model would mean re-parenting all twelve and
growing a second copy of the visibility rules that could drift from the first.

## Shape

- `channels.parent_id` — nullable, self-referencing, cascade on delete. Null means
  container; set means discussion.
- Exactly one level. A discussion may not itself have children. This *is* the category
  system; there will not later be categories wrapping channels wrapping discussions,
  because three levels of nesting is a sidebar nobody can read.
- A container holds nothing but identity, type, position and access. No messages, no map,
  no participants. Every one of those lives on a child.
- Every container has at least one child. New channels are born with a "General"; the
  backfill gives every existing channel one.
- Clicking a container never opens a container — it resolves to the viewer's default child
  (or General) and opens that. For a voice or Side Space channel this matters: a container
  is not a joinable room, so "which call am I joining?" always has one answer.

## Per-type behaviour

All four container types behave identically. A discussion in a voice channel gets its own
call; a discussion in a Side Space gets its own map. The only per-type wrinkle is authoring
cost: three discussions in a Side Space means three maps, so creating a space discussion
copies the container's General map by default, with a "copy from…" picker to choose another
sibling instead.

DMs and group chats get discussions too, on the same mechanism — their container is a
`Conversation` rather than a `Server`, which `Channel::container()` already abstracts.

## Permissions

Anyone who can see a container may create a discussion in it, gated by
`servers.discussion_creation` (`everyone` | `staff`, defaulting to `everyone`). The column
exists from day one even though it starts permissive: an open discussion list on a public
server is an unbounded thing anyone can spam, and adding the switch after that happens is
the expensive order to do it in.

Deleting a discussion is staff-only, the same rule that already guards deleting a channel:
opening a conversation is cheap and reversible, deleting one takes other people's messages with
it. It cascades its messages, and the last remaining discussion in a container cannot be deleted
— a container with no children is a channel you cannot open.

Privacy stays per-channel, so it now works at both levels: a private container hides its
whole subtree, and a public container may hold a private discussion.

## Phases

1. **Schema and read model.** `parent_id`; `channel_reads.default_child_id`;
   `servers.discussion_creation`. Backfill a General child for every existing channel and
   re-point all twelve child tables at it. `ChannelService::forServer` returns the tree and
   rolls child unread counts up to the container.
2. **Sidebar and routing.** Discussions draw as a branch under the channel row, collapsed
   by default and expanded for the channel you are in; a container's collapsed row carries
   a dot when any child is unread. Container routes resolve to a child. Breadcrumb and
   "all discussions" affordance inside a conversation.
3. **Per-user default.** Set/clear a default discussion; container resolution consults it.
4. **Creation and deletion.** Create/delete endpoints and UI, the space map copy, the
   `discussion_creation` switch in server settings.

All four phases are shipped.

## Where the UI lives

A channel with one discussion looks exactly as it did before this feature existed: no
breadcrumb, no branch, and the header still calls the place by the channel's name. That
constraint decides where each control goes.

- **Starting one** is on the channel's row in the sidebar, because that is the one surface a
  single-discussion channel still has. Also in the header picker and the directory, once there
  is one.
- **Switching, pinning, renaming and deleting** are in the header picker, which only appears
  once a channel holds more than one discussion. Renaming and deleting are staff's; pinning is
  yours alone.
- **The directory** — `/servers/{server}/discussions/{channel}` — is the forum-style view: every
  discussion in the channel with its message count and last activity, searchable and sortable.
  Reached from the "Discussions" button in the channel header or from the picker. It exists
  because the picker is the right shape for three discussions and the wrong shape for thirty.
- **The policy switch** is in server settings, beside the name.

The directory's counts exclude thread replies and system notices: a busy thread is activity
*inside* one conversation rather than evidence of more of them, and "X joined the call" is not
something anybody said.
