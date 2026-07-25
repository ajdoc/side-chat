<script setup lang="ts">
import { Ghost, Loader2, Siren, SkipForward, Sparkles, Vote, Wrench, X } from 'lucide-vue-next'
import type { SpaceGamePayload } from '~/types'
import { spriteHue } from '~/lib/spaceAvatar'
import { Button } from '~/components/ui/button'

/**
 * The game's whole interface, laid over the room — voting on it, playing it, meeting about it,
 * and seeing who won.
 *
 * Deliberately the *only* game-specific Vue in the app apart from what the stage draws on the
 * canvas. It renders whichever of the game's moments applies (there's one game today, but the
 * shape — a vote, a HUD, a meeting, an ending — is general), and it does none of the deciding:
 * every button emits an intent, and the stage, which is the thing that actually knows where
 * everyone is standing, turns that intent into the right move. So this file never computes who's
 * in kill range or which task you're on; it's handed those facts and draws the buttons for them.
 */
const props = defineProps<{
  game: SpaceGamePayload
  /** Everyone in the game, named and coloured for the meeting and the reveal. */
  players: { id: number, name: string, alive: boolean, role: 'crew' | 'impostor' | null, isMe: boolean }[]
  /** The task you're standing on and haven't done, if any — the stage worked this out. */
  nearTaskId: string | null
  /** A body at your feet you could report. */
  nearBody: boolean
  /** A crewmate in reach you could kill right now (impostor, off cooldown, in range). */
  killTarget: number | null
  /** Seconds until your kill is off cooldown, for the impostor's button. 0 when ready. */
  cooldownLeft: number
}>()

const emit = defineEmits<{
  vote: [boolean]
  dismiss: []
  'do-task': []
  report: []
  meeting: []
  kill: []
  'game-vote': [number | 'skip']
}>()

const state = computed(() => props.game.state)
const me = computed(() => props.players.find(p => p.isMe) ?? null)
const alive = computed(() => me.value?.alive ?? false)
const impostor = computed(() => state.value?.my_role === 'impostor')

/** A clock, ticking, for the meeting countdown. Its own — the server's deadline is the truth. */
const now = ref(Date.now())
let timer: ReturnType<typeof setInterval> | undefined
onMounted(() => { timer = setInterval(() => (now.value = Date.now()), 250) })
onBeforeUnmount(() => clearInterval(timer))

const meetingLeft = computed(() => {
  const ends = state.value?.meeting?.ends_at
  return ends ? Math.max(0, Math.ceil((ends - now.value) / 1000)) : 0
})

const proposer = computed(() => props.players.find(p => p.id === props.game.created_by)?.name ?? 'Someone')

/** The crew's shared progress, 0–1. */
const taskFraction = computed(() => {
  const s = state.value
  return s && s.task_goal > 0 ? s.task_done / s.task_goal : 0
})

const alivePlayers = computed(() => props.players.filter(p => p.alive))
const myVote = computed(() => state.value?.meeting?.mine ?? null)
const hasVoted = computed(() => myVote.value !== null)

/** A stable colour per player, the same golden-angle hue their sprite wears. */
function dot(id: number) {
  return `hsl(${spriteHue(id)} 62% 52%)`
}
</script>

