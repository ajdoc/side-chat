/**
 * Send a large file up out-of-band, rather than inside the message that carries it.
 *
 * An ordinary attachment rides along inside the send request, which caps it at whatever PHP,
 * the web server and every proxy in between will carry in one body — and stakes the whole
 * transfer on both the connection *and* the server container surviving to the end. Past
 * {@link CHUNK_THRESHOLD} the composer routes a file through here instead, and the send that
 * follows carries only the upload's id ({@see App\Http\Controllers\ChunkedUploadController}).
 *
 * The server decides how the bytes should travel and says so in `mode` when the upload is
 * opened, because only it knows what disk it stores on:
 *
 *  - `direct` — one PUT straight to a signed object-store URL, then a `complete` call. The
 *    bytes never touch the API, so no body limit or request timeout applies and a container
 *    being recycled mid-transfer can't destroy the upload.
 *  - `chunked` — the file is cut into {@link CHUNK_BYTES} slices and posted one at a time.
 *    Slices go up strictly in order and the server answers each with the index it wants next,
 *    which turns a dropped chunk into a resume rather than a restart: a failure retries the
 *    same slice, and a server that says "I already have that one" (409) moves the cursor.
 *
 * Cancelling aborts the request in flight *and* tells the server to bin the part-file — the
 * staging area is swept hourly, but not leaving litter is cheaper than sweeping it.
 */

/**
 * Files at or below this go the ordinary route, inside the send request.
 *
 * Kept small deliberately. Anything sent inline is subject to every body limit and timeout
 * between here and the app, and a message may carry ten of them at once — so the ceiling that
 * matters is ten times this number, not this number. Staging is cheap; a 503 halfway through
 * a send is not.
 */
export const CHUNK_THRESHOLD = 2 * 1024 * 1024

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
  /** Which shape of transfer the server wants — it decides, based on the disk it stores on. */
  mode: 'direct' | 'chunked'
  received_chunks: number
  total_chunks: number
  /** The piece the server wants next — the resume point. Chunked mode only. */
  next_index: number | null
  completed: boolean
  /** Direct mode: the signed URL to PUT the whole file to, and the headers it was signed with. */
  url?: string
  headers?: Record<string, string>
}

interface UploadOptions {
  onProgress?: (fraction: number) => void
  onStart?: (id: string) => void
  signal?: AbortSignal
}

export function useChunkedUpload() {
  const api = useApi()

  /**
   * Stage `file`, resolving to the upload id a send can claim it by.
   *
   * `onProgress` gets 0–1 as the transfer lands. `signal` aborts mid-flight — the rejection is
   * the abort itself, and the caller is expected to follow it with {@link cancel} if it knows
   * the upload id (which {@link upload} reports through `onStart`).
   */
  async function upload(file: File, opts: UploadOptions = {}): Promise<string> {
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

    if (started.data.mode === 'direct' && started.data.url) {
      await putDirect(file, started.data.url, started.data.headers ?? {}, opts)
      await api(`/api/uploads/${id}/complete`, { method: 'POST', signal: opts.signal })

      return id
    }

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

  /**
   * Send the whole file to a signed URL in one PUT — the object-store path.
   *
   * XHR rather than `fetch`, purely for progress: `fetch` reports nothing until a request body
   * has finished going out, which for the large files this path exists to carry means a bar
   * frozen at zero for the entire upload. XHR emits `upload.progress` throughout.
   *
   * The request goes straight to the bucket, so it carries none of our session — no bearer
   * token, no cookies (`withCredentials` stays off). The signature in the URL is the whole
   * grant, and sending credentials to a third-party origin would only invite a CORS rejection.
   * Headers are echoed from the server because they're covered by that signature.
   */
  function putDirect(
    file: File,
    url: string,
    headers: Record<string, string>,
    opts: UploadOptions,
  ): Promise<void> {
    return new Promise((resolve, reject) => {
      const xhr = new XMLHttpRequest()

      const onAbort = () => xhr.abort()
      opts.signal?.addEventListener('abort', onAbort, { once: true })
      const done = () => opts.signal?.removeEventListener('abort', onAbort)

      xhr.open('PUT', url, true)
      for (const [key, value] of Object.entries(headers)) xhr.setRequestHeader(key, value)

      xhr.upload.onprogress = (e) => {
        if (e.lengthComputable) opts.onProgress?.(e.loaded / e.total)
      }

      xhr.onload = () => {
        done()
        // The store answers 2xx and an empty body on success. Anything else is a failed upload
        // however it's dressed up, and must not be reported to the server as complete.
        if (xhr.status >= 200 && xhr.status < 300) resolve()
        else reject(new Error(`Upload failed (${xhr.status}).`))
      }

      // A network-level failure here is very often CORS: the bucket has to allow PUT from this
      // origin, and the browser reports that refusal as an indistinguishable zero-status error.
      xhr.onerror = () => {
        done()
        reject(new Error('Upload failed — the storage service could not be reached.'))
      }

      xhr.onabort = () => {
        done()
        reject(opts.signal?.reason ?? new DOMException('Aborted', 'AbortError'))
      }

      xhr.send(file)
    })
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
