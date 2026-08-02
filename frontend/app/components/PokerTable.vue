<script setup lang="ts">
import { Bot, Coins, LogOut, Play, RotateCcw, Spade, UserMinus, Users } from 'lucide-vue-next'
import type { PokerPlayer, PokerState, Widget } from '~/types'
import { Button } from '~/components/ui/button'

/**
 * Side Poker — the table card for no-limit Texas Hold'em.
 *
 * Deliberately the thinnest card in the catalogue. The racer and the shooter run their game
 * on the client and report outcomes; poker can't, because the deck is the game — so this
 * component decides *nothing*. It draws the state the server sent, posts button presses as
 * widget actions, and lets `WidgetUpdated` bring the next state back. Even "can I check?" is
 * only a hint here: the server refuses an illegal action regardless, and says why in the
 * ephemeral reply we surface.
 *
 * What it does own is the one piece of information that isn't shared: hole cards. Every
 * player's `cards` array arrives from the server as `null`s unless they're yours, or unless
 * a showdown has turned them face up — so "face down" here is a real absence of data, not a
 * CSS trick, and there is nothing in this client to peek at.
 */
const props = defineProps<{ widget: Widget }>()

const { action } = useWidgets()
const { user } = useAuth()

const state = computed(() => props.widget.state as PokerState)
const players = computed(() => state.value.players ?? {})
const myId = computed(() => (user.value ? String(user.value.id) : null))
const me = computed<PokerPlayer | null>(() => (myId.value ? players.value[myId.value] ?? null : null))
const seated = computed(() => me.value !== null)
const inHand = computed(() => state.value.status === 'betting')
const isMyTurn = computed(() => user.value != null && state.value.turnId === user.value.id)

/** Seating order, rotated so you're always at the bottom of your own list. */
const seats = computed(() => {
  const ids = (state.value.seats ?? []).map(String).filter(id => players.value[id])
  const at = myId.value ? ids.indexOf(myId.value) : -1
  const rotated = at < 0 ? ids : [...ids.slice(at), ...ids.slice(0, at)]
  return rotated.map(id => ({ id, ...(players.value[id] as PokerPlayer) }))
})

/** The pot as a player thinks of it: swept chips plus everything out on the felt right now. */
const pot = computed(() => (state.value.pot ?? 0) + seats.value.reduce((n, p) => n + (p.bet ?? 0), 0))
const owed = computed(() => Math.max(0, (state.value.bet ?? 0) - (me.value?.bet ?? 0)))
const canCheck = computed(() => owed.value === 0)
const minRaiseTo = computed(() => (state.value.bet ?? 0) + (state.value.minRaise ?? 20))
const maxRaiseTo = computed(() => (me.value?.bet ?? 0) + (me.value?.chips ?? 0))

const STAGE_LABEL: Record<string, string> = { preflop: 'Pre-flop', flop: 'Flop', turn: 'Turn', river: 'River' }

// The raise field follows the table: every time the bet moves, the box resets to the smallest
// legal raise, which is the number people actually want most often.
const raiseTo = ref(minRaiseTo.value)
watch(minRaiseTo, to => { raiseTo.value = Math.min(Math.max(to, raiseTo.value), maxRaiseTo.value) })
watch(isMyTurn, mine => { if (mine) raiseTo.value = Math.min(minRaiseTo.value, maxRaiseTo.value) })

// An illegal action comes back as an actor-only note ("it's not your turn"); the buttons have
// no chat line of their own, so the card shows it and lets it fade.
const note = ref<string | null>(null)
let noteTimer: ReturnType<typeof setTimeout> | null = null
async function send(name: string, payload: Record<string, unknown> = {}) {
  const reply = await action(props.widget.id, name, payload)
  if (noteTimer) clearTimeout(noteTimer)
  note.value = reply
  if (reply) noteTimer = setTimeout(() => { note.value = null }, 4000)
}
onBeforeUnmount(() => { if (noteTimer) clearTimeout(noteTimer) })

const raise = () => send('raise', { amount: Math.round(raiseTo.value) })

// Bots are seats, not accounts — they fill a table so one person can play. Their ids are
// negative server-side, which is also why their hole cards are hidden from *everyone*: the
// same "not you" rule that hides another player's covers them, with nothing special added.
const botCount = computed(() => seats.value.filter(p => p.bot).length)
const canDeal = computed(() => seats.value.length >= 2)

