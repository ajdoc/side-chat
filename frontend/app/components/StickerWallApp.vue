<script setup lang="ts">
import { ChevronLeft, Maximize, Pencil, Plus, Trash2, X, ZoomIn, ZoomOut } from 'lucide-vue-next'
import type { AppSticker } from '~/types'
import type { StickerContent } from '~/lib/stickers'
import { emptySticker } from '~/lib/stickers'

/**
 * The Sticker Wall — a channel's collage, built one doodle at a time.
 *
 * Two screens like the other apps: the wall, and the editor you draw a sticker in. Placement is
 * a drag on the wall itself rather than a coordinate field, because the whole appeal is putting
 * your thing next to somebody else's.
 *
 * Overlap is allowed and wanted. A wall where nothing may touch is a grid, and a grid is not
 * what makes a collage read as one picture — see the stickers migration.
 */
const props = defineProps<{
  basePath: string
  streamName: string
  canEdit: boolean
}>()

const { stickers, loaded, open, add, patch, remove, bringToFront } = useAppStickers(
  props.basePath, props.streamName,
)

open()

const { user } = useAuth()

const editing = ref(false)
const editorInitial = ref<StickerContent>(emptySticker())
const editorName = ref('New Sticker')
/** Set when an existing sticker is being redrawn rather than a new one created. */
const editingId = ref<number | null>(null)

function newSticker() {
  editorInitial.value = emptySticker()
  editorName.value = 'New Sticker'
  editingId.value = null
  editing.value = true
}

function editSticker(s: AppSticker) {
  editorInitial.value = s.content as StickerContent
  editorName.value = s.name ?? 'Sticker'
  editingId.value = s.id
  editing.value = true
}

async function onSave({ content, name }: { content: StickerContent, name: string }) {
  if (editingId.value != null) {
    await patch(editingId.value, { content, name })
  }
  else {
    // Dropped near the middle with a small scatter, so two people placing at once don't land
    // exactly on top of each other — and so a fresh sticker is somewhere you can see it.
    await add({
      content,
      name,
      x: 260 + Math.round((Math.random() - 0.5) * 220),
      y: 180 + Math.round((Math.random() - 0.5) * 160),
      w: 160,
      h: 160,
    })
  }
  editing.value = false
}

/** The wall's own size, in wall coordinates — the space stickers are placed in. */
const WALL_W = 1600
const WALL_H = 1200

// --- zoom ---------------------------------------------------------------------------------

/**
 * How far in or out the wall is drawn.
 *
 * The wall is 1600×1200 and deliberately larger than any window — that's what makes it feel
 * like somewhere you can keep adding to. The cost is that you can never see the collage as a
 * whole, which is the thing worth looking at, so zoom is what makes the wall readable rather
 * than a convenience.
 *
 * Bounds and step match the Board's, so the two canvases in this app behave the same way.
 */
const MIN_ZOOM = 0.2
const MAX_ZOOM = 3

const zoom = ref(1)
const viewport = ref<HTMLElement | null>(null)

function setZoom(next: number) {
  zoom.value = Math.min(MAX_ZOOM, Math.max(MIN_ZOOM, next))
}

/**
 * Zoom about a point, keeping whatever is under it still.
 *
 * Scaling alone moves the wall out from under the cursor, so every wheel notch drifts the thing
 * you were aiming at off screen. Holding the anchor fixed is what makes wheel-zoom feel like
 * zoom rather than like scrolling and scaling at once.
 */
function zoomAt(next: number, clientX: number, clientY: number) {
  const box = viewport.value
  if (!box) return setZoom(next)

  const before = zoom.value
  setZoom(next)
  const after = zoom.value
  if (after === before) return

  const rect = box.getBoundingClientRect()
  // Where the anchor sits in wall coordinates, which don't change as we scale.
  const wallX = (box.scrollLeft + clientX - rect.left) / before
  const wallY = (box.scrollTop + clientY - rect.top) / before

  // Put that same wall point back under the cursor at the new scale.
  box.scrollLeft = wallX * after - (clientX - rect.left)
  box.scrollTop = wallY * after - (clientY - rect.top)
}

