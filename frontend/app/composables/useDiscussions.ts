import type { Channel } from '~/types'

/** How the discussion directory may be ordered. Mirrors DiscussionController::SORTS. */
export type DiscussionSort = 'active' | 'created' | 'name' | 'busiest'

/**
 * Opening, closing and pinning the conversations inside a channel.
 *
 * All four calls are about a channel's *shape*, not its contents, which is why they live apart
 * from useServer's tree bookkeeping — this composable knows the endpoints, useServer knows how
 * to fold the answer back into the sidebar.
 */
export function useDiscussions() {
  const api = useApi()
  const { server, channels, patchChannel, forgetChannel } = useServer()

  /** May the signed-in user start one here? Server policy; a chat has none, so always yes. */
  const canCreate = computed(() =>
    server.value?.discussion_creation !== 'staff' || !!(server.value?.is_staff ?? server.value?.is_owner))

  /**
   * Add a discussion to a channel.
   *
   * `copyFrom` names the sibling whose room a new Side Space discussion starts as a copy of;
   * the server falls back to the channel's first discussion, which is the one people mean.
   */
  async function create(parent: Channel, name: string, copyFrom?: number | null) {
    const res = await api<{ data: Channel }>(`/api/channels/${parent.id}/discussions`, {
      method: 'POST',
      body: { name, copy_from: copyFrom ?? null },
    })

    // Appended to the branch we already hold rather than refetching the tree: the sidebar has
    // the channel open in front of the person who just made this, and a refetch would blink it.
    patchChannel(parent.id, { discussions: [...(parent.discussions ?? []), res.data] })

    return res.data
  }

  /** Staff only, and never the last one — the server refuses both. */
  async function remove(discussion: Channel) {
    await api(`/api/discussions/${discussion.id}`, { method: 'DELETE' })
    forgetChannel(discussion.id)
  }

  /** "Open this channel here from now on", for you alone. Pass null to go back to the default. */
  async function setDefault(discussion: Channel, on: boolean) {
    await api(`/api/discussions/${discussion.id}/default`, { method: on ? 'PUT' : 'DELETE' })

    if (discussion.parent_id) {
      patchChannel(discussion.parent_id, { default_child_id: on ? discussion.id : null })
    }
  }

  /**
   * The directory: every discussion in a channel, with how much has been said in each and when
   * it was last said. Fetched rather than read off the tree, because those two numbers are
   * aggregates the sidebar has never carried.
   */
  async function list(parentId: number, opts: { q?: string, sort?: DiscussionSort } = {}) {
    const res = await api<{ data: Channel[] }>(`/api/channels/${parentId}/discussions`, {
      query: { q: opts.q || undefined, sort: opts.sort },
    })

    return res.data
  }

  /** Staff only. Renames it for everybody — the same endpoint that renames a channel. */
  async function rename(discussion: Channel, name: string) {
    const res = await api<{ data: Channel }>(`/api/channels/${discussion.id}`, {
      method: 'PATCH',
      body: { name },
    })
    patchChannel(discussion.id, { name: res.data.name })

    return res.data
  }

  /** The channel a discussion hangs under, from the tree we already hold. */
  function parentOf(discussion: Channel): Channel | null {
    return channels.value.find(c => c.id === discussion.parent_id) ?? null
  }

  return { canCreate, create, list, rename, remove, setDefault, parentOf }
}
