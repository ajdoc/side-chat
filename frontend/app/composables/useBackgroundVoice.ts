/**
 * "Keep this call alive while the app isn't on screen."
 *
 * A browser tab that goes to the background keeps its microphone; an Android app does not. From
 * Android 12 the capture is cut when the app leaves the foreground, and from Android 14 the only
 * way to hold it is a foreground service that has declared `microphone` as its type. So on the
 * phone a call has to bracket itself with a request to the native shell — everywhere else this
 * is a pair of no-ops, which is the point: useVoice calls it unconditionally.
 *
 * iOS is deliberately not covered. `UIBackgroundModes` is declared in the app's Info.plist, but
 * WKWebView suspends its content process when the app backgrounds and takes the WebRTC capture
 * with it; keeping an iOS call alive means moving the call itself out of the WebView (CallKit
 * and a native peer connection), which is a different project. Calling this on iOS is harmless.
 *
 * @see VoicePlugin, the Android half.
 */

interface BackgroundVoicePlugin {
  start: (options: { title?: string, text?: string }) => Promise<void>
  stop: () => Promise<void>
  /** Fired when the user taps Leave on the call's own notification, outside the app. */
  addListener: (event: 'backgroundVoiceLeaveRequested', handler: () => void) => Promise<{ remove: () => void }>
}

/**
 * The native plugin, reached through the bridge Capacitor injects on `window` — the same
 * structural detection useDisplayCapture and usePlatform use, and for the same reason: there is
 * one generated bundle, and it is served from a web host and from Electron as well as the phone.
 */
function plugin(): BackgroundVoicePlugin | null {
  if (typeof window === 'undefined') return null
  return (window as any).Capacitor?.Plugins?.BackgroundVoice ?? null
}

/**
 * Module scope, not per-caller: there is one call and one service holding it, but `useVoice()`
 * is instantiated per component. Held inside the composable, a `stop()` from the global call bar
 * would find no listener to remove from the instance that started it.
 */
let leaveListener: { remove: () => void } | null = null

export function useBackgroundVoice() {
  /**
   * @param onLeaveRequested Called when the user hangs up from the notification. The page still
   *   owns leaving — it hangs up properly and the resulting `stop()` takes the service down, so
   *   the notification never disappears ahead of the call it represents.
   */
  async function start(options: { title?: string, text?: string, onLeaveRequested?: () => void } = {}) {
    const native = plugin()
    if (!native) return

    try {
      await native.start({ title: options.title, text: options.text })

      if (options.onLeaveRequested) {
        leaveListener?.remove()
        leaveListener = await native.addListener('backgroundVoiceLeaveRequested', options.onLeaveRequested)
      }
    } catch {
      // Never fail a join over this. Worst case the call is an ordinary foreground one, which
      // is exactly what it was before this existed.
    }
  }

  async function stop() {
    leaveListener?.remove()
    leaveListener = null

    try {
      await plugin()?.stop()
    } catch {
      // Nothing to salvage; the service stops with the WebView regardless.
    }
  }

  return { start, stop }
}
