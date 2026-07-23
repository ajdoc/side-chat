<script setup lang="ts">
import type { VoiceEffect, VoiceEffectEvent } from '~/types'

/**
 * The entrance and exit effects, drawn.
 *
 * Mounted once in the layout rather than by the call UI, for the same reason the call's
 * audio elements live outside the Vue tree: a call outlives the page you started it on, so
 * somebody arriving while you're off reading another channel still has to be seen to
 * arrive. Nothing here knows what a peer connection is — it reads a queue (see
 * useVoiceEffects) and paints it.
 *
 * One canvas for every particle in flight, and the names as ordinary DOM on top: text is
 * the one part that should stay selectable-crisp at any zoom and cost nothing to lay out.
 * The loop only runs while there is something to draw — an idle call paints no frames.
 */
const { events, dismiss } = useVoiceEffects()

interface Particle {
  x: number
  y: number
  /** Pixels per second. Everything integrates against real time, not frames. */
  vx: number
  vy: number
  /** Seconds remaining, and what it started with — together they give the fade. */
  life: number
  ttl: number
  size: number
  color: string
  shape: 'spark' | 'paper' | 'star'
  /** Paper only: how it's turned right now, and how fast it tumbles. */
  angle: number
  spin: number
  gravity: number
  drag: number
}

const canvas = ref<HTMLCanvasElement | null>(null)
let ctx: CanvasRenderingContext2D | null = null
let particles: Particle[] = []
let frame: number | undefined
let lastFrameAt = 0

/** Which events we've already spawned particles for, so a re-render can't fire one twice. */
const spawned = new Set<number>()

// Palettes: warm and bright on the way in, cooler and dimmer on the way out — the same
// difference the sound makes, so a glance is enough to tell an arrival from a departure.
const PALETTES: Record<VoiceEffect, { join: string[], leave: string[] }> = {
  fireworks: {
    join: ['#ffd166', '#ff6b6b', '#4ecdc4', '#f7fff7', '#ffe66d', '#ff9f1c'],
    leave: ['#8ecae6', '#adb5bd', '#7180ac', '#cdd7e0'],
  },
  confetti: {
    join: ['#ff595e', '#ffca3a', '#8ac926', '#1982c4', '#6a4c93', '#ff924c'],
    leave: ['#9aa5b1', '#7f9cc0', '#b7b7c9', '#cfd6dd'],
  },
  sparkles: {
    join: ['#fff3b0', '#ffd6ff', '#bde0fe', '#ffffff'],
    leave: ['#cbd5e1', '#a3b8cc', '#e2e8f0'],
  },
}

function pick(colors: string[]): string {
  return colors[Math.floor(Math.random() * colors.length)]!
}

function size() {
  const el = canvas.value
  if (!el) return { width: 0, height: 0 }

  // Back the canvas at device resolution and scale the context once, or every spark is a
  // soft grey smudge on a retina screen.
  const ratio = Math.min(window.devicePixelRatio || 1, 2)
  const width = window.innerWidth
  const height = window.innerHeight

  el.width = Math.floor(width * ratio)
  el.height = Math.floor(height * ratio)
  el.style.width = `${width}px`
  el.style.height = `${height}px`
  ctx?.setTransform(ratio, 0, 0, ratio, 0, 0)

  return { width, height }
}

// --- what each effect actually throws into the air ---

function spawnFireworks(phase: 'join' | 'leave', width: number, height: number) {
  const colors = PALETTES.fireworks[phase]
  const shells = phase === 'join' ? 3 : 2

  for (let s = 0; s < shells; s++) {
    const x = width * (0.2 + Math.random() * 0.6)
    const y = height * (phase === 'join' ? 0.15 + Math.random() * 0.3 : 0.05 + Math.random() * 0.15)
    const color = pick(colors)
    // A shell that's going out doesn't burst so much as give up: fewer sparks, less push,
    // and gravity takes them straight back down.
    const count = phase === 'join' ? 64 : 40
    const power = phase === 'join' ? 260 : 150

    for (let i = 0; i < count; i++) {
      const angle = (Math.PI * 2 * i) / count + Math.random() * 0.2
      const speed = power * (0.45 + Math.random() * 0.75)

      particles.push({
        x,
        y,
        vx: Math.cos(angle) * speed,
        vy: Math.sin(angle) * speed - (phase === 'join' ? 40 : -60),
        life: 1 + Math.random() * 0.8,
        ttl: 1.8,
        size: 1.5 + Math.random() * 1.8,
        color: Math.random() < 0.15 ? '#ffffff' : color,
        shape: 'spark',
        angle: 0,
        spin: 0,
        gravity: phase === 'join' ? 190 : 300,
        drag: 0.86,
      })
    }
  }
}

