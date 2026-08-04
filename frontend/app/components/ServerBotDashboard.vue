<script setup lang="ts">
import {
  Award,
  Bot as BotIcon,
  Clock,
  Code2,
  FileText,
  Gift,
  Heart,
  CircleCheck,
  CircleMinus,
  LayoutGrid,
  Loader2,
  Pencil,
  Play,
  Plus,
  RefreshCw,
  Settings2,
  TriangleAlert,
  X,
  Zap,
} from 'lucide-vue-next'
import type { Automation, Badge, BotAuditLine, BotSchedule, Channel, CustomCommand, Giveaway, ReactionRoleGroup, Server, ServerRole } from '~/types'
import { Button } from '~/components/ui/button'
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from '~/components/ui/alert-dialog'

/**
 * The bot's control panel: what it does, and how it behaves.
 *
 * A dialog rather than a route because that's where every other server setting lives
 * (Roles, Bots, Access) — and because it's a thing you step into from the server menu and
 * step out of, not somewhere you navigate to. The sidebar is a section switcher inside the
 * dialog rather than real navigation for the same reason.
 *
 * Staff, not owner-only. Running the place is what an admin is for, and a welcome message is
 * squarely that. The one thing an admin can't do is write a rule that hands out roles, which
 * the API refuses on the payload — see StoreAutomationRequest. This screen doesn't hide the
 * action, because a refusal that explains itself beats a control that silently isn't there.
 */
const props = defineProps<{ server: Server, channels: Channel[] }>()
const channelsProp = computed(() => props.channels)
const emit = defineEmits<{ close: [] }>()

type Section =
  | 'overview' | 'commands' | 'schedules' | 'automations'
  | 'reactionRoles' | 'giveaways' | 'badges' | 'logging' | 'configuration'

const section = ref<Section>('overview')

const dashboard = useBotDashboard(props.server.id)
const {
  overview, customAutomations, catalogue, badges, commands, schedules, reactionRoles,
  giveaways, log, settings, welcome, loading, error,
} = dashboard

/*
 * Total members, not members online. Who is online lives in the presence channel the
 * browser holds (usePresence) and is keyed by user id — the overview sends counts, not a
 * roster, so there is nothing here to intersect it with. A total that is true beats an
 * "online" number that is quietly wrong.
 */

const saving = ref(false)
const saveError = ref<string | null>(null)

/** The rule currently being edited, or null. A blank one means "new". */
const editing = ref<Partial<Automation> | null>(null)

const badgeDraft = ref<Partial<Badge> | null>(null)

/**
 * The pending destructive action, and whether its dialog is open.
 *
 * One dialog driven by a description of what's about to happen, rather than an
 * `<AlertDialog>` per button: this screen has seven destructive actions and seven copies of
 * the same markup would be seven places to forget a wording change. `run` is the thing that
 * actually happens on confirm, so the button that opens it also owns what it does.
 *
 * Two refs rather than one, and the second is not redundant. Reka closes the dialog itself
 * when the action button is clicked, which fires `update:open(false)` — so if the payload
 * were cleared there, it would already be null by the time the click handler read it and
 * confirming would silently do nothing. Open-ness and the payload have to be separable.
 */
const pendingAction = ref<{
  title: string
  description: string
  label: string
  run: () => Promise<void> | void
} | null>(null)

const confirmOpen = ref(false)

function askToConfirm(action: NonNullable<typeof pendingAction.value>) {
  pendingAction.value = action
  confirmOpen.value = true
}

async function runPendingAction() {
  const action = pendingAction.value
  confirmOpen.value = false
  if (!action) return

  try {
    await action.run()
  }
  catch (e: any) {
    saveError.value = e?.data?.message ?? 'That didn\'t work.'
  }
}

/**
 * Dismissed — by Cancel, Escape, or a click outside.
 *
 * Only closes; the payload is deliberately left alone. Reka fires this on the *confirm*
 * path too, and clearing here would race the click handler for the same value — which is
 * exactly the bug that made Delete do nothing. A stale closure behind a closed dialog costs
 * nothing, and the next askToConfirm overwrites it.
 */
function dismissConfirm() {
  confirmOpen.value = false
}

const commandDraft = ref<Partial<CustomCommand> | null>(null)
const scheduleDraft = ref<Partial<BotSchedule> | null>(null)

/** A reaction-role post being composed: one message, several emoji→badge pairs. */
const reactionRoleDraft = ref<{
  channel_id: number | null
  extra_channel_ids: number[]
  body: string
  pairs: { emoji: string, badge_id: number | null }[]
} | null>(null)

const giveawayDraft = ref<{
  channel_id: number | null
  extra_channel_ids: number[]
  prize: string
  emoji: string
  winner_count: number
  required_badge_id: number | null
  ends_at: string
} | null>(null)

/** Which outcomes the Logging page is showing. Empty is everything. */
const logFilter = ref<string>('')

/**
 * The log is fetched when its page is opened, not with the rest of the dashboard.
 *
 * It's paged and potentially large, and most visits never open it — loading fifty audit
 * lines to render an Overview nobody asked about would be the one genuinely wasteful part
 * of the initial load.
 */
watch([section, logFilter], ([current, outcome]) => {
  if (current === 'logging') dashboard.loadLog(outcome ? { outcome } : {})
})

function newReactionRole() {
  reactionRoleDraft.value = {
    channel_id: channelsProp.value[0]?.id ?? null,
    extra_channel_ids: [],
    body: 'React with the emoji below to receive the badge!',
    pairs: [{ emoji: '', badge_id: null }],
  }
}

function newGiveaway() {
  giveawayDraft.value = {
    channel_id: channelsProp.value[0]?.id ?? null,
    extra_channel_ids: [],
    prize: '',
    emoji: '🎉',
    winner_count: 1,
    required_badge_id: null,
    // A week out: far enough that nobody accidentally runs a giveaway that closes today.
    ends_at: new Date(Date.now() + 7 * 864e5).toISOString().slice(0, 16),
  }
}

async function saveReactionRole() {
  const draft = reactionRoleDraft.value
  const pairs = (draft?.pairs ?? []).filter(p => p.emoji.trim() && p.badge_id)
  if (!draft?.channel_id || !draft.body.trim() || !pairs.length) return

  saving.value = true
  saveError.value = null
  try {
    await dashboard.saveReactionRole({
      channel_id: draft.channel_id,
      extra_channel_ids: draft.extra_channel_ids,
      body: draft.body,
      pairs: pairs.map(p => ({ emoji: p.emoji.trim(), badge_id: p.badge_id! })),
    })
    reactionRoleDraft.value = null
  }
  catch (e: any) {
    // The API's own words — "pick a bot to run automations first" is the one that matters.
    saveError.value = e?.data?.message ?? 'Couldn\'t create that.'
  }
  finally {
    saving.value = false
  }
}

