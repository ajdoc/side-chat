import { useLocalStorage } from '@vueuse/core'

/**
 * Which half of the product an admin is currently working in.
 *
 * A super admin has two homes — the instance panel and the app itself — and `/` can only
 * send them to one. A plain "admins always land on /admin" redirect breaks the way back:
 * the panel's exit is a link to `/`, which would bounce straight back into the panel.
 *
 * So the landing rule reads a *remembered side* instead of the role alone. Switching sides
 * is an explicit act — the two buttons below — and `/` honours whatever you last chose. An
 * admin who goes back to Side Chat stays in Side Chat, including across reloads, until they
 * click into the panel again.
 *
 * Local storage rather than a cookie or shared state: it's a per-browser preference, it must
 * survive the hard navigation `goToApp` sometimes needs, and it is nobody's business but this
 * device's. It defaults to `admin`, so the first thing a super admin sees after signing in is
 * the panel.
 */
export type PanelSide = 'admin' | 'app'

export function usePanelSide() {
  const side = useLocalStorage<PanelSide>('panel:side', 'admin')

  /** Enter the admin panel, and remember that's where `/` should land. */
  function goToAdmin() {
    side.value = 'admin'
    return navigateTo('/admin')
  }

  /** Leave for the app. `/` then resolves normally — first server, chats, or onboarding. */
  function goToApp() {
    side.value = 'app'
    return navigateTo('/')
  }

  return { side, goToAdmin, goToApp }
}
