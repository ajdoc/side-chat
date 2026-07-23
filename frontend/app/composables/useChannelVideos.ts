import type { ChannelVideoFile } from '~/types'

/**
 * Video files already posted in a chat — what the video widget's "in this chat" picker
 * browses, so a clip someone dropped in the conversation can be played without re-uploading it.
 *
 * The scope is the channel, and that covers more than it sounds like: every message carries the
 * channel it lives in, whether it's on the main timeline, in a thread, or in a side chat. So one
 * request reaches all of them.
 *
 * Unpaginated on purpose — the server caps the list and `q` narrows it, which suits a picker
 * better than an infinite scroll (see useChannelFiles/useChannelGifs for the paged Info tabs).
 */
export function useChannelVideos() {
  const api = useApi()
  const videos = ref<ChannelVideoFile[]>([])
  const loading = ref(false)
  const loaded = ref(false)

  /**
   * Fetch the list, optionally filtered by filename.
   *
   * Searches race: type "hol" and the response for "ho" can land after it and overwrite the
   * narrower list. So each call takes a ticket and only the newest one is allowed to write.
   */
  let latest = 0

  async function load(channelId: number, query = '') {
    const ticket = ++latest
    loading.value = true
    try {
      const q = query.trim() ? `?q=${encodeURIComponent(query.trim())}` : ''
      const res = await api<{ data: ChannelVideoFile[] }>(`/api/channels/${channelId}/videos${q}`)
      if (ticket !== latest) return
      videos.value = res.data
      loaded.value = true
    }
    finally {
      if (ticket === latest) loading.value = false
    }
  }

  return { videos, loading, loaded, load }
}
