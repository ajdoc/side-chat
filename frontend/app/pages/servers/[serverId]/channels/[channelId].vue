<script setup lang="ts">
import { Hash, Info, MessagesSquare, Rocket, Volume2 } from 'lucide-vue-next'
import { Button } from '~/components/ui/button'

definePageMeta({ middleware: 'auth', layout: 'app' })

/**
 * A channel in a server.
 *
 * Almost nothing left here. The timeline, composer, threads, pins, reactions, read
 * receipts and typing all moved into ChannelView — which the DM/group page now uses too,
 * because a chat is a channel. What's left on this page is only what a *server* channel
 * has that a chat doesn't: a `#`, a Threads button, and the voice stage.
 */
const route = useRoute()
const { channels } = useServer()

const channelId = computed(() => Number(route.params.channelId))
const channel = computed(() => channels.value.find(c => c.id === channelId.value) ?? null)
const isVoice = computed(() => channel.value?.type === 'voice')

function openThreadsList() {
  navigateTo({ path: route.path, query: { threads: '1' } })
}
function openSideChatsList() {
  navigateTo({ path: route.path, query: { sidechats: '1' } })
}
function openInfo() {
  navigateTo({ path: route.path, query: { info: '1' } })
}
</script>

<template>
  <ChannelView
    v-if="channel"
    :key="channel.id"
    :channel="channel"
    :title="channel.name"
    :prefix="isVoice ? '' : '#'"
  >
    <template #icon>
      <Volume2 v-if="isVoice" class="h-5 w-5 shrink-0 text-muted-foreground" />
      <Hash v-else class="h-5 w-5 shrink-0 text-muted-foreground" />
    </template>

    <template #actions>
      <Button variant="ghost" size="sm" class="gap-2 text-muted-foreground" @click="openThreadsList">
        <MessagesSquare class="h-4 w-4" /> Threads
      </Button>
      <Button variant="ghost" size="sm" class="gap-2 text-muted-foreground" @click="openSideChatsList">
        <Rocket class="h-4 w-4" /> Side Chats
      </Button>
      <Button variant="ghost" size="sm" class="gap-2 text-muted-foreground" @click="openInfo">
        <Info class="h-4 w-4" /> Info
      </Button>
    </template>

    <!-- Text-in-voice: the call sits on top of the very same timeline every other channel
         has, and everything below it is unaware it's in a voice channel. -->
    <template v-if="isVoice" #call>
      <VoiceChannel :channel="channel" />
    </template>
  </ChannelView>
</template>
