<script setup lang="ts">
import { Headphones, HeadphoneOff, Mic, MicOff, PhoneOff, ScreenShare, Signal, Video, VideoOff } from 'lucide-vue-next'

/**
 * "You're in a call" — pinned above your name in the sidebar.
 *
 * A call isn't a page. You should be able to wander off into a text channel, another
 * server, or a different chat entirely while you talk, and the audio has no business
 * stopping because the component that started it unmounted — which is exactly why useVoice
 * keeps the peer connections and audio elements in module scope, outside Vue's lifecycle.
 * This bar is what makes that visible: your mic, your camera, your headphones, and the way
 * out, wherever you happen to be.
 *
 * It has to name the place you're talking in, and that place is now one of two things: a
 * channel in a server, or a chat. Both are only a channel id as far as useVoice is
 * concerned, so this asks both lists and links to whichever one claims it.
 */
const { user } = useAuth()
const { server, channels } = useServer()
const { conversations } = useConversations()
const {
  channelId, status, peers, selfMuted, selfDeafened, isSharing, isCameraOn, inCall,
  pushToTalk, pttHeld, micOpen,
  toggleMute, toggleDeafen, toggleCamera, disconnect, holdTalk, releaseTalk,
} = useVoice()

/**
 * The push-to-talk key, listened for here because this bar is the one piece of call UI that
 * exists wherever you've wandered off to — the whole point of the mode is that it works while
 * you're reading another channel.
 *
 * Space is the key, but only when you aren't typing: in a composer, an input, or any
 * contenteditable it stays a space. A held key repeats, so `holdTalk` is written to ignore
 * repeats; and the window losing focus closes the mic, because a keyup that lands on another
 * window would otherwise never reach us and leave the line open.
 */
function isTyping(target: EventTarget | null): boolean {
  const el = target as HTMLElement | null
  if (!el?.tagName) return false
  return el.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(el.tagName)
}

function onKeyDown(e: KeyboardEvent) {
  if (e.code !== 'Space' || !inCall.value || !pushToTalk.value) return
  if (isTyping(e.target) || e.ctrlKey || e.metaKey || e.altKey) return
  e.preventDefault() // Space would otherwise scroll the page
  holdTalk()
}

function onKeyUp(e: KeyboardEvent) {
  if (e.code !== 'Space') return
  releaseTalk()
}

onMounted(() => {
  window.addEventListener('keydown', onKeyDown)
  window.addEventListener('keyup', onKeyUp)
  window.addEventListener('blur', releaseTalk)
})
onBeforeUnmount(() => {
  window.removeEventListener('keydown', onKeyDown)
  window.removeEventListener('keyup', onKeyUp)
  window.removeEventListener('blur', releaseTalk)
})

const channel = computed(() => channels.value.find(c => c.id === channelId.value) ?? null)
const conversation = computed(() =>
  conversations.value.find(c => c.channel_id === channelId.value) ?? null,
)

const label = computed(() => {
  if (conversation.value) return conversationTitle(conversation.value, user.value)

  return channel.value?.name ?? 'Call'
})

const link = computed(() => {
  if (conversation.value) return `/chats/${conversation.value.id}`
  if (server.value && channel.value) return `/servers/${server.value.id}/channels/${channel.value.id}`

  return null
})
</script>

<template>
  <div v-if="inCall" class="shrink-0 border-t bg-muted/40 p-2">
    <div class="flex items-center gap-2 px-1 pb-2">
      <Signal
        class="h-4 w-4 shrink-0"
        :class="status === 'connected' ? 'text-green-600 dark:text-green-400' : 'animate-pulse text-amber-500'"
      />
      <div class="min-w-0 flex-1">
        <p class="truncate text-xs font-semibold" :class="status === 'connected' ? 'text-green-600 dark:text-green-400' : 'text-amber-500'">
          {{ status === 'connected' ? 'Voice connected' : 'Connecting…' }}
        </p>
        <NuxtLink v-if="link" :to="link" class="block truncate text-xs text-muted-foreground hover:underline">
          {{ label }} · {{ peers.length + 1 }}
        </NuxtLink>
      </div>
      <ScreenShare v-if="isSharing" class="h-4 w-4 shrink-0 text-primary" title="You're sharing your screen" />
    </div>

    <!-- Push-to-talk needs to say, at a glance, whether the line is open right now — the mic
         button alone can't, since on this mode it's the key that decides. -->
    <p
      v-if="pushToTalk && !selfMuted"
      class="mb-1 truncate rounded px-1.5 py-0.5 text-center text-[11px] transition-colors"
      :class="pttHeld ? 'bg-green-600/15 font-medium text-green-600 dark:text-green-400' : 'text-muted-foreground'"
    >
      {{ pttHeld ? 'Talking…' : 'Hold Space to talk' }}
    </p>

    <div class="flex gap-1">
      <button
        type="button"
        class="flex flex-1 items-center justify-center rounded p-1.5 transition hover:bg-muted"
        :class="micOpen ? 'text-muted-foreground' : 'text-destructive'"
        :title="selfMuted ? 'Unmute' : pushToTalk ? 'Push-to-talk — hold Space' : 'Mute'"
        @click="toggleMute"
      >
        <MicOff v-if="!micOpen" class="h-4 w-4" />
        <Mic v-else class="h-4 w-4" />
      </button>
      <!--
        Reachable from anywhere, deliberately. The camera light stays on until you turn it
        off, and needing to navigate back to the call you wandered away from in order to do
        that is not an acceptable thing to ask of someone.
      -->
      <button
        type="button"
        class="flex flex-1 items-center justify-center rounded p-1.5 transition hover:bg-muted"
        :class="isCameraOn ? 'text-primary' : 'text-muted-foreground'"
        :title="isCameraOn ? 'Turn your camera off' : 'Turn your camera on'"
        @click="toggleCamera"
      >
        <Video v-if="isCameraOn" class="h-4 w-4" />
        <VideoOff v-else class="h-4 w-4" />
      </button>
      <button
        type="button"
        class="flex flex-1 items-center justify-center rounded p-1.5 transition hover:bg-muted"
        :class="selfDeafened ? 'text-destructive' : 'text-muted-foreground'"
        :title="selfDeafened ? 'Undeafen' : 'Deafen'"
        @click="toggleDeafen"
      >
        <HeadphoneOff v-if="selfDeafened" class="h-4 w-4" />
        <Headphones v-else class="h-4 w-4" />
      </button>
      <!-- Devices and screen-share quality — reachable wherever the call bar is. -->
      <VoiceSettings />
      <button
        type="button"
        class="flex flex-1 items-center justify-center rounded p-1.5 text-muted-foreground transition hover:bg-destructive/10 hover:text-destructive"
        title="Leave the call"
        @click="disconnect"
      >
        <PhoneOff class="h-4 w-4" />
      </button>
    </div>
  </div>
</template>
