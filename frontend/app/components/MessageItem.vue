<script setup lang="ts">
import { Check, CheckCircle2, Copy, CornerUpLeft, Forward, Info, MessageSquarePlus, MessageSquareText, MessagesSquare, Paperclip, Pencil, Pin, PinOff, Rocket, Trash2, X } from 'lucide-vue-next'
import type { Message, User } from '~/types'
import { Button } from '~/components/ui/button'
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

const props = defineProps<{
  message: Message
  currentUserId: number | null
  threadActions?: boolean
  /**
   * Show the "Start a side chat off this message" button. Separate from threadActions so a
   * message *inside* a side chat can still start a thread without offering a nested side chat.
   */
  sideChatCreate?: boolean
  /** Show side-chat affordances: the living-object card, and (when joined) the decision toggle. */
  sideChatActions?: boolean
  /** Offer "Forward" — only where a parent is wired to open the forward picker. */
  forwardable?: boolean
  /** Whether the viewer has joined this side chat — gates the decision toggle. */
  joined?: boolean
  highlighted?: boolean
  /** People whose read marker rests on *this* message — see useReads().readersByMessage. */
  readers?: User[]
}>()

const emit = defineEmits<{
  reply: [message: Message]
  save: [id: number, body: string | null, files: File[], removeAttachmentIds: number[]]
  remove: [id: number]
  'create-thread': [messageId: number]
  'open-thread': [threadId: number]
  'create-side-chat': [messageId: number]
  'open-side-chat': [sideChatId: number]
  'toggle-decision': [messageId: number]
  'jump-to-reply': [messageId: number]
  'toggle-reaction': [messageId: number, emoji: string]
  'toggle-pin': [messageId: number]
  forward: [message: Message]
}>()

const isSystem = computed(() => props.message.type === 'system')
// A widget card is authored by whoever ran the command, so it's editable/deletable by the
// usual rules — but "editing" it would mean typing a body onto a player, which is nonsense.
const isWidget = computed(() => props.message.type === 'widget')
// System messages (e.g. "X joined the server") are generated — nobody may edit them.
const canModify = computed(() =>
  !isSystem.value && props.currentUserId != null && props.message.user.id === props.currentUserId,
)
const attachments = computed(() => props.message.attachments ?? [])

const { stripMarkdown } = useMarkdown()

const editing = ref(false)
const editDraft = ref('')
const editFiles = ref<File[]>([])
const removeIds = ref<number[]>([])
const editFileInput = ref<HTMLInputElement | null>(null)
const showDelete = ref(false)
const showInfo = ref(false)
const showComments = ref(false)
const pickerOpen = ref(false)
// Flips to a tick for a beat after a successful copy, then falls back to the copy icon.
const copied = ref(false)
let copiedTimer: ReturnType<typeof setTimeout> | undefined

function initials(name: string) {
  return name.split(' ').map(w => w[0]).slice(0, 2).join('').toUpperCase()
}
function formatTime(iso: string) {
  return new Date(iso).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
}

function startEdit() {
  editDraft.value = props.message.body ?? ''
  editFiles.value = []
  removeIds.value = []
  editing.value = true
}
function cancelEdit() {
  editing.value = false
}
function toggleRemove(id: number) {
  removeIds.value = removeIds.value.includes(id)
    ? removeIds.value.filter(x => x !== id)
    : [...removeIds.value, id]
}
function onPickEditFiles(event: Event) {
  const list = (event.target as HTMLInputElement).files
  if (list) editFiles.value = [...editFiles.value, ...Array.from(list)]
  if (editFileInput.value) editFileInput.value.value = ''
}
function onPasteEdit(event: ClipboardEvent) {
  const items = event.clipboardData?.items
  if (!items) return
  const pasted: File[] = []
  for (const item of items) {
    if (item.kind === 'file') {
      const f = item.getAsFile()
      if (f) pasted.push(f)
    }
  }
  if (pasted.length) {
    event.preventDefault()
    editFiles.value = [...editFiles.value, ...pasted]
  }
}
function saveEdit() {
  const body = editDraft.value.trim()
  const remaining = attachments.value.length - removeIds.value.length + editFiles.value.length
  if (!body && remaining === 0) return // don't allow an empty message with no files
  emit('save', props.message.id, body || null, editFiles.value, removeIds.value)
  editing.value = false
}