async function saveGiveaway() {
  const draft = giveawayDraft.value
  if (!draft?.channel_id || !draft.prize.trim()) return

  saving.value = true
  saveError.value = null
  try {
    await dashboard.saveGiveaway({
      ...draft,
      channel_id: draft.channel_id,
      ends_at: new Date(draft.ends_at).toISOString(),
    } as any)
    giveawayDraft.value = null
  }
  catch (e: any) {
    saveError.value = e?.data?.message ?? 'Couldn\'t start that giveaway.'
  }
  finally {
    saving.value = false
  }
}

function drawNow(giveaway: Giveaway) {
  askToConfirm({
    title: 'Draw the winners now?',
    description: `“${giveaway.prize}” closes immediately, ahead of its end time. `
      + `${giveaway.entries_count} entered — whoever hasn’t reacted yet misses out.`,
    label: 'Draw now',
    run: async () => {
      running.value = giveaway.id
      try {
        await dashboard.drawGiveaway(giveaway)
      }
      finally {
        running.value = null
      }
    },
  })
}

function cancelGiveaway(giveaway: Giveaway) {
  askToConfirm({
    title: 'Cancel this giveaway?',
    description: `“${giveaway.prize}” won’t be drawn and reacting stops entering people. `
      + 'It stays in this list marked cancelled, so there’s still a record of it.',
    label: 'Cancel giveaway',
    run: () => dashboard.cancelGiveaway(giveaway),
  })
}

onMounted(dashboard.load)

const sections: { id: Section, label: string, icon: any }[] = [
  { id: 'overview', label: 'Overview', icon: LayoutGrid },
  { id: 'commands', label: 'Commands', icon: Code2 },
  { id: 'schedules', label: 'Schedules', icon: Clock },
  { id: 'automations', label: 'Automations', icon: Zap },
  { id: 'reactionRoles', label: 'Reaction Roles', icon: Heart },
  { id: 'giveaways', label: 'Giveaways', icon: Gift },
  { id: 'badges', label: 'Badges', icon: Award },
  { id: 'logging', label: 'Logging', icon: FileText },
  { id: 'configuration', label: 'Configuration', icon: Settings2 },
]

function newAutomation() {
  editing.value = { name: '', trigger: '', conditions: [], condition_match: 'all', actions: [], enabled: true }
}

function edit(automation: Automation) {
  // A copy, so cancelling leaves the list untouched rather than half-edited.
  editing.value = JSON.parse(JSON.stringify(automation))
}

async function save() {
  if (!editing.value) return
  saving.value = true
  saveError.value = null
  try {
    await dashboard.saveAutomation(editing.value)
    editing.value = null
  }
  catch (e: any) {
    // The API's own message, because the one that matters most here — "only the owner can
    // create a rule that changes roles" — is a sentence we shouldn't paraphrase.
    saveError.value = e?.data?.message ?? 'Couldn\'t save this rule.'
  }
  finally {
    saving.value = false
  }
}

const running = ref<number | null>(null)

async function run(automation: Automation) {
  running.value = automation.id
  try {
    await dashboard.runAutomation(automation)
  }
  finally {
    running.value = null
  }
}

/**
 * Post an announcement again — a reaction-role message, or a giveaway.
 *
 * The two are different resources with different endpoints, so the caller hands over the call
 * itself and this only does what's the same either way: spin the right row while it's in flight
 * (`running` is keyed by the id the row's own buttons check), and don't leave a button spinning
 * for ever if the server refuses.
 */
async function resend(key: number, action: () => Promise<unknown>) {
  running.value = key
  try {
    await action()
  }
  finally {
    running.value = null
  }
}

function remove(automation: Automation) {
  askToConfirm({
    title: 'Delete this rule?',
    description: `“${automation.name}” stops running immediately. This can’t be undone — `
      + 'to switch it off without losing it, use the toggle instead.',
    label: 'Delete rule',
    run: () => dashboard.deleteAutomation(automation),
  })
}

async function saveBadge() {
  if (!badgeDraft.value?.name?.trim()) return
  saving.value = true
  saveError.value = null
  try {
    await dashboard.saveBadge(badgeDraft.value)
    badgeDraft.value = null
  }
  catch (e: any) {
    saveError.value = e?.data?.message ?? 'Couldn\'t save this badge.'
  }
  finally {
    saving.value = false
  }
}

async function saveCommand() {
  if (!commandDraft.value?.name?.trim() || !commandDraft.value?.response?.trim()) return
  saving.value = true
  saveError.value = null
  try {
    await dashboard.saveCommand(commandDraft.value)
    commandDraft.value = null
  }
  catch (e: any) {
    // The API's own words — "that name is already a built-in" is a sentence worth keeping.
    saveError.value = e?.data?.message ?? 'Couldn\'t save this command.'
  }
  finally {
    saving.value = false
  }
}

async function saveSchedule() {
  if (!scheduleDraft.value?.name?.trim() || !scheduleDraft.value?.body?.trim()) return
  saving.value = true
  saveError.value = null
  try {
    await dashboard.saveSchedule(scheduleDraft.value)
    scheduleDraft.value = null
  }
  catch (e: any) {
    saveError.value = e?.data?.message ?? 'Couldn\'t save this schedule.'
  }
  finally {
    saving.value = false
  }
}

async function sendSchedule(schedule: BotSchedule) {
  running.value = schedule.id
  try {
    // Returns why it didn't send, if it didn't — more use than a generic failure.
    const reason = await dashboard.runSchedule(schedule)
    if (reason) saveError.value = reason
  }
  finally {
    running.value = null
  }
}

function removeBadge(badge: Badge) {
  askToConfirm({
    title: 'Delete this badge?',
    description: `Everyone holding “${badge.name}” loses it, and any rule that grants or `
      + 'requires it will start recording a failure on the Logging page.',
    label: 'Delete badge',
    run: () => dashboard.deleteBadge(badge),
  })
}