/**
 * Wheel zooms; plain wheel still scrolls.
 *
 * Matching the Board: the wall is a scrolling surface, and stealing plain wheel would break the
 * ordinary way of getting around it. Ctrl/⌘ is also what a trackpad pinch sends, so pinching
 * lands here for free.
 */
function onWheel(e: WheelEvent) {
  if (!e.ctrlKey && !e.metaKey) return
  e.preventDefault()
  zoomAt(zoom.value * (e.deltaY < 0 ? 1.1 : 1 / 1.1), e.clientX, e.clientY)
}

/**
 * Shrink until the whole wall fits the window.
 *
 * The more useful of the two reset buttons, and the reason zoom was worth adding: it is the
 * only way to see the collage as one picture.
 */
function fitWall() {
  const box = viewport.value
  if (!box) return
  setZoom(Math.min(box.clientWidth / WALL_W, box.clientHeight / WALL_H))
  box.scrollLeft = 0
  box.scrollTop = 0
}

// --- dragging on the wall ---------------------------------------------------------------

const wall = ref<HTMLElement | null>(null)
const dragging = ref<{ id: number, dx: number, dy: number } | null>(null)

function mayMove(s: AppSticker) {
  // Mirrors the server's rule: your own sticker, or staff. Staff aren't distinguishable from
  // here, so the client offers what it knows and the server has the final say.
  return props.canEdit && s.user?.id === user.value?.id
}

function startDrag(e: PointerEvent, s: AppSticker) {
  if (!mayMove(s)) return
  const box = wall.value!.getBoundingClientRect()
  // Divided by the zoom, because `getBoundingClientRect` reports the *scaled* box while a
  // sticker's x/y are in unscaled wall coordinates. Without this a sticker dragged at 50% zoom
  // travels twice as far as the pointer.
  dragging.value = {
    id: s.id,
    dx: (e.clientX - box.left) / zoom.value - s.x,
    dy: (e.clientY - box.top) / zoom.value - s.y,
  }
  ;(e.target as Element).setPointerCapture(e.pointerId)
  // Picking a sticker up puts it on top, which is what a physical wall does and what makes
  // overlap workable.
  void bringToFront(s.id)
}

/**
 * Live position while dragging, so the sticker follows the pointer without a request per pixel.
 *
 * The move is committed once, on release — see `endDrag`.
 */
const livePos = ref<{ x: number, y: number } | null>(null)

function onDrag(e: PointerEvent) {
  if (!dragging.value || !wall.value) return
  const box = wall.value.getBoundingClientRect()
  livePos.value = {
    x: Math.round((e.clientX - box.left) / zoom.value - dragging.value.dx),
    y: Math.round((e.clientY - box.top) / zoom.value - dragging.value.dy),
  }
}

async function endDrag() {
  const drag = dragging.value
  const pos = livePos.value
  dragging.value = null
  livePos.value = null
  if (drag && pos) await patch(drag.id, pos)
}

function positionOf(s: AppSticker) {
  return dragging.value?.id === s.id && livePos.value ? livePos.value : { x: s.x, y: s.y }
}

/**
 * Which sticker's discussion is open.
 *
 * The wall has no detail *screen* — going somewhere else to read a comment about a collage
 * would lose the collage, which is the thing being talked about. So it's a panel beside the
 * wall, and the sticker stays in view.
 */
const inspecting = ref<AppSticker | null>(null)

watch(stickers, (list: AppSticker[]) => {
  // Somebody else removed the sticker you had open.
  if (inspecting.value && !list.some(s => s.id === inspecting.value!.id)) inspecting.value = null
})

async function onRemove(s: AppSticker) {
  // eslint-disable-next-line no-alert
  if (!window.confirm(`Remove “${s.name ?? 'this sticker'}” from the wall?`)) return
  if (inspecting.value?.id === s.id) inspecting.value = null
  await remove(s.id)
}
</script>