<template>
  <!-- VOTING — a card in the middle of the room asking the room to play. -->
  <div
    v-if="game.status === 'voting' && game.vote"
    class="pointer-events-auto absolute inset-0 z-20 grid place-items-center bg-black/40 p-4 backdrop-blur-[1px]"
  >
    <div class="w-full max-w-sm space-y-3 rounded-xl border bg-background p-5 text-center shadow-xl">
      <Sparkles class="mx-auto h-7 w-7 text-primary" />
      <div>
        <p class="text-sm text-muted-foreground"><span class="font-medium text-foreground">{{ proposer }}</span> wants to play</p>
        <p class="text-lg font-semibold">{{ game.label }}</p>
      </div>

      <!-- The tally: yeses out of the room, and the line it has to cross. -->
      <div class="space-y-1">
        <div class="h-2 overflow-hidden rounded-full bg-muted">
          <div class="h-full bg-primary transition-all" :style="{ width: `${(game.vote.yes / Math.max(1, game.vote.present)) * 100}%` }" />
        </div>
        <p class="text-xs text-muted-foreground">
          {{ game.vote.yes }} of {{ game.vote.present }} in — a majority starts it
        </p>
      </div>

      <div class="flex gap-2">
        <Button
          class="flex-1 gap-1.5"
          :variant="game.vote.mine === true ? 'default' : 'outline'"
          @click="emit('vote', true)"
        >
          <Vote class="h-4 w-4" /> Play
        </Button>
        <Button
          class="flex-1"
          :variant="game.vote.mine === false ? 'default' : 'outline'"
          @click="emit('vote', false)"
        >
          Not now
        </Button>
      </div>
      <button class="text-xs text-muted-foreground underline hover:text-foreground" @click="emit('dismiss')">
        Call it off
      </button>
    </div>
  </div>

  <!-- ENDED — who won, and everyone's true colours. -->
  <div
    v-else-if="game.status === 'ended' && state?.winner"
    class="pointer-events-auto absolute inset-0 z-30 grid place-items-center bg-black/60 p-4"
  >
    <div class="w-full max-w-sm space-y-4 rounded-xl border bg-background p-5 text-center shadow-xl">
      <p class="text-2xl font-bold" :class="state.winner === 'impostor' ? 'text-destructive' : 'text-primary'">
        {{ state.winner === 'impostor' ? 'Impostors win' : 'Crew win' }}
      </p>

      <div class="space-y-1 text-left">
        <div
          v-for="p in players"
          :key="p.id"
          class="flex items-center gap-2 rounded-md px-2 py-1 text-sm"
          :class="p.role === 'impostor' ? 'bg-destructive/10' : 'bg-muted/40'"
        >
          <span class="h-3 w-3 shrink-0 rounded-full" :style="{ backgroundColor: dot(p.id) }" />
          <span class="min-w-0 flex-1 truncate">{{ p.name }}<span v-if="p.isMe" class="text-muted-foreground"> (you)</span></span>
          <span class="shrink-0 text-xs font-medium" :class="p.role === 'impostor' ? 'text-destructive' : 'text-muted-foreground'">
            {{ p.role === 'impostor' ? 'Impostor' : 'Crew' }}
          </span>
        </div>
      </div>

      <Button class="w-full" @click="emit('dismiss')">Back to the room</Button>
    </div>
  </div>

  <!-- MEETING — the room drops everything and argues. -->
  <div
    v-else-if="state?.phase === 'meeting' && state.meeting"
    class="pointer-events-auto absolute inset-0 z-30 flex flex-col bg-black/70 p-4"
  >
    <div class="mx-auto flex w-full max-w-md flex-1 flex-col overflow-hidden">
      <div class="mb-3 text-center text-white">
        <Siren class="mx-auto h-7 w-7 text-red-400" />
        <p class="text-lg font-semibold">{{ state.meeting.reason === 'body' ? 'A body was reported' : 'Emergency meeting' }}</p>
        <p class="text-sm text-white/70">
          {{ hasVoted ? 'Waiting on the rest…' : alive ? 'Vote someone off, or skip.' : 'Ghosts don’t vote.' }}
          <span class="font-medium text-white">· {{ meetingLeft }}s</span>
        </p>
      </div>

      <div class="min-h-0 flex-1 space-y-1.5 overflow-y-auto">
        <button
          v-for="p in alivePlayers"
          :key="p.id"
          type="button"
          class="flex w-full items-center gap-2 rounded-lg border border-white/10 bg-white/5 px-3 py-2 text-left text-white transition-colors disabled:opacity-50"
          :class="myVote === p.id ? 'ring-2 ring-red-400' : 'hover:bg-white/10'"
          :disabled="!alive || hasVoted"
          @click="emit('game-vote', p.id)"
        >
          <span class="h-4 w-4 shrink-0 rounded-full" :style="{ backgroundColor: dot(p.id) }" />
          <span class="min-w-0 flex-1 truncate text-sm font-medium">{{ p.name }}<span v-if="p.isMe" class="text-white/60"> (you)</span></span>
          <!-- Who's already voted, not for whom. -->
          <span v-if="state.meeting.voted.includes(p.id)" class="text-[11px] text-white/60">voted</span>
        </button>
      </div>

      <button
        type="button"
        class="mt-2 flex items-center justify-center gap-2 rounded-lg border border-white/10 bg-white/5 px-3 py-2 text-sm text-white transition-colors disabled:opacity-50 hover:bg-white/10"
        :class="myVote === 'skip' ? 'ring-2 ring-white/60' : ''"
        :disabled="!alive || hasVoted"
        @click="emit('game-vote', 'skip')"
      >
        <SkipForward class="h-4 w-4" /> Skip vote
      </button>
    </div>
  </div>

  <!-- PLAYING — the HUD, out of the way at the bottom. -->
  <div v-else-if="game.status === 'running' && state" class="pointer-events-none absolute inset-x-0 bottom-0 z-20 p-3">
    <div class="mx-auto flex max-w-2xl flex-col items-center gap-2">
      <!-- The action buttons, big and thumb-reachable. -->
      <div class="pointer-events-auto flex flex-wrap items-center justify-center gap-2">
        <Button v-if="nearTaskId && alive" size="sm" class="gap-1.5" @click="emit('do-task')">
          <Wrench class="h-4 w-4" /> Do task
        </Button>

        <Button v-if="impostor && killTarget !== null" size="sm" variant="destructive" class="gap-1.5" @click="emit('kill')">
          <Ghost class="h-4 w-4" /> Kill
        </Button>
        <span
          v-else-if="impostor && alive && cooldownLeft > 0"
          class="pointer-events-none rounded-md bg-destructive/15 px-2.5 py-1.5 text-xs font-medium text-destructive"
        >Kill in {{ cooldownLeft }}s</span>

        <Button v-if="nearBody && alive" size="sm" variant="outline" class="gap-1.5" @click="emit('report')">
          <Siren class="h-4 w-4" /> Report
        </Button>
        <Button v-if="alive" size="sm" variant="outline" class="gap-1.5" @click="emit('meeting')">
          <Siren class="h-4 w-4" /> Meeting
        </Button>
      </div>

      <!-- The status strip: your role, and the crew's progress. -->
      <div class="pointer-events-auto flex w-full items-center gap-3 rounded-lg bg-background/90 px-3 py-1.5 text-xs shadow-sm backdrop-blur">
        <span class="flex items-center gap-1.5 font-semibold" :class="impostor ? 'text-destructive' : 'text-primary'">
          <component :is="impostor ? Ghost : Wrench" class="h-3.5 w-3.5" />
          {{ impostor ? 'Impostor' : 'Crewmate' }}
          <span v-if="!alive" class="font-normal text-muted-foreground">· ghost</span>
        </span>

        <div class="flex min-w-0 flex-1 items-center gap-1.5">
          <div class="h-1.5 min-w-0 flex-1 overflow-hidden rounded-full bg-muted">
            <div class="h-full bg-primary transition-all" :style="{ width: `${taskFraction * 100}%` }" />
          </div>
          <span class="shrink-0 text-muted-foreground">{{ state.task_done }}/{{ state.task_goal }} tasks</span>
        </div>

        <button class="shrink-0 text-muted-foreground hover:text-foreground" title="End the game" @click="emit('dismiss')">
          <X class="h-3.5 w-3.5" />
        </button>
      </div>

      <!-- The last thing that happened — an ejection, a report. -->
      <p v-if="state.log.length" class="pointer-events-none max-w-full truncate text-[11px] text-white drop-shadow">
        {{ state.log[state.log.length - 1] }}
      </p>
    </div>
  </div>
</template>
