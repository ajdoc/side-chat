interface Typist {
  id: number
  name: string
  at: number // last time we heard from them, for expiry
}

/** Re-announce while you keep typing; anyone who goes quiet for TTL is dropped. */
const WHISPER_EVERY = 2000
const TTL = 4000

/**
 * "Alice is typing…", over Reverb *client events* (whispers).
 *
 * A whisper goes straight from one subscriber to the others via the websocket server —
 * it never reaches Laravel. That's the whole point: a keystroke notification is worth
 * nothing a few seconds later, and routing every one of them through an HTTP request,
 * a queue and a broadcast would cost more than the messages themselves. Nothing is
 * persisted, and a missed whisper simply expires.
 *
 * Reverb only accepts client events from members of the channel, so the private-channel
 * auth we already do is what stops someone whispering into a channel they can't see.
 */
export function useTyping() {
  const echo: any = useNuxtApp().$echo
  const { user } = useAuth()

  const typists = ref<Typist[]>([])

  let channelName: string | null = null
  let lastWhisperAt = 0
  let pruneTimer: ReturnType<typeof setInterval> | undefined

  const { nameFor } = useNicknames()

  const label = computed(() => {
    // Whoever is typing, under whatever they're called here — the whisper carries their
    // account name, which is the fallback rather than the answer. See useNicknames.
    const names = typists.value.map(t => nameFor(t))

    if (names.length === 0) return ''
    if (names.length === 1) return `${names[0]} is typing…`
    if (names.length === 2) return `${names[0]} and ${names[1]} are typing…`

    // Past two, names stop being useful and start overflowing the line.
    return `${names.length} people are typing…`
  })

  function remember(id: number, name: string) {
    const now = Date.now()
    const existing = typists.value.find(t => t.id === id)

    if (existing) existing.at = now
    else typists.value = [...typists.value, { id, name, at: now }]
  }

  function forget(id: number) {
    typists.value = typists.value.filter(t => t.id !== id)
  }

  /** Someone can close the tab mid-word — nobody sends a "stop" for that. */
  function prune() {
    const cutoff = Date.now() - TTL
    const alive = typists.value.filter(t => t.at > cutoff)

    if (alive.length !== typists.value.length) typists.value = alive
  }

  /** Call on every keystroke — it rate-limits itself down to one whisper per interval. */
  function notifyTyping() {
    if (!channelName || !user.value) return

    const now = Date.now()
    if (now - lastWhisperAt < WHISPER_EVERY) return

    lastWhisperAt = now
    echo?.private(channelName).whisper('typing', { id: user.value.id, name: user.value.name })
  }

  /** On send, or on clearing the box — don't leave a ghost "typing…" behind. */
  function stopTyping() {
    if (!channelName || !user.value) return

    lastWhisperAt = 0
    echo?.private(channelName).whisper('stop-typing', { id: user.value.id })
  }

  /** `name` is the private channel already subscribed to, e.g. `channel.12` / `thread.4`. */
  function subscribe(name: string) {
    if (!echo) return

    channelName = name

    echo.private(name)
      .listenForWhisper('typing', (p: { id: number, name: string }) => {
        if (p.id !== user.value?.id) remember(p.id, p.name)
      })
      .listenForWhisper('stop-typing', (p: { id: number }) => forget(p.id))

    pruneTimer = setInterval(prune, 1000)
  }

  function unsubscribe(name: string) {
    clearInterval(pruneTimer)
    typists.value = []
    channelName = null

    // Drop only the whisper handlers: useMessages is still listening on this channel.
    echo?.private(name)
      .stopListeningForWhisper('typing')
      .stopListeningForWhisper('stop-typing')
  }

  onBeforeUnmount(() => clearInterval(pruneTimer))

  return { typists, label, notifyTyping, stopTyping, subscribe, unsubscribe }
}
