<script setup lang="ts">
import { Hash, Map as MapIcon, Volume2 } from 'lucide-vue-next'
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
const api = useApi()
const { createChannel } = useServer()
const serverId = computed(() => Number(route.params.serverId))

/** A room you can start a Side Space as. The server owns these; we only draw them. */
interface MapPreset {
  key: string
  label: string
  description: string
  width: number
  height: number
  tiles: string[]
}

const type = ref<ChannelType>('text')
const name = ref('')
const preset = ref('office')
const presets = ref<MapPreset[]>([])
const error = ref('')
const loading = ref(false)

const channelTypes: { value: ChannelType, label: string, hint: string, icon: any }[] = [
  { value: 'text', label: 'Text', hint: 'Post messages, images, and links', icon: Hash },
  { value: 'voice', label: 'Voice', hint: 'Hang out together with voice', icon: Volume2 },
  { value: 'space', label: 'Side Space', hint: 'A room you walk around — you hear whoever is near you', icon: MapIcon },
]

/**
 * Fetched rather than hardcoded, so the picker can't drift from the rooms the server will
 * actually build. Only when it's needed — most channels aren't Side Spaces.
 */
async function loadPresets() {
  if (presets.value.length) return

  try {
    const res = await api<{ data: MapPreset[] }>('/api/space/map-presets')
    presets.value = res.data
    preset.value = res.data[0]?.key ?? 'office'
  } catch {
    error.value = 'Could not load the room layouts.'
  }
}

watch(type, (t) => {
  if (t === 'space') loadPresets()
})

async function submit() {
  if (!name.value.trim()) return
  loading.value = true
  error.value = ''
  try {
    const channel = await createChannel(serverId.value, {
      name: name.value.trim(),
      type: type.value,
      // Only sent for a Side Space; the API requires it there and refuses it being absent.
      ...(type.value === 'space' ? { preset: preset.value } : {}),
    })
    await navigateTo(
      // A voice channel is somewhere you *join*, so creating one drops you back at the server
      // and you click into it. A text channel and a Side Space are both places you're now in.
      channel.type === 'voice'
        ? `/servers/${serverId.value}`
        : `/servers/${serverId.value}/channels/${channel.id}`,
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
  <div class="grid flex-1 place-items-center overflow-y-auto p-6">
    <Card class="w-full max-w-md">
      <CardHeader>
        <CardTitle class="text-2xl">Create a channel</CardTitle>
        <CardDescription>
          Text channels are for messages, voice channels for talking, and a Side Space is a room
          you walk around in.
        </CardDescription>
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
                <component :is="t.icon" class="h-5 w-5 shrink-0 text-muted-foreground" />
                <span class="min-w-0">
                  <span class="flex items-center gap-1.5">
                    <span class="text-sm font-medium">{{ t.label }}</span>
                    <AlphaBadge v-if="t.value === 'space'" />
                  </span>
                  <span class="block text-xs text-muted-foreground">{{ t.hint }}</span>
                </span>
              </button>
            </div>
          </div>

          <!-- Which room to start from. A real thumbnail of the grid, not an illustration —
               the tiles come from the same preset the server will seed the map with. -->
          <div v-if="type === 'space'" class="space-y-2">
            <Label>Starting layout</Label>
            <div class="grid grid-cols-2 gap-2">
              <button
                v-for="p in presets"
                :key="p.key"
                type="button"
                class="space-y-1.5 rounded-lg border p-2 text-left transition-colors"
                :class="preset === p.key ? 'border-primary bg-muted' : 'hover:bg-muted/50'"
                @click="preset = p.key"
              >
                <SideSpaceMapThumbnail :tiles="p.tiles" :width="p.width" :height="p.height" />
                <span class="block text-xs font-medium">{{ p.label }}</span>
                <span class="block text-[11px] leading-snug text-muted-foreground">{{ p.description }}</span>
              </button>
            </div>
            <p class="text-[11px] text-muted-foreground">
              You can rebuild the room later — walls, rooms and all.
            </p>
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
