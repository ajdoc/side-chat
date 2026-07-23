import type { VoiceEffect, VoiceEffectEvent } from '~/types'

/**
 * Entrance and exit effects: the thing everybody in a call sees and hears when somebody
 * walks in or out.
 *
 * ## Why it's here and not in useVoice
 *
 * The effect has nothing to do with media negotiation — it's a reaction to the *presence*
 * events useVoice already handles, and the two halves of it (a canvas full of particles,
 * a few oscillators) have no business inside the file that manages peer connections. So
 * useVoice decides *when* one fires and this decides what that means: a queue the overlay
 * component draws from, and a sound synthesised on the spot.
 *
 * ## Nothing is downloaded
 *
 * Every effect is drawn and synthesised in the browser. That's deliberate rather than
 * thrifty: an effect fires at everyone in the room without their asking, so it must not be
 * possible for one person to point it at an arbitrary file. The channel stores a slug from
 * a fixed list (Channel::VOICE_EFFECTS), and this is the only thing that turns a slug into
 * something you can see or hear.
 */

/** The catalogue, with the words the settings dialog puts next to each one. */
export const VOICE_EFFECTS: { value: VoiceEffect, label: string, hint: string }[] = [
  { value: 'fireworks', label: 'Fireworks', hint: 'Shells burst over the call, with the bang' },
  { value: 'confetti', label: 'Confetti', hint: 'A popper, and paper everywhere' },
  { value: 'sparkles', label: 'Sparkles', hint: 'A quiet shimmer and a chime' },
]

/** How long an effect lives on screen. The overlay drops it from the queue afterwards. */
export const EFFECT_DURATION_MS = 2600

/**
 * At most this many at once. Four people arriving together is a party; the tenth simultaneous
 * firework is a dropped frame rate, and on a shared stage that costs everyone.
 */
const MAX_CONCURRENT = 4

let sequence = 0

/**
 * One AudioContext for every effect this tab ever plays, built on the first one.
 *
 * Effects only fire while you're in a call, and joining a call is a click — so the context
 * is created after a gesture and the autoplay policy has no objection. It's kept rather than
 * rebuilt because a context is expensive and browsers cap how many a page may hold.
 */
let ctx: AudioContext | null = null

function audio(): AudioContext | null {
  if (typeof window === 'undefined') return null
  try {
    ctx ??= new AudioContext()
    if (ctx.state === 'suspended') void ctx.resume()
    return ctx
  } catch {
    return null
  }
}

/** A short burst of white noise — every bang, pop and hiss in here is built from this. */
function noiseBuffer(context: AudioContext, seconds: number): AudioBuffer {
  const buffer = context.createBuffer(1, Math.floor(context.sampleRate * seconds), context.sampleRate)
  const data = buffer.getChannelData(0)
  for (let i = 0; i < data.length; i++) data[i] = Math.random() * 2 - 1

  return buffer
}

/** A tone that slides from one pitch to another and fades out. Whistles and chimes. */
function tone(
  context: AudioContext,
  { type, from, to, at, duration, gain }: {
    type: OscillatorType
    from: number
    to: number
    at: number
    duration: number
    gain: number
  },
) {
  const osc = context.createOscillator()
  const amp = context.createGain()

  osc.type = type
  osc.frequency.setValueAtTime(from, at)
  osc.frequency.exponentialRampToValueAtTime(Math.max(to, 1), at + duration)

  // Ramped, never switched: a gain that jumps to zero is a click, and a click is the one
  // sound in here nobody chose.
  amp.gain.setValueAtTime(0.0001, at)
  amp.gain.exponentialRampToValueAtTime(gain, at + 0.02)
  amp.gain.exponentialRampToValueAtTime(0.0001, at + duration)

  osc.connect(amp).connect(context.destination)
  osc.start(at)
  osc.stop(at + duration + 0.05)
}

