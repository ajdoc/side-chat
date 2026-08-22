<script setup lang="ts">
import { Swords, Loader2, Trophy, X } from 'lucide-vue-next'
import type { Channel } from '~/types'

/**
 * The MOBA, as the body of an app channel.
 *
 * ## Three screens and a canvas
 *
 * **lobby** (pick a hero and a size) → **queue** (waiting, with who else is in) → **match** (a
 * canvas the wasm bundle owns). Vue draws the first two and then gets out of the way: once the
 * game starts, this component's only job is to keep the `<canvas>` mounted and hand the wasm
 * module a ticket.
 *
 * That hand-off is deliberate and it is the whole architecture in one line. Nothing about the
 * running game — positions, health, cooldowns — passes through Vue's reactivity, because a
 * 60fps render loop is the one place reactivity must not be. See MOBA.md.
 */
const props = defineProps<{
  channel: Channel
  canEdit: boolean
}>()

const {
  heroes, me, match, queued, ready, loading, error,
  hero, teamSize,
  loadCatalogue, refresh, join, leave, leaveMatch, startPolling,
} = useMoba()

const canvas = ref<HTMLCanvasElement | null>(null)

const inMatch = computed(
  () => !!match.value && match.value.status !== 'finished' && match.value.status !== 'abandoned',
)

/**
 * Match the canvas's backing store to the size it is displayed at.
 *
 * Without this the canvas keeps its default 300×150 buffer and the browser stretches it to fill
 * the panel — everything is drawn at roughly four times the intended size and blurred by the
 * upscale. It also breaks input, because the camera converts clicks against `canvas.width`, not
 * against the CSS box, so every order lands somewhere other than where it was aimed.
 *
 * Deliberately not scaled by `devicePixelRatio`: on a high-density screen that triples the pixel
 * count for a renderer that is currently coloured discs, and a smooth frame rate is worth more
 * than crisp edges until there is art that deserves them.
 */
function fitCanvas() {
  const el = canvas.value
  if (!el) return
  const rect = el.getBoundingClientRect()
  const width = Math.max(1, Math.floor(rect.width))
  const height = Math.max(1, Math.floor(rect.height))
  if (el.width !== width) el.width = width
  if (el.height !== height) el.height = height
}

let observer: ResizeObserver | null = null

/**
 * A ResizeObserver rather than a window listener: the panel changes size when the sidebar
 * collapses or the chat pane folds away, neither of which resizes the window.
 */
watch(canvas, (el) => {
  observer?.disconnect()
  if (!el) return
  fitCanvas()
  observer = new ResizeObserver(fitCanvas)
  observer.observe(el)
})

onBeforeUnmount(() => observer?.disconnect())

/**
 * The match the wasm module was last started for.
 *
 * An id rather than a boolean, and that is the whole fix for the second-match bug: a flag set on
 * launch and never cleared means the *next* match sees "already launched" and never starts, so
 * the canvas sits there showing nothing while a perfectly good match runs on the server.
 * Comparing ids makes a new match start and the same match not restart, which is what was
 * actually meant.
 */
const launchedFor = ref<number | null>(null)
const launchError = ref<string | null>(null)

onMounted(async () => {
  await Promise.all([loadCatalogue(), refresh()])
  startPolling()
})

/**
 * Start the game once a seat and an address exist.
 *
 * Watched rather than called from `join`, because the match may already be in progress when the
 * component mounts — a refreshed tab mid-game has to land straight in it, not back in the lobby.
 */
watch([ready, () => match.value?.id], async ([isReady]) => {
  const id = match.value?.id ?? null
  if (!isReady || id === null || launchedFor.value === id) return
  launchedFor.value = id

  try {
    // Loaded on demand, not at page load. The bundle is ~270KB of wasm and nobody browsing a
    // chat app should pay for it until they open a match.
    const { default: init, MobaGame } = await import('~/lib/moba/moba_client.js')
    await init()
    await nextTick()
    // Size it before the game reads it: the wasm module captures the canvas dimensions when it
    // builds its camera, so a canvas still at its default 300×150 would start with a camera
    // that thinks the viewport is tiny.
    fitCanvas()
    new MobaGame('moba-canvas', match.value!.server_address!, match.value!.ticket!).start()
  }
  catch (e: any) {
    // Cleared so a retry — or the next match — can try again rather than being locked out by
    // one failed import.
    launchedFor.value = null
    launchError.value = e?.message ?? 'Could not start the game client.'
  }
}, { immediate: true })

/**
 * Forget the finished match so the next one starts cleanly.
 *
 * Without this the stale canvas stays mounted with a closed socket behind it, which is what
 * "the map is not rendering" looked like: a live-looking match panel drawing nothing.
 */
watch(inMatch, (playing) => {
  if (!playing) {
    launchedFor.value = null
    launchError.value = null
  }
})

const confirmLeave = ref(false)
const finished = computed(() => match.value?.status === 'finished' ? match.value : null)

const byTeam = computed(() => ({
  blue: match.value?.players.filter(p => p.team === 0) ?? [],
  red: match.value?.players.filter(p => p.team === 1) ?? [],
}))

const heroName = (id: string) => heroes.value.find(h => h.id === id)?.name ?? id
</script>

