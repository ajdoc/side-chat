<script setup lang="ts">
import { Copy, Loader2, Plus, RefreshCw, Trash2, TriangleAlert, X } from 'lucide-vue-next'
import type { Bot, Server } from '~/types'
import { Button } from '~/components/ui/button'

/**
 * The bots on this server. Owner-only, like Roles — a bot is a credential that posts as a
 * member for as long as it exists, and whoever holds its token holds that.
 *
 * The screen is shaped around the one thing that can't be undone: **a secret is shown
 * once**. Both the API token and the webhook signing secret exist in readable form only in
 * the response that mints them, so each is surfaced in a banner that stays until it's
 * dismissed, says plainly that it won't be shown again, and offers a copy button. Anything
 * subtler — a toast, a value that fades — loses somebody their token and costs them a
 * rotation to get back.
 *
 * What this screen deliberately does *not* have is a per-channel toggle. A bot is an
 * ordinary member of the server, so it can already read every public channel and is added
 * to a private one through that channel's own Access dialog, exactly like a person. A
 * second, bot-shaped copy of that control would be a second answer to "who is in this
 * channel", and the two would drift.
 */
const props = defineProps<{ server: Server }>()
const emit = defineEmits<{ close: [] }>()

const api = useApi()

const bots = ref<Bot[]>([])
const loading = ref(true)
const error = ref<string | null>(null)
const working = ref<number | 'new' | null>(null)

/** The one secret currently on screen. Never more than one — see the component docblock. */
const revealed = ref<{ label: string, value: string, hint: string } | null>(null)

const creating = ref(false)
const draft = ref({ name: '', description: '', webhook_url: '' })

onMounted(load)

async function load() {
  loading.value = true
  try {
    const res = await api<{ data: Bot[] }>(`/api/servers/${props.server.id}/bots`)
    bots.value = res.data
  }
  catch {
    error.value = 'Couldn\'t load this server\'s bots.'
  }
  finally {
    loading.value = false
  }
}

async function create() {
  if (!draft.value.name.trim() || working.value) return
  working.value = 'new'
  error.value = null
  try {
    const res = await api<{ data: Bot, token: string, webhook_secret?: string }>(
      `/api/servers/${props.server.id}/bots`,
      {
        method: 'POST',
        body: {
          name: draft.value.name.trim(),
          description: draft.value.description.trim() || null,
          webhook_url: draft.value.webhook_url.trim() || null,
        },
      },
    )
    bots.value = [res.data, ...bots.value]
    creating.value = false
    draft.value = { name: '', description: '', webhook_url: '' }

    // The token first: it's the one nothing works without. A secret minted alongside it is
    // shown after this one is dismissed, so the two can't be confused for each other.
    reveal('API token', res.token, 'Put this in the bot\'s config as its Bearer token.')
    if (res.webhook_secret) pendingSecret.value = res.webhook_secret
  }
  catch (e: any) {
    error.value = e?.data?.message ?? 'Couldn\'t create that bot.'
  }
  finally {
    working.value = null
  }
}

/** Held back until the token banner is dismissed, so only one secret is ever on screen. */
const pendingSecret = ref<string | null>(null)

function dismissReveal() {
  revealed.value = null
  if (pendingSecret.value) {
    reveal('Webhook signing secret', pendingSecret.value, 'Verify every delivery\'s X-SideChat-Signature with this.')
    pendingSecret.value = null
  }
}

function reveal(label: string, value: string, hint: string) {
  revealed.value = { label, value, hint }
}

const copied = ref(false)

async function copy() {
  if (!revealed.value) return
  try {
    await navigator.clipboard.writeText(revealed.value.value)
    copied.value = true
    setTimeout(() => { copied.value = false }, 1500)
  }
  catch {
    // Clipboard blocked — the value is on screen and can still be selected by hand.
  }
}

