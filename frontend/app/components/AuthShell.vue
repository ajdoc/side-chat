<script setup lang="ts">
import { Gamepad2, Radio, Sparkles } from 'lucide-vue-next'

/**
 * The shell both auth pages sit in — a two-column splash: the brand on the left, the form
 * on the right. Login and register are a matched pair, so the chrome lives here once and
 * each page brings only its title, its fields and its footer link.
 *
 * The brand panel is deliberately dark in both themes (it's a poster, not a surface), but it
 * is still built from the accent registry in tailwind.css rather than fixed hexes — so
 * switching accent re-skins the splash exactly like it re-skins the app. Below `lg` the panel
 * would eat the fold on a phone, so it collapses to a compact logo lockup above the card.
 */
defineProps<{
  /** Big line above the form — "Welcome back", "Create your account". */
  title: string
  /** Supporting line under it. */
  subtitle: string
}>()

// The pitch, in the app's three registers at once: it's a game room, it's a hangout, and it's
// somewhere real work lands. Kept to three — a fourth turns the panel into a landing page.
const PITCH = [
  { icon: Gamepad2, label: 'Play', text: 'Walkable spaces, party games and a shared canvas.' },
  { icon: Radio, label: 'Talk', text: 'Proximity voice, video and screen sharing that just works.' },
  { icon: Sparkles, label: 'Ship', text: 'Boards, notes and docs beside every conversation.' },
]
</script>

<template>
  <div class="grid min-h-screen lg:grid-cols-[1.05fr_1fr]">
    <!-- ---------------------------------------------------------------- Brand -->
    <aside class="brand-panel relative hidden overflow-hidden lg:flex lg:flex-col lg:justify-between lg:p-12">
      <div class="brand-aurora" aria-hidden="true" />
      <div class="brand-grid" aria-hidden="true" />

      <!-- Logo lockup, framed in HUD corner brackets. -->
      <div class="relative">
        <div class="relative inline-flex items-center gap-4">
          <span class="brand-brackets relative grid h-20 w-20 place-items-center rounded-2xl">
            <img src="/brand/logo.png" alt="" class="brand-mark h-14 w-14 select-none" draggable="false">
          </span>
          <span>
            <span class="block text-3xl font-semibold tracking-tight text-white">Side Chat</span>
            <span class="brand-eyebrow mt-1 block">Hang out · Team up · Get it done</span>
          </span>
        </div>
      </div>

      <!-- The pitch. -->
      <div class="relative space-y-7">
        <h2 class="max-w-md text-[2.6rem] font-semibold leading-[1.1] tracking-tight text-white">
          Your crew's
          <span class="brand-gradient-text">favourite room</span>
          on the internet.
        </h2>

        <ul class="max-w-md space-y-4">
          <li v-for="p in PITCH" :key="p.label" class="flex items-start gap-3.5">
            <span class="brand-chip mt-0.5 grid h-9 w-9 flex-none place-items-center rounded-xl">
              <component :is="p.icon" class="h-[18px] w-[18px]" />
            </span>
            <span class="min-w-0">
              <span class="brand-eyebrow block">{{ p.label }}</span>
              <span class="block text-sm leading-relaxed text-white/65">{{ p.text }}</span>
            </span>
          </li>
        </ul>
      </div>

      <p class="brand-eyebrow relative">Alpha build · Built for the people who never log off</p>
    </aside>

    <!-- ----------------------------------------------------------------- Form -->
    <main class="flex items-center justify-center px-6 py-10 sm:px-10">
      <div class="w-full max-w-sm">
        <!-- The lockup the collapsed brand panel leaves behind on a phone. -->
        <div class="mb-8 flex flex-col items-center gap-3 lg:hidden">
          <img src="/brand/logo.png" alt="Side Chat" class="brand-mark h-16 w-16 select-none" draggable="false">
          <span class="text-xl font-semibold tracking-tight">Side Chat</span>
        </div>

        <header class="mb-7 text-center lg:text-left">
          <h1 class="text-[1.75rem] font-semibold leading-tight tracking-tight">{{ title }}</h1>
          <p class="mt-1.5 text-sm text-muted-foreground">{{ subtitle }}</p>
        </header>

        <slot />
      </div>
    </main>
  </div>
