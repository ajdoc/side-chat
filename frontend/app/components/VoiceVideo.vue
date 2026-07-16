<script setup lang="ts">
/**
 * A <video> fed from a live MediaStream.
 *
 * `srcObject` is a *property*, not an attribute, so `:src-object="stream"` in a template
 * quietly does nothing (Vue stringifies it into an attribute the browser ignores). It has
 * to be assigned to the element itself.
 */
const props = defineProps<{
  stream: MediaStream | null
  /** Your own screen, played back to you — it must never make a sound. */
  muted?: boolean
  /**
   * `contain` for a screen: letterbox it, because cropping someone's shared terminal is
   * cropping the thing they're trying to show you. `cover` for a face, which is going into
   * a circle and would look absurd letterboxed inside one.
   */
  fit?: 'contain' | 'cover'
}>()

const el = ref<HTMLVideoElement | null>(null)

/**
 * Bind the stream to the element by hand — and do it on a schedule we control.
 *
 * The earlier version leaned on a `watchEffect` that read the template ref: null before
 * mount, so it relied on the ref *populating* to re-run the effect and set `srcObject`
 * for the first time. When that re-run doesn't land, nothing assigns the stream and the
 * <video> sits there autoplaying nothing — a silent black rectangle, camera and screen
 * share alike, including your own self-view, which never even goes near a peer connection.
 *
 * So bind explicitly instead: once on mount, when the element is guaranteed to exist, and
 * again whenever the stream itself changes (camera toggled, a new peer's track arrives).
 */
function bind() {
  if (!el.value) return

  el.value.srcObject = props.stream
  // Autoplay is only permitted for muted video, and the audio is coming through its own
  // element anyway — playing it here as well would double every voice.
  el.value.play().catch(() => {})
}

onMounted(bind)
watch(() => props.stream, bind)
</script>

<template>
  <video
    ref="el"
    autoplay
    playsinline
    muted
    class="h-full w-full bg-black"
    :class="props.fit === 'cover' ? 'object-cover' : 'object-contain'"
  />
</template>
