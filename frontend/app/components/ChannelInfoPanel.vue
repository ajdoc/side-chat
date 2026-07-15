<script setup lang="ts">
import { CornerUpLeft, Download, Eye, FileText, Image as ImageIcon, Link2, Loader2, Pin, PinOff, Sparkles, X } from 'lucide-vue-next'
import type { Attachment, ChannelLink } from '~/types'
import { Button } from '~/components/ui/button'

const props = defineProps<{ channelId: number }>()

const emit = defineEmits<{ jump: [messageId: number] }>()

const route = useRoute()

const { files, hasMore, loading, load, loadMore } = useChannelFiles()
const {
  links,
  hasMore: hasMoreLinks,
  loading: loadingLinks,
  load: loadLinks,
  loadMore: loadMoreLinks,
} = useChannelLinks()
const {
  pins,
  hasMore: hasMorePins,
  loading: loadingPins,
  load: loadPins,
  loadMore: loadMorePins,
  toggle: togglePin,
} = usePins()

const { stripMarkdown } = useMarkdown()

type Tab = 'pinned' | 'files' | 'links' | 'gifs'
const tab = ref<Tab>('pinned')

const tabs: { value: Tab, label: string, icon: any, ready: boolean }[] = [
  { value: 'pinned', label: 'Pinned', icon: Pin, ready: true },
  { value: 'files', label: 'Files', icon: FileText, ready: true },
  { value: 'links', label: 'Links', icon: Link2, ready: true },
  { value: 'gifs', label: 'GIFs', icon: Sparkles, ready: false },
]

/** A pin with no body is an attachment someone thought was worth keeping. */
function pinPreview(message: { body: string | null, attachments?: Attachment[] }) {
  if (message.body) return stripMarkdown(message.body)

  const count = message.attachments?.length ?? 0
  if (!count) return 'Empty message'

  return count === 1 ? message.attachments![0]!.name : `${count} files`
}

const images = computed(() => files.value.filter(f => f.is_image))
const others = computed(() => files.value.filter(f => !f.is_image))

/** A failed unfurl has no title — fall back to something readable rather than a blank row. */
function linkTitle(link: ChannelLink) {
  return link.title || link.site_name || hostOf(link.url)
}
function hostOf(url: string) {
  try {
    return new URL(url).host
  } catch {
    return url
  }
}
function formatSharedAt(iso: string) {
  const date = new Date(iso)
  return date.toDateString() === new Date().toDateString()
    ? date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
    : date.toLocaleDateString([], { month: 'short', day: 'numeric' })
}

