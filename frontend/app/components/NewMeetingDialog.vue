<script setup lang="ts">
import { Check, Copy, Loader2, Mic, Map as MapIcon } from 'lucide-vue-next'
import type { Meeting } from '~/types'
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '~/components/ui/dialog'
import { Button } from '~/components/ui/button'

/**
 * "New meeting" — a room, a link to it, and optionally a time.
 *
 * ## The link is the point
 *
 * Everything here exists to produce one address you can paste somewhere. So the dialog doesn't
 * close on success: it becomes the link, with a copy button and a way into the room. Closing and
 * making the person go find what they just made would be the one thing this flow must not do.
 *
 * ## Two decisions, both with a default
 *
 * **Voice or Side Space**, defaulting to voice, because that is what "a meeting" means to nearly
 * everybody — a Side Space is a deliberate choice about how it should feel. And **where it
 * lives**: a group chat (which anybody with the link can be let into) or a server room (which
 * only that server's people can reach). Everything else is optional.
 *
 * ## Why external guests and a server room can't be combined
 *
 * A link cannot admit anybody to a server — being in one is that server's people's decision, not
 * a link's. The API refuses the pair outright, so the toggle disables itself and says why rather
 * than letting somebody create a link that turns every stranger away.
 */
const open = defineModel<boolean>('open', { required: true })

const { create, rooms: fetchRooms, linkFor, roomPath } = useMeetings()

/**
 * Rooms that already exist.
 *
 * "Schedule something in the standup room we already have" was reachable only from inside that
 * room — the API always took a `channel_id`, but nothing listed the rooms, so the dialog could
 * only ever make a new one. Picking one here creates **no channel**: the meeting is a link (and
 * perhaps a time) pointing at what's already there.
 */
const existing = ref<{ id: number, name: string, type: string, server: string | null }[]>([])
const { servers } = useServers()

const title = ref('')
const type = ref<'voice' | 'space'>('voice')
/**
 * Where the meeting is held: `''` a new group chat, `s:<id>` a new room in that server, or
 * `c:<id>` a room that already exists. One control, because it is one question — and the shapes
 * differ only in what the API is told.
 */
const where = ref<string>('')
const scheduled = ref(false)
const startsAt = ref('')
const remind = ref('10')
/** How far open the door is — see Meeting::ACCESS. */
const access = ref<'members' | 'account' | 'guest'>('guest')

const busy = ref(false)
const error = ref('')
const made = ref<Meeting | null>(null)
const copied = ref(false)

/** Only servers you could create a channel in — the same bar the API holds this to. */
const staffServers = computed(() => servers.value.filter(s => s.is_owner || s.role === 'owner' || s.role === 'admin'))

const inServer = computed(() => where.value.startsWith('s:'))
/** An existing room: nothing is created, and its own kind decides voice or Side Space. */
const inExisting = computed(() => where.value.startsWith('c:'))

const chosenRoom = computed(() =>
  (inExisting.value ? existing.value.find(r => String(r.id) === where.value.slice(2)) : null) ?? null)

/**
 * An existing room that lives in a server is a server meeting in every way that matters: a link
 * still can't admit anybody to that server, so the door setting has to be hidden there too.
 */
const inExistingServerRoom = computed(() => !!chosenRoom.value?.server)

watch(open, (isOpen) => {
  if (!isOpen) return
  void fetchRooms().then((list) => { existing.value = list }).catch(() => { existing.value = [] })
  title.value = ''
  type.value = 'voice'
  where.value = ''
  scheduled.value = false
  startsAt.value = ''
  remind.value = '10'
  access.value = 'guest'
  made.value = null
  error.value = ''
}, { immediate: true })

