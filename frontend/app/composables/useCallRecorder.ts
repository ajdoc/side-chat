import { CHUNK_THRESHOLD, maxUploadBytes, useChunkedUpload } from '~/composables/useChunkedUpload'

/**
 * Recording a call, in the browser that pressed the button.
 *
 * ## Why the client
 *
 * The server never has the audio. In a mesh call the streams go peer to peer and never touch it;
 * behind an SFU it forwards packets it doesn't decode. Recording server-side would mean a
 * media pipeline that mixes and encodes — a different kind of service with a different cost
 * model. The browser already has every stream decoded and playing, so the mix is a few nodes in
 * the graph that {@link useVoice} owns and the encode is `MediaRecorder`.
 *
 * The cost of that trade, stated plainly: **the recording is only as good as the recorder's
 * connection**, it stops if they close the tab, and it contains what *they* heard — somebody
 * whose audio never reached them isn't on it. A server-side recording would be authoritative;
 * this one is a good-faith copy made by a person in the room, which is what the announcement in
 * the timeline says it is.
 *
 * ## What the server does own
 *
 * The *fact* of it. Starting asks the API first, which enforces who may record, sets the flag
 * every client renders as a badge, and posts a line in the timeline. If that call is refused
 * nothing is captured — there is deliberately no path here that records without saying so.
 *
 * ## Audio only
 *
 * Screens are not captured. Mixing several video tracks into one canvas at a readable frame rate
 * is a different feature with its own performance budget, and a meeting's *audio* is the part
 * people go back to. Shared tab audio **is** in the mix, so a video played to the room is heard.
 */

/** Stop on our own at two hours: the blobs are held in memory until the upload starts. */
const MAX_MINUTES = 120

export function useCallRecorder() {
  const api = useApi()
  const voice = useVoice()
  const { upload } = useChunkedUpload()

  const recording = useState<boolean>('call-recorder:on', () => false)
  const startedAt = useState<number | null>('call-recorder:startedAt', () => null)
  const uploading = useState<boolean>('call-recorder:uploading', () => false)
  const error = useState<string | null>('call-recorder:error', () => null)
  /** Which channel is being recorded — a recording outlives navigation, like the call does. */
  const channelId = useState<number | null>('call-recorder:channelId', () => null)

  // Deliberately module-scope rather than reactive state: a MediaRecorder in a Vue proxy is a
  // MediaRecorder whose methods can throw, and nothing renders these.
  let recorder: MediaRecorder | null = null
  let chunks: Blob[] = []
  let bytes = 0
  let ticker: ReturnType<typeof setInterval> | undefined

  const elapsed = useState<number>('call-recorder:elapsed', () => 0)

  /** The codec everybody agrees on, or whatever the browser will give us. */
  function pickMime(): string | undefined {
    const candidates = ['audio/webm;codecs=opus', 'audio/webm', 'audio/mp4']
    return candidates.find(c => MediaRecorder.isTypeSupported?.(c))
  }

  async function start(id: number) {
    error.value = null

    if (recording.value || typeof MediaRecorder === 'undefined') {
      error.value = 'This browser can’t record.'
      return false
    }

    // The server first: it decides *whether*, and it is what tells the room. A capture that
    // began before this answered would be a recording nobody had been told about.
    try {
      await api(`/api/channels/${id}/voice/recording`, { method: 'POST', body: { recording: true } })
    }
    catch (e: any) {
      error.value = e?.data?.message ?? 'You can’t record this call.'
      return false
    }

    const stream = voice.startRecordingMix()

    if (!stream) {
      // Told the room we were recording and then found nothing to record. Take it back rather
      // than leaving a badge lit over silence.
      await api(`/api/channels/${id}/voice/recording`, { method: 'POST', body: { recording: false } })
      error.value = 'Join the call before recording it.'
      return false
    }

    const mime = pickMime()
    recorder = new MediaRecorder(stream, mime ? { mimeType: mime } : undefined)
    chunks = []
    bytes = 0

    recorder.ondataavailable = (event) => {
      if (!event.data.size) return
      chunks.push(event.data)
      bytes += event.data.size

      // Stop *before* the ceiling rather than failing at the upload: an hour of audio nobody can
      // send is the worst possible outcome of pressing record.
      if (bytes > maxUploadBytes() * 0.95) {
        error.value = 'Stopped — the recording reached the maximum file size.'
        void stop()
      }
    }

    // A timeslice, so the blob arrives in pieces and a crash loses seconds rather than the lot.
    recorder.start(5000)

    recording.value = true
    channelId.value = id
    startedAt.value = Date.now()
    elapsed.value = 0
    ticker = setInterval(() => {
      elapsed.value = Math.round((Date.now() - (startedAt.value ?? Date.now())) / 1000)
      if (elapsed.value >= MAX_MINUTES * 60) void stop()
    }, 1000)

    return true
  }

  /**
   * Stop, then upload and post.
   *
   * The flag is cleared as soon as capture ends rather than after the upload: the room's badge
   * answers "is this being recorded *now*", and a file still going up is not. The timeline line
   * that the server posts on stop says the file is coming, which is the honest version of the
   * wait.
   */
  async function stop() {
    const id = channelId.value

    if (!recorder || !id) return

    const done = new Promise<void>((resolve) => {
      recorder!.onstop = () => resolve()
    })

    try {
      recorder.stop()
      await done
    }
    finally {
      clearInterval(ticker)
      voice.stopRecordingMix()
      recorder = null
      recording.value = false
      startedAt.value = null
    }

    await api(`/api/channels/${id}/voice/recording`, { method: 'POST', body: { recording: false } })

    const blob = new Blob(chunks, { type: chunks[0]?.type || 'audio/webm' })
    chunks = []

    // A recording of nothing. Happens if you stop within a second of starting; posting an empty
    // file would be worse than saying nothing.
    if (blob.size < 1024) return

    await post(id, blob)
  }

  /** Put the finished file in the channel, as a message anyone can play. */
  async function post(id: number, blob: Blob) {
    uploading.value = true

    try {
      const stamp = new Date().toLocaleString([], { dateStyle: 'medium', timeStyle: 'short' })
      const name = `Call recording — ${stamp}.${blob.type.includes('mp4') ? 'm4a' : 'webm'}`
      const file = new File([blob], name, { type: blob.type })
      const minutes = Math.max(1, Math.round(elapsed.value / 60))
      const body = `🔴 Recording of this call — about ${minutes} minute${minutes === 1 ? '' : 's'}.`

      // The same two routes the composer uses, chosen by size: a long recording goes up in
      // slices and the message carries only its id.
      if (file.size > CHUNK_THRESHOLD) {
        const uploadId = await upload(file)
        await api(`/api/channels/${id}/messages`, { method: 'POST', body: { body, uploads: [uploadId] } })
      }
      else {
        const form = new FormData()
        form.append('body', body)
        form.append('attachments[]', file)
        await api(`/api/channels/${id}/messages`, { method: 'POST', body: form })
      }
    }
    catch (e: any) {
      error.value = e?.data?.message ?? 'The recording couldn’t be uploaded.'
    }
    finally {
      uploading.value = false
      channelId.value = null
    }
  }

  /** mm:ss, for the button. */
  const label = computed(() => {
    const m = Math.floor(elapsed.value / 60)
    const s = elapsed.value % 60
    return `${m}:${String(s).padStart(2, '0')}`
  })

  return { recording, uploading, error, elapsed, label, channelId, start, stop }
}