</template>

<style scoped>
/* A poster surface, not an app surface — near-black, but tinted with the live accent hue so
   the splash follows the theme picker like everything else does. */
.brand-panel {
  background-color: oklch(0.16 calc(0.03 * var(--cs)) var(--h));
}

/* Two slow-drifting accent blooms. They're what keeps the panel from reading as a flat block. */
.brand-aurora {
  position: absolute;
  inset: -25%;
  background-image:
    radial-gradient(38rem 30rem at 22% 18%, oklch(var(--pld) var(--cd) var(--h) / 0.42), transparent 62%),
    radial-gradient(32rem 26rem at 82% 78%, oklch(0.62 0.19 calc(var(--h) + 120) / 0.3), transparent 65%),
    radial-gradient(26rem 22rem at 62% 42%, oklch(0.6 0.2 calc(var(--h) - 90) / 0.22), transparent 70%);
  filter: blur(6px);
  animation: aurora-drift 22s ease-in-out infinite alternate;
}

@keyframes aurora-drift {
  from { transform: translate3d(-3%, -2%, 0) scale(1); }
  to { transform: translate3d(3%, 2%, 0) scale(1.12); }
}

/* Faint blueprint grid — the "gamer HUD" cue, dialled down until it's texture rather than pattern. */
.brand-grid {
  position: absolute;
  inset: 0;
  background-image:
    linear-gradient(oklch(1 0 0 / 0.05) 1px, transparent 1px),
    linear-gradient(90deg, oklch(1 0 0 / 0.05) 1px, transparent 1px);
  background-size: 46px 46px;
  /* Fade the grid out toward the bottom-right so it never fights the text. */
  mask-image: radial-gradient(70% 70% at 30% 25%, #000 0%, transparent 100%);
}

/* Small uppercase micro-label. Monospaced and widely tracked — reads as UI telemetry. */
.brand-eyebrow {
  font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
  font-size: 0.6875rem;
  letter-spacing: 0.16em;
  text-transform: uppercase;
  color: oklch(var(--pld) var(--cd) var(--h) / 0.85);
}

.brand-gradient-text {
  background-image: linear-gradient(
    100deg,
    oklch(var(--pld) var(--cd) var(--h)),
    oklch(0.78 0.17 calc(var(--h) + 110))
  );
  -webkit-background-clip: text;
  background-clip: text;
  color: transparent;
}

.brand-chip {
  background-color: oklch(1 0 0 / 0.07);
  border: 1px solid oklch(1 0 0 / 0.1);
  color: oklch(var(--pld) var(--cd) var(--h));
}

/* HUD corner brackets around the logo — drawn as two layered gradients per corner so there's
   no extra markup, and clipped to a 1.5rem arm on each side. */
.brand-brackets {
  background-color: oklch(1 0 0 / 0.05);
  border: 1px solid oklch(1 0 0 / 0.12);
  box-shadow: inset 0 1px 0 oklch(1 0 0 / 0.08);
}
.brand-brackets::before,
.brand-brackets::after {
  content: '';
  position: absolute;
  width: 0.9rem;
  height: 0.9rem;
  border-color: oklch(var(--pld) var(--cd) var(--h) / 0.9);
}
.brand-brackets::before {
  top: -3px;
  left: -3px;
  border-top: 2px solid;
  border-left: 2px solid;
  border-top-left-radius: 0.6rem;
}
.brand-brackets::after {
  right: -3px;
  bottom: -3px;
  border-bottom: 2px solid;
  border-right: 2px solid;
  border-bottom-right-radius: 0.6rem;
}

/* The mark itself gets a soft accent halo and the gentlest bob. The logo art is a flat PNG on
   white-less background, so the glow has to come from a drop-shadow rather than a box-shadow. */
.brand-mark {
  filter: drop-shadow(0 6px 20px oklch(var(--pld) var(--cd) var(--h) / 0.45));
  animation: mark-float 6s ease-in-out infinite;
}

@keyframes mark-float {
  0%, 100% { transform: translateY(0); }
  50% { transform: translateY(-5px); }
}

@media (prefers-reduced-motion: reduce) {
  .brand-aurora,
  .brand-mark {
    animation: none;
  }
}
</style>