function removeCommand(command: CustomCommand) {
  askToConfirm({
    title: 'Delete this command?',
    description: `“${command.name}” stops answering immediately, and any rule that posts its `
      + 'response will start failing.',
    label: 'Delete command',
    run: () => dashboard.deleteCommand(command),
  })
}

function removeSchedule(schedule: BotSchedule) {
  askToConfirm({
    title: 'Delete this schedule?',
    description: `“${schedule.name}” will never post again. To pause it instead, use the `
      + 'toggle — that keeps the message and the timing.',
    label: 'Delete schedule',
    run: () => dashboard.deleteSchedule(schedule),
  })
}

function removeReactionRole(group: ReactionRoleGroup) {
  askToConfirm({
    title: 'Delete this reaction role?',
    description: 'Reacting stops granting the badge, and un-reacting stops taking it back. '
      + 'People who already have it keep it, and the message itself stays where it is.',
    label: 'Delete reaction role',
    run: () => dashboard.deleteReactionRole(group),
  })
}

/** Settings save on blur — each field is one value, and the round trip is the confirmation. */
async function patchSettings(patch: Record<string, unknown>) {
  try {
    await dashboard.saveSettings(patch)
  }
  catch (e: any) {
    saveError.value = e?.data?.message ?? 'Couldn\'t save that.'
  }
}

async function saveWelcome() {
  if (!welcome.value) return
  try {
    await dashboard.saveWelcome({
      channel_id: welcome.value.channel_id,
      body: welcome.value.body,
      enabled: welcome.value.enabled,
    })
  }
  catch (e: any) {
    saveError.value = e?.data?.message ?? 'Couldn\'t save the welcome message.'
  }
}

function toggleModRole(role: ServerRole, on: boolean) {
  const current = settings.value?.mod_roles ?? []
  patchSettings({ mod_roles: on ? [...current, role] : current.filter(r => r !== role) })
}

// From the sidebar's own list rather than the section id: 'reactionRoles' is a key, not a
// heading, and title-casing it would render "ReactionRoles".
const sectionLabel = computed(() => sections.find(s => s.id === section.value)?.label ?? '')

const triggerLabel = (name: string) =>
  catalogue.value?.triggers.find(t => t.name === name)?.label ?? name

/**
 * The event's own values, for the Logging page.
 *
 * Ids are dropped — they're in the line already and say nothing about why a filter missed.
 * What's left is the strings somebody would actually have written a filter against.
 */
function eventFields(line: BotAuditLine): [string, string][] {
  const event = (line.context?.event ?? {}) as Record<string, unknown>

  return Object.entries(event)
    .filter(([key, value]) => !key.endsWith('_id') && value !== null && value !== '')
    .map(([key, value]) => [key, String(value)] as [string, string])
    .slice(0, 6)
}

const outcomeIcon = { ok: CircleCheck, skipped: CircleMinus, failed: TriangleAlert }
const outcomeClass = {
  ok: 'text-emerald-500',
  // Grey, not amber. A skip means "there was nothing to do", which is not a warning.
  skipped: 'text-muted-foreground',
  failed: 'text-destructive',
}
</script>

