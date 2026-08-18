<script setup lang="ts">
import { ArrowUp, ArrowUpRight, Loader2, MessagesSquare, SmilePlus, Tag, Trash2, X } from 'lucide-vue-next'
import { tagChip } from '~/lib/tracker'
import { isChannelPath } from '~/lib/deskScope'

/**
 * Reactions, tags and a comment thread, for any item in any app.
 *
 * Drop-in: give it the surface's base path, the item's morph name and its id, and it fetches,
 * renders and writes on its own. That's the payoff of making those tables polymorphic — a
 * calendar entry gets a discussion by adding this one tag to its detail panel.
 *
 * ## Layout
 *
 * Two bands, in the order people use them. A **chip band** first — reactions and tags, both
 * cheap one-click responses, and by far the most common thing anyone adds — then the **thread**,
 * which most items never get at all.
 *
 * The chip band shows only what *exists*, plus one small affordance to add more. The first
 * version drew all five reaction emoji as permanent outlined buttons, which put a loud row of
 * controls on every item to represent, almost always, nothing. Chips you can count at a glance
 * beat buttons you have to read.
 *
 * ## Sizing
 *
 * This lives *inside* other apps — a 288px side panel, a calendar editor, under a note — so it
 * can never assume a column of its own. Everything is on one type scale (`text-xs` for content,
 * `text-[11px]` for metadata) and nothing here sets a width.
 */
const props = withDefaults(defineProps<{
  basePath: string
  /** The short morph name — 'calendar_event', 'canvas_item'. See App\Support\Apps\AppSubjects. */
  subject: string
  itemId: number | null
  canEdit?: boolean
  /** Tags are worth showing on some items and only noise on others. */
  showTags?: boolean
  /** Same for reactions — a doc shelf wants them, a one-line canvas note may not. */
  showReactions?: boolean
}>(), { canEdit: true, showTags: true, showReactions: true })

/**
 * Comments, tags, reactions and the side chat all live on **channel-scoped** endpoints
 * (`channels/{channel}/apps/...`), and several of the apps that host this panel can also be
 * rendered on a *side chat*, where their rows belong to the side chat instead.
 *
 * There is no side-chat half of these tables — deliberately, since a side chat has its own
 * roster and the authorisation would be a different rule (see APP_CHANNELS.md). So on a
 * side-chat surface the panel draws nothing at all rather than four requests that 404 and an
 * empty thread that never accepts a comment.
 */
const channelScoped = computed(() => isChannelPath(props.basePath))

/** Null on a side chat, which is what stops useAppItem fetching anything there. */
const id = computed(() => (channelScoped.value ? props.itemId : null))

const { comments, reactions, itemTags, loading, discussion, addComment, removeComment, attachTag, detachTag, react, startDiscussion }
  = useAppItem(props.basePath, props.subject, id)

const { user } = useAuth()

/**
 * The emoji on offer.
 *
 * A short fixed set rather than a full picker: this sits inside another app's panel, and an
 * emoji keyboard would be the largest thing in it by a wide margin.
 */
const QUICK_REACTIONS = ['👍', '❤️', '🔥', '💡', '👀']

/** Only the emoji somebody has actually used — what the band draws. */
const used = computed(() => reactions.value.filter((r: any) => r.count > 0))

function countFor(emoji: string) {
  return reactions.value.find((r: any) => r.emoji === emoji)
}

const picking = ref(false)

async function pick(emoji: string) {
  picking.value = false
  await react(emoji)
}

// --- tags ---------------------------------------------------------------------------------

const tagDraft = ref('')
const addingTag = ref(false)

/**
 * The tags this item wears.
 *
 * Fetched, not accumulated. It used to be a local array that only ever gained the tags *you*
 * added in this session, so anything tagged before you opened the panel simply didn't appear —
 * the tag was on the item, the chip was not on the screen. See `itemTags` in useAppItem.
 */
const mine = itemTags

watch(id, () => {
  draft.value = ''
  picking.value = false
  addingTag.value = false
})

async function submitTag() {
  const label = tagDraft.value.trim()
  if (!label) return
  tagDraft.value = ''
  addingTag.value = false
  // The composable keeps `itemTags` in step, so there's nothing to merge here.
  await attachTag(label)
}

async function dropTag(tag: { id: number }) {
  await detachTag(tag.id)
}

// --- comments -----------------------------------------------------------------------------

const draft = ref('')

async function submit() {
  const body = draft.value.trim()
  if (!body) return
  draft.value = ''
  await addComment(body)
}

function when(iso: string) {
  const mins = Math.round((Date.now() - new Date(iso).getTime()) / 60000)
  if (mins < 1) return 'now'
  if (mins < 60) return `${mins}m`
  if (mins < 1440) return `${Math.round(mins / 60)}h`
  return new Date(iso).toLocaleDateString(undefined, { month: 'short', day: 'numeric' })
}

