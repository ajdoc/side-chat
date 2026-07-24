import type { Attachment, SpaceDocument } from '~/types'

interface Paginated<T> {
  data: T[]
  meta: { current_page: number, last_page: number }
}

/**
 * Normalise a Side Desk *shelf* document into the attachment shape the Files tab renders, so
 * files uploaded to Docs appear here too. A negative id keeps it distinct from any real
 * attachment id in the list (which are positive), and `uploaded_by` collapses to a name to
 * match {@link AttachmentResource}.
 */
function docAsAttachment(d: SpaceDocument): Attachment {
  return {
    id: -d.id,
    message_id: 0, // no owning message — a shelf upload isn't tied to a chat message
    name: d.name,
    mime_type: d.mime_type,
    extension: d.extension,
    size: d.size,
    is_image: false,
    is_pdf: d.kind === 'pdf',
    is_gif: false,
    url: d.url,
    download_url: d.download_url,
    uploaded_by: d.uploaded_by?.name ?? null,
    created_at: d.created_at,
  }
}

// Files posted in a channel — the Info panel's Files tab. Also folds in the Side Desk Docs
// shelf so the Files tab and Docs show the same set of documents.
export function useChannelFiles() {
  const api = useApi()
  const files = ref<Attachment[]>([])
  const shelfDocs = ref<Attachment[]>([])
  const page = ref(1)
  const lastPage = ref(1)
  const loading = ref(false)

  const hasMore = computed(() => page.value < lastPage.value)

  async function load(channelId: number) {
    loading.value = true
    try {
      const [att, docs] = await Promise.all([
        api<Paginated<Attachment>>(`/api/channels/${channelId}/attachments?page=1`),
        // The merged Docs list; keep only shelf uploads — chat docs are already in `files`.
        api<{ data: SpaceDocument[] }>(`/api/channels/${channelId}/documents`).catch(() => ({ data: [] as SpaceDocument[] })),
      ])
      files.value = att.data
      page.value = att.meta.current_page
      lastPage.value = att.meta.last_page
      shelfDocs.value = docs.data.filter(d => d.source === 'shelf').map(docAsAttachment)
    } finally {
      loading.value = false
    }
  }

  async function loadMore(channelId: number) {
    if (!hasMore.value || loading.value) return
    loading.value = true
    try {
      const res = await api<Paginated<Attachment>>(`/api/channels/${channelId}/attachments?page=${page.value + 1}`)
      const seen = new Set(files.value.map(f => f.id))
      files.value = [...files.value, ...res.data.filter(f => !seen.has(f.id))]
      page.value = res.meta.current_page
      lastPage.value = res.meta.last_page
    } finally {
      loading.value = false
    }
  }

  return { files, shelfDocs, hasMore, loading, load, loadMore }
}
