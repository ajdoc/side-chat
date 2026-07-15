/**
 * Whether the user has opted out of the "create your first server" flow.
 *
 * `/` sends anyone with no servers to /onboarding, so opting out has to be *remembered* —
 * otherwise "skip" just bounces you back through the door you were trying to walk out of,
 * on this page load and every one after it.
 *
 * A cookie rather than server state: it's a UI preference about which screen to open, it
 * should survive a reload, and nothing on the backend needs an opinion about it. Cleared
 * the moment they do create a server, so a user who later leaves every server they're in
 * gets the guided flow again rather than a dead end they once dismissed.
 */
export function useOnboarding() {
  const skipped = useCookie<boolean>('onboarding_skipped', {
    maxAge: 60 * 60 * 24 * 365,
    sameSite: 'lax',
    path: '/',
    default: () => false,
  })

  function skip() {
    skipped.value = true
  }

  function reset() {
    skipped.value = false
  }

  return { skipped, skip, reset }
}
