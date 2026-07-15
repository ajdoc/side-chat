<script setup lang="ts">
import { Hash, Volume2 } from 'lucide-vue-next'
import type { ChannelType } from '~/types'
import { Button } from '~/components/ui/button'
import { Input } from '~/components/ui/input'
import { Label } from '~/components/ui/label'
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from '~/components/ui/card'

definePageMeta({ middleware: 'auth', layout: 'app' })

const route = useRoute()
const { createChannel } = useServer()
const serverId = computed(() => Number(route.params.serverId))

const type = ref<ChannelType>('text')
const name = ref('')
const error = ref('')
const loading = ref(false)

const channelTypes: { value: ChannelType, label: string, hint: string, icon: any }[] = [
  { value: 'text', label: 'Text', hint: 'Post messages, images, and links', icon: Hash },
  { value: 'voice', label: 'Voice', hint: 'Hang out together with voice', icon: Volume2 },
]

async function submit() {
  if (!name.value.trim()) return
  loading.value = true
  error.value = ''
  try {
    const channel = await createChannel(serverId.value, { name: name.value.trim(), type: type.value })
    await navigateTo(
      channel.type === 'text'
        ? `/servers/${serverId.value}/channels/${channel.id}`
        : `/servers/${serverId.value}`,
    )
  } catch (e: any) {
    error.value = e?.data?.message ?? 'Could not create the channel.'
  } finally {
    loading.value = false
  }
}

// Back to the server, which drops you into its first text channel — or, if this was going
// to be the first channel, into the empty state that offers to create one again.
function cancel() {
  navigateTo(`/servers/${serverId.value}`)
}
</script>

<template>
  <div class="grid flex-1 place-items-center p-6">
    <Card class="w-full max-w-md">
      <CardHeader>
        <CardTitle class="text-2xl">Create a channel</CardTitle>
        <CardDescription>Text channels are for messages; voice channels are for talking.</CardDescription>
      </CardHeader>

      <CardContent>
        <form class="space-y-5" @submit.prevent="submit">
          <div class="space-y-2">
            <Label>Channel type</Label>
            <div class="grid gap-2">
              <button
                v-for="t in channelTypes"
                :key="t.value"
                type="button"
                class="flex items-center gap-3 rounded-lg border p-3 text-left transition-colors"
                :class="type === t.value ? 'border-primary bg-muted' : 'hover:bg-muted/50'"
                @click="type = t.value"
              >
                <component :is="t.icon" class="h-5 w-5 text-muted-foreground" />
                <span>
                  <span class="block text-sm font-medium">{{ t.label }}</span>
                  <span class="block text-xs text-muted-foreground">{{ t.hint }}</span>
                </span>
              </button>
            </div>
          </div>

          <div class="space-y-2">
            <Label for="name">Channel name</Label>
            <Input id="name" v-model="name" placeholder="e.g. general" required autofocus />
          </div>

          <p v-if="error" class="text-sm text-destructive">{{ error }}</p>

          <div class="flex gap-2">
            <Button type="button" variant="outline" class="flex-1" :disabled="loading" @click="cancel">
              Cancel
            </Button>
            <Button type="submit" class="flex-1" :disabled="loading">
              {{ loading ? 'Creating…' : 'Create channel' }}
            </Button>
          </div>
        </form>
      </CardContent>
    </Card>
  </div>
</template>
