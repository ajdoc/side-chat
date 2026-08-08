<script setup lang="ts">
import { Check, ChevronDown, Hash, LayoutList, Map as MapIcon, Pencil, Pin, PinOff, Plus, Trash2, Volume2 } from 'lucide-vue-next'
import type { Channel } from '~/types'
import { Button } from '~/components/ui/button'
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '~/components/ui/dialog'
import { Input } from '~/components/ui/input'
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from '~/components/ui/alert-dialog'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '~/components/ui/dropdown-menu'

/**
 * The way back out of a discussion, and everything you can do to one.
 *
 * A channel holds several conversations now, so the header has to answer two questions it
 * never had to before: which channel am I in, and what else is in it. This is both — an
 * eyebrow naming the container, and a menu listing its discussions with the one you're
 * reading ticked.
 *
 * Drawn only when there is a choice to make. A channel with a single discussion is a channel
 * exactly as it was before discussions existed, and it should look like one: no breadcrumb, no
 * chevron, nothing to explain. Starting the *second* discussion therefore can't live here —
 * it's on the channel's row in the sidebar, which is where a channel with one discussion still
 * has somewhere to offer it from.
 */
const props = defineProps<{
  /** The container. Its `discussions` are what the menu lists. */
  parent: Channel
  /** The discussion currently open. */
  current: Channel
}>()

const route = useRoute()
const { server } = useServer()
const { canCreate, remove, rename, setDefault } = useDiscussions()

const discussions = computed(() => props.parent.discussions ?? [])
const hasChoice = computed(() => discussions.value.length > 1)
const isStaff = computed(() => !!(server.value?.is_staff ?? server.value?.is_owner))
const isDefault = computed(() => props.parent.default_child_id === props.current.id)
// The last one standing can't be deleted: every route into a channel resolves to a discussion,
// and a channel with none is a channel you can open but not read. The server refuses it too.
const canDelete = computed(() => isStaff.value && discussions.value.length > 1)

const Icon = computed(() => props.parent.type === 'space'
  ? MapIcon
  : props.parent.type === 'voice' ? Volume2 : Hash)

const showNew = ref(false)
const showDelete = ref(false)
const showRename = ref(false)
const nameDraft = ref('')
const working = ref(false)
const error = ref('')

/** Renaming is staff's, like deleting — it changes what a conversation is called for everybody. */
const canRename = computed(() => isStaff.value)

const directoryPath = computed(() => `/servers/${route.params.serverId}/discussions/${props.parent.id}`)

function askRename() {
  nameDraft.value = props.current.name
  error.value = ''
  showRename.value = true
}

async function confirmRename() {
  const name = nameDraft.value.trim()
  if (!name || working.value) return

  working.value = true
  error.value = ''
  try {
    await rename(props.current, name)
    showRename.value = false
  } catch (e: any) {
    error.value = e?.data?.message ?? 'Could not rename the discussion.'
  } finally {
    working.value = false
  }
}

function pathTo(discussion: Channel) {
  return `/servers/${route.params.serverId}/channels/${discussion.id}`
}

async function toggleDefault() {
  try {
    await setDefault(props.current, !isDefault.value)
  } catch {
    // A preference that didn't save is not worth interrupting anybody over; the tick simply
    // stays where it was, and trying again costs one click.
  }
}

async function confirmDelete() {
  if (working.value) return
  working.value = true
  error.value = ''
  try {
    const siblings = discussions.value.filter(d => d.id !== props.current.id)
    await remove(props.current)
    showDelete.value = false
    // You were standing in the room that just went; the channel's next discussion is where
    // "back" means here.
    if (siblings[0]) await navigateTo(pathTo(siblings[0]))
  } catch (e: any) {
    error.value = e?.data?.message ?? 'Could not delete the discussion.'
  } finally {
    working.value = false
  }
}
</script>

