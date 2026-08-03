# The bot dashboard

Everything a server's bot does without anybody writing a program: greeting new members,
answering `/rules`, posting a weekly headcount, handing out badges when people react, running
a giveaway.

None of it needs the bot API. [BOTS.md](BOTS.md) is for writing a program that talks to Side
Chat from outside; this is for configuring one that already lives here.

Open it from the **server dropdown → Bot dashboard**. Owner or admin.

## Contents

- [Before anything works: pick a bot](#before-anything-works-pick-a-bot)
- [Badges](#badges)
- [Configuration](#configuration)
- [Commands](#commands)
- [Schedules](#schedules)
- [Automations](#automations)
- [Reaction Roles](#reaction-roles)
- [Giveaways](#giveaways)
- [Logging](#logging)
- [Placeholders](#placeholders)

## Before anything works: pick a bot

Everything on this dashboard posts **as a bot** — with a name, an avatar and a `BOT` badge —
so a server has to say which of its bots speaks for it.

1. Server dropdown → **Bots** (owner only; a token is standing write access, so issuing one
   stays with the person who answers for it).
2. **Add a bot** if there isn't one. A name is all it needs — no webhook, no code. Copy the
   token if you'll use it elsewhere, or ignore it.
3. Tick **Runs this server's automations**.

One bot per server holds this. Choosing another moves it.

Until you do, rules still save and their triggers still fire — but every step that posts or
reacts is **skipped**, and the dashboard says so at the top of every page. Skipped is not
failed: nothing is broken, there's just nobody to say it.

## Badges

A badge is a label a server hands out — "Griefer", "Veteran", "Playtester". An automation can
grant or revoke it, and a command or giveaway can require it.

**Where you see one.** Two places:

- **Next to the author's name in the timeline**, on every message they send. Up to two, so a
  badge collector can't push the timestamp off the line.
- **In the channel roster** — Info panel → **People**, and anywhere that list is reused. Up
  to three, then a `+2` you can hover.

Badges are scoped to the server that issued them: one you hold in another server never shows
here. In a DM or group chat there's no server, so there are no badges.

Both surfaces read from the same place — the channel roster the app already fetches — so
granting or revoking one updates every message that person has sent, without the timeline
being re-fetched.

**A badge is not a permission.** `owner / admin / member` remains the only thing that decides
who may do what. That separation is deliberate and load-bearing: it's what makes it safe for a
reaction anybody can click to grant a badge. The worst a badge can gate is a canned message or
an entry in a draw.

**Badges → New badge:**

| Field | Notes |
| --- | --- |
| Emoji | Optional. Click the 🙂 button to pick one — there's no way to type an emoji on a desktop keyboard. |
| Name | Required, unique per server. Two badges with one name are indistinguishable everywhere they're shown. |
| Colour | Optional. Tints the badge pill. |
| Description | Optional, for your own reference. |

The number on the right of each row is how many members hold it.

Deleting a badge takes it off everyone who had it, and any rule that named it starts recording
a failure on the Logging page. That's on purpose — refusing the delete until every rule had
been edited is a worse trade than one clear red line.

## Configuration

Four unrelated things share this page, so they're listed rather than described as a whole.

**Welcome message.** Pick a channel and write the greeting; it posts when somebody is admitted
to the server. Leave the channel blank to switch it off. This is an ordinary `member.joined`
rule underneath — set it here, then open **Automations** the moment you want it to do more
than post (grant a badge, DM them, post twice).

**Command prefix.** One character, `!` by default. It's what `!rules`-style commands answer
to. Move it if another bot in your server already owns `!`.

**Channels.** Where the bot posts each kind of thing. All optional; blank means "don't".

- *Moderation log* — where moderation actions are recorded.
- *Announcements* — the default channel for bot announcements.
- *Reminders* — the fallback for any schedule that doesn't name its own channel. Setting this
  lets you move every unassigned schedule at once.

**Moderation.** Which roles may run the bot's moderation commands. **Empty means nobody**, and
that's the default — these stay off until you say who has them. A bot arriving with moderation
already live for every admin is a surprise, and a surprise in that direction is expensive.

Every field here saves as you leave it. There's no Save button because each one is a single
value and the round trip is the confirmation.

## Commands

A canned answer to a question that gets asked twice a week.

**Commands → New command:**

- **Name** — letters, digits and dashes, starting with a letter. You can't use a built-in
  name (`/help`, `/roll`, `/8ball`, `/me`, `/shrug`, `/remind`, `/web`); it's refused when you
  save, rather than saved and silently never fired.
- **Shape** — `/` only, `!` only, or both. Moving a command between the two doesn't rename it.
- **Response** — what the bot says back. Supports [placeholders](#placeholders), including
  `{args}` for whatever was typed after the command.
- **Needs badge** — only holders may run it. Anybody else gets a private "you need X" rather
  than a public refusal.
- **Cooldown** — seconds one *person* must wait before running it again. Per person, not per
  channel: the thing being prevented is one member spamming `!ip`, and a channel-wide lock
  would punish everyone else for it.

Custom commands appear in `/help` and in the composer's autocomplete, so the list is always
telling the truth. A prefix-only command isn't listed there, because it isn't callable with a
slash.

Resolution order for `/name`: **built-ins → your custom commands → bot-registered commands.**
A server can't shadow `/help`, but its own command does beat a bot's.

A stray `!` in ordinary chat is left alone — "!!! it worked" and "wait, !rules is wrong" post
as written. Only a whole message that is exactly `!command` counts.

## Schedules

Recurring posts.

**Schedules → New schedule:** a name, the message, when, and which channel (blank uses the
Reminders channel from Configuration).

The **When** dropdown offers hourly / daily / weekly / monthly. The stored value is a standard
five-field cron expression, so anything else is possible — but the presets cover what people
actually ask for, and nobody should need a syntax lesson to post on Mondays.

Times are in **your browser's timezone**, saved with the schedule. "Every Monday at 9" means
nine where your people are, not nine UTC.

Each row shows its next run. The ▶ button sends it now **without moving its clock** — the
Monday post is still due on Monday. The toggle switches it off; switching it back on restarts
the window from now rather than firing for every run it slept through.

> Schedules and giveaways need Laravel's scheduler running (`schedule:run` on a cron, once a
> minute). Without it nothing fires on its own — the ▶ and *Draw now* buttons still work.

## Automations

The general form of everything above: **when X happens, do Y.**

Every built-in feature on this dashboard is one of these underneath, which is the point — the
welcome message is a `member.joined` rule, a reaction role is a pair of `reaction.added` /
`reaction.removed` rules. Nothing the built-ins do is out of reach of a rule you write.

A rule has three parts:

**When** — one trigger:

| Trigger | Fires when |
| --- | --- |
| Member joined | Somebody is admitted to the server |
| Member left | Somebody leaves or is removed |
| Role assigned | A member's role actually changes |
| Message sent | Somebody posts in a channel (**never** for a bot's own message) |
| Reaction added / removed | Somebody reacts, or takes it back |
| Command used | Somebody runs a slash command here |
| A schedule ran | One of your recurring posts came due |
| Badge granted | A member is given a badge, by rule or by hand |

**Only when** — optional filters, e.g. *body contains "deploy"*. All of them must be true.
For "either / or", make it two rules — the filter language deliberately has no `OR`, because
the moment it grows one it needs parentheses, a parser, and error messages nobody wants to
write.

**Do** — one or more steps, in order:

`Post a message` · `Send a direct message` · `Give a badge` · `Take a badge away` ·
`Change a member's role` · `React to the message` · `Post a custom command's response` ·
`Send a schedule now` · `Enter the giveaway`

**Steps run in order, one after another.** That matters when one depends on the one before —
"give the badge, *then* announce it" reads wrong reversed. When they don't depend on each
other, the order is simply irrelevant and costs you nothing.

What order is *not* for is doing the same thing in several places. **Post a message** takes an
"Also post in" list, so announcing in three channels is one step with three channels, not
three steps — one message to edit, and the audit log records it as the single thing it is. If
the bot can't reach one of those channels the rest still go out, and the log line says which
one missed.

A failing step doesn't abort the ones after it — a rule is a list of things to do, not a
transaction, and losing the welcome because the log channel was deleted would be a strange
reading of that.

Two things worth knowing:

- **Only the owner** can create a rule containing *Change a member's role*. An admin who could
  write "react 👑 → make them an admin" would have found a way to appoint admins.
- The **▶** button runs a rule for real, against you — it posts the message, it grants the
  badge. There's no dry run, because the failures worth catching (a deleted channel, a bot
  without access) only show up on the real path.

Rules can cause each other — posting a message makes *Message sent* true — which is how they
compose. There's a depth limit of 3 so a badly-written pair stops instead of running forever,
and a bot's own message never triggers anything.

## Reaction Roles

"React with 🎮 to get the Griefer badge."

Make the badges first, then **Reaction Roles → New reaction role**: pick a channel, write the
message, and add emoji → badge pairs (click the 🙂 button for the emoji).

**Post & create rules** does three things at once: posts the message as the bot, puts each
emoji on it ready to be clicked, and writes the rules. Reacting grants the badge; un-reacting
takes it back.

One emoji can't mean two badges on the same message. Deleting a reaction role removes both
halves of every pair — leaving the grant half behind would create a badge nobody could give up.

## Giveaways

**Giveaways → New giveaway**: a prize, a channel, a closing time, how many winners, and
optionally a badge people must hold to enter.

The bot posts the announcement and seeds the entry emoji. Reacting enters you; reacting twice
is not two chances. Entries close at the deadline even if the draw hasn't run yet.

Winners are drawn in one pass, so a three-winner giveaway can't name the same person twice. A
giveaway nobody entered says so plainly rather than inventing a winner.

**▶** draws early; **✕** cancels. Cancelling marks it cancelled rather than deleting it —
people entered, and a record of a giveaway that was called off is more honest than a silence.

## Logging

Every step of every rule, successes included. This is the answer to "why did nothing happen?",
which is otherwise unanswerable — a rule runs on a queue, out of sight, in response to
something that happened to somebody else.

Three outcomes:

- **ok** — it happened.
- **skipped** — nothing was wrong and there was nothing to do. The member had already left,
  they already had the badge, no bot is set to speak. Grey, not red, because a server full of
  these is working correctly.
- **failed** — something is wrong and the line says what. A deleted badge, a channel the bot
  can't post in.

Filter to **Failures** to find the handful that matter among thousands of successes. Kept for
30 days.

## Placeholders

Usable in welcome messages, custom command responses, automation messages and schedules:

| Placeholder | Becomes |
| --- | --- |
| `{user}` | The member the event is about |
| `{server}` | The server's name |
| `{channel}` | The channel it happened in |
| `{args}` | Whatever was typed after a command (commands only) |

A placeholder naming something the event didn't carry renders as empty rather than as literal
`{braces}` — a slightly awkward sentence beats braces in the channel.
