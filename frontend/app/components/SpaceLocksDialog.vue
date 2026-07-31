<script setup lang="ts">
import { DoorClosed, KeyRound, Loader2, Lock, LockOpen, Users, X } from 'lucide-vue-next'
import type { SpaceMap } from '~/lib/spaceMapEngine'
import { decorKind } from '~/lib/spaceDecor'
import { Button } from '~/components/ui/button'

/**
 * Who is in charge of which room, and which doors are shut.
 *
 * One panel for both because they are one subject: a room owner exists in order to lock their
 * room's doors, and a lock exists because somebody is responsible for what's behind it. Splitting
 * them would mean appointing somebody in one place and discovering what you'd given them in
 * another.
 *
 * ## Two audiences, one screen
 *
 * The scoping is the server's, not this component's — it draws what it was handed. But the shape
 * of what it's handed is worth stating, because it's the feature:
 *
 *   - the **server's owner** gets the Rooms tab (appointing is theirs alone) and a Locks list
 *     containing every lock in the space, whoever set it.
 *   - a **room owner** gets no Rooms tab and a Locks list of the locks *they* set. Not every lock
 *     in their room: a lock the server owner put on their door isn't theirs to manage, and
 *     listing it would only offer a button that fails.
 *   - **anybody else** never sees the button that opens this.
 *
 * ## Why doors are listed from the map
 *
 * The Locks tab shows locks; the "lock a door" half has to show *doors*, and those live in the
 * map rather than in any list the server keeps. So the door list is computed from the same
 * `map.objects` the room is drawn from, filtered to the rooms this person may administer — which
 * is also why a door in open ground only appears for the server's owner.
 */
const props = defineProps<{ channelId: number, map: SpaceMap, members: Array<{ id: number, name: string }> }>()
const emit = defineEmits<{ close: [] }>()

const { locks, canManageRooms, myRooms, loading, error, load, lock, unlock, assignRoom } = useSpaceLocks(props.channelId)
const { nameFor } = useNicknames()

type Tab = 'locks' | 'rooms'
const tab = ref<Tab>('locks')
const busy = ref('')

onMounted(load)

/** Locks by door, so the door list can show what's already on each. */
const lockByDoor = computed(() => new Map(locks.value.map(l => [l.object_id, l])))

/**
 * Which room a door belongs to, worked out the way the server works it out: the zone under it,
 * else the zone under a tile it touches.
 *
 * Mirrored here rather than sent per-door, for the same reason the collision grid is mirrored —
 * the panel needs it for every door the moment it opens, and the rule is six lines. If the two
 * ever disagree the server wins: it refuses the lock, and the button says so.
 */
function zoneOf(x: number, y: number): string | null {
  for (const z of props.map.zones ?? []) {
    if (x >= z.x && x < z.x + z.w && y >= z.y && y < z.y + z.h) return z.id
  }

  return null
}

function roomFor(door: { x: number, y: number }): string | null {
  return zoneOf(door.x, door.y)
    ?? zoneOf(door.x, door.y - 1)
    ?? zoneOf(door.x, door.y + 1)
    ?? zoneOf(door.x - 1, door.y)
    ?? zoneOf(door.x + 1, door.y)
}

const zoneNames = computed(() => new Map((props.map.zones ?? []).map(z => [z.id, z.name])))

/** Every door this person may lock — theirs to administer, and still on the map. */
const doors = computed(() =>
  (props.map.objects ?? [])
    .filter(o => decorKind(o.kind)?.door)
    .map(o => ({ object: o, zone: roomFor(o) }))
    .filter(d => canManageRooms.value || (d.zone !== null && myRooms.value.includes(d.zone)))
    .map(d => ({
      ...d,
      label: decorKind(d.object.kind)?.label ?? 'Door',
      room: d.zone ? zoneNames.value.get(d.zone) ?? 'a room' : null,
      lock: lockByDoor.value.get(d.object.id) ?? null,
    })),
)

/**
 * The rooms, each with everybody responsible for it. Server owner's tab.
 *
 * `map.rooms` arrives as one row per person per room — a room with three owners appears three
 * times — so the grouping happens here rather than being a second shape on the wire for the same
 * fact.
 */