// Copy the message text verbatim — the markdown the author typed, not the rendered HTML,
// so it round-trips losslessly if it's pasted back into another message.
async function copyMessage() {
  if (!props.message.body) return
  try {
    await navigator.clipboard.writeText(props.message.body)
    copied.value = true
    clearTimeout(copiedTimer)
    copiedTimer = setTimeout(() => (copied.value = false), 1500)
  } catch {
    // clipboard blocked (insecure context / denied) — nothing we can do but leave it be
  }
}

onBeforeUnmount(() => clearTimeout(copiedTimer))
</script>

<template>
  <!-- System notice. The body is generated server-side and already names whoever it's
       about ("X joined the server", "Call ended · 4m 12s") — don't prepend the author. -->
  <div v-if="isSystem" class="flex items-center gap-2 px-2 py-1.5 text-xs text-muted-foreground">
    <Info class="h-3.5 w-3.5 shrink-0 opacity-70" />
    <span class="text-foreground">{{ message.body }}</span>
    <span class="opacity-60">{{ formatTime(message.created_at) }}</span>
  </div>

  <div
    v-else
    class="group relative flex gap-3 rounded px-2 py-1.5 transition-colors duration-500 hover:bg-muted/50"
    :class="highlighted ? 'bg-amber-200/50 dark:bg-amber-400/10' : ''"
  >
    <div class="grid h-9 w-9 shrink-0 place-items-center rounded-full bg-primary text-xs font-semibold text-primary-foreground">
      {{ initials(message.user.name) }}
    </div>

    <div class="min-w-0 flex-1">
      <!-- Pinned: says so on the message itself, not only in the Pinned tab — otherwise
           the only way to know a message is pinned is to go looking for it. -->
      <p v-if="message.pinned" class="mb-0.5 flex items-center gap-1 text-xs font-medium text-primary">
        <Pin class="h-3 w-3 shrink-0" /> Pinned
      </p>

      <!-- Recorded as a decision (side chats only) — says so on the message, like a pin. -->
      <p v-if="message.decided" class="mb-0.5 flex items-center gap-1 text-xs font-medium text-emerald-600 dark:text-emerald-400">
        <CheckCircle2 class="h-3 w-3 shrink-0" /> Decision
      </p>

      <!-- Forwarded: says whose message this was before it was passed along. The origin
           lives in another room the viewer may not even be in, so it's a label, not a link. -->
      <p v-if="message.forwarded_from" class="mb-0.5 flex items-center gap-1 text-xs italic text-muted-foreground">
        <Forward class="h-3 w-3 shrink-0" />
        Forwarded<template v-if="message.forwarded_from.user_name"> from <span class="font-medium not-italic">{{ message.forwarded_from.user_name }}</span></template>
      </p>

      <!-- reply reference: click to jump to the original message -->
      <button
        v-if="message.reply_to"
        type="button"
        class="mb-0.5 flex w-full items-center gap-1 truncate text-left text-xs text-muted-foreground hover:text-foreground hover:underline"
        @click="emit('jump-to-reply', message.reply_to.id)"
      >
        <CornerUpLeft class="h-3 w-3 shrink-0" />
        <span class="font-medium">{{ message.reply_to.user_name ?? 'unknown' }}</span>
        <span class="truncate">{{ message.reply_to.body ? stripMarkdown(message.reply_to.body) : '' }}</span>
      </button>

      <div class="flex items-baseline gap-2">
        <span class="text-sm font-semibold">{{ message.user.name }}</span>
        <span class="text-xs text-muted-foreground">{{ formatTime(message.created_at) }}</span>
      </div>

      <!-- EDITING -->
      <div v-if="editing" class="mt-1 space-y-2">
        <MarkdownEditor
          v-model="editDraft"
          autofocus
          placeholder="Message"
          @submit="saveEdit"
          @cancel="cancelEdit"
          @paste="onPasteEdit"
        />

        <!-- existing attachments: click X to delete them (file is removed from storage) -->
        <AttachmentList
          :attachments="attachments"
          removable
          :marked-for-removal="removeIds"
          @remove="toggleRemove"
        />

        <!-- newly added files -->
        <div v-if="editFiles.length" class="flex flex-wrap gap-2">
          <span
            v-for="(f, i) in editFiles"
            :key="i"
            class="flex items-center gap-1 rounded border bg-muted/40 px-2 py-1 text-xs"
          >
            <Paperclip class="h-3 w-3" /> {{ f.name }}
            <button class="hover:text-destructive" @click="editFiles = editFiles.filter((_, x) => x !== i)">
              <X class="h-3 w-3" />
            </button>
          </span>
        </div>

        <div class="flex items-center gap-2">
          <input ref="editFileInput" type="file" multiple class="hidden" @change="onPickEditFiles">
          <Button type="button" variant="outline" size="sm" class="gap-1" @click="editFileInput?.click()">
            <Paperclip class="h-3.5 w-3.5" /> Add files
          </Button>
          <Button type="button" size="sm" @click="saveEdit">Save</Button>
          <Button type="button" variant="ghost" size="sm" @click="cancelEdit">Cancel</Button>
        </div>
      </div>

      <!-- VIEWING -->
      <template v-else>
        <MarkdownBody v-if="message.body" :source="message.body" :edited="message.edited" />

        <!-- interactive widget card (music player / kanban board) — kept live over the stream -->
        <WidgetCard v-if="message.type === 'widget' && message.widget" :widget="message.widget" />

        <AttachmentList :attachments="attachments" />

        <!-- unfurled links: empty at first, filled in over the websocket -->
        <LinkPreviewList :previews="message.link_previews ?? []" />

        <ReactionBar
          :reactions="message.reactions ?? []"
          :current-user-id="currentUserId"
          @toggle="emit('toggle-reaction', message.id, $event)"
        />

        <!-- "word-reactions": popular comment chips. Click one to co-sign it, or + to write. -->
        <CommentBar
          :message-id="message.id"
          :comments="message.comments ?? []"
          :current-user-id="currentUserId"
          @open="showComments = true"
        />

        <!-- thread indicator (channel timeline only) -->
        <button
          v-if="threadActions && message.started_thread"
          class="mt-1 inline-flex items-center gap-1.5 rounded-md border bg-background px-2 py-1 text-xs font-medium text-primary hover:bg-muted"
          @click="emit('open-thread', message.started_thread.id)"
        >
          <MessagesSquare class="h-3.5 w-3.5" />
          {{ message.started_thread.name }}
          <span class="text-muted-foreground">
            · {{ message.started_thread.replies_count }} {{ message.started_thread.replies_count === 1 ? 'reply' : 'replies' }}
          </span>
        </button>

        <!-- side chat living-object card (channel timeline only) -->
        <SideChatCard
          v-if="sideChatActions && message.started_side_chat"
          :side-chat="message.started_side_chat"
          :current-user-id="currentUserId"
          @open="emit('open-side-chat', message.started_side_chat!.id)"
        />

        <!-- who has read this far -->
        <SeenBy :readers="readers ?? []" />
      </template>
    </div>

    <!-- hover actions -->
    <div
      v-if="!editing"
      class="absolute right-2 top-1 items-center gap-1 rounded border bg-background p-0.5 shadow-sm"
      :class="pickerOpen ? 'flex' : 'hidden group-hover:flex'"
    >
      <!-- Start a side chat off this message — the app's signature move, so it leads the
           action bar as a labeled, primary-tinted affordance rather than hiding in an
           overflow menu. A side chat is a room with its own roster; spinning one up from the
           message that prompted it is the gesture the whole app is built around. -->
      <button
        v-if="sideChatCreate"
        class="flex items-center gap-1 rounded bg-primary/10 px-1.5 py-1 text-xs font-medium text-primary hover:bg-primary/20"
        title="Start a side chat off this message"
        @click="emit('create-side-chat', message.id)"
      >
        <Rocket class="h-4 w-4" /> Side chat
      </button>
      <EmojiPicker v-model:open="pickerOpen" @select="emit('toggle-reaction', message.id, $event)" />
      <button class="rounded p-1 text-muted-foreground hover:text-foreground" title="Reply" @click="emit('reply', message)">
        <CornerUpLeft class="h-4 w-4" />
      </button>
      <button class="rounded p-1 text-muted-foreground hover:text-foreground" title="Comment" @click="showComments = true">
        <MessageSquareText class="h-4 w-4" />
      </button>
      <!-- Forward this message into another chat, group or channel. -->
      <button v-if="forwardable && !isWidget" class="rounded p-1 text-muted-foreground hover:text-foreground" title="Forward" @click="emit('forward', message)">
        <Forward class="h-4 w-4" />
      </button>
      <!-- Copy the message text. Only where there's text to copy. -->
      <button
        v-if="message.body"
        class="rounded p-1 hover:text-foreground"
        :class="copied ? 'text-green-600 dark:text-green-400' : 'text-muted-foreground'"
        :title="copied ? 'Copied!' : 'Copy text'"
        @click="copyMessage"
      >
        <Check v-if="copied" class="h-4 w-4" />
        <Copy v-else class="h-4 w-4" />
      </button>
      <!-- Threads are the quick, inline per-message follow-up: a reply that stays attached to
           this message, no roster of its own. Side chats (above) are the deliberate room. -->
      <button
        v-if="threadActions"
        class="rounded p-1 text-muted-foreground hover:text-foreground"
        title="Reply in thread"
        @click="emit('create-thread', message.id)"
      >
        <MessageSquarePlus class="h-4 w-4" />
      </button>
      <!-- Record / un-record a decision (inside a side chat, participants only). -->
      <button
        v-if="sideChatActions && joined"
        class="rounded p-1 hover:text-foreground"
        :class="message.decided ? 'text-emerald-600 dark:text-emerald-400' : 'text-muted-foreground'"
        :title="message.decided ? 'Unmark decision' : 'Mark as decision'"
        @click="emit('toggle-decision', message.id)"
      >
        <CheckCircle2 class="h-4 w-4" />
      </button>
      <!-- Any member may pin *or* unpin: a pin belongs to the channel, not to whoever
           happened to make it. -->
      <button
        class="rounded p-1 text-muted-foreground hover:text-foreground"
        :title="message.pinned ? 'Unpin message' : 'Pin message'"
        @click="emit('toggle-pin', message.id)"
      >
        <PinOff v-if="message.pinned" class="h-4 w-4" />
        <Pin v-else class="h-4 w-4" />
      </button>
      <button class="rounded p-1 text-muted-foreground hover:text-foreground" title="Message info" @click="showInfo = true">
        <Info class="h-4 w-4" />
      </button>
      <button v-if="canModify && !isWidget" class="rounded p-1 text-muted-foreground hover:text-foreground" title="Edit" @click="startEdit">
        <Pencil class="h-4 w-4" />
      </button>
      <button v-if="canModify" class="rounded p-1 text-muted-foreground hover:text-destructive" title="Delete" @click="showDelete = true">
        <Trash2 class="h-4 w-4" />
      </button>
    </div>

    <MessageInfoDialog v-if="showInfo" v-model:open="showInfo" :message="message" />

    <CommentDialog v-if="showComments" v-model:open="showComments" :message="message" />

    <AlertDialog v-model:open="showDelete">
      <AlertDialogContent>
        <AlertDialogHeader>
          <AlertDialogTitle>Delete message?</AlertDialogTitle>
          <AlertDialogDescription>
            This can’t be undone.
            <template v-if="attachments.length">
              Its {{ attachments.length }} attached file{{ attachments.length === 1 ? '' : 's' }} will be permanently deleted.
            </template>
            <template v-if="message.started_thread">
              Its thread “{{ message.started_thread.name }}” and all of its replies will be deleted too.
            </template>
          </AlertDialogDescription>
        </AlertDialogHeader>
        <AlertDialogFooter>
          <AlertDialogCancel>Cancel</AlertDialogCancel>
          <AlertDialogAction
            class="bg-destructive text-white hover:bg-destructive/90"
            @click="emit('remove', message.id)"
          >
            Delete
          </AlertDialogAction>
        </AlertDialogFooter>
      </AlertDialogContent>
    </AlertDialog>
  </div>
</template>