/** Filtered noise: the boom of a shell, the pop of a popper, the hiss of glitter. */
function burst(
  context: AudioContext,
  { at, duration, cutoff, gain }: { at: number, duration: number, cutoff: number, gain: number },
) {
  const source = context.createBufferSource()
  const filter = context.createBiquadFilter()
  const amp = context.createGain()

  source.buffer = noiseBuffer(context, duration)
  filter.type = 'lowpass'
  filter.frequency.setValueAtTime(cutoff, at)
  filter.frequency.exponentialRampToValueAtTime(Math.max(cutoff / 6, 80), at + duration)

  amp.gain.setValueAtTime(gain, at)
  amp.gain.exponentialRampToValueAtTime(0.0001, at + duration)

  source.connect(filter).connect(amp).connect(context.destination)
  source.start(at)
  source.stop(at + duration)
}

/**
 * The sound of one effect.
 *
 * Leaving is the same effect played downwards — pitches fall instead of rise, and the whole
 * thing is a touch quieter. That's the entire difference, and it reads immediately: you can
 * tell someone left without looking at the screen.
 */
function playEffect(effect: VoiceEffect, phase: 'join' | 'leave') {
  const context = audio()
  if (!context) return

  const now = context.currentTime
  const up = phase === 'join'
  const level = up ? 1 : 0.75

  if (effect === 'fireworks') {
    // The whistle of the shell going up, then the bang — twice, slightly apart, because a
    // single perfectly-timed bang sounds like a door slamming rather than a firework.
    tone(context, {
      type: 'sine',
      from: up ? 320 : 900,
      to: up ? 1100 : 220,
      at: now,
      duration: 0.34,
      gain: 0.06 * level,
    })
    burst(context, { at: now + 0.36, duration: 0.55, cutoff: 1600, gain: 0.16 * level })
    burst(context, { at: now + 0.62, duration: 0.45, cutoff: 1100, gain: 0.1 * level })
    return
  }

  if (effect === 'confetti') {
    // The cork, then the paper.
    burst(context, { at: now, duration: 0.09, cutoff: 3200, gain: 0.22 * level })
    tone(context, {
      type: 'square',
      from: up ? 480 : 720,
      to: up ? 880 : 300,
      at: now + 0.02,
      duration: 0.16,
      gain: 0.05 * level,
    })
    burst(context, { at: now + 0.12, duration: 0.7, cutoff: 5200, gain: 0.045 * level })
    return
  }

  // Sparkles: three bell partials, up the arpeggio on the way in and down it on the way out.
  const notes = up ? [880, 1174.7, 1568] : [1568, 1174.7, 880]
  notes.forEach((frequency, i) => {
    tone(context, {
      type: 'triangle',
      from: frequency,
      to: frequency,
      at: now + i * 0.09,
      duration: 0.5,
      gain: 0.05 * level,
    })
  })
  burst(context, { at: now, duration: 0.5, cutoff: 9000, gain: 0.02 * level })
}

export function useVoiceEffects() {
  /**
   * What is going off right now. Shared state rather than a component ref, because the thing
   * that fires an effect (a presence event, in useVoice) and the thing that draws it (the
   * overlay in the layout) never meet — and a call outlives whatever page you started it on.
   */
  const events = useState<VoiceEffectEvent[]>('voice:effectEvents', () => [])

  /**
   * Fire one. `silent` is how deafening yourself reaches this: you asked for quiet, and an
   * entrance effect is precisely the sort of thing that has no right to override that. You
   * still see it — deafen is about your speakers.
   */
  function fire(effect: VoiceEffect, phase: 'join' | 'leave', name: string, options: { silent?: boolean } = {}) {
    const event: VoiceEffectEvent = { id: ++sequence, effect, phase, name }

    events.value = [...events.value, event].slice(-MAX_CONCURRENT)

    if (!options.silent) playEffect(effect, phase)

    return event
  }

  /** The overlay calls this once it has finished drawing one. */
  function dismiss(id: number) {
    events.value = events.value.filter(e => e.id !== id)
  }

  /** Nothing in flight — used when a call ends, so an effect can't outlive the room. */
  function clear() {
    events.value = []
  }

  return { events, fire, dismiss, clear }
}
