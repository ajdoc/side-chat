<script setup lang="ts">
import { Loader2, Shield, Swords, X, Zap } from 'lucide-vue-next'
import type { PetBattleState, SpaceGamePayload } from '~/types'
import { drawPetPortrait } from '~/lib/spacePets'
import { Button } from '~/components/ui/button'

/**
 * A pet battle, over the room: the challenge someone answers, then the turn-by-turn duel.
 *
 * The battle twin of {@link SpaceGamePanel}. Both are chosen by the stage from `game.type`, and
 * both take the same shape — a proposal, the play, an ending — but a battle's proposal is a duel
 * invitation (accept or decline) rather than a room-wide vote, and its play is two fighters taking
 * turns rather than a whole room walking about. It computes nothing about the room; the stage
 * feeds it the game and the names, and it draws the fight.
 */
const props = defineProps<{
  game: SpaceGamePayload
  /** id → display name, for the challenger and the challenged before the fight has players. */
  names: Record<number, string>
  myId: number | null
}>()

const emit = defineEmits<{
  accept: []
  decline: []
  dismiss: []
  move: [string]
  forfeit: []
}>()

const state = computed(() => (props.game.type === 'petbattle' ? props.game.state as PetBattleState | null : null))

const nameOf = (id: number | null) => (id == null ? 'Someone' : (props.names[id] ?? `Player ${id}`))

// --- the challenge (voting) ---

const iAmChallenged = computed(() => props.game.opponent === props.myId)
const iAmChallenger = computed(() => props.game.created_by === props.myId)

// --- the fight (running) ---

const fighters = computed(() => state.value?.players ?? [])
const myTurn = computed(() => !!state.value && state.value.turn === state.value.you && state.value.winner === null)
const amFighting = computed(() => !!state.value && state.value.you !== null)
const winner = computed(() => state.value?.winner ?? null)

const ELEMENT: Record<string, { label: string, class: string }> = {
  grass: { label: 'Grass', class: 'bg-green-500/15 text-green-600 dark:text-green-400' },
  fire: { label: 'Fire', class: 'bg-orange-500/15 text-orange-600 dark:text-orange-400' },
  water: { label: 'Water', class: 'bg-blue-500/15 text-blue-600 dark:text-blue-400' },
}

const MOVES = [
  { id: 'tackle', label: 'Tackle', hint: 'A plain hit', icon: Swords },
  { id: 'strike', label: 'Strike', hint: 'Your element — the type triangle applies', icon: Zap },
  { id: 'special', label: 'Special', hint: 'Big, but can miss', icon: Zap },
  { id: 'guard', label: 'Guard', hint: 'Heal a little, blunt the next hit', icon: Shield },
]

const busy = ref(false)
async function play(move: string) {
  if (busy.value || !myTurn.value) return
  busy.value = true
  emit('move', move)
  // A short lockout so a double-tap doesn't fire two moves before the state comes back.
  setTimeout(() => (busy.value = false), 400)
}

// --- pet portraits ---

const portraits = ref<Record<number, HTMLCanvasElement | null>>({})

function paintPortraits() {
  for (const f of fighters.value) {
    const canvas = portraits.value[f.id]
    if (!canvas) continue
    const dpr = window.devicePixelRatio || 1
    canvas.width = 72 * dpr
    canvas.height = 72 * dpr
    const ctx = canvas.getContext('2d')
    if (!ctx) continue
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0)
    ctx.clearRect(0, 0, 72, 72)
    drawPetPortrait(ctx, f.pet, 36, 58, 48)
  }
}

watch(fighters, () => nextTick(paintPortraits), { deep: true })
onMounted(() => nextTick(paintPortraits))
</script>

