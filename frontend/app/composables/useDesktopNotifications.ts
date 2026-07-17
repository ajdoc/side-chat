/**
 * Desktop notifications for the things worth interrupting someone over — mentions, for now.
 *
 * The rule is "only when you're not already here": a sidebar badge is enough while the app
 * is in front of you, so a system notification fires only when the tab is hidden or in the
 * background. Clicking one focuses the window and jumps to wherever it came from.
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

  function notify(opts: { title: string, body?: string, tag?: string, to?: string }) {
    if (!supported || Notification.permission !== 'granted') return
    // In front of you already? The badge said it. Don't also buzz the OS.
    if (document.visibilityState === 'visible') return

    // `tag` collapses repeat mentions in the same place into one, and re-alerts on each.
    const n = new Notification(opts.title, { body: opts.body, tag: opts.tag, renotify: !!opts.tag })
    n.onclick = () => {
      window.focus()
      if (opts.to) router.push(opts.to)
      n.close()
    }
  }

  return { supported, ensurePermission, notify }
}