const rooms = computed(() =>
  (props.map.zones ?? []).map(z => ({
    ...z,
    owners: (props.map.rooms ?? []).filter(r => r.zone_id === z.id).map(r => r.owner_id).filter((id): id is number => id != null),
  })),
)

/** Add or remove one person from a room's owners, sending the whole set back. */
function toggleOwner(zoneId: string, userId: number) {
  const current = rooms.value.find(r => r.id === zoneId)?.owners ?? []
  const next = current.includes(userId) ? current.filter(id => id !== userId) : [...current, userId]

  return run(`${zoneId}:${userId}`, () => assignRoom(zoneId, next))
}

async function run(key: string, action: () => Promise<unknown>) {
  if (busy.value) return
  busy.value = key
  try {
    await action()
  } catch (e: any) {
    error.value = e?.data?.message ?? 'That did not work.'
  } finally {
    busy.value = ''
  }
}

/**
 * Locking a door hands out no keys to begin with.
 *
 * Deliberate: the people who can always pass — whoever set it, and whoever is in charge of the
 * room — are resolved server-side and never need adding, so an empty list is already a working
 * lock rather than one that shuts its own author out. Keys are given afterwards, one at a time.
 *
 * The server's owner is *not* among them. They can unlock any door here, which is visible and
 * undoable; walking silently through one is neither. See Doors::keyholders.
 */
const onLock = (id: string) => run(id, () => lock(id, []))
const onUnlock = (id: string) => run(id, () => unlock(id))

/**
 * Add or remove one person's key.
 *
 * Edits `granted` — the keys actually stored on the lock — and never `allowed`, which also
 * contains the people who pass without a key. Sending the resolved list back would write the
 * room's current owners into the row, where they'd stay after the room changed hands.
 */
function toggleKey(objectId: string, userId: number) {
  const row = lockByDoor.value.get(objectId)
  if (!row) return

  const next = row.granted.includes(userId)
    ? row.granted.filter(id => id !== userId)
    : [...row.granted, userId]

  return run(`${objectId}:${userId}`, () => lock(objectId, next))
}

/** Whether they hold a key we could take away, as opposed to passing for who they are. */
const hasKey = (objectId: string, userId: number) =>
  !!lockByDoor.value.get(objectId)?.granted.includes(userId)

/**
 * The password box, per door — what's typed, not what's stored.
 *
 * There is nothing to prefill it with, and that's deliberate rather than an omission: the phrase
 * is only ever kept as a hash, so even the person who set it can't be shown it. What the panel
 * can say is whether there *is* one and how many people have used it, which is what the line
 * under the box says. So the control is always "set a new one", never "edit the current one".
 */
const passwords = ref<Record<string, string>>({})

/** Set or replace a door's password — which also forgets everybody who entered the old one. */
function savePassword(objectId: string) {
  const row = lockByDoor.value.get(objectId)
  const phrase = (passwords.value[objectId] ?? '').trim()
  if (!row || phrase.length < 4) return

  return run(`${objectId}:password`, async () => {
    await lock(objectId, row.granted, phrase)
    passwords.value[objectId] = ''
  })
}

/** Take the password off. The door goes back to being a list of people. */
function clearPassword(objectId: string) {
  const row = lockByDoor.value.get(objectId)
  if (!row) return

  return run(`${objectId}:password`, () => lock(objectId, row.granted, null))
}

/** Whether they come through regardless — the room's owners, and whoever set the lock. */
const passesAnyway = (objectId: string, userId: number) =>
  !hasKey(objectId, userId) && !!lockByDoor.value.get(objectId)?.allowed.some(a => a.id === userId)
</script>