<template>
  <div class="relative flex min-h-0 flex-1 flex-col border-b bg-background">
    <!-- ── The match ─────────────────────────────────────────────────────────────────────
         Kept mounted for as long as a match is live. The canvas element must exist before the
         wasm module looks it up by id, which is why the watcher above awaits a tick first. -->
    <div v-if="inMatch && ready" class="relative min-h-0 flex-1">
      <canvas id="moba-canvas" ref="canvas" class="block h-full w-full" />

      <!--
        The way out. A MOBA has no natural exit before someone wins, so without this a match
        that never started properly leaves everyone in it stuck: the API counts an unfinished
        match as a commitment and nothing else clears one.
      -->
      <Button
        variant="secondary"
        size="sm"
        class="absolute right-3 top-3 opacity-70 hover:opacity-100"
        @click="confirmLeave = true"
      >
        <X class="mr-1.5 size-4" /> Leave
      </Button>

      <ConfirmDialog
        v-model:open="confirmLeave"
        title="Leave the match?"
        description="This ends the match for everyone in it — a MOBA cannot be played a player short."
        confirm-label="Leave match"
        @confirm="leaveMatch"
      />
      <p
        v-if="launchError"
        class="absolute inset-x-0 top-4 mx-auto w-fit rounded-md bg-destructive px-3 py-1.5 text-sm text-destructive-foreground"
      >
        {{ launchError }}
      </p>
    </div>

    <!-- ── The lobby and the queue ───────────────────────────────────────────────────── -->
    <div v-else class="min-h-0 flex-1 overflow-y-auto p-6">
      <div class="mx-auto flex max-w-3xl flex-col gap-6">
        <header class="flex items-center gap-3">
          <Swords class="size-5 text-muted-foreground" />
          <h2 class="text-lg font-semibold">MOBA</h2>
          <span v-if="me" class="ml-auto text-sm text-muted-foreground">
            {{ me.mmr }} MMR · {{ me.wins }}/{{ me.games }}
          </span>
        </header>

        <!-- Results of the last match, if there is one to show. -->
        <section v-if="finished" class="rounded-lg border p-4">
          <div class="mb-3 flex items-center gap-2 font-medium">
            <Trophy class="size-4 text-amber-500" />
            {{ finished.winning_team === finished.you?.team ? 'Victory' : 'Defeat' }}
          </div>
          <table class="w-full text-sm">
            <tbody>
              <tr v-for="player in finished.players" :key="player.slot" class="border-t">
                <td class="py-1.5" :class="player.team === 0 ? 'text-blue-500' : 'text-rose-500'">
                  {{ player.name ?? 'Unknown' }}
                </td>
                <td class="py-1.5 text-muted-foreground">{{ heroName(player.hero) }}</td>
                <td class="py-1.5 tabular-nums">
                  {{ player.kills }}/{{ player.deaths }}/{{ player.assists }}
                </td>
                <td
                  class="py-1.5 text-right tabular-nums"
                  :class="(player.mmr_change ?? 0) >= 0 ? 'text-emerald-500' : 'text-rose-500'"
                >
                  {{ (player.mmr_change ?? 0) >= 0 ? '+' : '' }}{{ player.mmr_change ?? 0 }}
                </td>
              </tr>
            </tbody>
          </table>
        </section>

        <!-- Waiting. Shows the roster as it fills, so the wait has something in it. -->
        <section v-if="queued || (match && !ready)" class="rounded-lg border p-6 text-center">
          <Loader2 class="mx-auto mb-3 size-6 animate-spin text-muted-foreground" />
          <p class="font-medium">
            {{ match ? 'Match found — starting…' : `Searching for a ${teamSize}v${teamSize}…` }}
          </p>
          <p class="mt-1 text-sm text-muted-foreground">
            The search widens the longer you wait.
          </p>

          <div v-if="match" class="mt-4 flex justify-center gap-8 text-sm">
            <ul class="space-y-1 text-blue-500">
              <li v-for="p in byTeam.blue" :key="p.slot">{{ p.name }} · {{ heroName(p.hero) }}</li>
            </ul>
            <ul class="space-y-1 text-rose-500">
              <li v-for="p in byTeam.red" :key="p.slot">{{ p.name }} · {{ heroName(p.hero) }}</li>
            </ul>
          </div>

          <Button v-if="!match" variant="ghost" class="mt-4" @click="leave">
            <X class="mr-1.5 size-4" /> Cancel
          </Button>
        </section>

        <!-- Picking. -->
        <section v-else class="flex flex-col gap-5">
          <div>
            <h3 class="mb-2 text-sm font-medium text-muted-foreground">Mode</h3>
            <div class="flex flex-wrap gap-2">
              <!-- 1v1 first, and not by accident: it is the only size one person can test, and
                   the sim scales waves and structures to it so it plays as a real game. -->
              <Button
                v-for="size in [1, 2, 3, 4, 5]"
                :key="size"
                :variant="teamSize === size ? 'default' : 'outline'"
                size="sm"
                @click="teamSize = size"
              >
                {{ size }}v{{ size }}
              </Button>
            </div>
          </div>

          <div>
            <h3 class="mb-2 text-sm font-medium text-muted-foreground">Hero</h3>
            <div class="grid grid-cols-2 gap-2 sm:grid-cols-3">
              <button
                v-for="h in heroes"
                :key="h.id"
                type="button"
                class="rounded-lg border p-3 text-left transition hover:bg-accent"
                :class="hero === h.id ? 'border-primary ring-1 ring-primary' : ''"
                @click="hero = h.id"
              >
                <div class="font-medium">{{ h.name }}</div>
                <div class="text-xs text-muted-foreground">{{ h.role }}</div>
              </button>
            </div>
          </div>

          <p v-if="error" class="text-sm text-destructive">{{ error }}</p>

          <Button :disabled="loading" class="self-start" @click="join(props.channel.id)">
            <Swords class="mr-1.5 size-4" /> Find match
          </Button>
        </section>
      </div>
    </div>
  </div>
</template>