async function submit() {
  if (!title.value.trim() || busy.value) return
  busy.value = true
  error.value = ''

  try {
    made.value = await create({
      title: title.value.trim(),
      type: type.value,
      server_id: inServer.value ? Number(where.value.slice(2)) : null,
      channel_id: inExisting.value ? Number(where.value.slice(2)) : null,
      starts_at: scheduled.value && startsAt.value ? new Date(startsAt.value).toISOString() : null,
      remind_minutes: scheduled.value ? Number(remind.value) : null,
      // Meaningless in a server room — and refused there — see the class comment. An existing
      // room is whatever it already is, so the same rule applies once it's in a server.
      access: inServer.value || inExistingServerRoom.value ? 'members' : access.value,
    })
  }
  catch (e: any) {
    error.value = e?.data?.message ?? 'That meeting couldn’t be created.'
  }
  finally {
    busy.value = false
  }
}

const link = computed(() => (made.value ? linkFor(made.value.token) : ''))

async function copy() {
  try {
    await navigator.clipboard.writeText(link.value)
    copied.value = true
    setTimeout(() => { copied.value = false }, 1500)
  }
  catch {
    // Clipboard refused (an insecure context, or a browser that asks). The field below is
    // selectable, so there is still a way to get the link.
  }
}

function openRoom() {
  if (!made.value) return
  open.value = false
  return navigateTo(roomPath(made.value))
}
</script>

