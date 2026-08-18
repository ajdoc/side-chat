/**
 * "Is the person using this app a guest?"
 *
 * A guest walked in through a meeting link: an account with no password, admitted to exactly one
 * conversation and refused everything else by `ConfineGuests` server-side.
 *
 * **This composable is not the boundary** — the middleware is, and it denies by default so a
 * component that forgets to ask cannot leak anything. What this is for is manners: a guest
 * offered "Add a server" or "Schedule" gets a 403, and a guest is the one visitor with no way to
 * tell a refusal from a broken app. So the affordances that would refuse them aren't drawn.
 *
 * Kept as one named helper rather than `user.is_guest` scattered through components, so the
 * places that hide something from a guest can be found by grepping for one word.
 */
export function useGuest() {
  const { user } = useAuth()

  const isGuest = computed(() => !!user.value?.is_guest)

  return { isGuest }
}
