/**
 * Desktop notifications for the things worth interrupting someone over.
 *
 * The rule is "only when you're not already here". Getting that rule wrong in either
 * direction is what made this feel unreliable: `visibilityState` alone answers "is this
 * page rendered", not "is this person looking at it" — a window sitting behind the editor,
 * or open on a second monitor, is *visible* and reports itself as such, so every alert
 * that mattered while you were working in another app was silently dropped. Focus is the
 * question actually being asked, so focus is what's tested.
 *
 * Which alerts get here at all is useNotifyPolicy's decision, and it's the same rule the
 * server applies to push — otherwise muting a channel would quiet your phone and leave
 * your laptop pinging.
 */
export function useDesktopNotifications() {
  const supported = import.meta.client && 'Notification' in window
  const router = useRouter()

  /** Ask once, the first time. A no-op if the user has already granted or blocked us. */
  async function ensurePermission(): Promise<boolean> {
    if (!supported) return false

    if (Notification.permission === 'default') {
      try {
        await Notification.requestPermission()
      }
      catch {
        // Older Safari rejects a permission request made without a user gesture — nothing
        // to do but let the next gesture-driven call try again.
      }
    }

    return Notification.permission === 'granted'
  }

  /**
   * Are they actually looking at us?
   *
   * Both halves are needed. `hidden` covers a minimised window, another tab, and the
   * Electron shell tucked into the tray; `hasFocus()` covers the far more common case of
   * the window being perfectly visible behind whatever they're actually working in.
   */
  function inFront(): boolean {
    return document.visibilityState === 'visible' && document.hasFocus()
  }

  function notify(opts: { title: string, body?: string, tag?: string, to?: string }) {
    if (!supported || Notification.permission !== 'granted') return
    if (inFront()) return

    try {
      // `tag` collapses repeat alerts from the same place into one, and re-alerts on each.
      const n = new Notification(opts.title, { body: opts.body, tag: opts.tag, renotify: !!opts.tag })

      n.onclick = () => {
        window.focus()
        if (opts.to) router.push(opts.to)
        n.close()
      }
    }
    catch {
      // Chrome throws here rather than returning when the platform wants notifications to
      // come from a service worker instead of a page. Swallowed on purpose: an alert we
      // couldn't raise must not take the message handler down with it, or one unlucky
      // notification would stop the badge updating too.
    }
  }

  return { supported, ensurePermission, notify, inFront }
}
