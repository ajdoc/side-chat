<script setup lang="ts">
import { Mic, Square, X } from 'lucide-vue-next'

/**
 * Record a voice message, right in the composer.
 *
 * It hands back a plain {@link File}, which is the whole trick: a voice note is an ordinary
 * attachment, so it travels the path files already travel — the same upload, the same 20MB
 * ceiling, the same appearance in Info → Files — and needs nothing new on the server. The
 * composer drops it into its pending list rather than sending on the spot, so you can hear
 * what you're about to send, add a line of text to it, or throw it away.
 *
 * Opus in WebM where the browser has it (small enough that minutes of speech stay well inside
 * the attachment limit), falling back through the containers Safari prefers. Recording stops
 * itself at {@link MAX_SECONDS} — a voice *message* that runs longer than that wanted to be a
 * call.
 */
const props = defineProps<{ disabled?: boolean }>()
const emit = defineEmits<{ recorded: [File] }>()

const MAX_SECONDS = 300

const recording = ref(false)
const seconds = ref(0)
const error = ref('')
// Decided on the client, after mount: MediaRecorder doesn't exist during SSR, and a browser
// without it should show no button at all rather than one that fails when pressed.
const supported = ref(false)
onMounted(() => {
  supported.value = typeof MediaRecorder !== 'undefined' && !!navigator.mediaDevices?.getUserMedia
})

let recorder: MediaRecorder | null = null
let stream: MediaStream | null = null
let chunks: BlobPart[] = []
let ticker: ReturnType<typeof setInterval> | undefined
// Set when you bin the recording, so the stop handler knows not to hand anything back.
let discarded = false

/** The best container this browser will actually record, and the extension to name it with. */
function pickFormat(): { mime: string, ext: string } {
  const candidates = [
    { mime: 'audio/webm;codecs=opus', ext: 'webm' },
    { mime: 'audio/webm', ext: 'webm' },
    { mime: 'audio/ogg;codecs=opus', ext: 'ogg' },
    { mime: 'audio/mp4', ext: 'm4a' },
  ]
  const supported = candidates.find(c => MediaRecorder.isTypeSupported?.(c.mime))
  // No match means the browser only offers its own default — let it choose, and call the
  // result webm, which is what every engine that gets here actually produces.
  return supported ?? { mime: '', ext: 'webm' }
}

function fmt(total: number) {
  const m = Math.floor(total / 60)
  const s = total % 60
  return `${m}:${String(s).padStart(2, '0')}`
}

async function start() {
  if (props.disabled || recording.value) return
  error.value = ''
  try {
    stream = await navigator.mediaDevices.getUserMedia({ audio: true })
  } catch {
    error.value = 'No microphone'
    return
  }

  const { mime, ext } = pickFormat()
  chunks = []
  discarded = false
  recorder = new MediaRecorder(stream, mime ? { mimeType: mime } : undefined)

  recorder.ondataavailable = (e) => {
    if (e.data.size) chunks.push(e.data)
  }
  recorder.onstop = () => {
    const type = recorder?.mimeType || mime || 'audio/webm'
    const blob = new Blob(chunks, { type })
    cleanup()
    if (discarded || !blob.size) return
    // Timestamped so a thread full of voice notes doesn't read as one file over and over.
    const stamp = new Date().toISOString().slice(0, 16).replace(/[:T]/g, '-')
    emit('recorded', new File([blob], `voice-message-${stamp}.${ext}`, { type }))
  }

  recorder.start()
  recording.value = true
  seconds.value = 0
  ticker = setInterval(() => {
    seconds.value++
    if (seconds.value >= MAX_SECONDS) stop()
  }, 1000)
}

function stop() {
  if (!recording.value) return
  recorder?.stop() // the rest happens in onstop, which fires for both stop and discard
}

function discard() {
  discarded = true
  stop()
}

/** Drop the timer and, crucially, the mic: a live capture leaves the browser's recording dot on. */
function cleanup() {
  clearInterval(ticker)
  ticker = undefined
  stream?.getTracks().forEach(t => t.stop())
  stream = null
  recorder = null
  recording.value = false
}

onBeforeUnmount(() => {
  discarded = true
  if (recording.value) recorder?.stop()
  else cleanup()
})
</script>

<template>
  <!-- Idle: one button, sitting with the other composer tools. -->
  <button
    v-if="supported && !recording"
    type="button"
    tabindex="-1"
    class="rounded p-1.5 text-muted-foreground hover:bg-muted hover:text-foreground disabled:opacity-40"
    :title="error || 'Record a voice message'"
    :disabled="disabled"
    @click="start"
  >
    <Mic class="h-3.5 w-3.5" :class="error ? 'text-destructive' : ''" />
  </button>

  <!-- Recording: the elapsed time, and the two ways out of it. -->
  <span v-else-if="recording" class="flex items-center gap-1.5 rounded bg-destructive/10 px-1.5 py-0.5">
    <span class="h-2 w-2 animate-pulse rounded-full bg-destructive" />
    <span class="font-mono text-xs tabular-nums text-destructive">{{ fmt(seconds) }}</span>
    <button
      type="button"
      tabindex="-1"
      class="rounded p-0.5 text-muted-foreground hover:text-foreground"
      title="Stop and attach"
      @click="stop"
    >
      <Square class="h-3.5 w-3.5" />
    </button>
    <button
      type="button"
      tabindex="-1"
      class="rounded p-0.5 text-muted-foreground hover:text-destructive"
      title="Discard"
      @click="discard"
    >
      <X class="h-3.5 w-3.5" />
    </button>
  </span>
</template>
