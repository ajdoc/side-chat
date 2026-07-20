import type { SpaceDocument } from '~/types'

/**
 * A Side Space's Docs app — the view-only shelf of uploaded files, loaded over HTTP and kept
 * in sync over broadcast. Surface-agnostic like the other Side Space composables: the caller
 * passes the REST base path and the private stream, so this drives a side chat's shelf
 * (`/api/side-chats/{id}/documents`, `sidechat.{id}`) and a channel's alike.
 *
 * Uploads go up as multipart form data; each broadcasts to everyone else via `->toOthers()`.
 * The signed `url`/`download_url` on each document expire, so the list is the source of truth
 * — reopen the tab and it reloads fresh links.
 */
export function useDocuments(basePath: string, streamName: string) {
  const api = useApi()
  const echo: any = useNuxtApp().$echo

  const documents = ref<SpaceDocument[]>([])
  const uploading = ref(false)

  let channel: any = null

  function socketHeaders() {
    return { 'X-Socket-ID': echo?.socketId() ?? '' }
  }

  async function load() {
    const res = await api<{ data: SpaceDocument[] }>(`${basePath}/documents`)
    documents.value = res.data
  }

  /** Upload one file. Returns the saved document (newest first in the list). */
  async function upload(file: File) {
    uploading.value = true
    try {
      const form = new FormData()
      form.append('file', file)
      const res = await api<{ data: SpaceDocument }>(`${basePath}/documents`, {
        method: 'POST',
        body: form,
        headers: socketHeaders(),
      })
      documents.value = [res.data, ...documents.value]
      return res.data
    } finally {
      uploading.value = false
    }
  }

  async function remove(id: number) {
    const prev = documents.value
    documents.value = documents.value.filter(d => !(d.source === 'shelf' && d.id === id))
    try {
      await api(`${basePath}/documents/${id}`, { method: 'DELETE', headers: socketHeaders() })
    } catch (e) {
      documents.value = prev
      throw e
    }
  }

  /** Share a shelf document into the chat timeline (channel surfaces only). */
  async function sendToChat(id: number) {
    await api(`${basePath}/documents/${id}/send`, { method: 'POST', headers: socketHeaders() })
  }

  function subscribe() {
    if (!echo) return
    channel = echo.private(streamName)
    channel
      .listen('.SpaceDocumentAdded', (doc: SpaceDocument) => {
        // Broadcast documents are always shelf uploads; key against those to avoid an id
        // clash with a chat-sourced document (a different table, same numeric space).
        if (documents.value.some(d => d.source === 'shelf' && d.id === doc.id)) return
        documents.value = [doc, ...documents.value]
      })
      .listen('.SpaceDocumentRemoved', (p: { id: number }) => {
        documents.value = documents.value.filter(d => !(d.source === 'shelf' && d.id === p.id))
      })
  }

  function unsubscribe() {
    channel
      ?.stopListening('.SpaceDocumentAdded')
      .stopListening('.SpaceDocumentRemoved')
    channel = null
  }

  return { documents, uploading, load, upload, remove, sendToChat, subscribe, unsubscribe }
}
