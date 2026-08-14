import type { AppSticker } from '~/types'

/**
 * A channel's Sticker Wall — the collage, and placing things on it.
 *
 * Same base-path/stream contract as the other surface apps, state in a
 * {@link useSurfaceStore}, riding the shared `TrackerChanged` broadcast under the `sticker`
 * subject.
 */
export function useAppStickers(basePath: string, streamName: string) {
  const api = useApi()
  const echo: any = useNuxtApp().$echo
  const { hold, release } = useEchoStream()

  const { state, attach } = useSurfaceStore('stickers', basePath, () => ({
    stickers: ref<AppSticker[]>([]),
    loaded: ref(false),
  }))

  const { stickers, loaded } = state

  function socketHeaders() {
    return { 'X-Socket-ID': echo?.socketId() ?? '' }
  }

  /** Paint order, bottom to top — the wall is drawn straight from this. */
  function sort() {
    stickers.value = [...stickers.value].sort((a: AppSticker, b: AppSticker) => a.z - b.z || a.id - b.id)
  }

  function upsert(sticker: AppSticker) {
    const idx = stickers.value.findIndex((s: AppSticker) => s.id === sticker.id)
    if (idx === -1) stickers.value = [...stickers.value, sticker]
    else stickers.value.splice(idx, 1, sticker)
    sort()
  }

  async function load() {
    const res = await api<{ data: AppSticker[] }>(`${basePath}/stickers`)
    stickers.value = res.data
    loaded.value = true
  }

  async function add(input: {
    name?: string | null
    content: Record<string, any>
    x?: number
    y?: number
    w?: number
    h?: number
    rotation?: number
  }) {
    const res = await api<{ data: AppSticker }>(`${basePath}/stickers`, {
      method: 'POST', body: input, headers: socketHeaders(),
    })
    upsert(res.data)
    return res.data
  }

  /** Optimistic, because the common call is a drag and a drag must not lag the pointer. */
  async function patch(id: number, changes: Partial<AppSticker>) {
    const idx = stickers.value.findIndex((s: AppSticker) => s.id === id)
    if (idx === -1) return
    const prev = stickers.value[idx]!
    stickers.value.splice(idx, 1, { ...prev, ...changes } as AppSticker)
    try {
      const res = await api<{ data: AppSticker }>(`${basePath}/stickers/${id}`, {
        method: 'PATCH', body: changes, headers: socketHeaders(),
      })
      upsert(res.data)
      return res.data
    }
    catch (e) {
      stickers.value.splice(idx, 1, prev)
      throw e
    }
  }

  async function remove(id: number) {
    const prev = stickers.value
    stickers.value = stickers.value.filter((s: AppSticker) => s.id !== id)
    try {
      await api(`${basePath}/stickers/${id}`, { method: 'DELETE', headers: socketHeaders() })
    }
    catch (e) {
      stickers.value = prev
      throw e
    }
  }

  /** Put a sticker on top — rewriting `z`, which is all "bring to front" means. */
  async function bringToFront(id: number) {
    const top = stickers.value.reduce((max: number, s: AppSticker) => Math.max(max, s.z), 0)
    return patch(id, { z: top + 1 })
  }

  function open() {
    attach(() => {
      void load()

      if (!echo) return
      const channel = hold(streamName)
      channel.listen('.TrackerChanged', (e: { subject: string, action: string, payload: any }) => {
        if (e.subject !== 'sticker') return
        if (e.action === 'removed') stickers.value = stickers.value.filter((s: AppSticker) => s.id !== e.payload.id)
        else upsert(e.payload)
      })

      return () => {
        channel.stopListening('.TrackerChanged')
        release(streamName)
      }
    })
  }

  return { stickers, loaded, open, load, add, patch, remove, bringToFront }
}