/* ---------------------------------------------------------------- chat about this item */

/**
 * "Discuss in chat" — the return trip for "Add this message to an app".
 *
 * A comment thread and a side chat answer different questions, which is why this sits *beside*
 * the thread rather than replacing it: a comment is a note on the record, and a side chat is a
 * conversation with participants, decisions and a desk of its own. The item that needs working
 * out gets the room; the item that needs a remark gets the thread.
 *
 * One button for "start" and "open", because the server is idempotent — pressing it second joins
 * the first person's room. Only the label changes.
 */
const starting = ref(false)

/**
 * The path to the room.
 *
 * Built here from the ids the API returned, the same `?sidechat=` route the search panel builds.
 * A server that emitted paths would be a second place the frontend's routes are written down.
 */
const discussionPath = computed(() => {
  const d = discussion.value
  if (!d) return null
  const base = d.conversation_id ? `/chats/${d.conversation_id}` : `/servers/${d.server_id}/channels/${d.channel_id}`
  return `${base}?sidechat=${d.side_chat_id}`
})

async function openDiscussion() {
  if (discussionPath.value) return navigateTo(discussionPath.value)

  starting.value = true
  try {
    const created = await startDiscussion()
    // Straight into the room it just made: pressing "discuss" and then having to go find the
    // conversation would be two steps for one intention.
    if (created && discussionPath.value) await navigateTo(discussionPath.value)
  }
  finally {
    starting.value = false
  }
}

/** Whether the chip band has anything at all to draw, including its add buttons. */
const hasChipBand = computed(() =>
  (props.showReactions || props.showTags) && (props.canEdit || used.value.length > 0 || mine.value.length > 0))
</script>

