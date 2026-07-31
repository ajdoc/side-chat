import type { AvatarLook } from '~/lib/spaceAvatar'
import type { PetKind } from '~/lib/spacePets'
import type { User } from '~/types'
import { normaliseLook } from '~/lib/spaceAvatar'

/**
 * What you look like in a Side Space — read from, and written back to, your own user record.
 *
 * A costume rather than a preference: it's how everyone *else* sees you, so it lives on the
 * user alongside your display name rather than in local storage alongside your theme. That also
 * means it follows you between servers and browsers, which is the only behaviour that isn't
 * surprising for something with your face on it.
 *
 * There's nothing to subscribe to. A room learns your new look from your next whisper (see
 * {@link useSpacePresence}), which is at most a twelfth of a second away, and nobody outside a
 * room with you has any use for the news.
 */
export function useSpaceAppearance() {
  const api = useApi()
  const { user } = useAuth()

  /** Always complete, even for somebody who has never opened the picker. */
  const look = computed<AvatarLook>(() => normaliseLook(user.value?.space_avatar))
  const pet = computed<PetKind | null>(() => user.value?.space_pet ?? null)
  /** The line over your head, or null when you're not shouting anything. */
  const shout = computed<string | null>(() => user.value?.space_shout ?? null)

  /**
   * Save a look, a pet, or both.
   *
   * `pet` and `shout` are sent whenever the caller passes the key at all — including as `null`,
   * which is how the pet goes home and how the shout bubble goes away. That's why they're an
   * explicit `in patch` rather than a truthiness check: "no pet" and "don't change the pet" are
   * different requests, and so are "stop shouting" and "leave my shout alone".
   */
  async function save(patch: { avatar?: AvatarLook, pet?: PetKind | null, shout?: string | null }) {
    const body: Record<string, unknown> = {}
    if (patch.avatar) body.avatar = patch.avatar
    if ('pet' in patch) body.pet = patch.pet
    if ('shout' in patch) body.shout = patch.shout

    const res = await api<{ data: User }>('/api/space/appearance', { method: 'PATCH', body })

    // The response is the whole user, so the app's copy stays authoritative rather than
    // drifting into whatever we hoped we'd saved.
    user.value = res.data

    return res.data
  }

  return { look, pet, shout, save }
}
