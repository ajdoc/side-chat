/**
 * Send one large file up in pieces.
 *
 * An ordinary attachment rides along inside the send request, which caps it at whatever PHP,
 * the web server and every proxy in between will carry in one body — and stakes the whole
 * transfer on the connection surviving to the end. Past {@link CHUNK_THRESHOLD} the composer
 * routes a file through here instead: the file is cut into {@link CHUNK_BYTES} slices, posted
 * one at a time to a staging area, and the send that follows carries only the upload's id
 * ({@see App\Http\Controllers\ChunkedUploadController}).
 *
 * Slices go up strictly in order, and the server answers each one with the index it wants
 * next. That turns a dropped chunk into a resume rather than a restart: a failure retries the
 * same slice, and a server that says "I already have that one" (409) simply moves the cursor.
 * Cancelling aborts the request in flight *and* tells the server to bin the half-file — the
 * staging area is swept hourly, but not leaving litter is cheaper than sweeping it.
 */

/** Files at or below this go the ordinary route; there's no point staging a small one. */
export const CHUNK_THRESHOLD = 8 * 1024 * 1024

/** One slice. Small enough to be inside any sane body limit, big enough to keep the round trips down. */
export const CHUNK_BYTES = 4 * 1024 * 1024

/**
 * The server's ceiling, checked here so a hopeless pick fails instantly instead of after its
 * first chunk. It mirrors `config/uploads.php` — change one and change the other, via
 * `NUXT_PUBLIC_MAX_UPLOAD_MB` and `MAX_UPLOAD_MB` respectively.
 */
export function maxUploadBytes(): number {
  const mb = Number(useRuntimeConfig().public.maxUploadMb) || 2048
  return mb * 1024 * 1024
}

/** How many times one slice is retried before the upload gives up. */
const CHUNK_ATTEMPTS = 3

interface UploadState {
  id: string
  received_chunks: number
  total_chunks: number
  /** The piece the server wants next — the resume point. */
  next_index: number | null
  completed: boolean
}

export function useChunkedUpload() {
  const api = useApi()

  /**
   * Stage `file`, resolving to the upload id a send can claim it by.
   *
   * `onProgress` gets 0–1 as slices land. `signal` aborts mid-flight — the rejection is the
   * abort itself, and the caller is expected to follow it with {@link cancel} if it knows the
   * upload id (which {@link upload} reports through `onStart`).
   */
  async function upload(file: File, opts: {
    onProgress?: (fraction: number) => void
    onStart?: (id: string) => void
    signal?: AbortSignal
  } = {}): Promise<string> {
    const ceiling = maxUploadBytes()
    if (file.size > ceiling) {
      throw new Error(`That file is larger than the ${Math.round(ceiling / 1024 / 1024)}MB limit.`)
    }

    const total = Math.max(1, Math.ceil(file.size / CHUNK_BYTES))
    const started = await api<{ data: UploadState }>('/api/uploads', {
      method: 'POST',
      body: {
        name: file.name,
        size: file.size,
        mime_type: file.type || 'application/octet-stream',
        total_chunks: total,
      },
      signal: opts.signal,
    })

    const id = started.data.id
    opts.onStart?.(id)

    // `?? 0` rather than trust: an upload that reports nothing has received nothing, and
    // posting `index=null` as the first chunk is a 422 that reads like a client bug.
    let index = started.data.next_index ?? 0
    let attempts = 0

    while (index < total) {
      opts.signal?.throwIfAborted()

      const form = new FormData()
      form.append('index', String(index))
      // A name is required for the part to arrive as a file rather than a string field.
      form.append('chunk', file.slice(index * CHUNK_BYTES, (index + 1) * CHUNK_BYTES), 'chunk')

      try {
        const res = await api<{ data: UploadState }>(`/api/uploads/${id}/chunks`, {
          method: 'POST',
          body: form,
          signal: opts.signal,
        })
        // Same defensiveness as the opening cursor: the server names the next piece, but a
        // missing answer must not stall the loop on the slice it just accepted.
        index = res.data.next_index ?? index + 1
        attempts = 0
        opts.onProgress?.(res.data.received_chunks / total)
      } catch (e: any) {
        if (opts.signal?.aborted) throw e

        // The server disagrees about where we are — it's the authority, so follow it. This is
        // the resume path: a chunk that landed but whose response was lost ends up here.
        const status = e?.status ?? e?.statusCode
        if (status === 409 && typeof e?.data?.data?.next_index === 'number') {
          index = e.data.data.next_index
          attempts = 0
          opts.onProgress?.(index / total)
          continue
        }

        if (++attempts >= CHUNK_ATTEMPTS) throw e
        await new Promise(r => setTimeout(r, 400 * attempts)) // back off, then re-send the same slice
      }
    }

    return id
  }

  /** Bin a staged upload — a removed attachment, or one abandoned mid-transfer. */
  async function cancel(id: string) {
    try {
      await api(`/api/uploads/${id}`, { method: 'DELETE' })
    } catch {
      // Already gone, or never landed. The hourly prune is the backstop either way.
    }
  }

  return { upload, cancel }
}