<template>
  <Dialog v-model:open="open">
    <DialogContent class="max-w-md">
      <DialogHeader>
        <DialogTitle>{{ made ? 'Meeting ready' : 'New meeting' }}</DialogTitle>
        <DialogDescription>
          <template v-if="made">Share this link. Anyone who opens it signs in, then lands in the room.</template>
          <template v-else>A room and a link to it. Add a time if it isn’t starting now.</template>
        </DialogDescription>
      </DialogHeader>

      <!-- Made. The dialog becomes the link rather than closing over it. -->
      <div v-if="made" class="space-y-3">
        <div class="flex items-center gap-2">
          <input
            :value="link"
            readonly
            class="min-w-0 flex-1 rounded-md border bg-muted/40 px-2 py-1.5 font-mono text-xs"
            @focus="($event.target as HTMLInputElement).select()"
          >
          <Button size="sm" variant="outline" class="gap-1.5 shrink-0" @click="copy">
            <component :is="copied ? Check : Copy" class="h-3.5 w-3.5" />
            {{ copied ? 'Copied' : 'Copy' }}
          </Button>
        </div>

        <p class="text-xs text-muted-foreground">
          <template v-if="made.admits_guests">
            Anyone with this link can join — no account needed. Their arrival is recorded.
          </template>
          <template v-else-if="made.admits_outsiders">
            Anyone signed in can join, and will be added to this meeting’s group chat.
          </template>
          <template v-else-if="made.room?.server_id">
            Only people in that server can follow this link — a link can’t let anybody into a server.
          </template>
          <template v-else>
            Only people already in the chat can follow this link.
          </template>
        </p>

        <div class="flex gap-2">
          <Button size="sm" class="gap-1.5" @click="openRoom">Open the room</Button>
          <Button size="sm" variant="ghost" @click="open = false">Done</Button>
        </div>
      </div>

      <!-- Making one. -->
      <div v-else class="space-y-3">
        <label class="block space-y-1">
          <span class="text-xs font-medium">What's it called</span>
          <input
            v-model="title"
            placeholder="Design sync"
            class="w-full rounded-md border bg-background px-2 py-1.5 text-sm"
            @keyup.enter="submit"
          >
        </label>

        <!-- Voice or Side Space, as two buttons rather than a select: it's a choice about what
             the meeting *feels* like, and two options deserve to both be visible. Not asked at
             all for a room that already exists — it is whatever it already is. -->
        <div v-if="!inExisting" class="grid grid-cols-2 gap-2">
          <button
            v-for="option in ([
              { id: 'voice', label: 'Voice', hint: 'A call', icon: Mic },
              { id: 'space', label: 'Side Space', hint: 'A room you walk around', icon: MapIcon },
            ] as const)"
            :key="option.id"
            type="button"
            class="flex flex-col items-start gap-0.5 rounded-md border p-2.5 text-left transition-colors"
            :class="type === option.id ? 'border-primary bg-primary/5' : 'hover:bg-muted'"
            @click="type = option.id"
          >
            <span class="flex items-center gap-1.5 text-sm font-medium">
              <component :is="option.icon" class="h-3.5 w-3.5" /> {{ option.label }}
            </span>
            <span class="text-[11px] text-muted-foreground">{{ option.hint }}</span>
          </button>
        </div>

        <label class="block space-y-1">
          <span class="text-xs font-medium">Where it lives</span>
          <select v-model="where" class="w-full rounded-md border bg-background px-2 py-1.5 text-sm">
            <option value="">A new group chat — anyone with the link can be let in</option>
            <optgroup v-if="staffServers.length" label="A new room in a server">
              <option v-for="server in staffServers" :key="server.id" :value="`s:${server.id}`">
                {{ server.name }}
              </option>
            </optgroup>
            <!-- Rooms that already exist. Nothing is created: the meeting is a link (and perhaps
                 a time) pointing at what's already there. -->
            <optgroup v-if="existing.length" label="A room you already have">
              <option v-for="room in existing" :key="room.id" :value="`c:${room.id}`">
                {{ room.type === 'space' ? '🗺️' : '🔊' }} {{ room.name }}<template v-if="room.server"> · {{ room.server }}</template>
              </option>
            </optgroup>
          </select>
        </label>

        <!-- Three answers rather than a checkbox: "how far open is this door" genuinely has
             three, and a pair of booleans would have made a nonsense state expressible. -->
        <label v-if="!inServer && !inExistingServerRoom" class="block space-y-1">
          <span class="text-xs font-medium">Who can follow the link</span>
          <select v-model="access" class="w-full rounded-md border bg-background px-2 py-1.5 text-sm">
            <option value="guest">Anyone with the link — no account needed</option>
            <option value="account">Anyone signed in to Side Chat</option>
            <option value="members">Only people already in the chat</option>
          </select>
          <span class="text-[11px] text-muted-foreground">
            <template v-if="access === 'guest'">Guests type a name and join. Their arrival is recorded.</template>
            <template v-else-if="access === 'account'">They’ll be added to this meeting’s group chat.</template>
            <template v-else>The link is just the address — it won’t let anybody new in.</template>
          </span>
        </label>

        <p v-else class="text-[11px] text-muted-foreground">
          Everyone in that server can join. A link can’t let anybody *into* a server — use a group
          chat for people from outside.
        </p>

        <p v-if="inExisting && !inExistingServerRoom" class="text-[11px] text-muted-foreground">
          The meeting points at that room. Nothing new is created.
        </p>

        <label class="flex items-center gap-2 text-xs">
          <input v-model="scheduled" type="checkbox" class="h-3.5 w-3.5 accent-[var(--primary)]">
          Schedule it for later
        </label>

        <div v-if="scheduled" class="grid grid-cols-2 gap-2">
          <input v-model="startsAt" type="datetime-local" class="rounded-md border bg-background px-2 py-1.5 text-sm">
          <select v-model="remind" class="rounded-md border bg-background px-2 py-1.5 text-sm">
            <option value="0">Remind when it starts</option>
            <option value="5">5 minutes before</option>
            <option value="10">10 minutes before</option>
            <option value="30">30 minutes before</option>
            <option value="60">1 hour before</option>
          </select>
        </div>

        <p v-if="error" class="text-xs text-destructive">{{ error }}</p>

        <Button size="sm" :disabled="!title.trim() || busy" class="gap-1.5" @click="submit">
          <Loader2 v-if="busy" class="h-3.5 w-3.5 animate-spin" />
          Create meeting
        </Button>
      </div>
    </DialogContent>
  </Dialog>
</template>
