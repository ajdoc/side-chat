# Bots

A bot is an ordinary member of one server that happens to be a program. It posts, gets
`@mentioned`, appears in the member list, and shows a **BOT** badge next to its name. What
it has that a person doesn't is a long-lived API token, and — optionally — a webhook that
tells it when things happen.

Everything below is the whole of a bot's reach. There is no other endpoint it can call.

## Contents

- [Registering a bot](#registering-a-bot)
- [Authenticating](#authenticating)
- [Posting a message](#posting-a-message)
- [Working the apps](#working-the-apps)
- [Webhooks](#webhooks)
- [Verifying a delivery](#verifying-a-delivery)
- [Slash commands](#slash-commands)
- [Limits and failure modes](#limits-and-failure-modes)

## Registering a bot

The **owner** of a server creates bots from the server's dropdown menu → **Bots**. Admins
can't: a token is standing write access to the server, so issuing one stays with the person
who answers for it.

Creating a bot hands back an **API token**, and — if a webhook URL was set at the same time
— a **webhook signing secret**. Both are shown **once**. Neither is stored in readable form,
so a secret that isn't copied out of that screen can only be replaced, never recovered.
Rotating either one invalidates the old value immediately.

A bot joins the server as a plain member, which means it can already see every public
channel. To let it into a **private** channel, add it from that channel's **Access**
settings, exactly like a person.

## Authenticating

Every call sends the API token as a bearer token:

```
Authorization: Bearer sc_bot_...
```

Start with `GET /api/bot/me` — it answers with the bot's own account, its server, and the
channels it can post in. Channel ids aren't guessable and they change as channels are added,
so this is how a bot finds where it lives rather than being handed ids out of band.

```bash
curl https://your-host/api/bot/me -H "Authorization: Bearer $BOT_TOKEN"
```

```jsonc
{
  "data": {
    "id": 3,
    "user": { "id": 42, "name": "Deploy Bot", "is_bot": true, "...": "..." },
    "server": { "id": 1, "name": "Acme" },
    "channels": [ { "id": 7, "name": "general", "type": "text", "...": "..." } ]
  }
}
```

A token that has been rotated, or whose bot has been removed, gets `401` from every route.

## Posting a message

```bash
curl -X POST https://your-host/api/bot/channels/7/messages \
  -H "Authorization: Bearer $BOT_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"body": "Build **1482** passed."}'
```

| Field         | Type            | Notes                                                    |
| ------------- | --------------- | -------------------------------------------------------- |
| `body`        | string          | Required, max 2000 characters. Markdown is rendered.      |
| `reply_to_id` | int, optional   | Must be a main-timeline message in the same channel.      |

The response is the created message, in the same shape the web app receives.

Three things follow from a bot's message taking the ordinary send path:

- **Mentions work.** `@Ada` in a bot's message lights up Ada's sidebar like anyone else's.
- **Links unfurl**, on the queue, arriving a moment after the message.
- **Widget commands fire.** A bot posting `k!add ship it` files a card on the channel's
  kanban board, exactly as a person typing it would. Useful, but worth knowing: a token's
  reach is the whole command surface, not just chat.

Bots can't upload files, and can't post into threads or side chats.

## Working the apps

A bot can also read and write the productivity apps directly, without going through chat. Same
token, same `bot/` prefix:

| Method | Path | What it does |
| --- | --- | --- |
| `GET` | `bot/channels/{channel}/kanban` | The board — columns and cards |
| `POST` | `bot/channels/{channel}/kanban/cards` | Add a card: `text`, optional `column` |
| `PATCH` | `bot/channels/{channel}/kanban/cards/{card}` | Move or edit one: `column`, `position`, `text`, `assignee_id` |
| `GET` | `bot/channels/{channel}/tracker/projects` | The channel's projects |
| `GET` | `bot/channels/{channel}/tracker/tasks` | Tasks, `?project={id}` for one board |
| `POST` | `bot/channels/{channel}/tracker/tasks` | Open a task: `project_id`, `title`, … |
| `PATCH` | `bot/channels/{channel}/tracker/tasks/{task}` | Update one — `status`, `assignee_id`, … |
| `GET` | `bot/channels/{channel}/calendar` | The channel's entries |
| `POST` | `bot/channels/{channel}/calendar` | Add one: `title`, `starts_at`, … |

```bash
curl -X POST https://your-host/api/bot/channels/7/kanban/cards \
  -H "Authorization: Bearer $BOT_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"text": "Build 1482 failed on main", "column": "doing"}'
```

Three things to know:

- **These are the endpoints the web app uses**, with the same bodies and the same responses — a
  bot is a member account, so it is held to the same membership rule as a person. A private
  channel it hasn't been added to refuses it identically.
- **The token is scoped to its server.** A bot account can be on several servers' rosters; the
  token reaches only channels in the server that issued it.
- **Nothing here deletes.** A credential living in a CI config shouldn't be able to remove other
  people's work. Cards and tasks can be added, moved and updated; removing them is a person's job.

A bot's writes are attributed to the bot, so a card it files shows its name — the same as any
member's.

## Webhooks

Set a webhook URL on the bot (at creation, or later from the Bots dialog) and Side Chat will
`POST` events to it. Both `http` and `https` are accepted — the signature, not the transport,
is what proves a delivery is genuine — but by default the URL must resolve to a **public**
address. A self-hosted deployment whose bot runs on the same private network can lift that
with `BOT_WEBHOOK_ALLOW_PRIVATE=true`.

Delivery is queued, so a slow receiver never delays the person who sent the message.

### Headers

| Header                 | Meaning                                                  |
| ---------------------- | -------------------------------------------------------- |
| `X-SideChat-Event`     | The event name, e.g. `message.created`.                   |
| `X-SideChat-Delivery`  | A UUID for this attempt's delivery. Use it to deduplicate.|
| `X-SideChat-Timestamp` | Unix seconds, and part of what's signed.                  |
| `X-SideChat-Signature` | `sha256=<hex>` — see below.                               |

### Body

```jsonc
{
  "id": "0f2c…",            // same value as X-SideChat-Delivery
  "event": "message.created",
  "data": { }                // shape depends on the event
}
```

### Events

| Event             | `data`                                                        |
| ----------------- | ------------------------------------------------------------- |
| `message.created` | The message, in the same shape the API returns.                |
| `command.invoked` | `{ command, args, channel_id, user: { id, name } }`            |

`message.created` is the default subscription, and currently the only one that can be
subscribed to or out of (via the `events` field on the owner's API). `command.invoked` isn't
a subscription at all — it's delivered for the commands the bot itself registered, whatever
else it asked for.

**What is deliberately never delivered:**

- **Anything a bot wrote.** Two bots that each answer the other would loop forever at queue
  speed, and nothing stops a bot answering itself. Bots are driven by people.
- **Thread and side-chat messages**, since a bot has no way to reply into either — telling it
  would be an invitation it can't accept.
- **System notices and widget cards**, which aren't things anybody said.
- **Anything in a channel the bot can't see.** A private channel it was never added to is
  silent, not merely unreplyable.

## Verifying a delivery

The signature is an HMAC-SHA256 over `{timestamp}.{raw body}`, keyed with the signing secret,
hex-encoded and prefixed `sha256=`. Sign the **raw request body**, before any JSON parsing —
re-serialising changes the bytes and the signature won't match.

The timestamp is signed as well as sent, so rejecting old ones gives you replay protection.
Compare signatures in constant time.

**Node**

```js
import crypto from 'node:crypto'

function verify(rawBody, headers, secret) {
  const timestamp = headers['x-sidechat-timestamp']
  const signature = headers['x-sidechat-signature']

  // Reject anything older than five minutes — the timestamp is signed, so this can't be forged.
  if (Math.abs(Date.now() / 1000 - Number(timestamp)) > 300) return false

  const expected = 'sha256=' + crypto
    .createHmac('sha256', secret)
    .update(`${timestamp}.${rawBody}`)
    .digest('hex')

  return crypto.timingSafeEqual(Buffer.from(expected), Buffer.from(signature))
}
```

**Python**

```python
import hashlib, hmac, time

def verify(raw_body: bytes, headers, secret: str) -> bool:
    timestamp = headers["X-SideChat-Timestamp"]
    signature = headers["X-SideChat-Signature"]

    if abs(time.time() - int(timestamp)) > 300:
        return False

    expected = "sha256=" + hmac.new(
        secret.encode(),
        f"{timestamp}.".encode() + raw_body,
        hashlib.sha256,
    ).hexdigest()

    return hmac.compare_digest(expected, signature)
```

**PHP**

```php
$expected = 'sha256='.hash_hmac(
    'sha256',
    $request->header('X-SideChat-Timestamp').'.'.$request->getContent(),
    $secret,
);

if (! hash_equals($expected, (string) $request->header('X-SideChat-Signature'))) {
    abort(401);
}
```

Answer with any `2xx`. Anything else is treated as a failure and retried.

## Slash commands

A bot declares the commands it answers to, and Side Chat routes matching `/name` messages to
its webhook. The list is what the composer's autocomplete and `/help` are built from, so a
command that exists documents itself.

```bash
curl -X PUT https://your-host/api/bot/commands \
  -H "Authorization: Bearer $BOT_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"commands": [
        {"name": "deploy", "description": "Ship a build.", "usage": "/deploy staging"},
        {"name": "rollback", "description": "Undo the last deploy."}
      ]}'
```

This replaces the **whole set** — a bot announces what this version of it can do, usually on
boot. Anything else leaves it responsible for cleaning up commands its previous version
registered, which it will forget to do, and the stale ones then sit in everybody's
autocomplete pointing at handlers that no longer exist. Sending `{"commands": []}`
unregisters everything.

Names are lowercase, may contain digits and dashes, must contain at least one letter, and
max 32 characters. Two refusals worth expecting:

- **Reserved names.** A bot can't claim `help`, `roll`, `8ball`, `me`, `shrug`, `remind` or `web` —
  a bot that owned `/help` would become the only way to find out what anything does.
- **Already taken.** Two bots in one server can't share a name. The collision is refused at
  registration, because at call time one of them would silently never fire and neither author
  would have been told.

When somebody types `/deploy staging`, the person who typed it gets a private acknowledgement,
nothing is posted to the channel, and the bot receives:

```jsonc
{
  "id": "…",
  "event": "command.invoked",
  "data": {
    "command": "deploy",
    "args": "staging",
    "channel_id": 7,
    "user": { "id": 42, "name": "Ada" }
  }
}
```

Whatever happens next is the bot's business — it replies by posting a message like anything
else. If it never does, nothing is left half-finished.

## Limits and failure modes

| Thing                    | Value                                                          |
| ------------------------ | -------------------------------------------------------------- |
| Message body             | 2000 characters                                                 |
| Registered commands      | 50 per bot                                                      |
| Webhook timeout          | 5s connect, 5s total (`BOT_WEBHOOK_TIMEOUT`)                    |
| Retries                  | 3 attempts, ~10s then ~60s apart (`BOT_WEBHOOK_TRIES`)          |
| Consecutive failures     | 50 before delivery is switched off (`BOT_WEBHOOK_MAX_FAILURES`) |

A failure counts once per event, after its retries are spent — not once per attempt. Any
success resets the count, so an endpoint that's up but flaky never creeps towards being
switched off. An endpoint that has refused 50 events in a row isn't restarting, it's gone:
delivery is switched off, and the server's owner sees why in the Bots dialog with a button to
turn it back on. Setting a new URL clears the count and re-enables it.

Redirects are **not** followed. A webhook receiver has no legitimate reason to bounce us
elsewhere, and following one would mean re-running every safety check against a target the
owner never registered.

**Removing a bot** deletes its registration and membership, and its token stops working — but
its account, and everything it has posted, stays. `messages.user_id` cascades on delete, so
removing the account would erase months of channel history because somebody retired an
integration.