<template>
  <div v-if="itemId != null && channelScoped" class="space-y-3 text-foreground">
    <!--
      Above the chips: it's the one control here that leaves the page, and burying an exit under
      a comment box is how people miss that the conversation already exists.
    -->
    <button
      v-if="canEdit"
      type="button"
      class="flex w-full items-center gap-1.5 rounded-md border px-2 py-1.5 text-xs transition-colors hover:bg-muted"
      :class="discussion ? 'border-primary/40 text-primary' : 'text-muted-foreground'"
      :disabled="starting"
      @click="openDiscussion"
    >
      <Loader2 v-if="starting" class="h-3.5 w-3.5 animate-spin" />
      <MessagesSquare v-else class="h-3.5 w-3.5" />
      <span class="truncate">{{ discussion ? 'Open the side chat' : 'Start a side chat' }}</span>
      <ArrowUpRight class="ml-auto h-3.5 w-3.5 shrink-0 opacity-60" />
    </button>

    <!--
      Says what the button does before it does it.

      Pressing it *navigates* — out of this panel, into a room in the channel — and a control
      that moves you somewhere without warning reads as a bug the first time. Naming the side
      chat (rather than calling it "chat") also matters: it's a thing this product already has,
      with participants and decisions, and people who know what one is need only be told that's
      what they're getting.
    -->
    <p v-if="canEdit" class="-mt-1.5 px-0.5 text-[11px] text-muted-foreground">
      <template v-if="discussion">Opens “{{ discussion.name }}” in the channel.</template>
      <template v-else>Opens a side chat in this channel, linked to this item. Everyone here can find it.</template>
    </p>

    <!--
      The chip band: reactions and tags together, because they're the same gesture — a
      one-click label on the thing. Wrapping in one row keeps two near-empty rows from taking
      the vertical space of a paragraph.
    -->
    <div v-if="hasChipBand" class="flex flex-wrap items-center gap-1">
      <button
        v-for="r in (showReactions ? used : [])"
        :key="r.emoji"
        type="button"
        class="inline-flex h-6 items-center gap-1 rounded-full border px-2 text-[11px] leading-none transition-colors"
        :class="r.reacted
          ? 'border-primary/40 bg-primary/10 text-foreground'
          : 'border-transparent bg-muted text-muted-foreground hover:bg-muted/70'"
        :disabled="!canEdit"
        :title="r.reacted ? `Remove ${r.emoji}` : `React ${r.emoji}`"
        @click="react(r.emoji)"
      >
        <span class="text-[13px] leading-none">{{ r.emoji }}</span>
        <span class="tabular-nums">{{ r.count }}</span>
      </button>

      <!-- One button, not five. The set opens on demand. -->
      <div v-if="showReactions && canEdit" class="relative">
        <button
          type="button"
          class="grid h-6 w-6 place-items-center rounded-full border border-dashed text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
          title="Add a reaction"
          @click="picking = !picking"
        >
          <SmilePlus class="h-3.5 w-3.5" />
        </button>

        <div v-if="picking" class="fixed inset-0 z-20" @click="picking = false" />
        <div
          v-if="picking"
          class="absolute left-0 top-7 z-30 flex gap-0.5 rounded-lg border bg-popover p-1 shadow-md"
        >
          <button
            v-for="emoji in QUICK_REACTIONS"
            :key="emoji"
            type="button"
            class="grid h-7 w-7 place-items-center rounded text-base transition-colors hover:bg-muted"
            :class="countFor(emoji)?.reacted && 'bg-primary/15'"
            :title="emoji"
            @click="pick(emoji)"
          >
            {{ emoji }}
          </button>
        </div>
      </div>

      <!-- A hairline between the two kinds of chip, only when both are present. -->
      <span
        v-if="showReactions && showTags && (used.length || canEdit) && mine.length"
        class="mx-0.5 h-4 w-px bg-border"
      />

      <template v-if="showTags">
        <span
          v-for="tag in mine"
          :key="tag.id"
          class="group/tag inline-flex h-6 items-center gap-1 rounded-full border px-2 text-[11px] leading-none"
          :class="tagChip(tag.color)"
        >
          {{ tag.label }}
          <button
            v-if="canEdit"
            type="button"
            class="-mr-0.5 opacity-50 transition-opacity hover:opacity-100"
            :title="`Remove ${tag.label}`"
            @click="dropTag(tag)"
          >
            <X class="h-2.5 w-2.5" />
          </button>
        </span>

        <button
          v-if="canEdit && !addingTag"
          type="button"
          class="grid h-6 w-6 place-items-center rounded-full border border-dashed text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
          title="Add a tag"
          @click="addingTag = true"
        >
          <Tag class="h-3 w-3" />
        </button>
        <input
          v-else-if="canEdit"
          v-model="tagDraft"
          placeholder="tag…"
          class="h-6 w-24 rounded-full border bg-transparent px-2 text-[11px] outline-none transition-colors focus:border-primary"
          autofocus
          @keydown.enter.prevent="submitTag"
          @keydown.esc="addingTag = false"
          @blur="addingTag = false"
        >
      </template>
    </div>

    <!-- The thread. -->
    <div class="space-y-2">
      <p class="flex items-center gap-1.5 text-[11px] font-medium uppercase tracking-wide text-muted-foreground">
        Comments
        <span v-if="comments.length" class="rounded bg-muted px-1 tabular-nums">{{ comments.length }}</span>
        <Loader2 v-if="loading" class="h-3 w-3 animate-spin" />
      </p>

      <div v-for="c in comments" :key="c.id" class="group flex gap-2">
        <TrackerAvatar :user="c.user" size="sm" class="mt-0.5" />
        <div class="min-w-0 flex-1">
          <p class="flex items-baseline gap-1.5">
            <span class="truncate text-xs font-medium">{{ c.user?.name ?? 'Someone' }}</span>
            <span class="shrink-0 text-[11px] text-muted-foreground">{{ when(c.created_at) }}</span>
            <span v-if="c.edited_at" class="shrink-0 text-[11px] text-muted-foreground/70">edited</span>
            <button
              v-if="c.user?.id === user?.id"
              type="button"
              class="ml-auto shrink-0 text-muted-foreground opacity-0 transition-opacity hover:text-destructive focus:opacity-100 group-hover:opacity-100"
              title="Delete comment"
              @click="removeComment(c.id)"
            >
              <Trash2 class="h-3 w-3" />
            </button>
          </p>
          <p class="whitespace-pre-wrap break-words text-xs leading-relaxed text-foreground/90">{{ c.body }}</p>
        </div>
      </div>

      <!-- Said plainly rather than left blank, so an item with no discussion doesn't read as
           a panel that failed to load. -->
      <p v-if="!loading && !comments.length" class="text-[11px] text-muted-foreground">
        No comments yet.
      </p>

      <!--
        The composer.

        A bordered field with the button inside it, rather than an input and a detached icon:
        the two belong to each other, and at this size a separate button reads as unrelated
        chrome. Lights up on focus so it's obvious where typing goes.
      -->
      <form
        v-if="canEdit"
        class="flex items-end gap-1 rounded-lg border bg-background px-2 py-1 transition-colors focus-within:border-primary"
        @submit.prevent="submit"
      >
        <input
          v-model="draft"
          placeholder="Write a comment…"
          class="min-w-0 flex-1 bg-transparent py-1 text-xs outline-none placeholder:text-muted-foreground"
        >
        <button
          type="submit"
          class="grid h-6 w-6 shrink-0 place-items-center rounded-md text-muted-foreground transition-colors hover:bg-muted hover:text-foreground disabled:pointer-events-none disabled:opacity-30"
          :disabled="!draft.trim()"
          title="Comment"
        >
          <ArrowUp class="h-3.5 w-3.5" />
        </button>
      </form>
    </div>
  </div>
</template>
