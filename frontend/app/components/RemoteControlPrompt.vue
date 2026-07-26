<script setup lang="ts">
import { Check, MousePointer2, ShieldAlert, Unplug, X } from 'lucide-vue-next'

/**
 * Everything remote control has to say to you, wherever you happen to be in the app.
 *
 * Mounted at the app level for the same reason IncomingCall is: a call outlives the page you
 * started it on, so "someone wants to drive your screen" has to reach you in a channel you
 * wandered off to. Three things live here, and only one of them can be showing at once:
 *
 *   - the ask, on the sharer's side, as a modal prompt (it's a consent decision, so it is
 *     deliberately not a dismissible toast you can flick away by accident)
 *   - the standing banner while someone holds control, with the way out always visible
 *   - the controller's own "waiting…" / "they said no", so an ask never just vanishes
 *
 * Also where the state machine gets wired to the call — see `install`.
 */
const {
  requests,
  controller,
  approve,
  deny,
  revoke,
  controlling,
  awaiting,
  refusedBy,
  releaseControl,
  install,
} = useRemoteControl()

const { peers } = useVoice()

install()

function nameOf(id: number | null) {
  if (id === null) return 'Someone'
  return peers.value.find(p => p.id === id)?.name ?? 'Someone'
}

/** One prompt at a time — the oldest ask, so a queue drains in the order people asked. */
const asking = computed(() => requests.value[0] ?? null)

// "They said no" is the one thing here that should fade rather than wait to be dismissed:
// there's no decision attached to it.
watch(refusedBy, id => {
  if (id !== null) setTimeout(() => (refusedBy.value = null), 5000)
})
</script>

<template>
  <div>
    <!-- ------------------------------------------------------- The ask (sharer side) -->
    <div v-if="asking !== null" class="fixed inset-0 z-[70] grid place-items-center bg-black/50 p-4 backdrop-blur-sm">
      <div class="w-full max-w-sm rounded-xl border bg-card p-5 shadow-xl">
        <div class="flex items-start gap-3">
          <span class="grid h-10 w-10 flex-none place-items-center rounded-full bg-primary/10 text-primary">
            <MousePointer2 class="h-5 w-5" />
          </span>
          <div class="min-w-0">
            <h2 class="font-semibold leading-tight">{{ nameOf(asking) }} wants to control your screen</h2>
            <p class="mt-1 text-sm text-muted-foreground">
              They'll be able to move your mouse and type on this machine until you stop it.
            </p>
          </div>
        </div>

        <!-- Not a disclaimer to scroll past: people say yes to this prompt quickly, and the one
             thing worth knowing is that it's the whole machine, not just the window. -->
        <p class="mt-4 flex items-start gap-2 rounded-lg border border-destructive/30 bg-destructive/10 px-3 py-2 text-xs text-destructive">
          <ShieldAlert class="mt-px h-3.5 w-3.5 flex-none" />
          <span>This gives them the same access you have. Only allow it for people you trust.</span>
        </p>

        <div class="mt-4 flex gap-2">
          <button
            type="button"
            class="flex flex-1 items-center justify-center gap-1.5 rounded-lg border px-3 py-2 text-sm font-medium transition hover:bg-muted"
            @click="deny(asking)"
          >
            <X class="h-4 w-4" /> Deny
          </button>
          <button
            type="button"
            class="flex flex-1 items-center justify-center gap-1.5 rounded-lg bg-primary px-3 py-2 text-sm font-medium text-primary-foreground transition hover:opacity-90"
            @click="approve(asking)"
          >
            <Check class="h-4 w-4" /> Allow
          </button>
        </div>
      </div>
    </div>

    <!-- --------------------------------------------- Someone is driving (sharer side) -->
    <!-- Deliberately loud and deliberately un-dismissible. Forgetting that a session is still
         open is the failure mode that matters here, so the way to end it stays on screen. -->
    <div
      v-if="controller !== null"
      class="safe-inset fixed inset-x-0 top-0 z-[60] flex items-center justify-center gap-3 bg-primary px-4 py-2 text-sm text-primary-foreground shadow-lg"
    >
      <MousePointer2 class="h-4 w-4 flex-none animate-pulse" />
      <span class="min-w-0 truncate"><b>{{ nameOf(controller) }}</b> is controlling your screen</span>
      <button
        type="button"
        class="flex flex-none items-center gap-1.5 rounded-md bg-primary-foreground/15 px-2.5 py-1 text-xs font-medium transition hover:bg-primary-foreground/25"
        @click="revoke"
      >
        <Unplug class="h-3.5 w-3.5" /> Stop
      </button>
    </div>

    <!-- ------------------------------------------------- Waiting / refused (controller) -->
    <div
      v-if="(awaiting !== null || refusedBy !== null) && controlling === null"
      class="safe-inset pointer-events-none fixed inset-x-0 bottom-4 z-[60] flex justify-center px-4"
    >
      <p class="pointer-events-auto flex items-center gap-2 rounded-full border bg-card px-3.5 py-2 text-sm shadow-lg">
        <template v-if="awaiting !== null">
          <span class="h-2 w-2 flex-none animate-pulse rounded-full bg-primary" />
          Asking {{ nameOf(awaiting) }} for control…
          <button class="font-medium text-muted-foreground hover:text-foreground" @click="releaseControl">Cancel</button>
        </template>
        <template v-else>
          <X class="h-4 w-4 flex-none text-destructive" />
          {{ nameOf(refusedBy) }} didn't allow control.
        </template>
      </p>
    </div>
  </div>
</template>