async function rotateToken(bot: Bot) {
  if (!confirm(`Rotate ${bot.user.name}'s API token? The current one stops working immediately.`)) return
  working.value = bot.id
  try {
    const res = await api<{ data: { token: string } }>(`/api/servers/${props.server.id}/bots/${bot.id}/token`, { method: 'POST' })
    reveal('API token', res.data.token, `${bot.user.name}'s old token no longer works.`)
  }
  catch {
    error.value = `Couldn't rotate ${bot.user.name}'s token.`
  }
  finally {
    working.value = null
  }
}

async function rotateSecret(bot: Bot) {
  if (!confirm(`Rotate ${bot.user.name}'s signing secret? Deliveries will fail until the bot is updated.`)) return
  working.value = bot.id
  try {
    const res = await api<{ data: { webhook_secret: string } }>(`/api/servers/${props.server.id}/bots/${bot.id}/webhook-secret`, { method: 'POST' })
    reveal('Webhook signing secret', res.data.webhook_secret, `${bot.user.name} must be updated before its deliveries verify again.`)
  }
  catch {
    error.value = `Couldn't rotate ${bot.user.name}'s signing secret.`
  }
  finally {
    working.value = null
  }
}

/** Patch one bot and swap the row for what came back. */
async function patch(bot: Bot, body: Record<string, unknown>) {
  working.value = bot.id
  error.value = null
  try {
    const res = await api<{ data: Bot, webhook_secret?: string }>(
      `/api/servers/${props.server.id}/bots/${bot.id}`,
      { method: 'PATCH', body },
    )
    bots.value = bots.value.map(b => (b.id === bot.id ? res.data : b))

    // Registering an endpoint for the first time mints the signing secret — the only PATCH
    // that hands one back.
    if (res.webhook_secret) {
      reveal('Webhook signing secret', res.webhook_secret, 'Verify every delivery\'s X-SideChat-Signature with this.')
    }
  }
  catch (e: any) {
    error.value = e?.data?.message ?? `Couldn't update ${bot.user.name}.`
  }
  finally {
    working.value = null
  }
}

/**
 * Save the webhook URL as typed, including empty — which unregisters it.
 *
 * Sent on blur rather than behind a Save button: the field is one value and the round trip
 * is the confirmation. An empty string is deliberately turned into null, because "no
 * endpoint" and "the empty endpoint" have to be the same thing here.
 */
function saveWebhook(bot: Bot, value: string) {
  const url = value.trim()
  if (url === (bot.webhook_url ?? '')) return
  patch(bot, { webhook_url: url || null })
}

function saveName(bot: Bot, value: string) {
  const name = value.trim()
  if (!name || name === bot.user.name) return
  patch(bot, { name })
}

/**
 * Make this the bot the server's automations speak as.
 *
 * Exactly one per server, so this reads as a radio button rather than a switch: choosing a
 * bot takes the job off whichever had it, which is what the endpoint does in one
 * transaction. There is deliberately no "none" — a server that has stopped wanting
 * automations turns the *rules* off, and leaving them on with nobody to run them would mean
 * every one of them silently skipping.
 */
async function setAutomationBot(bot: Bot) {
  if (bot.runs_automations || working.value) return
  working.value = bot.id
  error.value = null
  try {
    await api(`/api/servers/${props.server.id}/bots/${bot.id}/automations`, { method: 'PUT' })
    // Reloaded rather than patched locally: this moves a flag on *another* row too, and a
    // local edit would leave the previous holder still looking chosen.
    await load()
  }
  catch (e: any) {
    error.value = e?.data?.message ?? `Couldn't make ${bot.user.name} the automation bot.`
  }
  finally {
    working.value = null
  }
}

async function destroy(bot: Bot) {
  if (!confirm(`Remove ${bot.user.name}? Its token stops working. What it has already posted stays.`)) return
  working.value = bot.id
  try {
    await api(`/api/servers/${props.server.id}/bots/${bot.id}`, { method: 'DELETE' })
    bots.value = bots.value.filter(b => b.id !== bot.id)
  }
  catch {
    error.value = `Couldn't remove ${bot.user.name}.`
  }
  finally {
    working.value = null
  }
}
</script>