<template>
  <div class="flex min-h-0 flex-1 flex-col">
    <header class="flex h-12 shrink-0 items-center gap-2 border-b px-2 sm:px-3">
      <button
        v-if="editing"
        type="button"
        class="flex items-center gap-1 rounded-md border px-2 py-1.5 text-sm transition-colors hover:bg-muted"
        @click="editing = false"
      >
        <ChevronLeft class="h-4 w-4" /> Back
      </button>
      <p class="truncate font-semibold">{{ editing ? 'Draw a sticker' : 'Sticker Wall' }}</p>
      <span class="flex-1" />
      <button
        v-if="canEdit && !editing"
        type="button"
        class="flex items-center gap-1.5 rounded-md border px-2.5 py-1.5 text-xs transition-colors hover:bg-muted"
        @click="newSticker"
      >
        <Plus class="h-3.5 w-3.5" /> Create
      </button>
    </header>

    <StickerEditor
      v-if="editing"
      :initial="editorInitial"
      :name="editorName"
      @save="onSave"
      @cancel="editing = false"
    />

    <div v-else-if="!loaded" class="grid flex-1 place-items-center text-sm text-muted-foreground">
      Loading…
    </div>

    <!-- The wall. Scrolls in both directions and is deliberately larger than the viewport: a
         wall you can fill is more inviting than one that's already full. -->
    <div v-else class="relative min-h-0 flex-1">
      <!--
        Zoom, floated over the wall.

        Outside the scroller and absolute against this wrapper, so the controls stay put while
        the collage scrolls under them — and so they take no space in the scrolling area, which
        a sticky element inside it would. Top-left, leaving the bottom-right to the chat pill.
      -->
      <div class="absolute left-2 top-2 z-20 flex w-max items-center gap-1 rounded-lg border bg-background/90 p-1 shadow backdrop-blur">
        <button
          type="button"
          class="grid h-7 w-7 place-items-center rounded text-muted-foreground transition-colors hover:bg-muted disabled:opacity-40"
          title="Zoom out"
          :disabled="zoom <= MIN_ZOOM"
          @click="setZoom(zoom / 1.25)"
        >
          <ZoomOut class="h-4 w-4" />
        </button>
        <button
          type="button"
          class="grid h-7 min-w-11 place-items-center rounded px-1 text-[11px] tabular-nums text-muted-foreground transition-colors hover:bg-muted"
          title="Back to actual size"
          @click="setZoom(1)"
        >
          {{ Math.round(zoom * 100) }}%
        </button>
        <button
          type="button"
          class="grid h-7 w-7 place-items-center rounded text-muted-foreground transition-colors hover:bg-muted disabled:opacity-40"
          title="Zoom in"
          :disabled="zoom >= MAX_ZOOM"
          @click="setZoom(zoom * 1.25)"
        >
          <ZoomIn class="h-4 w-4" />
        </button>
        <button
          type="button"
          class="grid h-7 w-7 place-items-center rounded text-muted-foreground transition-colors hover:bg-muted"
          title="Fit the whole wall"
          @click="fitWall"
        >
          <Maximize class="h-4 w-4" />
        </button>
      </div>

      <div ref="viewport" class="h-full w-full overflow-auto" @wheel="onWheel">
        <!--
          Two nested boxes, and both are needed.

          The inner one is the wall at its true size, scaled with `transform` from its top-left
          — transforms are what keep the drawing sharp and the sticker coordinates unscaled. But
          a transform doesn't change layout, so the scroll area would stay 1600×1200 whatever
          the zoom: zoomed out you'd be stranded in a scroller far larger than its contents, and
          zoomed in the far edge would be unreachable. The outer box carries the *scaled* size so
          the scrollbars tell the truth.
        -->
        <div
          class="relative"
          :style="{ width: `${WALL_W * zoom}px`, height: `${WALL_H * zoom}px` }"
        >
          <div
            ref="wall"
            class="absolute left-0 top-0 origin-top-left"
            :style="{ width: `${WALL_W}px`, height: `${WALL_H}px`, transform: `scale(${zoom})` }"
            @pointermove="onDrag"
            @pointerup="endDrag"
            @pointercancel="endDrag"
          >
        <div
          v-for="s in stickers"
          :key="s.id"
          class="group absolute select-none"
          :class="mayMove(s) ? 'cursor-grab active:cursor-grabbing' : 'cursor-default'"
          :style="{
            left: `${positionOf(s).x}px`,
            top: `${positionOf(s).y}px`,
            width: `${s.w}px`,
            height: `${s.h}px`,
            transform: `rotate(${s.rotation}deg)`,
            zIndex: s.z,
          }"
          :title="s.user ? `${s.name ?? 'Sticker'} — ${s.user.name}` : (s.name ?? 'Sticker')"
          @pointerdown="startDrag($event, s)"
          @click="inspecting = s"
          @dblclick="mayMove(s) && editSticker(s)"
        >
          <StickerCanvas :content="s.content as StickerContent" />

          <!-- Yours to edit or remove, on hover. Deliberately small and unlabelled: the wall is
               the content, and permanent buttons on every tile would be the loudest thing on it.
               Redrawing used to be double-click only, which nobody finds. -->
          <span
            v-if="mayMove(s)"
            class="absolute -right-1 -top-1 flex gap-0.5 opacity-0 transition-opacity group-hover:opacity-100"
          >
            <button
              type="button"
              class="grid h-5 w-5 place-items-center rounded-full border bg-background text-muted-foreground shadow transition-colors hover:text-foreground"
              title="Redraw this sticker"
              @pointerdown.stop
              @click.stop="editSticker(s)"
            >
              <Pencil class="h-2.5 w-2.5" />
            </button>
            <button
              type="button"
              class="grid h-5 w-5 place-items-center rounded-full border bg-background text-red-500 shadow transition-colors hover:bg-red-500/10"
              title="Remove from the wall"
              @pointerdown.stop
              @click.stop="onRemove(s)"
            >
              <Trash2 class="h-2.5 w-2.5" />
            </button>
          </span>
        </div>

            <p v-if="!stickers.length" class="absolute left-1/2 top-24 -translate-x-1/2 text-sm text-muted-foreground">
              The wall is empty — draw the first sticker.
            </p>
          </div>
        </div>
      </div>

      <!--
        The open sticker's discussion, beside the wall rather than over it — a comment about a
        collage is best read with the collage still on screen.

        Full-width on a phone, where a side panel would leave neither half usable.
      -->
      <aside
        v-if="inspecting"
        class="absolute inset-y-0 right-0 z-30 flex w-full flex-col border-l bg-background/95 backdrop-blur sm:w-72"
      >
        <header class="flex shrink-0 items-center gap-2 border-b p-2">
          <span class="h-8 w-8 shrink-0">
            <StickerCanvas :content="inspecting.content as StickerContent" />
          </span>
          <span class="min-w-0 flex-1">
            <span class="block truncate text-sm font-medium">{{ inspecting.name ?? 'Sticker' }}</span>
            <span class="block truncate text-[11px] text-muted-foreground">
              {{ inspecting.user?.name ?? 'Someone' }}
            </span>
          </span>
          <button
            v-if="mayMove(inspecting)"
            type="button"
            class="grid h-7 w-7 shrink-0 place-items-center rounded text-muted-foreground transition-colors hover:bg-muted"
            title="Redraw this sticker"
            @click="editSticker(inspecting)"
          >
            <Pencil class="h-4 w-4" />
          </button>
          <button
            type="button"
            class="grid h-7 w-7 shrink-0 place-items-center rounded text-muted-foreground transition-colors hover:bg-muted"
            title="Close"
            @click="inspecting = null"
          >
            <X class="h-4 w-4" />
          </button>
        </header>

        <div class="min-h-0 flex-1 overflow-y-auto p-2">
          <!-- The same component the Calendar and the Polls app use. It knows nothing about
               stickers — that's what the polymorphic tables bought. -->
          <AppItemDiscussion
            :key="inspecting.id"
            :base-path="basePath"
            subject="app_sticker"
            :item-id="inspecting.id"
            :can-edit="canEdit"
          />
        </div>
      </aside>
    </div>
  </div>
</template>