<template>
  <div class="pointer-events-auto absolute inset-0 z-30 grid place-items-center bg-black/50 p-4">
    <!-- THE CHALLENGE — before anyone's accepted. -->
    <div v-if="game.status === 'voting'" class="w-full max-w-sm space-y-4 rounded-xl border bg-background p-5 text-center shadow-xl">
      <Swords class="mx-auto h-7 w-7 text-primary" />

      <template v-if="iAmChallenged">
        <p class="text-sm">
          <span class="font-medium">{{ nameOf(game.created_by) }}</span> challenges you to a pet battle!
        </p>
        <div class="flex gap-2">
          <Button class="flex-1 gap-1.5" @click="emit('accept')"><Swords class="h-4 w-4" /> Accept</Button>
          <Button variant="outline" class="flex-1" @click="emit('decline')">Decline</Button>
        </div>
      </template>

      <template v-else-if="iAmChallenger">
        <p class="text-sm text-muted-foreground">
          Waiting for <span class="font-medium text-foreground">{{ nameOf(game.opponent) }}</span> to accept…
        </p>
        <Loader2 class="mx-auto h-5 w-5 animate-spin text-muted-foreground" />
        <button class="text-xs text-muted-foreground underline hover:text-foreground" @click="emit('dismiss')">
          Call it off
        </button>
      </template>

      <template v-else>
        <p class="text-sm text-muted-foreground">
          <span class="font-medium text-foreground">{{ nameOf(game.created_by) }}</span> challenged
          <span class="font-medium text-foreground">{{ nameOf(game.opponent) }}</span> to a battle.
        </p>
      </template>
    </div>

    <!-- THE FIGHT. -->
    <div v-else-if="state" class="w-full max-w-lg space-y-3 rounded-xl border bg-background p-5 shadow-xl">
      <div class="flex items-center justify-between">
        <p class="text-sm font-semibold">Pet Battle</p>
        <button class="rounded p-1 text-muted-foreground hover:text-foreground" title="End the battle" @click="emit('dismiss')">
          <X class="h-4 w-4" />
        </button>
      </div>

      <!-- The two fighters, facing off. -->
      <div class="flex items-stretch justify-between gap-3">
        <div
          v-for="(f, i) in fighters"
          :key="f.id"
          class="flex flex-1 flex-col items-center gap-1 rounded-lg border p-2"
          :class="[state.turn === f.id && !winner ? 'border-primary ring-1 ring-primary' : '', i === 1 ? 'order-3' : '']"
        >
          <canvas
            :ref="el => (portraits[f.id] = el as HTMLCanvasElement)"
            class="h-16 w-16"
            :class="i === 1 ? '-scale-x-100' : ''"
            style="image-rendering: pixelated"
          />
          <p class="max-w-full truncate text-sm font-medium">
            {{ f.name }}<span v-if="f.id === state.you" class="text-muted-foreground"> (you)</span>
          </p>
          <span class="rounded-full px-2 py-0.5 text-[10px] font-medium" :class="ELEMENT[f.element]?.class">
            {{ ELEMENT[f.element]?.label }}
          </span>

          <div class="mt-1 w-full">
            <div class="h-2 overflow-hidden rounded-full bg-muted">
              <div
                class="h-full rounded-full transition-all"
                :class="f.hp > f.max_hp * 0.3 ? 'bg-green-500' : 'bg-destructive'"
                :style="{ width: `${(f.hp / f.max_hp) * 100}%` }"
              />
            </div>
            <p class="mt-0.5 flex items-center justify-center gap-1 text-[11px] text-muted-foreground">
              {{ Math.max(0, f.hp) }}/{{ f.max_hp }}
              <Shield v-if="f.guarding" class="h-3 w-3 text-blue-500" />
            </p>
          </div>
        </div>

        <div class="flex items-center text-xs font-bold text-muted-foreground order-2">VS</div>
      </div>

      <!-- The last blow-by-blow. -->
      <p class="min-h-[2.5rem] rounded-md bg-muted/50 px-3 py-1.5 text-center text-xs text-muted-foreground">
        {{ state.log[state.log.length - 1] }}
      </p>

      <!-- Your moves, on your turn. -->
      <div v-if="winner === null && amFighting" class="space-y-2">
        <p class="text-center text-xs" :class="myTurn ? 'font-medium text-foreground' : 'text-muted-foreground'">
          {{ myTurn ? 'Your move' : `${nameOf(state.turn)} is choosing…` }}
        </p>
        <div class="grid grid-cols-2 gap-2">
          <Button
            v-for="m in MOVES"
            :key="m.id"
            variant="outline"
            size="sm"
            class="justify-start gap-1.5"
            :disabled="!myTurn || busy"
            :title="m.hint"
            @click="play(m.id)"
          >
            <component :is="m.icon" class="h-4 w-4 shrink-0" /> {{ m.label }}
          </Button>
        </div>
        <button class="w-full text-center text-[11px] text-muted-foreground underline hover:text-destructive" @click="emit('forfeit')">
          Forfeit
        </button>
      </div>

      <!-- A spectator just watches. -->
      <p v-else-if="winner === null" class="text-center text-xs text-muted-foreground">Watching the battle…</p>

      <!-- The result. -->
      <div v-else class="space-y-2 text-center">
        <p class="text-lg font-bold text-primary">{{ nameOf(winner) }} wins!</p>
        <Button class="w-full" @click="emit('dismiss')">Done</Button>
      </div>
    </div>
  </div>
</template>