<template>
  <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" @click.self="emit('close')">
    <div class="flex h-[85vh] w-full max-w-4xl overflow-hidden rounded-xl border bg-background shadow-lg">
      <!-- Sidebar -->
      <nav class="flex w-48 shrink-0 flex-col border-r bg-muted/30 p-2">
        <div class="mb-3 flex items-center gap-2 px-2 py-1.5">
          <BotIcon class="h-4 w-4 text-primary" />
          <span class="truncate text-sm font-semibold">{{ overview?.bot?.user.name ?? 'Bot' }}</span>
        </div>

        <button
          v-for="item in sections"
          :key="item.id"
          class="flex items-center gap-2 rounded-md px-2 py-1.5 text-left text-sm"
          :class="section === item.id ? 'bg-primary/10 text-primary' : 'text-muted-foreground hover:text-foreground'"
          @click="section = item.id"
        >
          <component :is="item.icon" class="h-4 w-4 shrink-0" />
          {{ item.label }}
        </button>

        <div class="mt-auto px-2 pb-1 text-[11px] text-muted-foreground">
          {{ server.name }}
        </div>
      </nav>

      <!-- Body -->
      <div class="flex min-w-0 flex-1 flex-col">
        <div class="flex items-center justify-between border-b px-4 py-3">
          <h2 class="text-sm font-semibold">{{ sectionLabel }}</h2>
          <button class="text-muted-foreground hover:text-foreground" aria-label="Close" @click="emit('close')">
            <X class="h-4 w-4" />
          </button>
        </div>

        <div v-if="loading" class="flex flex-1 items-center justify-center">
          <Loader2 class="h-5 w-5 animate-spin text-muted-foreground" />
        </div>

        <p v-else-if="error" class="p-4 text-sm text-destructive">{{ error }}</p>

        <div v-else class="min-h-0 flex-1 overflow-y-auto p-4">
          <p v-if="saveError" class="mb-3 rounded-lg border border-destructive/40 bg-destructive/10 px-3 py-2 text-xs text-destructive">
            {{ saveError }}
          </p>

          <!-- No bot to speak as. The one state where nothing on this screen can work, so
               it's said once at the top rather than as a failure on every rule. -->
          <div v-if="!overview?.bot" class="mb-4 rounded-lg border border-amber-500/40 bg-amber-500/10 px-3 py-2 text-xs">
            <p class="font-medium text-amber-600 dark:text-amber-400">No bot is set to run automations.</p>
            <p class="mt-0.5 text-muted-foreground">
              Rules will save, but every step that posts or reacts is skipped until a bot is
              picked. The server's owner does that in
              <span class="font-medium text-foreground">the server menu → Bots</span>, using
              the “Runs this server's automations” option on the bot they want.
              <span v-if="!overview?.has_bots">There are no bots here yet — one has to be created there first.</span>
            </p>
          </div>

          <!-- Overview -->
          <template v-if="section === 'overview'">
            <div class="mb-4 grid grid-cols-2 gap-2 sm:grid-cols-4">
              <div class="rounded-lg border p-3">
                <p class="text-lg font-semibold">{{ overview?.member_count }}</p>
                <p class="text-xs text-muted-foreground">Members</p>
              </div>
              <div class="rounded-lg border p-3">
                <p class="text-lg font-semibold">{{ overview?.channel_count }}</p>
                <p class="text-xs text-muted-foreground">Channels</p>
              </div>
              <div class="rounded-lg border p-3">
                <p class="text-lg font-semibold">{{ overview?.enabled_automation_count }}</p>
                <p class="text-xs text-muted-foreground">Active rules</p>
              </div>
              <div class="rounded-lg border p-3">
                <p class="text-lg font-semibold">{{ overview?.badge_count }}</p>
                <p class="text-xs text-muted-foreground">Badges</p>
              </div>
            </div>

            <h3 class="mb-2 text-xs font-medium uppercase tracking-wide text-muted-foreground">Recent activity</h3>
            <p v-if="!overview?.recent.length" class="rounded-lg border border-dashed px-3 py-6 text-center text-xs text-muted-foreground">
              Nothing yet. When a rule runs, every step it took shows up here — including the
              ones that did nothing, and why.
            </p>
            <div v-for="line in overview?.recent ?? []" :key="line.id" class="flex items-start gap-2 border-b py-2 text-xs last:border-0">
              <component :is="outcomeIcon[line.outcome]" class="mt-0.5 h-3.5 w-3.5 shrink-0" :class="outcomeClass[line.outcome]" />
              <div class="min-w-0 flex-1">
                <p>
                  <span class="font-medium">{{ line.automation ?? 'Bot' }}</span>
                  <span class="text-muted-foreground"> · {{ line.action }}</span>
                  <span v-if="line.subject" class="text-muted-foreground"> · {{ line.subject }}</span>
                </p>
                <p v-if="line.message" class="text-muted-foreground">{{ line.message }}</p>
              </div>
              <span class="shrink-0 text-muted-foreground">{{ new Date(line.created_at).toLocaleTimeString() }}</span>
            </div>
          </template>

          <!-- Commands -->
          <template v-else-if="section === 'commands'">
            <div class="mb-3 flex items-center justify-between">
              <p class="text-xs text-muted-foreground">
                A canned answer to a question that gets asked twice a week. Callable as
                <code class="rounded bg-muted px-1">/name</code> or
                <code class="rounded bg-muted px-1">{{ settings?.command_prefix ?? '!' }}name</code>.
              </p>
              <Button size="sm" @click="commandDraft = { name: '', response: '', kind: 'both', cooldown_seconds: 0 }">
                <Plus class="mr-1 h-3.5 w-3.5" />New command
              </Button>
            </div>

            <div v-if="commandDraft" class="mb-3 rounded-lg border p-3">
              <div class="flex gap-2">
                <input v-model="commandDraft.name" class="min-w-0 flex-1 rounded border bg-background px-2 py-1 text-sm" placeholder="rules">
                <select v-model="commandDraft.kind" class="rounded border bg-background px-2 py-1 text-sm">
                  <option value="both">/ and {{ settings?.command_prefix ?? '!' }}</option>
                  <option value="slash">/ only</option>
                  <option value="prefix">{{ settings?.command_prefix ?? '!' }} only</option>
                </select>
              </div>
              <textarea
                v-model="commandDraft.response"
                class="mt-2 w-full rounded border bg-background px-2 py-1 text-sm"
                rows="3"
                placeholder="What the bot says back. {user}, {server} and {args} are filled in."
              />
              <div class="mt-2 flex flex-wrap items-center gap-2">
                <label class="text-[11px] text-muted-foreground">
                  Needs badge
                  <select v-model="commandDraft.required_badge_id" class="ml-1 rounded border bg-background px-1.5 py-1 text-xs">
                    <option :value="null">— anybody —</option>
                    <option v-for="badge in badges" :key="badge.id" :value="badge.id">{{ badge.name }}</option>
                  </select>
                </label>
                <label class="text-[11px] text-muted-foreground">
                  Cooldown
                  <input v-model.number="commandDraft.cooldown_seconds" type="number" min="0" max="3600" class="ml-1 w-16 rounded border bg-background px-1.5 py-1 text-xs">
                  s per person
                </label>
              </div>
              <div class="mt-2 flex gap-2">
                <Button size="sm" :disabled="saving" @click="saveCommand">Save</Button>
                <Button size="sm" variant="ghost" @click="commandDraft = null">Cancel</Button>
              </div>
            </div>

            <p v-if="!commands.length" class="rounded-lg border border-dashed px-3 py-8 text-center text-xs text-muted-foreground">
              No commands yet.
            </p>

            <div v-for="command in commands" :key="command.id" class="mb-1.5 rounded-lg border p-2.5">
              <div class="flex items-center gap-2">
                <code class="shrink-0 rounded bg-muted px-1.5 py-0.5 text-xs">
                  {{ command.kind === 'prefix' ? (settings?.command_prefix ?? '!') : '/' }}{{ command.name }}
                </code>
                <span class="min-w-0 flex-1 truncate text-xs text-muted-foreground">{{ command.response }}</span>
                <span class="shrink-0 text-xs text-muted-foreground">{{ command.use_count }}×</span>
                <button class="text-muted-foreground hover:text-foreground" aria-label="Edit" @click="commandDraft = { ...command }">
                  <Pencil class="h-3.5 w-3.5" />
                </button>
                <button class="text-muted-foreground hover:text-destructive" aria-label="Delete" @click="removeCommand(command)">
                  <X class="h-3.5 w-3.5" />
                </button>
              </div>
            </div>
          </template>

          <!-- Schedules -->
          <template v-else-if="section === 'schedules'">
            <div class="mb-3 flex items-center justify-between">
              <p class="text-xs text-muted-foreground">Post something on a repeating clock.</p>
              <Button
                size="sm"
                @click="scheduleDraft = { name: '', body: '', cron: '0 9 * * 1', channel_id: null, enabled: true }"
              >
                <Plus class="mr-1 h-3.5 w-3.5" />New schedule
              </Button>
            </div>

            <div v-if="scheduleDraft" class="mb-3 rounded-lg border p-3">
              <input v-model="scheduleDraft.name" class="w-full rounded border bg-background px-2 py-1 text-sm" placeholder="Weekly headcount">
              <textarea
                v-model="scheduleDraft.body"
                class="mt-2 w-full rounded border bg-background px-2 py-1 text-sm"
                rows="3"
                placeholder="What to post"
              />
              <div class="mt-2 grid grid-cols-2 gap-2">
                <div>
                  <span class="mb-0.5 block text-[11px] text-muted-foreground">Channels</span>
                  <ChannelMultiSelect
                    v-model:primary="scheduleDraft.channel_id"
                    v-model:extras="scheduleDraft.extra_channel_ids"
                    :channels="channels"
                    empty-label="— the reminders channel —"
                  />
                </div>
              </div>
              <div class="mt-2">
                <span class="mb-0.5 block text-[11px] text-muted-foreground">When</span>
                <CronPicker v-model="scheduleDraft.cron" />
              </div>
              <div class="mt-2 flex gap-2">
                <Button size="sm" :disabled="saving" @click="saveSchedule">Save</Button>
                <Button size="sm" variant="ghost" @click="scheduleDraft = null">Cancel</Button>
              </div>
            </div>

            <p v-if="!schedules.length" class="rounded-lg border border-dashed px-3 py-8 text-center text-xs text-muted-foreground">
              No schedules yet.
            </p>

            <div v-for="schedule in schedules" :key="schedule.id" class="mb-1.5 rounded-lg border p-2.5">
              <div class="flex items-center gap-2">
                <span class="min-w-0 flex-1 truncate text-sm font-medium">{{ schedule.name }}</span>
                <button
                  class="relative h-5 w-9 shrink-0 rounded-full transition-colors"
                  :class="schedule.enabled ? 'bg-primary' : 'bg-muted-foreground/30'"
                  :aria-label="schedule.enabled ? 'Disable' : 'Enable'"
                  @click="dashboard.toggleSchedule(schedule)"
                >
                  <span class="absolute top-0.5 h-4 w-4 rounded-full bg-white transition-all" :class="schedule.enabled ? 'left-[1.15rem]' : 'left-0.5'" />
                </button>
                <button class="text-muted-foreground hover:text-foreground" aria-label="Send now" :disabled="running === schedule.id" @click="sendSchedule(schedule)">
                  <Loader2 v-if="running === schedule.id" class="h-3.5 w-3.5 animate-spin" />
                  <Play v-else class="h-3.5 w-3.5" />
                </button>
                <button class="text-muted-foreground hover:text-foreground" aria-label="Edit" @click="scheduleDraft = { ...schedule }">
                  <Pencil class="h-3.5 w-3.5" />
                </button>
                <button class="text-muted-foreground hover:text-destructive" aria-label="Delete" @click="removeSchedule(schedule)">
                  <X class="h-3.5 w-3.5" />
                </button>
              </div>
              <p class="mt-0.5 truncate text-xs text-muted-foreground">{{ schedule.body }}</p>
              <p class="mt-0.5 text-xs text-muted-foreground">
                <code class="rounded bg-muted px-1">{{ schedule.cron }}</code>
                <span v-if="schedule.next_run_at && schedule.enabled">
                  · next {{ new Date(schedule.next_run_at).toLocaleString() }}
                </span>
              </p>
            </div>
          </template>

          <!-- Automations -->
          <template v-else-if="section === 'automations'">
            <template v-if="editing">
              <AutomationBuilder
                v-model="editing"
                :catalogue="catalogue!"
                :channels="channels"
                :badges="badges"
              />
              <div class="mt-4 flex gap-2">
                <Button size="sm" :disabled="saving || !editing.name || !editing.trigger || !editing.actions?.length" @click="save">
                  <Loader2 v-if="saving" class="mr-1 h-3.5 w-3.5 animate-spin" />
                  Save rule
                </Button>
                <Button size="sm" variant="ghost" @click="editing = null">Cancel</Button>
              </div>
            </template>

            <template v-else>
              <div class="mb-3 flex items-center justify-between">
                <p class="text-xs text-muted-foreground">
                  When something happens in this server, do something about it.
                </p>
                <Button size="sm" @click="newAutomation">
                  <Plus class="mr-1 h-3.5 w-3.5" />New rule
                </Button>
              </div>

              <p v-if="!customAutomations.length" class="rounded-lg border border-dashed px-3 py-8 text-center text-xs text-muted-foreground">
                No rules yet. The welcome message on the Configuration page is one of these
                underneath — anything it can do, a rule here can too.
              </p>

              <div v-for="automation in customAutomations" :key="automation.id" class="mb-2 rounded-lg border p-3">
                <div class="flex items-center gap-2">
                  <span class="min-w-0 flex-1 truncate text-sm font-medium">{{ automation.name }}</span>

                  <!-- The switch, first: stopping a misbehaving rule is the edit people
                       make in a hurry. -->
                  <button
                    class="relative h-5 w-9 shrink-0 rounded-full transition-colors"
                    :class="automation.enabled ? 'bg-primary' : 'bg-muted-foreground/30'"
                    :aria-label="automation.enabled ? 'Disable' : 'Enable'"
                    @click="dashboard.toggleAutomation(automation)"
                  >
                    <span class="absolute top-0.5 h-4 w-4 rounded-full bg-white transition-all" :class="automation.enabled ? 'left-[1.15rem]' : 'left-0.5'" />
                  </button>

                  <button class="text-muted-foreground hover:text-foreground" aria-label="Run now" :disabled="running === automation.id" @click="run(automation)">
                    <Loader2 v-if="running === automation.id" class="h-3.5 w-3.5 animate-spin" />
                    <Play v-else class="h-3.5 w-3.5" />
                  </button>
                  <button class="text-muted-foreground hover:text-foreground" aria-label="Edit" @click="edit(automation)">
                    <Pencil class="h-3.5 w-3.5" />
                  </button>
                  <button class="text-muted-foreground hover:text-destructive" aria-label="Delete" @click="remove(automation)">
                    <X class="h-3.5 w-3.5" />
                  </button>
                </div>

                <div class="mt-1 flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                  <span class="rounded bg-primary/10 px-1.5 py-0.5 text-primary">{{ triggerLabel(automation.trigger) }}</span>
                  <span>{{ automation.actions.length }} step{{ automation.actions.length === 1 ? '' : 's' }}</span>
                  <span v-if="automation.conditions.length">
                    {{ automation.conditions.length }} filter{{ automation.conditions.length === 1 ? '' : 's' }}
                    <template v-if="automation.conditions.length > 1">({{ automation.condition_match === 'any' ? 'any' : 'all' }})</template>
                  </span>
                  <!-- "Has it ever fired" is the first question anybody debugging asks. -->
                  <span>{{ automation.run_count ? `ran ${automation.run_count}×` : 'never run' }}</span>
                </div>
              </div>
            </template>
          </template>

          <!-- Reaction Roles -->
          <template v-else-if="section === 'reactionRoles'">
            <div class="mb-3 flex items-center justify-between">
              <p class="text-xs text-muted-foreground">
                Post a message, pick an emoji and a badge. Reacting grants it; un-reacting
                takes it back.
              </p>
              <Button size="sm" @click="newReactionRole">
                <Plus class="mr-1 h-3.5 w-3.5" />New reaction role
              </Button>
            </div>

            <div v-if="reactionRoleDraft" class="mb-3 rounded-lg border p-3">
              <label class="mb-0.5 block text-[11px] text-muted-foreground">Channels</label>
              <ChannelMultiSelect
                v-model:primary="reactionRoleDraft.channel_id"
                v-model:extras="reactionRoleDraft.extra_channel_ids"
                :channels="channels"
              />

              <label class="mb-0.5 mt-2 block text-[11px] text-muted-foreground">Message to post</label>
              <textarea v-model="reactionRoleDraft.body" class="w-full rounded border bg-background px-2 py-1 text-sm" rows="2" />

              <label class="mb-1 mt-2 block text-[11px] text-muted-foreground">Reactions → badges</label>
              <div v-for="(pair, index) in reactionRoleDraft.pairs" :key="index" class="mb-1.5 flex items-center gap-1.5">
                <div class="flex w-16 shrink-0 items-center gap-1 rounded border bg-background px-1.5 py-1">
                  <span class="text-sm">{{ pair.emoji || '—' }}</span>
                  <EmojiPicker compact @select="pair.emoji = $event" />
                </div>
                <select v-model.number="pair.badge_id" class="min-w-0 flex-1 rounded border bg-background px-2 py-1 text-xs">
                  <option :value="null" disabled>— badge —</option>
                  <option v-for="badge in badges" :key="badge.id" :value="badge.id">{{ badge.name }}</option>
                </select>
                <button
                  class="text-muted-foreground hover:text-destructive disabled:opacity-30"
                  :disabled="reactionRoleDraft.pairs.length === 1"
                  aria-label="Remove pair"
                  @click="reactionRoleDraft.pairs.splice(index, 1)"
                >
                  <X class="h-3.5 w-3.5" />
                </button>
              </div>
              <button class="text-xs text-muted-foreground hover:text-foreground" @click="reactionRoleDraft.pairs.push({ emoji: '', badge_id: null })">
                <Plus class="mr-0.5 inline h-3 w-3" />Add another reaction
              </button>

              <div class="mt-3 flex items-center gap-2">
                <Button size="sm" :disabled="saving || !badges.length" @click="saveReactionRole">
                  <Loader2 v-if="saving" class="mr-1 h-3.5 w-3.5 animate-spin" />
                  Post &amp; create rules
                </Button>
                <Button size="sm" variant="ghost" @click="reactionRoleDraft = null">Cancel</Button>
                <!-- The one dead end worth naming: there is nothing to hand out yet. -->
                <span v-if="!badges.length" class="text-xs text-muted-foreground">Make a badge first.</span>
              </div>
            </div>

            <p v-if="!reactionRoles.length" class="rounded-lg border border-dashed px-3 py-8 text-center text-xs text-muted-foreground">
              No reaction roles yet.
            </p>

            <div v-for="group in reactionRoles" :key="group.message_id" class="mb-1.5 rounded-lg border p-2.5">
              <div class="flex items-center gap-2">
                <span class="min-w-0 flex-1 text-xs text-muted-foreground">
                  #{{ channels.find(c => c.id === group.channel_id)?.name ?? 'channel' }}
                </span>
                <button
                  class="text-muted-foreground hover:text-foreground"
                  aria-label="Post again"
                  title="Post the message again — the rules follow it"
                  :disabled="running === group.message_id"
                  @click="resend(group.message_id, () => dashboard.resendReactionRole(group))"
                >
                  <Loader2 v-if="running === group.message_id" class="h-3.5 w-3.5 animate-spin" />
                  <RefreshCw v-else class="h-3.5 w-3.5" />
                </button>
                <button class="text-muted-foreground hover:text-destructive" aria-label="Delete" @click="removeReactionRole(group)">
                  <X class="h-3.5 w-3.5" />
                </button>
              </div>
              <div class="mt-1 flex flex-wrap gap-1.5">
                <span v-for="pair in group.pairs" :key="pair.emoji" class="rounded bg-muted px-1.5 py-0.5 text-xs">
                  {{ pair.emoji }} → {{ pair.badge_name ?? 'deleted badge' }}
                </span>
              </div>
            </div>
          </template>

          <!-- Giveaways -->
          <template v-else-if="section === 'giveaways'">
            <div class="mb-3 flex items-center justify-between">
              <p class="text-xs text-muted-foreground">React to enter. Drawn automatically when the time is up.</p>
              <Button size="sm" @click="newGiveaway">
                <Plus class="mr-1 h-3.5 w-3.5" />New giveaway
              </Button>
            </div>

            <div v-if="giveawayDraft" class="mb-3 rounded-lg border p-3">
              <div class="flex items-center gap-2">
                <div class="flex w-14 shrink-0 items-center gap-1 rounded border bg-background px-1.5 py-1">
                  <span class="text-sm">{{ giveawayDraft.emoji || '🎉' }}</span>
                  <EmojiPicker compact @select="giveawayDraft.emoji = $event" />
                </div>
                <input v-model="giveawayDraft.prize" class="min-w-0 flex-1 rounded border bg-background px-2 py-1 text-sm" placeholder="What they win">
              </div>
              <div class="mt-2 grid grid-cols-2 gap-2">
                <div>
                  <span class="mb-0.5 block text-[11px] text-muted-foreground">Channels</span>
                  <ChannelMultiSelect
                    v-model:primary="giveawayDraft.channel_id"
                    v-model:extras="giveawayDraft.extra_channel_ids"
                    :channels="channels"
                  />
                </div>
                <label class="block">
                  <span class="mb-0.5 block text-[11px] text-muted-foreground">Closes</span>
                  <input v-model="giveawayDraft.ends_at" type="datetime-local" class="w-full rounded border bg-background px-2 py-1 text-xs">
                </label>
                <label class="block">
                  <span class="mb-0.5 block text-[11px] text-muted-foreground">Winners</span>
                  <input v-model.number="giveawayDraft.winner_count" type="number" min="1" max="20" class="w-full rounded border bg-background px-2 py-1 text-xs">
                </label>
                <label class="block">
                  <span class="mb-0.5 block text-[11px] text-muted-foreground">Only badge holders</span>
                  <select v-model.number="giveawayDraft.required_badge_id" class="w-full rounded border bg-background px-2 py-1 text-xs">
                    <option :value="null">— anybody —</option>
                    <option v-for="badge in badges" :key="badge.id" :value="badge.id">{{ badge.name }}</option>
                  </select>
                </label>
              </div>
              <div class="mt-2 flex gap-2">
                <Button size="sm" :disabled="saving" @click="saveGiveaway">Start giveaway</Button>
                <Button size="sm" variant="ghost" @click="giveawayDraft = null">Cancel</Button>
              </div>
            </div>

            <p v-if="!giveaways.length" class="rounded-lg border border-dashed px-3 py-8 text-center text-xs text-muted-foreground">
              No giveaways yet.
            </p>

            <div v-for="giveaway in giveaways" :key="giveaway.id" class="mb-1.5 rounded-lg border p-2.5">
              <div class="flex items-center gap-2">
                <span class="min-w-0 flex-1 truncate text-sm font-medium">{{ giveaway.emoji }} {{ giveaway.prize }}</span>
                <span
                  class="shrink-0 rounded px-1.5 py-0.5 text-[10px] uppercase tracking-wide"
                  :class="giveaway.status === 'running' ? 'bg-emerald-500/15 text-emerald-600 dark:text-emerald-400'
                    : giveaway.status === 'cancelled' ? 'bg-muted text-muted-foreground'
                      : 'bg-primary/10 text-primary'"
                >{{ giveaway.status }}</span>
                <template v-if="giveaway.status === 'running' || giveaway.status === 'ending'">
                  <button
                    class="text-muted-foreground hover:text-foreground"
                    aria-label="Post again"
                    title="Post the announcement again — entry follows it"
                    :disabled="running === giveaway.id"
                    @click="resend(giveaway.id, () => dashboard.resendGiveaway(giveaway))"
                  >
                    <Loader2 v-if="running === giveaway.id" class="h-3.5 w-3.5 animate-spin" />
                    <RefreshCw v-else class="h-3.5 w-3.5" />
                  </button>
                  <button class="text-muted-foreground hover:text-foreground" aria-label="Draw now" :disabled="running === giveaway.id" @click="drawNow(giveaway)">
                    <Loader2 v-if="running === giveaway.id" class="h-3.5 w-3.5 animate-spin" />
                    <Play v-else class="h-3.5 w-3.5" />
                  </button>
                  <button class="text-muted-foreground hover:text-destructive" aria-label="Cancel" @click="cancelGiveaway(giveaway)">
                    <X class="h-3.5 w-3.5" />
                  </button>
                </template>
              </div>
              <p class="mt-0.5 text-xs text-muted-foreground">
                {{ giveaway.entries_count }} entered ·
                {{ giveaway.winner_count }} winner{{ giveaway.winner_count === 1 ? '' : 's' }} ·
                closes {{ new Date(giveaway.ends_at).toLocaleString() }}
                <span v-if="giveaway.required_badge"> · needs {{ giveaway.required_badge }}</span>
              </p>
              <p v-if="giveaway.winners.length" class="mt-0.5 text-xs">
                🎉 {{ giveaway.winners.join(', ') }}
              </p>
            </div>
          </template>

          <!-- Logging -->
          <template v-else-if="section === 'logging'">
            <div class="mb-3 flex items-center gap-2">
              <p class="min-w-0 flex-1 text-xs text-muted-foreground">
                Every step of every rule, successes included — which is what makes “why did
                nothing happen?” answerable. Kept for 30 days.
              </p>
              <select v-model="logFilter" class="rounded border bg-background px-2 py-1 text-xs">
                <option value="">Everything</option>
                <option value="failed">Failures</option>
                <option value="skipped">Skipped</option>
                <option value="ok">Succeeded</option>
              </select>
            </div>

            <p v-if="!log.length" class="rounded-lg border border-dashed px-3 py-8 text-center text-xs text-muted-foreground">
              Nothing recorded{{ logFilter ? ' with that outcome' : ' yet' }}.
            </p>

            <div v-for="line in log" :key="line.id" class="flex items-start gap-2 border-b py-2 text-xs last:border-0">
              <component :is="outcomeIcon[line.outcome]" class="mt-0.5 h-3.5 w-3.5 shrink-0" :class="outcomeClass[line.outcome]" />
              <div class="min-w-0 flex-1">
                <p>
                  <span class="font-medium">{{ line.automation ?? 'Bot' }}</span>
                  <span class="text-muted-foreground"> · {{ line.action }}</span>
                  <span v-if="line.subject" class="text-muted-foreground"> · {{ line.subject }}</span>
                </p>
                <p v-if="line.message" class="text-muted-foreground">{{ line.message }}</p>
                <!-- What the event actually contained. This is what a filter compares
                     against, and a filter that rejects logs nothing at all — so seeing the
                     real values here is the only way to work out why one never matches. -->
                <p v-if="eventFields(line).length" class="mt-0.5 flex flex-wrap gap-x-2 gap-y-0.5">
                  <span v-for="[key, value] in eventFields(line)" :key="key" class="text-[11px] text-muted-foreground">
                    <code class="rounded bg-muted px-1">{{ key }}</code> {{ value }}
                  </span>
                </p>
              </div>
              <span class="shrink-0 text-muted-foreground">{{ new Date(line.created_at).toLocaleString() }}</span>
            </div>
          </template>

          <!-- Badges -->
          <template v-else-if="section === 'badges'">
            <div class="mb-3 flex items-center justify-between">
              <p class="text-xs text-muted-foreground">
                Labels you hand out. A badge is decoration and a way to address a group — it
                grants nothing on its own.
              </p>
              <Button size="sm" @click="badgeDraft = { name: '', emoji: '', color: '' }">
                <Plus class="mr-1 h-3.5 w-3.5" />New badge
              </Button>
            </div>

            <div v-if="badgeDraft" class="mb-3 rounded-lg border p-3">
              <div class="flex items-center gap-2">
                <!-- The app's own picker, not a text box: on a desktop keyboard there is no
                     way to type an emoji, so a bare input is a field you can only paste into. -->
                <div class="flex w-14 shrink-0 items-center gap-1 rounded border bg-background px-1.5 py-1">
                  <span class="text-sm">{{ badgeDraft.emoji || '—' }}</span>
                  <EmojiPicker compact @select="badgeDraft.emoji = $event" />
                </div>
                <input v-model="badgeDraft.name" class="min-w-0 flex-1 rounded border bg-background px-2 py-1 text-sm" placeholder="Name">
                <input v-model="badgeDraft.color" class="w-16 rounded border bg-background px-1 py-1 text-sm" type="color">
              </div>
              <input v-model="badgeDraft.description" class="mt-2 w-full rounded border bg-background px-2 py-1 text-xs" placeholder="What it's for (optional)">
              <div class="mt-2 flex gap-2">
                <Button size="sm" :disabled="saving || !badgeDraft.name?.trim()" @click="saveBadge">Save</Button>
                <Button size="sm" variant="ghost" @click="badgeDraft = null">Cancel</Button>
              </div>
            </div>

            <p v-if="!badges.length" class="rounded-lg border border-dashed px-3 py-8 text-center text-xs text-muted-foreground">
              No badges yet.
            </p>

            <div v-for="badge in badges" :key="badge.id" class="mb-1.5 flex items-center gap-2 rounded-lg border p-2.5">
              <span
                class="rounded px-1.5 py-0.5 text-xs font-medium"
                :style="badge.color ? { backgroundColor: `${badge.color}22`, color: badge.color } : undefined"
              >{{ badge.emoji }} {{ badge.name }}</span>
              <span class="min-w-0 flex-1 truncate text-xs text-muted-foreground">{{ badge.description }}</span>
              <span class="shrink-0 text-xs text-muted-foreground">{{ badge.holders_count ?? 0 }}</span>
              <button class="text-muted-foreground hover:text-foreground" aria-label="Edit" @click="badgeDraft = { ...badge }">
                <Pencil class="h-3.5 w-3.5" />
              </button>
              <button class="text-muted-foreground hover:text-destructive" aria-label="Delete" @click="removeBadge(badge)">
                <X class="h-3.5 w-3.5" />
              </button>
            </div>
          </template>

          <!-- Configuration -->
          <template v-else>
            <section class="mb-5">
              <h3 class="mb-1 text-xs font-medium uppercase tracking-wide text-muted-foreground">Welcome message</h3>
              <p class="mb-2 text-xs text-muted-foreground">
                Greets people when they're admitted. This is an ordinary rule underneath — it
                shows up on the Automations page the moment you need it to do more.
              </p>
              <select
                v-if="welcome"
                class="w-full rounded border bg-background px-2 py-1.5 text-sm"
                :value="welcome.channel_id ?? ''"
                @change="welcome.channel_id = ($event.target as HTMLSelectElement).value ? Number(($event.target as HTMLSelectElement).value) : null; saveWelcome()"
              >
                <option value="">— don't greet anybody —</option>
                <option v-for="channel in channels" :key="channel.id" :value="channel.id"># {{ channel.name }}</option>
              </select>
              <textarea
                v-if="welcome?.channel_id"
                v-model="welcome.body"
                class="mt-2 w-full rounded border bg-background px-2 py-1.5 text-sm"
                rows="3"
                placeholder="Welcome {user} to {server}!"
                @blur="saveWelcome"
              />
              <p v-if="welcome?.channel_id" class="mt-1 text-[11px] text-muted-foreground">
                <code class="rounded bg-muted px-1">{user}</code> and
                <code class="rounded bg-muted px-1">{server}</code> are filled in.
              </p>
            </section>

            <section v-if="settings" class="mb-5">
              <h3 class="mb-1 text-xs font-medium uppercase tracking-wide text-muted-foreground">Command prefix</h3>
              <input
                class="w-20 rounded border bg-background px-2 py-1.5 text-center text-sm"
                maxlength="1"
                :value="settings.command_prefix"
                @blur="patchSettings({ command_prefix: ($event.target as HTMLInputElement).value })"
              >
              <p class="mt-1 text-[11px] text-muted-foreground">
                One character. Move it if another bot in this server already uses it.
              </p>
            </section>

            <section v-if="settings" class="mb-5">
              <h3 class="mb-1 text-xs font-medium uppercase tracking-wide text-muted-foreground">Channels</h3>
              <div class="space-y-2">
                <label v-for="key in (['mod_log_channel_id', 'announcement_channel_id', 'reminder_channel_id'] as const)" :key="key" class="block">
                  <span class="mb-0.5 block text-[11px] text-muted-foreground">
                    {{ key === 'mod_log_channel_id' ? 'Moderation log' : key === 'announcement_channel_id' ? 'Announcements' : 'Reminders' }}
                  </span>
                  <select
                    class="w-full rounded border bg-background px-2 py-1.5 text-sm"
                    :value="settings[key] ?? ''"
                    @change="patchSettings({ [key]: ($event.target as HTMLSelectElement).value ? Number(($event.target as HTMLSelectElement).value) : null })"
                  >
                    <option value="">— disabled —</option>
                    <option v-for="channel in channels" :key="channel.id" :value="channel.id"># {{ channel.name }}</option>
                  </select>
                </label>
              </div>
            </section>

            <section v-if="settings">
              <h3 class="mb-1 text-xs font-medium uppercase tracking-wide text-muted-foreground">Moderation</h3>
              <p class="mb-2 text-xs text-muted-foreground">
                Who may run the bot's moderation commands. Empty means nobody, which is the
                default — these stay off until you say who has them.
              </p>
              <label v-for="role in (['admin', 'member'] as ServerRole[])" :key="role" class="mr-4 inline-flex items-center gap-1.5 text-sm">
                <input
                  type="checkbox"
                  :checked="settings.mod_roles.includes(role)"
                  @change="toggleModRole(role, ($event.target as HTMLInputElement).checked)"
                >
                {{ role }}
              </label>
            </section>
          </template>
        </div>
      </div>
    </div>

    <!-- One dialog for every destructive action on this screen — see pendingAction. -->
    <AlertDialog :open="confirmOpen" @update:open="!$event && dismissConfirm()">
      <AlertDialogContent v-if="pendingAction">
        <AlertDialogHeader>
          <AlertDialogTitle>{{ pendingAction.title }}</AlertDialogTitle>
          <AlertDialogDescription>{{ pendingAction.description }}</AlertDialogDescription>
        </AlertDialogHeader>
        <AlertDialogFooter>
          <AlertDialogCancel>Keep it</AlertDialogCancel>
          <AlertDialogAction
            class="bg-destructive text-white hover:bg-destructive/90"
            @click="runPendingAction"
          >
            {{ pendingAction.label }}
          </AlertDialogAction>
        </AlertDialogFooter>
      </AlertDialogContent>
    </AlertDialog>
  </div>
</template>