function spawnConfetti(phase: 'join' | 'leave', width: number, height: number) {
  const colors = PALETTES.confetti[phase]
  const count = phase === 'join' ? 130 : 90

  for (let i = 0; i < count; i++) {
    // Arriving, it comes out of a popper at the bottom of the screen; leaving, it's already
    // in the air and only has one direction left to go.
    const fromPopper = phase === 'join'
    const angle = fromPopper
      ? -Math.PI / 2 + (Math.random() - 0.5) * 1.5
      : Math.PI / 2 + (Math.random() - 0.5) * 0.6
    const speed = fromPopper ? 420 + Math.random() * 460 : 60 + Math.random() * 120

    particles.push({
      x: fromPopper ? width * 0.5 + (Math.random() - 0.5) * 140 : Math.random() * width,
      y: fromPopper ? height * 0.92 : -20 - Math.random() * height * 0.2,
      vx: Math.cos(angle) * speed,
      vy: Math.sin(angle) * speed,
      life: 1.6 + Math.random() * 0.9,
      ttl: 2.5,
      size: 5 + Math.random() * 6,
      color: pick(colors),
      shape: 'paper',
      angle: Math.random() * Math.PI,
      spin: (Math.random() - 0.5) * 12,
      gravity: 520,
      drag: 0.55,
    })
  }
}

function spawnSparkles(phase: 'join' | 'leave', width: number, height: number) {
  const colors = PALETTES.sparkles[phase]
  const count = 70
  const cx = width * 0.5
  const cy = height * 0.45

  for (let i = 0; i < count; i++) {
    const angle = Math.random() * Math.PI * 2
    const radius = Math.min(width, height) * (0.1 + Math.random() * 0.32)
    const outward = phase === 'join'

    particles.push({
      // Going out from the middle on arrival; drifting back into it on the way out.
      x: outward ? cx + Math.cos(angle) * 12 : cx + Math.cos(angle) * radius,
      y: outward ? cy + Math.sin(angle) * 12 : cy + Math.sin(angle) * radius,
      vx: Math.cos(angle) * (outward ? 60 + Math.random() * 140 : -40 - Math.random() * 90),
      vy: Math.sin(angle) * (outward ? 60 + Math.random() * 140 : -40 - Math.random() * 90) - (outward ? 30 : -20),
      life: 1.2 + Math.random() * 0.9,
      ttl: 2.1,
      size: 2 + Math.random() * 3,
      color: pick(colors),
      shape: 'star',
      angle: Math.random() * Math.PI,
      spin: (Math.random() - 0.5) * 4,
      gravity: outward ? -10 : 60,
      drag: 0.7,
    })
  }
}

function spawn(event: VoiceEffectEvent) {
  const { width, height } = size()
  if (!width || !height) return

  if (event.effect === 'fireworks') spawnFireworks(event.phase, width, height)
  else if (event.effect === 'confetti') spawnConfetti(event.phase, width, height)
  else spawnSparkles(event.phase, width, height)

  start()
}

// --- the loop ---

function draw(particle: Particle) {
  if (!ctx) return

  // Fade over the last of the particle's life rather than all of it: a spark that starts
  // dimming the instant it appears never looks bright.
  ctx.globalAlpha = Math.max(0, Math.min(1, particle.life / (particle.ttl * 0.45)))
  ctx.fillStyle = particle.color
  ctx.strokeStyle = particle.color

  if (particle.shape === 'spark') {
    // Drawn as a short streak along its own velocity — a firework is motion blur, and a
    // round dot reads as a bubble however fast it's moving.
    ctx.lineWidth = particle.size
    ctx.lineCap = 'round'
    ctx.beginPath()
    ctx.moveTo(particle.x, particle.y)
    ctx.lineTo(particle.x - particle.vx * 0.022, particle.y - particle.vy * 0.022)
    ctx.stroke()

    return
  }

  ctx.save()
  ctx.translate(particle.x, particle.y)
  ctx.rotate(particle.angle)

  if (particle.shape === 'paper') {
    ctx.fillRect(-particle.size / 2, -particle.size / 4, particle.size, particle.size / 2)
  } else {
    // A four-point star: two crossed spindles, which is a glint at this size and costs
    // nothing next to an arc.
    const r = particle.size
    ctx.beginPath()
    ctx.moveTo(0, -r * 2.4)
    ctx.lineTo(r * 0.6, 0)
    ctx.lineTo(0, r * 2.4)
    ctx.lineTo(-r * 0.6, 0)
    ctx.closePath()
    ctx.fill()
    ctx.beginPath()
    ctx.moveTo(-r * 2.4, 0)
    ctx.lineTo(0, r * 0.6)
    ctx.lineTo(r * 2.4, 0)
    ctx.lineTo(0, -r * 0.6)
    ctx.closePath()
    ctx.fill()
  }

  ctx.restore()
}

