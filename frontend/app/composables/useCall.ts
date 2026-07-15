import type { Conversation, IncomingCall } from '~/types'

/** Long enough to notice, short enough not to be a nuisance if you've walked away. */
const RING_TIMEOUT_MS = 45_000

/**
 * The ringtone, and the timer that gives up on it — at *module* scope, not inside the
 * composable.
 *
 * This is the whole bug that was here, and it's worth naming. `useCall()` is called from
 * several places (useUserStream, IncomingCall.vue, the chat page), and each call re-runs
 * the function body. Reactive state survives that because `useState` is keyed and shared —
 * but a plain `let` does not: every caller got its own. So useUserStream started the
 * oscillator in *its* closure, and when IncomingCall.vue called decline(), it cleared a
 * `tone` variable that had always been undefined. The dialog vanished (shared state) and
 * the phone kept ringing (unshared handle).
 *
 * There is exactly one ringtone playing at a time, however many components are looking at
 * it — so it lives exactly once, out here. Same reasoning as the peer connections in
 * useVoice: a thing that isn't reactive state, and must not be duplicated, doesn't belong
 * in a function that runs once per caller.
 */
let ringTimeout: ReturnType<typeof setTimeout> | undefined
let ringTone: { stop: () => void } | undefined

/**
 * A ringing phone.
 *
 * The one piece of this feature with no counterpart in a server's voice channel, and the
 * reason DM calls needed any new code at all. A voice channel is a *place*: it's in the
 * sidebar, it's always there, you walk in when you feel like it and nobody is interrupted.
 * A call in a chat is an *event* aimed at a person — it has to reach them wherever they
 * are, they have to be able to say no, and it has to give up if nobody's home.
 *
 * The call *itself* is entirely useVoice, unchanged: accepting is `connect(channel_id)`,
 * exactly the same call a voice channel makes. All that's here is the ringing.
 */
export function useCall() {
  const api = useApi()
  const { connect } = useVoice()

  // Shared: the app-wide IncomingCall dialog and the chat page's header both read this.
  const incoming = useState<IncomingCall | null>('call:incoming', () => null)
  const joining = useState<boolean>('call:joining', () => false)

  function stopRinging() {
    clearTimeout(ringTimeout)
    ringTimeout = undefined
    ringTone?.stop()
    ringTone = undefined
    incoming.value = null
  }

  function ringingFor(call: IncomingCall) {
    // A second ring for the same conversation is the caller reconnecting, not a new call.
    if (incoming.value?.conversation.id === call.conversation.id) return

    stopRinging()
    incoming.value = call
    ringTone = playRingtone()

    // Give up on our own if the far end never tells us it's over — a caller whose browser
    // died mid-ring would otherwise leave this thing chiming forever.
    ringTimeout = setTimeout(stopRinging, RING_TIMEOUT_MS)
  }

  /** Pick up. From here on it's an ordinary call — the same one a voice channel holds. */
  async function accept() {
    const call = incoming.value
    if (!call || joining.value) return

    joining.value = true
    try {
      stopRinging()
      await connect(call.conversation.channel_id)
      await navigateTo(`/chats/${call.conversation.id}`)
    } finally {
      joining.value = false
    }
  }

  /** "Not now." Silences it here, and on your other tabs — see CallDeclined. */
  async function decline() {
    const call = incoming.value
    if (!call) return

    stopRinging()
    try {
      await api(`/api/conversations/${call.conversation.id}/call/decline`, { method: 'POST' })
    } catch {
      // Already over, or the network went. Either way we've stopped ringing, which is the
      // part that actually mattered to the person holding this device.
    }
  }

  /** Start a call in a chat. There's no "start" endpoint — walking into an empty room is it. */
  async function start(conversation: Conversation) {
    await connect(conversation.channel_id)
  }

  return { incoming, joining, ringingFor, stopRinging, accept, decline, start }
}

/**
 * A ringtone, synthesised rather than fetched.
 *
 * Two tones a beat apart, on a repeating cycle — the shape everyone recognises as "your
 * phone is ringing" without having to be told. Built out of oscillators because shipping
 * an audio file for this would mean a network request on the one code path that must not
 * wait for one, and because a gain envelope is what stops it clicking.
 *
 * Fails silently by design: a browser that hasn't been interacted with yet won't let us
 * make a sound, and a silent ring is very much better than a thrown exception on the path
 * that also has to put the dialog on the screen.
 */
function playRingtone(): { stop: () => void } {
  let ctx: AudioContext | null = null
  let timer: ReturnType<typeof setInterval> | undefined

  try {
    ctx = new AudioContext()

    const ring = () => {
      if (!ctx) return

      for (const [index, delay] of [0, 0.4].entries()) {
        const osc = ctx.createOscillator()
        const gain = ctx.createGain()

        osc.type = 'sine'
        osc.frequency.value = index === 0 ? 480 : 440

        const at = ctx.currentTime + delay
        // A gentle in/out. An oscillator switched on and off at full volume *clicks*.
        gain.gain.setValueAtTime(0, at)
        gain.gain.linearRampToValueAtTime(0.12, at + 0.02)
        gain.gain.setValueAtTime(0.12, at + 0.28)
        gain.gain.linearRampToValueAtTime(0, at + 0.32)

        osc.connect(gain).connect(ctx.destination)
        osc.start(at)
        osc.stop(at + 0.34)
      }
    }

    ring()
    timer = setInterval(ring, 2500)
  } catch {
    // No audio available. The dialog is still on screen, which is the half that matters.
  }

  return {
    stop() {
      clearInterval(timer)
      void ctx?.close()
      ctx = null
    },
  }
}
