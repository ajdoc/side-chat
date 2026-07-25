/**
 * Which shell is this bundle running inside?
 *
 * The same `nuxt generate` output is served three ways: from a web host, from Capacitor's
 * embedded server on a phone, and from Electron's `app://` protocol on a desktop. A handful
 * of decisions differ between them — where the auth token lives, whether a route exists,
 * whether the sidebar is a drawer — so they all ask here rather than sniffing the UA
 * themselves.
 *
 * Detection is deliberately structural, not UA-based: Capacitor and Electron each announce
 * themselves on `window`, and anything else is the web.
 */
export type Platform = 'web' | 'capacitor' | 'electron'

function detect(): Platform {
  if (typeof window === 'undefined') return 'web'
  if ((window as any).sideChatDesktop) return 'electron'
  if ((window as any).Capacitor?.isNativePlatform?.()) return 'capacitor'
  return 'web'
}

export function usePlatform() {
  const platform = useState<Platform>('platform', detect)

  return {
    platform,
    /** True in either native shell — use this for "not a browser tab" decisions. */
    isNative: computed(() => platform.value !== 'web'),
    isMobile: computed(() => platform.value === 'capacitor'),
    isDesktop: computed(() => platform.value === 'electron'),
  }
}