/** `"Ts"` → suit-marked pip and colour. A `null` card is one we're not entitled to see. */
const SUITS: Record<string, { pip: string, red: boolean }> = {
  s: { pip: '♠', red: false }, h: { pip: '♥', red: true },
  d: { pip: '♦', red: true }, c: { pip: '♣', red: false },
}
const rankOf = (card: string) => (card[0] === 'T' ? '10' : card[0])
const suitOf = (card: string) => SUITS[card[1]!] ?? SUITS.s!
</script>

<template>
  <div class="mt-1.5 w-full max-w-md overflow-hidden rounded-xl border bg-gradient-to-b from-muted/50 to-muted/20 shadow-sm">
    <!-- Header -->
    <div class="flex items-center gap-1.5 border-b bg-background/40 px-3 py-1.5 text-xs font-semibold uppercase tracking-wide text-primary">
      <Spade class="h-3.5 w-3.5" /> Side Poker
      <span v-if="state.handNo" class="rounded-full bg-primary/10 px-1.5 py-px text-[10px] normal-case">Hand #{{ state.handNo }}</span>
      <span v-if="inHand" class="ml-auto text-[10px] normal-case text-muted-foreground">{{ STAGE_LABEL[state.stage] }}</span>
      <span v-else-if="state.status === 'showdown'" class="ml-auto text-[10px] normal-case text-muted-foreground">Showdown</span>
    </div>

    <div class="p-3">
      <!-- The felt: community cards and the pot -->
      <div class="rounded-lg bg-emerald-900/80 p-3 text-center shadow-inner ring-1 ring-emerald-950/40">
        <div class="flex items-center justify-center gap-1">
          <template v-if="state.board?.length">
            <span
              v-for="(card, i) in state.board"
              :key="i"
              class="flex h-11 w-8 flex-col items-center justify-center rounded bg-white text-sm font-bold leading-none shadow"
              :class="suitOf(card).red ? 'text-red-600' : 'text-neutral-900'"
            >
              {{ rankOf(card) }}<span class="text-[11px]">{{ suitOf(card).pip }}</span>
            </span>
          </template>
          <span v-else class="text-xs text-emerald-200/70">{{ inHand ? 'Waiting on the flop' : 'No cards out' }}</span>
        </div>
        <p class="mt-2 flex items-center justify-center gap-1 text-xs font-semibold text-emerald-100">
          <Coins class="h-3.5 w-3.5" /> Pot {{ pot }}
          <span v-if="inHand && state.bet" class="font-normal text-emerald-200/70">· to call {{ state.bet }}</span>
        </p>
      </div>

      <!-- Seats -->
      <ul v-if="seats.length" class="mt-3 space-y-0.5">
        <li
          v-for="p in seats"
          :key="p.id"
          class="flex items-center gap-2 rounded px-1.5 py-1 text-xs transition"
          :class="[
            p.id === myId && 'bg-primary/10',
            state.turnId === Number(p.id) && 'ring-1 ring-primary/50',
            p.folded && 'opacity-45',
          ]"
        >
          <span class="w-4 flex-none text-center text-[10px]" :title="state.buttonId === Number(p.id) ? 'Dealer button' : ''">
            {{ state.buttonId === Number(p.id) ? '🔘' : '' }}
          </span>
          <span class="min-w-0 flex-1 truncate" :class="p.id === myId ? 'font-semibold' : 'text-foreground/80'">
            {{ p.name }}<span v-if="p.id === myId" class="text-muted-foreground"> (you)</span>
            <Bot v-if="p.bot" class="mb-0.5 ml-1 inline h-3 w-3 text-muted-foreground" title="House bot" />
            <span v-if="p.hand && state.status === 'showdown' && !p.folded" class="ml-1 text-[10px] text-muted-foreground">{{ p.hand }}</span>
          </span>

          <!-- Their cards: real ones only if they're ours or face up at a showdown. -->
          <span class="flex flex-none gap-0.5">
            <span
              v-for="(card, i) in p.cards"
              :key="i"
              class="flex h-7 w-5 flex-col items-center justify-center rounded text-[10px] font-bold leading-none shadow-sm"
              :class="card
                ? (suitOf(card).red ? 'bg-white text-red-600' : 'bg-white text-neutral-900')
                : 'bg-gradient-to-br from-sky-700 to-sky-900'"
            >
              <template v-if="card">{{ rankOf(card) }}<span class="text-[9px]">{{ suitOf(card).pip }}</span></template>
            </span>
          </span>

          <span v-if="p.bet" class="w-10 flex-none text-right text-[10px] tabular-nums text-amber-500">+{{ p.bet }}</span>
          <span v-else-if="p.allIn" class="w-10 flex-none text-right text-[10px] font-semibold text-amber-500">ALL IN</span>
          <span v-else class="w-10 flex-none" />
          <span class="w-12 flex-none text-right font-medium tabular-nums text-primary">{{ p.chips }}</span>
        </li>
      </ul>
      <p v-else class="mt-3 text-center text-xs text-muted-foreground">
        <Users class="mr-1 inline h-3 w-3" /> Nobody's sitting yet.
      </p>

      <!-- Controls -->
      <div class="mt-3 border-t pt-2">
        <div v-if="!seated" class="text-center">
          <Button size="sm" class="gap-1.5" @click="send('join')"><Spade class="h-3.5 w-3.5" /> Sit down</Button>
          <p class="mt-1.5 text-[10px] text-muted-foreground">1000 chips, blinds 10/20 · or type <code class="rounded bg-muted px-1">h!poker</code></p>
        </div>

        <!-- Your turn: the only place a decision is offered. -->
        <div v-else-if="inHand && isMyTurn" class="space-y-2">
          <div class="flex flex-wrap items-center gap-1.5">
            <Button size="sm" variant="outline" class="gap-1" @click="send('fold')">Fold</Button>
            <Button v-if="canCheck" size="sm" class="gap-1" @click="send('check')">Check</Button>
            <Button v-else size="sm" class="gap-1" @click="send('call')">Call {{ Math.min(owed, me?.chips ?? 0) }}</Button>
            <Button size="sm" variant="secondary" class="ml-auto gap-1" @click="send('allin')">All in</Button>
          </div>
          <div v-if="maxRaiseTo > minRaiseTo" class="flex items-center gap-2">
            <input
              v-model.number="raiseTo"
              type="range"
              class="h-1.5 min-w-0 flex-1 accent-primary"
              :min="minRaiseTo"
              :max="maxRaiseTo"
              step="10"
            >
            <Button size="sm" class="flex-none gap-1 tabular-nums" @click="raise">
              {{ state.bet ? 'Raise to' : 'Bet' }} {{ Math.round(raiseTo) }}
            </Button>
          </div>
        </div>

        <div v-else-if="inHand" class="text-center text-xs text-muted-foreground">
          <span v-if="me?.folded">You folded this hand.</span>
          <span v-else-if="me?.allIn">You're all in — sit back.</span>
          <span v-else-if="state.turnId">Waiting on {{ players[String(state.turnId)]?.name ?? 'the next player' }}…</span>
          <span v-else>Dealing…</span>
        </div>

        <div v-else class="flex flex-wrap items-center justify-center gap-1.5">
          <Button size="sm" :disabled="!canDeal" class="gap-1.5" @click="send('deal')">
            <Play class="h-3.5 w-3.5" /> {{ state.handNo ? 'Next hand' : 'Deal' }}
          </Button>
          <Button size="sm" variant="outline" class="gap-1.5" title="Sit a house bot down — enough to play on your own" @click="send('addbot')">
            <Bot class="h-3.5 w-3.5" /> Add a bot
          </Button>
          <Button v-if="botCount" size="sm" variant="ghost" class="gap-1.5 text-muted-foreground" title="Send the last bot home" @click="send('removebot')">
            <UserMinus class="h-3.5 w-3.5" />
          </Button>
          <Button size="sm" variant="ghost" class="gap-1.5 text-muted-foreground" title="Fresh table, everyone back to 1000" @click="send('reset')">
            <RotateCcw class="h-3.5 w-3.5" />
          </Button>
          <Button size="sm" variant="ghost" class="gap-1.5 text-muted-foreground" title="Stand up" @click="send('leave')">
            <LogOut class="h-3.5 w-3.5" />
          </Button>
        </div>

        <p v-if="seated && !inHand && !canDeal" class="mt-1.5 text-center text-[10px] text-muted-foreground">
          A hand needs two at the table — add a bot, or wait for someone to sit down.
        </p>
        <p v-if="note" class="mt-2 rounded bg-muted px-2 py-1 text-[11px] text-muted-foreground">{{ note }}</p>
      </div>

      <div v-if="state.log?.length" class="mt-2 space-y-px rounded-lg bg-background/50 p-2 text-[11px] leading-snug text-muted-foreground">
        <p v-for="(line, i) in state.log" :key="i" class="truncate" :class="i === state.log.length - 1 && 'text-foreground'">{{ line }}</p>
      </div>
    </div>
  </div>
</template>