function tick(now: number) {
  const el = canvas.value
  if (!ctx || !el) {
    frame = undefined
    return
  }

  // Clamp the step: a tab that was in the background hands back a delta of several seconds,
  // and integrating that in one go teleports every particle off the screen.
  const dt = Math.min((now - lastFrameAt) / 1000, 0.05)
  lastFrameAt = now

  ctx.clearRect(0, 0, el.width, el.height)

  particles = particles.filter((p) => {
    p.life -= dt
    if (p.life <= 0) return false

    // Air resistance as an exponential decay, so it's frame-rate independent — the same
    // reason everything else here integrates against dt.
    const damp = Math.exp(-p.drag * dt)
    p.vx *= damp
    p.vy = p.vy * damp + p.gravity * dt
    p.x += p.vx * dt
    p.y += p.vy * dt
    p.angle += p.spin * dt

    draw(p)

    return p.y < window.innerHeight + 60
  })

  ctx.globalAlpha = 1

  if (particles.length) {
    frame = requestAnimationFrame(tick)
  } else {
    ctx.clearRect(0, 0, el.width, el.height)
    frame = undefined
  }
}

function start() {
  if (frame !== undefined) return
  lastFrameAt = performance.now()
  frame = requestAnimationFrame(tick)
}

/**
 * Pick up anything newly queued.
 *
 * Keyed on ids we've already handled rather than on the array's length: the queue is capped
 * (see useVoiceEffects) so entries fall off the front, and a length comparison would miss a
 * firing that arrived in the same tick as one expiring.
 */
watch(events, (list) => {
  for (const event of list) {
    if (spawned.has(event.id)) continue
    spawned.add(event.id)
    spawn(event)

    // The queue is the *label's* lifetime; the particles outlive it by however long they
    // take to fall, which is why the two aren't cleared together.
    setTimeout(() => {
      spawned.delete(event.id)
      dismiss(event.id)
    }, EFFECT_DURATION_MS)
  }
}, { deep: true })

function onResize() {
  size()
}

onMounted(() => {
  ctx = canvas.value?.getContext('2d') ?? null
  size()
  window.addEventListener('resize', onResize)
})

onBeforeUnmount(() => {
  window.removeEventListener('resize', onResize)
  if (frame !== undefined) cancelAnimationFrame(frame)
  frame = undefined
  particles = []
})
</script>

<template>
  <!--
    Above everything and clickable through, always. An effect fires without anybody asking
    for it, so it must never be in the way of the thing they were actually doing — including
    the moment it fires over an open dialog.
  -->
  <div class="pointer-events-none fixed inset-0 z-[200] select-none">
    <canvas ref="canvas" class="h-full w-full" />

    <div class="absolute inset-x-0 top-[18%] flex flex-col items-center gap-2">
      <p
        v-for="event in events"
        :key="event.id"
        class="voice-effect-label rounded-full bg-black/55 px-4 py-1.5 text-sm font-semibold text-white shadow-lg backdrop-blur-sm"
      >
        {{ event.name }} {{ event.phase === 'join' ? 'joined the call' : 'left the call' }}
      </p>
    </div>
  </div>
</template>

<style scoped>
/*
 * Rises and fades on its own timer rather than a transition, because the element is removed
 * by the queue expiring underneath it — there is no state change left to transition out of.
 */
.voice-effect-label {
  animation: voice-effect-label 2.6s ease-out forwards;
}

@keyframes voice-effect-label {
  0% { opacity: 0; transform: translateY(14px) scale(0.94); }
  12% { opacity: 1; transform: translateY(0) scale(1); }
  78% { opacity: 1; transform: translateY(0) scale(1); }
  100% { opacity: 0; transform: translateY(-10px) scale(0.98); }
}

/* Someone who asked for less motion gets the text and none of the movement. The canvas is
   hidden outright: it is the effect, and there is no gentle version of a firework. */
@media (prefers-reduced-motion: reduce) {
  canvas { display: none; }

  .voice-effect-label {
    animation: voice-effect-fade 2.6s ease-out forwards;
  }

  @keyframes voice-effect-fade {
    0%, 100% { opacity: 0; }
    12%, 78% { opacity: 1; }
  }
}
</style>