function close() {
  navigateTo({ path: route.path, query: {} })
}
function formatSize(bytes: number) {
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(0)} KB`
  return `${(bytes / 1024 / 1024).toFixed(1)} MB`
}
function triggerDownload(a: Attachment) {
  const link = document.createElement('a')
  link.href = a.download_url
  link.download = a.name
  document.body.appendChild(link)
  link.click()
  link.remove()
}
function onView(a: Attachment) {
  if (a.is_image || a.is_pdf) window.open(a.url, '_blank', 'noopener')
  else triggerDownload(a)
}

// Each tab is fetched when it's actually opened. Most visits to this panel never leave the
// tab they landed on, so fetching all three up front would be two wasted round trips.
watch([tab, () => props.channelId], ([current, id]) => {
  if (current === 'pinned') loadPins(id)
  if (current === 'files') load(id)
  if (current === 'links') loadLinks(id)
}, { immediate: true })
</script>

<template>
  <aside class="flex w-[360px] shrink-0 flex-col border-l">
    <header class="flex h-12 shrink-0 items-center justify-between border-b px-4">
      <span class="font-semibold">Channel info</span>
      <button class="text-muted-foreground hover:text-foreground" aria-label="Close" @click="close">
        <X class="h-4 w-4" />
      </button>
    </header>

    <!-- Tabs -->
    <div class="flex shrink-0 gap-1 border-b p-2">
      <button
        v-for="t in tabs"
        :key="t.value"
        class="flex flex-1 items-center justify-center gap-1.5 rounded-md px-2 py-1.5 text-xs font-medium transition"
        :class="tab === t.value ? 'bg-muted text-foreground' : 'text-muted-foreground hover:bg-muted/50'"
        :disabled="!t.ready"
        :title="t.ready ? t.label : 'Coming soon'"
        @click="t.ready && (tab = t.value)"
      >
        <component :is="t.icon" class="h-3.5 w-3.5" />
        {{ t.label }}
        <span v-if="!t.ready" class="text-[10px] opacity-60">soon</span>
      </button>
    </div>

    <div class="flex-1 overflow-y-auto p-3">
      <!-- PINNED -->
      <template v-if="tab === 'pinned'">
        <div v-if="loadingPins && !pins.length" class="flex justify-center py-6">
          <Loader2 class="h-4 w-4 animate-spin text-muted-foreground" />
        </div>

        <p v-else-if="!pins.length" class="py-6 text-center text-sm text-muted-foreground">
          Nothing pinned yet. Hover a message and hit the pin to keep it here.
        </p>

        <template v-else>
          <div class="space-y-2">
            <div
              v-for="message in pins"
              :key="message.id"
              class="group/pin rounded-md border bg-muted/30 p-2 transition hover:bg-muted/60"
            >
              <div class="flex items-baseline gap-2">
                <span class="truncate text-sm font-medium">{{ message.user.name }}</span>
                <span class="shrink-0 text-xs text-muted-foreground">{{ formatSharedAt(message.created_at) }}</span>
                <button
                  class="ml-auto shrink-0 rounded p-1 text-muted-foreground opacity-0 transition hover:text-destructive group-hover/pin:opacity-100"
                  title="Unpin"
                  @click="togglePin(message.id)"
                >
                  <PinOff class="h-3.5 w-3.5" />
                </button>
              </div>

              <p class="line-clamp-3 whitespace-pre-wrap break-words text-sm text-foreground">
                {{ pinPreview(message) }}
              </p>

              <div class="mt-1 flex items-center gap-1 text-xs text-muted-foreground">
                <span v-if="message.pinned_by" class="truncate">Pinned by {{ message.pinned_by }}</span>
                <!--
                  Same rule as the Links tab: the jump pages the channel backwards until it
                  finds the message, which will never surface one that lives in a thread.
                -->
                <button
                  v-if="!message.thread_id"
                  class="ml-auto flex shrink-0 items-center gap-1 rounded px-1 py-0.5 opacity-0 transition hover:bg-muted hover:text-foreground group-hover/pin:opacity-100"
                  title="Jump to message"
                  @click="emit('jump', message.id)"
                >
                  <CornerUpLeft class="h-3 w-3" /> Jump
                </button>
                <span v-else class="ml-auto shrink-0 opacity-70">in a thread</span>
              </div>
            </div>
          </div>

          <Button
            v-if="hasMorePins"
            variant="outline"
            size="sm"
            class="mt-4 w-full"
            :disabled="loadingPins"
            @click="loadMorePins(channelId)"
          >
            {{ loadingPins ? 'Loading…' : 'Load more' }}
          </Button>
        </template>
      </template>

      <template v-else-if="tab === 'files'">
        <div v-if="loading && !files.length" class="flex justify-center py-6">
          <Loader2 class="h-4 w-4 animate-spin text-muted-foreground" />
        </div>

        <p v-else-if="!files.length" class="py-6 text-center text-sm text-muted-foreground">
          No files posted in this channel yet.
        </p>

        <template v-else>
          <!-- Images -->
          <section v-if="images.length" class="mb-4">
            <p class="mb-2 flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
              <ImageIcon class="h-3.5 w-3.5" /> Images ({{ images.length }})
            </p>
            <div class="grid grid-cols-3 gap-2">
              <img
                v-for="img in images"
                :key="img.id"
                :src="img.url"
                :alt="img.name"
                :title="`${img.name} · ${img.uploaded_by ?? ''}`"
                class="aspect-square w-full cursor-zoom-in rounded-md border object-cover"
                @click="onView(img)"
              >
            </div>
          </section>

          <!-- Other files -->
          <section v-if="others.length">
            <p class="mb-2 flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
              <FileText class="h-3.5 w-3.5" /> Files ({{ others.length }})
            </p>
            <div class="space-y-2">
              <div
                v-for="f in others"
                :key="f.id"
                class="flex items-center gap-2 rounded-md border bg-muted/30 p-2"
              >
                <FileText class="h-6 w-6 shrink-0 text-muted-foreground" />
                <div class="min-w-0 flex-1">
                  <p class="truncate text-sm font-medium">{{ f.name }}</p>
                  <p class="truncate text-xs text-muted-foreground">
                    {{ formatSize(f.size) }}<template v-if="f.uploaded_by"> · {{ f.uploaded_by }}</template>
                  </p>
                </div>
                <button class="rounded p-1 text-muted-foreground hover:text-foreground" title="View" @click="onView(f)">
                  <Eye class="h-4 w-4" />
                </button>
                <button class="rounded p-1 text-muted-foreground hover:text-foreground" title="Download" @click="triggerDownload(f)">
                  <Download class="h-4 w-4" />
                </button>
              </div>
            </div>
          </section>

          <Button
            v-if="hasMore"
            variant="outline"
            size="sm"
            class="mt-4 w-full"
            :disabled="loading"
            @click="loadMore(channelId)"
          >
            {{ loading ? 'Loading…' : 'Load more' }}
          </Button>
        </template>
      </template>

      <!-- LINKS -->
      <template v-else-if="tab === 'links'">
        <div v-if="loadingLinks && !links.length" class="flex justify-center py-6">
          <Loader2 class="h-4 w-4 animate-spin text-muted-foreground" />
        </div>

        <p v-else-if="!links.length" class="py-6 text-center text-sm text-muted-foreground">
          No links posted in this channel yet.
        </p>

        <template v-else>
          <div class="space-y-2">
            <div
              v-for="link in links"
              :key="`${link.message_id}-${link.id}`"
              class="group/link rounded-md border bg-muted/30 p-2 transition hover:bg-muted/60"
            >
              <div class="flex gap-2">
                <img
                  v-if="link.image_url"
                  :src="link.image_url"
                  alt=""
                  loading="lazy"
                  class="h-10 w-10 shrink-0 rounded object-cover"
                >
                <span v-else class="grid h-10 w-10 shrink-0 place-items-center rounded bg-muted text-muted-foreground">
                  <Link2 class="h-4 w-4" />
                </span>

                <a
                  :href="link.url"
                  target="_blank"
                  rel="noopener noreferrer nofollow"
                  class="min-w-0 flex-1 no-underline"
                >
                  <p class="truncate text-sm font-medium text-foreground">{{ linkTitle(link) }}</p>
                  <p class="truncate text-xs text-muted-foreground">{{ hostOf(link.url) }}</p>
                </a>
              </div>

              <div class="mt-1 flex items-center gap-1 pl-12 text-xs text-muted-foreground">
                <span class="truncate">{{ link.shared_by }} · {{ formatSharedAt(link.shared_at) }}</span>
                <!--
                  Only for the main timeline: the jump pages the channel backwards until it
                  finds the message, which will never surface one that lives in a thread.
                -->
                <button
                  v-if="!link.thread_id"
                  class="ml-auto flex shrink-0 items-center gap-1 rounded px-1 py-0.5 opacity-0 transition hover:bg-muted hover:text-foreground group-hover/link:opacity-100"
                  title="Jump to message"
                  @click="emit('jump', link.message_id)"
                >
                  <CornerUpLeft class="h-3 w-3" /> Jump
                </button>
                <span v-else class="ml-auto shrink-0 opacity-70">in a thread</span>
              </div>
            </div>
          </div>

          <Button
            v-if="hasMoreLinks"
            variant="outline"
            size="sm"
            class="mt-4 w-full"
            :disabled="loadingLinks"
            @click="loadMoreLinks(channelId)"
          >
            {{ loadingLinks ? 'Loading…' : 'Load more' }}
          </Button>
        </template>
      </template>
    </div>
  </aside>
</template>
