/**
 * Native push, for the case no amount of websocket cleverness can cover: the app is closed.
 *
 * A backgrounded Capacitor app is a frozen WebView — no timers, no socket, no JavaScript at
 * all — so the only thing that can wake it is the OS. On Android that means Firebase Cloud
 * Messaging, which is why this composable exists alongside useDesktopNotifications rather
 * than inside it: they solve the same problem on opposite sides of a boundary the page
 * can't reach across.
 *
 * A no-op in the browser and in the Electron shell, both of which keep a live connection
 * and handle their own alerts.
 */
export function usePushNotifications() {
  const api = useApi()
  const router = useRouter()

  /** The token we last told the server about, so sign-out knows what to revoke. */
  const registered = useState<string | null>('push:token', () => null)

  let bound = false

  /** Only a real device build has any of this. Everything below is guarded on it. */
  async function plugin() {
    if (!import.meta.client) return null

    const { Capacitor } = await import('@capacitor/core')
    if (!Capacitor.isNativePlatform()) return null

    const { PushNotifications } = await import('@capacitor/push-notifications')
    return PushNotifications
  }

  /**
   * Ask, register, and tell the server where to find us.
   *
   * Called on every launch rather than once ever: FCM reissues a token whenever it feels
   * like it — a restore onto a new phone, cleared app data, a long enough silence — and a
   * client that registered once would go quietly unreachable with no symptom but silence.
   * The server upserts on the token, so repeating this costs nothing.
   */
  async function register(): Promise<boolean> {
    const push = await plugin()
    if (!push) return false

    // Android 13+ makes this a runtime permission like any other. Declined is a final
    // answer for this launch — asking again on the next tick just annoys people.
    const status = await push.checkPermissions()
    const granted = status.receive === 'granted'
      ? true
      : (await push.requestPermissions()).receive === 'granted'

    if (!granted) return false

    bind(push)
    await push.register()

    return true
  }

  /**
   * Wire the listeners once per session.
   *
   * `registration` can fire more than once (that's the token rotating), so the handler has
   * to be an upsert rather than a one-shot — but *adding* the listener twice would post
   * twice, hence the guard.
   */
  function bind(push: any) {
    if (bound) return
    bound = true

    push.addListener('registration', async (token: { value: string }) => {
      registered.value = token.value

      await api('/api/device-tokens', {
        method: 'POST',
        body: { token: token.value, platform: 'android' },
      }).catch(() => {
        // Offline at launch is the common case here. The next launch registers again, and
        // until then the badge and the live socket cover everything the push would have.
        registered.value = null
      })
    })

    push.addListener('registrationError', () => {
      // Almost always a build with no google-services.json. Nothing the user can do, and
      // nothing worth interrupting them over — the app works, it just won't buzz.
      registered.value = null
    })

    // Tapped the notification. The payload is data-only (see the FcmSender), so everything
    // needed to get to the right place is in `data` rather than in a notification body.
    push.addListener('pushNotificationActionPerformed', (action: any) => {
      const data = action?.notification?.data ?? {}
      const to = routeFor(data)

      if (to) router.push(to)
    })
  }

  /**
   * Arrived while the app was open and in front of you.
   *
   * Deliberately silent: you are looking at the app, so the sidebar badge has already said
   * it and the message itself is probably on screen. This listener exists to *suppress*,
   * which is the whole reason the server sends data-only messages — a payload with a
   * `notification` block would have gone to the system tray before we ever saw it.
   */
  async function bindForeground() {
    const push = await plugin()
    if (!push) return

    push.addListener('pushNotificationReceived', () => {})
  }

  /** Where a push points. Mirrors the routes the sidebar builds for the same places. */
  function routeFor(data: Record<string, string>): string | null {
    if (data.conversation_id) return `/chats/${data.conversation_id}`
    if (data.server_id && data.channel_id) return `/servers/${data.server_id}/channels/${data.channel_id}`

    return null
  }

  /**
   * Sign-out. Revoking before the token is forgotten locally, because after that we have
   * no way to name it — and a phone that keeps receiving the last person's messages is
   * the worst failure this feature has.
   */
  async function unregister() {
    const token = registered.value
    if (!token) return

    registered.value = null

    await api('/api/device-tokens', { method: 'DELETE', body: { token } }).catch(() => {})
  }

  return { register, unregister, bindForeground }
}