<template>
  <DropdownMenu v-if="hasChoice">
    <!-- The negative margins are the point: they buy the trigger a finger-sized hit area
         without moving it or the title beneath it. A 12px line of text is readable at arm's
         length and untappable at any length. -->
    <DropdownMenuTrigger
      class="-mx-1 -my-0.5 flex max-w-full items-center gap-1 truncate rounded px-1 py-0.5 text-xs text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
      :title="`${parent.name} — switch discussion`"
    >
      <component :is="Icon" class="h-3 w-3 shrink-0" />
      <span class="truncate">{{ parent.name }}</span>
      <ChevronDown class="h-3 w-3 shrink-0" />
    </DropdownMenuTrigger>

    <DropdownMenuContent align="start" class="max-h-[70vh] w-56 max-w-[calc(100vw-2rem)] overflow-y-auto">
      <DropdownMenuLabel class="flex items-center gap-1.5">
        <component :is="Icon" class="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
        <span class="truncate">{{ parent.name }}</span>
      </DropdownMenuLabel>
      <DropdownMenuSeparator />

      <DropdownMenuItem
        v-for="d in discussions"
        :key="d.id"
        class="gap-2"
        @select="navigateTo(pathTo(d))"
      >
        <Check class="h-3.5 w-3.5 shrink-0" :class="d.id === current.id ? '' : 'invisible'" />
        <span class="truncate">{{ d.name }}</span>
        <!-- Unread in a discussion you aren't reading is the whole reason to open this menu. -->
        <span
          v-if="d.unread_count && d.id !== current.id"
          class="ml-auto shrink-0 rounded-full bg-primary px-1.5 py-0.5 text-[10px] font-semibold tabular-nums text-primary-foreground"
        >{{ d.unread_count > 99 ? '99+' : d.unread_count }}</span>
      </DropdownMenuItem>

      <DropdownMenuSeparator />

      <!-- The long-form view of the same list: searchable, sortable, and showing how busy each
           conversation is. One click is enough for three discussions; thirty need a page. -->
      <DropdownMenuItem class="gap-2" @select="navigateTo(directoryPath)">
        <LayoutList class="h-3.5 w-3.5 shrink-0" />
        <span>See all discussions</span>
      </DropdownMenuItem>

      <!-- Yours alone: pinning a discussion changes where *you* land and nothing for anyone
           else, which is why it sits with the navigation rather than in a channel setting. -->
      <DropdownMenuItem class="gap-2" @select="toggleDefault">
        <component :is="isDefault ? PinOff : Pin" class="h-3.5 w-3.5 shrink-0" />
        <span>{{ isDefault ? 'Don’t open here by default' : 'Open here by default' }}</span>
      </DropdownMenuItem>

      <DropdownMenuItem v-if="canCreate" class="gap-2" @select="showNew = true">
        <Plus class="h-3.5 w-3.5 shrink-0" />
        <span>New discussion…</span>
      </DropdownMenuItem>

      <DropdownMenuItem v-if="canRename" class="gap-2" @select="askRename">
        <Pencil class="h-3.5 w-3.5 shrink-0" />
        <span>Rename this discussion…</span>
      </DropdownMenuItem>

      <DropdownMenuItem v-if="canDelete" class="gap-2 text-destructive" @select="showDelete = true">
        <Trash2 class="h-3.5 w-3.5 shrink-0" />
        <span>Delete this discussion…</span>
      </DropdownMenuItem>
    </DropdownMenuContent>
  </DropdownMenu>

  <NewDiscussionDialog v-model:open="showNew" :parent="parent" @created="navigateTo(pathTo($event))" />

  <Dialog v-model:open="showRename">
    <DialogContent>
      <DialogHeader>
        <DialogTitle>Rename discussion</DialogTitle>
        <DialogDescription>Everyone in the channel sees the new name.</DialogDescription>
      </DialogHeader>
      <form class="space-y-3" @submit.prevent="confirmRename">
        <Input v-model="nameDraft" placeholder="Discussion name" maxlength="100" autofocus />
        <p v-if="error" class="text-sm text-destructive">{{ error }}</p>
        <div class="flex justify-end gap-2">
          <Button type="button" variant="outline" :disabled="working" @click="showRename = false">Cancel</Button>
          <Button type="submit" :disabled="working || !nameDraft.trim()">
            {{ working ? 'Saving…' : 'Save' }}
          </Button>
        </div>
      </form>
    </DialogContent>
  </Dialog>

  <AlertDialog v-model:open="showDelete">
    <AlertDialogContent>
      <AlertDialogHeader>
        <AlertDialogTitle>Delete “{{ current.name }}”?</AlertDialogTitle>
        <AlertDialogDescription>
          Every message, thread and file in this discussion goes with it, for everyone. The
          channel’s other discussions are untouched.
        </AlertDialogDescription>
      </AlertDialogHeader>
      <p v-if="error" class="text-sm text-destructive">{{ error }}</p>
      <AlertDialogFooter>
        <AlertDialogCancel :disabled="working">Cancel</AlertDialogCancel>
        <AlertDialogAction as-child>
          <Button variant="destructive" :disabled="working" @click.prevent="confirmDelete">
            {{ working ? 'Deleting…' : 'Delete discussion' }}
          </Button>
        </AlertDialogAction>
      </AlertDialogFooter>
    </AlertDialogContent>
  </AlertDialog>
</template>