<template>
  <div class="fixed inset-0 z-50 grid place-items-center bg-black/50 p-4" @click.self="emit('close')">
    <div class="flex max-h-[85vh] w-full max-w-2xl flex-col overflow-hidden rounded-lg border bg-background shadow-xl">
      <header class="flex h-12 shrink-0 items-center justify-between border-b px-4">
        <span class="flex items-center gap-2 font-semibold">
          <Lock class="h-4 w-4" /> Rooms &amp; locks
        </span>
        <button class="rounded p-1 text-muted-foreground hover:text-foreground" aria-label="Close" @click="emit('close')">
          <X class="h-4 w-4" />
        </button>
      </header>

      <!-- The Rooms tab only exists for the server's owner, so the strip is a single label for
           everybody else rather than a tab bar with one disabled half. -->
      <div v-if="canManageRooms" class="flex shrink-0 gap-1 border-b px-3 py-2">
        <button
          v-for="t in (['locks', 'rooms'] as Tab[])"
          :key="t"
          type="button"
          class="rounded px-2.5 py-1 text-sm transition-colors"
          :class="tab === t ? 'bg-muted font-medium' : 'text-muted-foreground hover:bg-muted/50'"
          @click="tab = t"
        >
          {{ t === 'locks' ? 'Doors' : 'Rooms' }}
        </button>
      </div>

      <div class="min-h-0 flex-1 overflow-y-auto p-4">
        <p v-if="error" class="mb-3 rounded border border-destructive/40 bg-destructive/10 px-3 py-2 text-sm text-destructive">
          {{ error }}
        </p>

        <div v-if="loading" class="grid place-items-center py-10">
          <Loader2 class="h-5 w-5 animate-spin text-muted-foreground" />
        </div>

        <!-- Doors -->
        <div v-else-if="tab === 'locks'" class="space-y-4">
          <p class="text-xs text-muted-foreground">
            A locked door still opens — just not for everyone. You and anyone in charge of the room
            can always come through; anybody else needs a key, or the password if you set one.
          </p>

          <p v-if="!doors.length" class="rounded border border-dashed px-3 py-6 text-center text-sm text-muted-foreground">
            No doors you can lock. Put a door in a room's wall in the editor, and it'll show up here.
          </p>

          <div v-for="d in doors" :key="d.object.id" class="rounded-md border p-3">
            <div class="flex items-center gap-2">
              <DoorClosed class="h-4 w-4 shrink-0 text-muted-foreground" />
              <span class="text-sm font-medium">{{ d.label }}</span>
              <span class="text-xs text-muted-foreground">
                {{ d.room ? `— ${d.room}` : '— out in the open' }}
              </span>
              <Button
                size="sm"
                :variant="d.lock ? 'outline' : 'default'"
                class="ml-auto gap-1.5"
                :disabled="!!busy"
                @click="d.lock ? onUnlock(d.object.id) : onLock(d.object.id)"
              >
                <Loader2 v-if="busy === d.object.id" class="h-3.5 w-3.5 animate-spin" />
                <component :is="d.lock ? LockOpen : Lock" v-else class="h-3.5 w-3.5" />
                {{ d.lock ? 'Unlock' : 'Lock' }}
              </Button>
            </div>

            <!-- Keys. Only meaningful once the door is locked, so it isn't offered before. -->
            <div v-if="d.lock" class="mt-3 border-t pt-3">
              <p class="mb-2 flex items-center gap-1.5 text-xs font-medium text-muted-foreground">
                <Users class="h-3.5 w-3.5" /> Who can come through
              </p>
              <div class="flex flex-wrap gap-1.5">
                <!-- Three states, not two. Somebody who comes through anyway (they're in charge
                     of the room, or they set the lock) is shown as included but not removable —
                     offering a toggle that can't change anything is worse than showing why. -->
                <button
                  v-for="m in members"
                  :key="m.id"
                  type="button"
                  class="rounded-full border px-2.5 py-1 text-xs transition-colors disabled:opacity-50"
                  :class="hasKey(d.object.id, m.id)
                    ? 'border-primary bg-primary/10 font-medium'
                    : passesAnyway(d.object.id, m.id)
                      ? 'border-dashed border-primary/50 text-muted-foreground'
                      : 'hover:bg-muted'"
                  :disabled="!!busy || passesAnyway(d.object.id, m.id)"
                  :title="passesAnyway(d.object.id, m.id) ? 'Comes through anyway — in charge of this room, or set the lock' : undefined"
                  @click="toggleKey(d.object.id, m.id)"
                >
                  {{ nameFor(m) }}
                </button>
              </div>
              <!--
                A password, which is the other way in.

                Under the key list rather than beside it, because it's the same question asked
                the other way round: keys are for people you can name, a password is for people
                you can't. Never prefilled — the phrase is stored hashed, so "change it" is the
                only offer that can honestly be made.
              -->
              <div class="mt-3 border-t pt-3">
                <p class="mb-2 flex items-center gap-1.5 text-xs font-medium text-muted-foreground">
                  <KeyRound class="h-3.5 w-3.5" /> Password
                </p>
                <div class="flex flex-wrap items-center gap-2">
                  <input
                    v-model="passwords[d.object.id]"
                    type="password"
                    autocomplete="off"
                    :placeholder="d.lock.has_password ? 'Set a new password' : 'No password — set one'"
                    class="min-w-0 flex-1 rounded-md border bg-background px-2.5 py-1.5 text-sm outline-none focus:ring-2 focus:ring-ring"
                    @keydown.enter.prevent="savePassword(d.object.id)"
                  >
                  <Button
                    size="sm"
                    :disabled="!!busy || (passwords[d.object.id] ?? '').trim().length < 4"
                    @click="savePassword(d.object.id)"
                  >
                    <Loader2 v-if="busy === `${d.object.id}:password`" class="mr-1.5 h-3.5 w-3.5 animate-spin" />
                    {{ d.lock.has_password ? 'Change' : 'Set' }}
                  </Button>
                  <Button
                    v-if="d.lock.has_password"
                    size="sm"
                    variant="outline"
                    :disabled="!!busy"
                    @click="clearPassword(d.object.id)"
                  >
                    Remove
                  </Button>
                </div>
                <p class="mt-1.5 text-[11px] text-muted-foreground">
                  <template v-if="d.lock.has_password">
                    Anyone who knows it can come through, and is asked again at every crossing —
                    it buys a way through the door, not a key to it.
                    {{ d.lock.passed_count === 1 ? 'One person is' : `${d.lock.passed_count} people are` }}
                    through on it right now. Changing or removing it shuts the door immediately.
                  </template>
                  <template v-else>
                    At least 4 characters. Anyone you give it to can let themselves in, without
                    you having to add them here.
                  </template>
                </p>
              </div>

              <p v-if="!d.lock.mine" class="mt-2 text-[11px] text-muted-foreground">
                Locked by {{ d.lock.created_by ?? 'somebody who has since left' }}.
              </p>
            </div>
          </div>

          <!-- Stale rows: a lock whose door has gone. Shown rather than filtered, because it's
               the one thing in here nobody can reach any other way. -->
          <div v-for="l in locks.filter(x => !x.present)" :key="`stale-${l.object_id}`" class="rounded-md border border-dashed p-3">
            <div class="flex items-center gap-2">
              <span class="text-sm text-muted-foreground">
                A lock on a door that is no longer on the map{{ l.room ? ` (${l.room})` : '' }}.
              </span>
              <Button size="sm" variant="outline" class="ml-auto" :disabled="!!busy" @click="onUnlock(l.object_id)">
                Remove
              </Button>
            </div>
          </div>
        </div>

        <!-- Rooms -->
        <div v-else class="space-y-3">
          <p class="text-xs text-muted-foreground">
            Anyone in charge of a room can lock and unlock its doors and decide who has a key, and
            can always come through them. A room can be several people's. You can lock and unlock
            anything here yourself — but that's the only key it gives you.
          </p>

          <p v-if="!rooms.length" class="rounded border border-dashed px-3 py-6 text-center text-sm text-muted-foreground">
            This space has no rooms yet. Drag one out with the Room tool in the editor.
          </p>

          <!-- Chips rather than a dropdown: a room can be several people's, and a multi-select
               that shows the current answer at a glance beats one that hides it behind a click. -->
          <div v-for="r in rooms" :key="r.id" class="rounded-md border p-3">
            <div class="flex items-baseline gap-2">
              <p class="truncate text-sm font-medium">{{ r.name }}</p>
              <p class="text-xs text-muted-foreground">{{ r.w }}×{{ r.h }} tiles</p>
              <p class="ml-auto text-xs text-muted-foreground">
                {{ r.owners.length ? `${r.owners.length} in charge` : 'Nobody in charge' }}
              </p>
            </div>
            <div class="mt-2 flex flex-wrap gap-1.5">
              <button
                v-for="m in members"
                :key="m.id"
                type="button"
                class="rounded-full border px-2.5 py-1 text-xs transition-colors disabled:opacity-50"
                :class="r.owners.includes(m.id) ? 'border-primary bg-primary/10 font-medium' : 'hover:bg-muted'"
                :disabled="!!busy"
                @click="toggleOwner(r.id, m.id)"
              >
                {{ nameFor(m) }}
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