<template>
  <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" @click.self="emit('close')">
    <div class="flex max-h-[85vh] w-full max-w-lg flex-col rounded-xl border bg-background p-4 shadow-lg">
      <div class="mb-1 flex items-center justify-between">
        <h2 class="font-semibold">Bots · {{ server.name }}</h2>
        <button class="text-muted-foreground hover:text-foreground" aria-label="Close" @click="emit('close')">
          <X class="h-4 w-4" />
        </button>
      </div>
      <p class="mb-3 text-xs text-muted-foreground">
        A bot posts as a member of this server using a token you issue. To let one into a
        private channel, add it from that channel's Access settings, like anyone else.
      </p>

      <!-- A secret, on screen for the only time it will ever be readable. -->
      <div v-if="revealed" class="mb-3 rounded-lg border border-amber-500/40 bg-amber-500/10 p-3">
        <p class="mb-1 text-xs font-semibold uppercase tracking-wide text-amber-600 dark:text-amber-400">
          {{ revealed.label }} — shown once
        </p>
        <p class="mb-2 text-xs text-muted-foreground">{{ revealed.hint }} Copy it now; it can't be shown again, only replaced.</p>
        <div class="flex items-center gap-2">
          <code class="min-w-0 flex-1 truncate rounded bg-background px-2 py-1 font-mono text-xs">{{ revealed.value }}</code>
          <Button size="sm" variant="secondary" @click="copy">
            <Copy class="mr-1 h-3.5 w-3.5" />{{ copied ? 'Copied' : 'Copy' }}
          </Button>
          <Button size="sm" variant="ghost" @click="dismissReveal">Done</Button>
        </div>
      </div>

      <!-- The state that makes every rule on the bot dashboard skip. Said here, once,
           because this is the only screen that can fix it. -->
      <div
        v-if="!loading && bots.length && !bots.some(b => b.runs_automations)"
        class="mb-3 rounded-lg border border-amber-500/40 bg-amber-500/10 px-3 py-2 text-xs"
      >
        <p class="font-medium text-amber-600 dark:text-amber-400">No bot runs this server's automations.</p>
        <p class="mt-0.5 text-muted-foreground">
          Rules, welcome messages and schedules will save but never post. Pick one below.
        </p>
      </div>

      <div v-if="loading" class="flex justify-center py-8">
        <Loader2 class="h-5 w-5 animate-spin text-muted-foreground" />
      </div>

      <div v-else class="min-h-0 flex-1 space-y-2 overflow-y-auto">
        <div v-for="bot in bots" :key="bot.id" class="rounded-lg border p-3">
          <div class="flex items-center gap-2">
            <input
              class="min-w-0 flex-1 rounded border-transparent bg-transparent px-1 py-0.5 text-sm font-medium hover:border-input focus:border-input focus:outline-none"
              :value="bot.user.name"
              :disabled="working === bot.id"
              @blur="saveName(bot, ($event.target as HTMLInputElement).value)"
              @keydown.enter="($event.target as HTMLInputElement).blur()"
            >
            <Loader2 v-if="working === bot.id" class="h-3.5 w-3.5 animate-spin text-muted-foreground" />
            <button class="text-muted-foreground hover:text-destructive" :aria-label="`Remove ${bot.user.name}`" @click="destroy(bot)">
              <Trash2 class="h-3.5 w-3.5" />
            </button>
          </div>

          <p v-if="bot.description" class="mt-0.5 px-1 text-xs text-muted-foreground">{{ bot.description }}</p>

          <label class="mt-2 block px-1 text-[11px] font-medium uppercase tracking-wide text-muted-foreground">Webhook</label>
          <input
            class="mt-1 w-full rounded border bg-background px-2 py-1 text-xs"
            placeholder="https://example.com/hook — leave empty for none"
            :value="bot.webhook_url ?? ''"
            :disabled="working === bot.id"
            @blur="saveWebhook(bot, ($event.target as HTMLInputElement).value)"
            @keydown.enter="($event.target as HTMLInputElement).blur()"
          >

          <!-- The one state that looks like "the bot is broken" and has no other symptom. -->
          <p v-if="bot.webhook_url && !bot.webhook_enabled" class="mt-1 flex items-center gap-1 text-xs text-destructive">
            <TriangleAlert class="h-3.5 w-3.5 shrink-0" />
            Delivery switched off after {{ bot.webhook_failures }} failures.
            <button class="underline" @click="patch(bot, { webhook_enabled: true })">Turn it back on</button>
          </p>

          <div class="mt-2 flex flex-wrap items-center gap-2 px-1 text-xs text-muted-foreground">
            <span>{{ bot.last_used_at ? `Last used ${new Date(bot.last_used_at).toLocaleString()}` : 'Never used' }}</span>
            <span v-if="bot.created_by">· by {{ bot.created_by }}</span>
          </div>

          <!-- Which bot the automations speak as. Here rather than on the bot dashboard
               because issuing and assigning a bot is the owner's, and the dashboard is
               staff — see StoreBotRequest for the same argument about tokens. -->
          <label class="mt-2 flex items-center gap-2 px-1 text-xs" :class="bot.runs_automations ? 'text-foreground' : 'text-muted-foreground'">
            <input
              type="radio"
              :name="`automation-bot-${server.id}`"
              :checked="bot.runs_automations"
              :disabled="working === bot.id"
              @change="setAutomationBot(bot)"
            >
            Runs this server's automations
          </label>

          <div class="mt-2 flex flex-wrap gap-2">
            <Button size="sm" variant="ghost" :disabled="working === bot.id" @click="rotateToken(bot)">
              <RefreshCw class="mr-1 h-3.5 w-3.5" /> New token
            </Button>
            <Button v-if="bot.webhook_url" size="sm" variant="ghost" :disabled="working === bot.id" @click="rotateSecret(bot)">
              <RefreshCw class="mr-1 h-3.5 w-3.5" /> New signing secret
            </Button>
          </div>
        </div>

        <p v-if="!bots.length && !creating" class="py-6 text-center text-sm text-muted-foreground">
          No bots here yet.
        </p>

        <!-- New bot -->
        <div v-if="creating" class="space-y-2 rounded-lg border border-dashed p-3">
          <input v-model="draft.name" class="w-full rounded border bg-background px-2 py-1 text-sm" placeholder="Name (e.g. Deploy Bot)" autofocus>
          <input v-model="draft.description" class="w-full rounded border bg-background px-2 py-1 text-xs" placeholder="What it does (optional)">
          <input v-model="draft.webhook_url" class="w-full rounded border bg-background px-2 py-1 text-xs" placeholder="Webhook URL (optional)">
          <div class="flex justify-end gap-2">
            <Button size="sm" variant="ghost" @click="creating = false">Cancel</Button>
            <Button size="sm" :disabled="!draft.name.trim() || working === 'new'" @click="create">
              <Loader2 v-if="working === 'new'" class="mr-1 h-3.5 w-3.5 animate-spin" />
              Create
            </Button>
          </div>
        </div>
      </div>

      <p v-if="error" class="mt-2 text-xs text-destructive">{{ error }}</p>

      <div class="mt-4 flex justify-between">
        <Button v-if="!creating" size="sm" variant="secondary" @click="creating = true">
          <Plus class="mr-1 h-3.5 w-3.5" /> Add a bot
        </Button>
        <span v-else />
        <Button variant="ghost" @click="emit('close')">Done</Button>
      </div>
    </div>
  </div>
</template>
