<script setup lang="ts">
import {
  DoorOpen, Eraser, LayoutTemplate, Loader2, MousePointer2, RotateCcw, RotateCw, Sofa,
  SquareDashed, Trash2, X, ZoomIn, ZoomOut,
} from 'lucide-vue-next'
import type { Camera, MapTheme, SpaceMap, SpaceZone } from '~/lib/spaceMapEngine'
import type { DecorFacing, SpaceObject } from '~/lib/spaceDecor'
import type { MapPreset } from '~/composables/useSpacePresets'
import {
  TILE,
  TILE_BRUSHES,
  VOID,
  ZOOM_STEP,
  blankTiles,
  drawMap,
  isWalkable,
  resizeTiles,
  toScreen,
  toTile,
  zoomAround,
} from '~/lib/spaceMapEngine'
import { DECOR, DECOR_FACINGS, decorCovers, decorSize } from '~/lib/spaceDecor'
import { isWalkableTile } from '~/lib/spaceTiles'
import { Button } from '~/components/ui/button'
import { Input } from '~/components/ui/input'
import { Label } from '~/components/ui/label'

/**
 * Building the room. Owner only — the stage hides the way in from everybody else, and the API
 * refuses it besides, because this replaces the floor under people who are standing on it.
 *
 * Edits are local until Save. That's deliberate: painting is a drag, and broadcasting every
 * tile under the brush would be both a flood and a room that flickers for everyone else while
 * one person makes up their mind. One save, one broadcast, one new room.
 *
 * The whole grid is drawn with the same {@link drawMap} the stage uses, so what you paint is
 * literally what everybody will see — there is no second renderer to drift out of step with the
 * first, and furniture previews as the sprite it will be rather than as a coloured box.
 *
 * ## Two layers, one brush
 *
 * The ground is a grid of characters and the furniture is a list of things standing on it, so
 * the brush is really two brushes wearing one coat: a tile character to paint, or a kind of
 * furniture to place. Both are checked against the same rules the server will apply on save —
 * a painting refuses to go anywhere but a wall, a desk refuses to go anywhere but the floor —
 * because being told on save is a much worse way to find out than simply not being able to put
 * it there.
 */
/**
 * `mode` is how much of the room this session is editing — no longer who is editing it.
 *
 *   - `full` — the whole room: ground, furniture, rooms, the entrance, the size. Saves the map
 *     entire, and is the only way any of the geometry changes.
 *   - `decor` — the furniture and nothing else. The ground shows through as a backdrop you can't
 *     paint, and Save writes only the furniture, through the furniture-only endpoint.
 *
 * Both are open to any member now; the modes stayed because "I want to move a couch" and "I want
 * to rebuild the room" are still different jobs, and offering the second when somebody asked for
 * the first is how rooms get rebuilt by accident.
 *
 * One component rather than two because the *canvas* — how a room is drawn, how a piece previews,
 * how placement is checked — is identical either way. All `mode` changes is which tools appear
 * and which endpoint Save calls.
 */
const props = withDefaults(defineProps<{ channelId: number, map: SpaceMap, mode?: 'full' | 'decor' }>(), {
  mode: 'full',
})
const emit = defineEmits<{ close: [], saved: [] }>()

const { save, saveObjects } = useSpaceMap(props.channelId)

const isDecorMode = computed(() => props.mode === 'decor')

type Tool = 'select' | 'tile' | 'decor' | 'spawn' | 'zone' | 'erase-zone' | 'erase-decor' | 'erase-tile'

/** The full toolbox. In decorate mode only the furniture tools survive — see {@link TOOLS}. */
const ALL_TOOLS: { id: Tool, label: string, icon: any, hint: string, decor?: boolean }[] = [
  { id: 'select', label: 'Move things', icon: MousePointer2, hint: 'Pick a thing up, turn it, or take it away', decor: true },
  { id: 'spawn', label: 'Entrance', icon: DoorOpen, hint: 'Where people arrive' },
  { id: 'zone', label: 'Room', icon: SquareDashed, hint: 'Drag out a sealed room — inside hears inside only' },
  // Taking the ground away is its own tool rather than only the last swatch in the Ground row.
  // It was reachable there — "Nothing" paints the void — but a transparent square at the end of
  // twelve coloured ones is not where anybody looks for an eraser, and rubbing a wall out is a
  // dragged gesture like painting one, not a colour you happen to choose.
  { id: 'erase-tile', label: 'Erase ground', icon: Eraser, hint: 'Drag to rub out wall and floor, back to nothing' },
  { id: 'erase-zone', label: 'Erase room', icon: SquareDashed, hint: 'Click a room to remove it' },
  { id: 'erase-decor', label: 'Remove furniture', icon: Trash2, hint: 'Click a thing to take it away', decor: true },
]

const TOOLS = computed(() => (isDecorMode.value ? ALL_TOOLS.filter(t => t.decor) : ALL_TOOLS))

/** Which way "facing left" points, said the way somebody laying out a room would say it. */
const FACING_LABELS: Record<DecorFacing, string> = {
  down: 'forwards',
  left: 'left',
  up: 'away',
  right: 'right',
}

/** Furniture, grouped the way somebody furnishing a room thinks about it. */
const DECOR_GROUPS: { title: string, kinds: string[] }[] = [
  // Doors lead, because a door is the first thing you place after a wall: it's the hole in it.
  { title: 'Ways through', kinds: ['door', 'gate'] },
  { title: 'Things that do something', kinds: ['speaker', 'tv', 'computer', 'arcade', 'racer', 'easel', 'noticeboard'] },
  // The Side Desk's own apps, standing in the room: the whiteboard *is* the Board tab.
  { title: 'Your Side Desk, in the room', kinds: ['whiteboard', 'lectern', 'planner', 'filecabinet'] },
  { title: 'Furniture', kinds: ['desk', 'couch', 'bench', 'chair', 'stool', 'throne', 'bookshelf', 'cabinet', 'fridge', 'watercooler', 'lamp'] },
  // The stone set. Built for the gyms, but it's what turns any room into a hall rather than an
  // office, so the themed presets lean on it too — and a room you can't add a pillar to is a
  // room you can't finish.
  { title: 'Stone and ceremony', kinds: ['pillar', 'statue', 'torch', 'boulder'] },
  { title: 'Bits and pieces', kinds: ['plant', 'plush', 'plush_vessel', 'plush_pickachu', 'crate', 'barrel', 'campfire', 'rug', 'mat'] },
  { title: 'On the wall', kinds: ['painting', 'poster', 'window', 'clock', 'shelf'] },
]

// A working copy. Nothing here touches the live map until Save.
const name = ref(props.map.name)
const width = ref(props.map.width)
const height = ref(props.map.height)
const tiles = ref<string[]>([...props.map.tiles])
const zones = ref<SpaceZone[]>(props.map.zones.map(z => ({ ...z })))
const objects = ref<SpaceObject[]>((props.map.objects ?? []).map(o => ({ ...o })))
const spawn = ref({ ...props.map.spawn })

// Decorate mode has no ground brush, so it opens on the furniture; full mode opens on the wall.
const tool = ref<Tool>(props.mode === 'decor' ? 'decor' : 'tile')
/** Which tile character the ground brush paints, and which kind the furniture brush places. */
const tile = ref<string>('#')
const decor = ref<string>('speaker')
/** Which way the *next* piece goes down. Turning a piece that's already down is separate. */
const facing = ref<DecorFacing>('down')
/**
 * The piece being worked on, if any.
 *
 * Held as an id rather than as the object, because everything that edits the room replaces the
 * `objects` array wholesale — a held reference would quietly become a copy of how the piece used
 * to be, and the chips beside it would move something that no longer exists.
 */
const selectedId = ref<string | null>(null)
const saving = ref(false)
const error = ref('')
/** Set when a piece can't go where you clicked, and cleared as soon as one can. */
const refused = ref('')

/**
 * The tool rail, on a screen that can't spare 256px for it.
 *
 * Painting already works with a finger — the grid takes pointers, not mouse events — but a
 * fixed rail beside a phone-width canvas leaves a strip of room too small to aim at. So on a
 * narrow window the rail becomes a drawer over the grid, and picking a brush closes it: you
 * choose a thing, then you place it, and the grid has the screen for the half that matters.
 */
const { narrow } = useNavDrawer()
const showTools = ref(false)

/** Picking a brush on a phone means "now let me put it down" — so the drawer gets out of the way. */
function chose() {
  if (narrow.value) showTools.value = false
}

const canvas = ref<HTMLCanvasElement | null>(null)
const wrap = ref<HTMLElement | null>(null)
const camera = reactive<Camera>({ x: 0, y: 0, zoom: 1, width: 0, height: 0 })

/**
 * Swapping the room for one of the starting layouts.
 *
 * The same list the channel-creation page picks from, and the reason a room isn't stuck with
 * whatever it was born as: you can start over from Office, or turn the place into a gym, without
 * deleting the channel. Owner-only, like every other geometry change — the panel is hidden in
 * decorate mode, and the save it feeds is the owner's endpoint either way.
 *
 * Behind a toggle rather than always open, because it *replaces the room* and shouldn't be one
 * stray click away in a panel you're using to paint.
 */
const { grouped: groupedPresets, load: loadPresets, loading: loadingPresets } = useSpacePresets()

/**
 * The ways a *room* can come furnished, and which one new rooms are drawn as.
 *
 * A different catalogue from the one above, and the distinction is the whole point: those
 * replace the map, these fill a rectangle inside it. Fetched when the room tool is first
 * reached for rather than on mount, since most editing sessions never draw a room at all.
 */
const { presets: roomPresets, load: loadRoomPresets } = useSpaceRoomPresets()
const roomStyle = ref('empty')

watch(tool, (t) => { if (t === 'zone') void loadRoomPresets() }, { immediate: true })
const showPresets = ref(false)
/** Which preset was last loaded, so the panel can show what you're now working from. */
const loadedPreset = ref<string | null>(null)

/** An in-progress zone drag, in tiles. */
let zoneDrag: { x0: number, y0: number, x1: number, y1: number } | null = null
/**
 * The piece being dragged, and *where in it* the pointer took hold.
 *
 * The offset is the whole reason this isn't just "put the piece under the cursor": grabbing a
 * two-tile couch by its right end and having it jump so its left end is under your finger is the
 * kind of small wrongness that makes a room impossible to lay out.
 */
let dragging: { id: string, ox: number, oy: number } | null = null
let painting = false
/** Every pointer on the canvas. One paints; two pinch and pan. */
const touches = new Map<number, { x: number, y: number }>()
/** A two-finger gesture: the gap between the fingers and where their midpoint was. */
let pinch: { gap: number, x: number, y: number } | null = null
/** A middle-button pan, in client pixels. */
let panning: { x: number, y: number } | null = null
/** Whether the camera is where *you* put it, rather than where {@link fit} put it. */
let moved = false
let frame: number | undefined
let ro: ResizeObserver | undefined
let openedAt = performance.now()

/** The working map, in the shape the renderer and the API both want. */
const draft = computed<SpaceMap>(() => ({
  id: props.map.id,
  channel_id: props.map.channel_id,
  name: name.value,
  width: width.value,
  height: height.value,
  tiles: tiles.value,
  zones: zones.value,
  objects: objects.value,
  spawn: spawn.value,
}))

// --- painting ---

function setTile(x: number, y: number, char: string) {
  if (x < 0 || y < 0 || x >= width.value || y >= height.value) return

  const row = tiles.value[y]
  if (!row || row[x] === char) return

  tiles.value = tiles.value.map((r, i) => (i === y ? r.slice(0, x) + char + r.slice(x + 1) : r))
}

function paintAt(px: number, py: number) {
  const { x, y } = toTile(camera, px, py)

  if (tool.value === 'select') return
  if (tool.value === 'tile') return setTile(x, y, tile.value)
  // Rubbing out is painting the void: solid, drawn as nothing, and the character a room's
  // outside is already made of. So an erased wall is not a hole in the map — it's the map
  // ending there, which is what somebody rubbing out a wall means.
  if (tool.value === 'erase-tile') return setTile(x, y, VOID)
  if (tool.value === 'decor') return placeDecor(x, y)

  if (tool.value === 'spawn') {
    // The entrance has to be somewhere you can stand — the API refuses otherwise, and being
    // told so on save is a worse way to find out than simply not being able to place it.
    if (isWalkable(draft.value, x, y)) spawn.value = { x, y }

    return
  }

  if (tool.value === 'erase-zone') {
    zones.value = zones.value.filter(z => !(x >= z.x && x < z.x + z.w && y >= z.y && y < z.y + z.h))
  }

  if (tool.value === 'erase-decor') {
    objects.value = objects.value.filter((o) => {
      const kind = DECOR[o.kind]

      return !kind || !decorCovers(o, kind, x, y)
    })
  }
}

/**
 * Why a piece can't stand at `x, y` turned that way — or null, meaning it can.
 *
 * The server's three rules, checked here so the answer is immediate: it has to fit on the map,
 * it has to be on the right sort of tile for how it's mounted, and two solid things can't share
 * a square. Overlapping a *flat* thing is fine and rather the point — a desk on a rug, a chair
 * tucked under it.
 *
 * One rulebook for all three things that need it: putting a piece down, dragging one somewhere
 * else, and turning one where it stands. A move is checked with `ignore` set to the piece being
 * moved, or every piece would collide with the copy of itself it hasn't left yet.
 */
function refusalFor(kindName: string, x: number, y: number, face: DecorFacing, ignore?: string): string | null {
  const kind = DECOR[kindName]
  if (!kind) return 'That is not a kind of furniture we know.'

  // As placed, not as catalogued: a turned desk needs 1×2 where an unturned one needs 2×1.
  const { w, h } = decorSize({ id: '', kind: kindName, x, y, facing: face }, kind)

  if (x < 0 || y < 0 || x + w > width.value || y + h > height.value) return 'It doesn’t fit there.'

  for (let dy = y; dy < y + h; dy++) {
    for (let dx = x; dx < x + w; dx++) {
      const ground = tiles.value[dy]?.[dx] ?? ' '

      if (kind.mount === 'wall' && isWalkableTile(ground)) {
        return `A ${kind.label.toLowerCase()} has to hang on a wall.`
      }

      if (kind.mount === 'floor' && !isWalkableTile(ground)) {
        return `A ${kind.label.toLowerCase()} has to stand on the floor.`
      }

      if (kind.solid && objects.value.some((o) => {
        if (o.id === ignore) return false
        const other = DECOR[o.kind]

        return other?.solid && decorCovers(o, other, dx, dy)
      })) {
        return 'Something’s already there.'
      }
    }
  }

  return null
}

/** Put a piece of furniture down, or say why not. */
function placeDecor(x: number, y: number) {
  const why = refusalFor(decor.value, x, y, facing.value)
  if (why) return refuse(why)

  refused.value = ''
  objects.value = [...objects.value, {
    id: `d-${Date.now().toString(36)}-${objects.value.length}`,
    kind: decor.value,
    x,
    y,
    facing: facing.value,
  }]
}

/**
 * Lay an existing room out again, from the dropdown beside its name.
 *
 * The select goes back to its placeholder afterwards, because it's a *verb* rather than a
 * property of the room. A room doesn't have a style — it has whatever furniture happens to be
 * standing in it, which you're free to rearrange straight afterwards, and leaving "Throne room"
 * selected in the box would claim otherwise.
 */
function restyleZone(zone: SpaceZone, key: string, select?: HTMLSelectElement) {
  if (key) furnishZone(zone, key)
  if (select) select.value = ''
}

function refuse(why: string) {
  refused.value = why
}

// --- picking a piece up ---

const selected = computed(() => objects.value.find(o => o.id === selectedId.value) ?? null)

/** The piece on a tile, topmost first — the last one in the list is the last one drawn. */
function objectAt(x: number, y: number): SpaceObject | null {
  for (let i = objects.value.length - 1; i >= 0; i--) {
    const o = objects.value[i]!
    const kind = DECOR[o.kind]
    if (kind && decorCovers(o, kind, x, y)) return o
  }

  return null
}

/** Replace one piece in place, leaving the rest of the room alone. */
function patchObject(id: string, patch: Partial<SpaceObject>) {
  objects.value = objects.value.map(o => (o.id === id ? { ...o, ...patch } : o))
}

function removeSelected() {
  if (!selectedId.value) return

  objects.value = objects.value.filter(o => o.id !== selectedId.value)
  selectedId.value = null
  refused.value = ''
}

/**
 * Turn something a quarter, clockwise or back.
 *
 * With a piece selected it turns *that*; with nothing selected it turns the brush, so you can
 * decide which way a couch faces before you put it down rather than only afterwards. A turn that
 * wouldn't fit is refused rather than applied, for the same reason a placement is: finding out
 * on save is worse than not being able to do it.
 */
function turn(step: 1 | -1) {
  const piece = selected.value

  if (!piece) {
    facing.value = turned(facing.value, step)

    return
  }

  const next = turned(piece.facing ?? 'down', step)
  const why = refusalFor(piece.kind, piece.x, piece.y, next, piece.id)
  if (why) return refuse(why)

  refused.value = ''
  patchObject(piece.id, { facing: next })
}

function turned(from: DecorFacing, step: 1 | -1): DecorFacing {
  const at = DECOR_FACINGS.indexOf(from)

  return DECOR_FACINGS[(at + step + DECOR_FACINGS.length) % DECOR_FACINGS.length]!
}

/** Move the selection by a tile — the keyboard's version of dragging it. */
function nudge(dx: number, dy: number) {
  const piece = selected.value
  if (!piece) return

  const why = refusalFor(piece.kind, piece.x + dx, piece.y + dy, piece.facing ?? 'down', piece.id)
  if (why) return refuse(why)

  refused.value = ''
  patchObject(piece.id, { x: piece.x + dx, y: piece.y + dy })
}

/**
 * The selection's box on screen, for the chips that hang off it.
 *
 * Recomputed from the camera rather than stored, so it follows a zoom or a resize without anyone
 * having to remember to move it.
 */
const selectionBox = computed(() => {
  const piece = selected.value
  const kind = piece ? DECOR[piece.kind] : null
  if (!piece || !kind) return null

  const { w, h } = decorSize(piece, kind)
  const size = TILE * camera.zoom
  const p = toScreen(camera, piece.x - 0.5, piece.y - 0.5)

  return { x: p.x, y: p.y, w: w * size, h: h * size, label: kind.label }
})

/** Take hold of whatever is under the pointer — or, on empty floor, let go of what was held. */
function grab(px: number, py: number) {
  const { x, y } = toTile(camera, px, py)
  const piece = objectAt(x, y)

  refused.value = ''
  selectedId.value = piece?.id ?? null
  dragging = piece ? { id: piece.id, ox: x - piece.x, oy: y - piece.y } : null
}

/**
 * Drag the held piece under the pointer.
 *
 * A move that breaks a rule simply doesn't happen — the piece stays where it last legally was
 * and the drag carries on, so dragging a couch across a wall slides it along the wall rather than
 * dropping it or filling the screen with complaints. The refusal text is left to the actions that
 * are one-shot (placing, turning), where there's no next frame to say it in.
 */
function dragTo(px: number, py: number) {
  if (!dragging) return

  const piece = objects.value.find(o => o.id === dragging!.id)
  if (!piece) return (dragging = null)

  const { x, y } = toTile(camera, px, py)
  const nx = x - dragging.ox
  const ny = y - dragging.oy

  if (nx === piece.x && ny === piece.y) return
  if (refusalFor(piece.kind, nx, ny, piece.facing ?? 'down', piece.id)) return

  patchObject(piece.id, { x: nx, y: ny })
}

/**
 * The keyboard, for the fiddly half of laying out a room.
 *
 * Arrows nudge a tile at a time, which a drag can't do accurately at a zoomed-out size; R turns;
 * Delete takes a piece away; Escape lets it go. Ignored while a text field has focus, or naming a
 * room would rotate the furniture behind the dialog.
 */
function onKeydown(e: KeyboardEvent) {
  const el = e.target as HTMLElement | null
  if (el && (el.tagName === 'INPUT' || el.tagName === 'TEXTAREA' || el.isContentEditable)) return

  const key = e.key.toLowerCase()

  if (key === 'escape' && selectedId.value) {
    selectedId.value = null

    return e.preventDefault()
  }

  if (key === 'r') {
    turn(e.shiftKey ? -1 : 1)

    return e.preventDefault()
  }

  if (!selected.value) return

  if (key === 'delete' || key === 'backspace') {
    removeSelected()

    return e.preventDefault()
  }

  const step = { arrowleft: [-1, 0], arrowright: [1, 0], arrowup: [0, -1], arrowdown: [0, 1] }[key]
  if (!step) return

  nudge(step[0]!, step[1]!)
  e.preventDefault()
}

function onPointerDown(e: PointerEvent) {
  const rect = canvas.value!.getBoundingClientRect()
  const px = e.clientX - rect.left
  const py = e.clientY - rect.top

  canvas.value?.setPointerCapture(e.pointerId)

  touches.set(e.pointerId, { x: e.clientX, y: e.clientY })
  if (touches.size > 1) return startPinch()

  // A middle-drag pans wherever you are and whatever you're holding, so there's always a way to
  // reach the far side of a room you've zoomed into.
  if (e.button === 1) {
    panning = { x: e.clientX, y: e.clientY }

    return
  }

  if (tool.value === 'select') return grab(px, py)

  if (tool.value === 'zone') {
    const t = toTile(camera, px, py)
    zoneDrag = { x0: t.x, y0: t.y, x1: t.x, y1: t.y }

    return
  }

  // Furniture is placed one click at a time. Dragging a brush across the floor would leave a
  // trail of forty desks behind it, which is never what anybody meant.
  painting = tool.value !== 'decor'
  paintAt(px, py)
}

function onPointerMove(e: PointerEvent) {
  const rect = canvas.value!.getBoundingClientRect()
  const px = e.clientX - rect.left
  const py = e.clientY - rect.top

  if (touches.has(e.pointerId)) touches.set(e.pointerId, { x: e.clientX, y: e.clientY })

  if (pinch) return trackPinch()

  if (panning) {
    panBy(e.clientX - panning.x, e.clientY - panning.y)
    panning = { x: e.clientX, y: e.clientY }

    return
  }

  if (dragging) return dragTo(px, py)

  if (zoneDrag) {
    const t = toTile(camera, px, py)
    zoneDrag.x1 = t.x
    zoneDrag.y1 = t.y

    return
  }

  if (painting) paintAt(px, py)
}

function onPointerUp(e: PointerEvent) {
  canvas.value?.releasePointerCapture(e.pointerId)
  touches.delete(e.pointerId)
  painting = false
  dragging = null
  panning = null

  // Lifting a finger out of a pinch ends it; the one still down doesn't become a brush, or
  // every zoom would paint a wall on its way out.
  if (pinch) {
    if (touches.size < 2) pinch = null

    return
  }

  if (!zoneDrag) return

  const x = Math.max(0, Math.min(zoneDrag.x0, zoneDrag.x1))
  const y = Math.max(0, Math.min(zoneDrag.y0, zoneDrag.y1))
  const w = Math.min(width.value, Math.max(zoneDrag.x0, zoneDrag.x1) + 1) - x
  const h = Math.min(height.value, Math.max(zoneDrag.y0, zoneDrag.y1) + 1) - y

  zoneDrag = null

  if (w < 1 || h < 1) return

  const zone: SpaceZone = {
    id: `z-${Date.now().toString(36)}`,
    name: `Room ${zones.value.length + 1}`,
    kind: 'private',
    x,
    y,
    w,
    h,
  }

  zones.value = [...zones.value, zone]

  // A room you dragged comes out furnished, if you asked for one that does. The old behaviour
  // is still there and still the default — it's the `empty` style, which lays a floor and
  // stops, and naming it is better than hiding it.
  furnishZone(zone, roomStyle.value)
}

/**
 * Lay a room out in one of the styles: floor, then furniture.
 *
 * Three steps, in an order that matters. The floor goes down first, because the furniture
 * rulebook checks what a piece is standing on and would refuse a couch on a wall tile that is
 * about to become carpet. Then whatever was already inside the room is cleared, so re-styling a
 * room *replaces* it rather than stacking a second set of sofas on the first. Then the pieces
 * go in one at a time, each through the same {@link refusalFor} a hand-placed one goes through
 * — a preset gets no more licence than a person does, so a room drawn over a pillar simply
 * comes out with one fewer bench and no way to save a map the API would reject.
 */
function furnishZone(zone: SpaceZone, key: string) {
  const preset = roomPresets.value.find(p => p.key === key)
  if (!preset) return

  for (let dy = zone.y; dy < zone.y + zone.h; dy++) {
    for (let dx = zone.x; dx < zone.x + zone.w; dx++) setTile(dx, dy, preset.floor)
  }

  objects.value = objects.value.filter(o => !objectInZone(o, zone))

  const stamp = Date.now().toString(36)
  let n = 0

  for (const piece of anchorObjects(preset, zone)) {
    const face = piece.facing ?? 'down'
    if (refusalFor(piece.kind, piece.x, piece.y, face)) continue

    objects.value = [...objects.value, { ...piece, facing: face, id: `d-${stamp}-${n++}` }]
  }

  refused.value = ''
}

// --- the grid's size ---

/**
 * Resize the room, keeping what still fits.
 *
 * Zones, furniture and the entrance are all pulled back inside, since a grid that shrinks past
 * them would otherwise produce a map the API rejects for reasons that aren't visible on screen.
 */
function applySize() {
  width.value = clampSize(width.value)
  height.value = clampSize(height.value)
  tiles.value = resizeTiles(tiles.value, width.value, height.value)
  zones.value = zones.value.filter(z => z.x + z.w <= width.value && z.y + z.h <= height.value)
  objects.value = objects.value.filter((o) => {
    const kind = DECOR[o.kind]
    if (!kind) return false

    // Measured as placed: a turned desk sticks out southwards where an unturned one sticks out
    // eastwards, so which of them survives a shrink is not the same question.
    const { w, h } = decorSize(o, kind)

    return o.x + w <= width.value && o.y + h <= height.value
  })

  if (!isWalkable(draft.value, spawn.value.x, spawn.value.y)) {
    const found = firstFloor()
    if (found) spawn.value = found
  }
}

function clampSize(n: number) {
  return Math.max(8, Math.min(80, Math.round(n) || 8))
}

function firstFloor() {
  for (let y = 0; y < height.value; y++) {
    for (let x = 0; x < width.value; x++) {
      if (isWalkable(draft.value, x, y)) return { x, y }
    }
  }

  return null
}

function clearRoom() {
  tiles.value = blankTiles(width.value, height.value)
  zones.value = []
  objects.value = []
  spawn.value = { x: Math.floor(width.value / 2), y: Math.floor(height.value / 2) }
}

// --- loading a preset over the room ---

function togglePresets() {
  showPresets.value = !showPresets.value
  if (showPresets.value) void loadPresets()
}

/**
 * Replace the working room with a starting layout — its size, ground, rooms, furniture and
 * entrance, all of it.
 *
 * Deliberately *everything but the name*. A preset is a room, not a title: somebody who called
 * this place "Design standup" and then loads Office to re-floor it shouldn't find it renamed
 * "Office" underneath them. The name field is right there in the header if they do want it.
 *
 * Local until Save, like every other edit here — so Cancel still backs out of the whole swap, and
 * nobody standing in the room sees anything until it's saved.
 */
function applyPreset(preset: MapPreset) {
  width.value = preset.width
  height.value = preset.height
  // Copied, never referenced: these live in a session-wide cache (see useSpacePresets), and
  // painting a wall must not edit the catalogue every other picker reads from.
  tiles.value = [...preset.tiles]
  zones.value = preset.zones.map(z => ({ ...z }))
  objects.value = preset.objects.map(o => ({ ...o }))
  spawn.value = { ...preset.spawn }

  loadedPreset.value = preset.key
  refused.value = ''
  showPresets.value = false

  // The new room is a different size, so the camera has to re-frame it.
  fit()
}

// --- saving ---

async function onSave() {
  saving.value = true
  error.value = ''

  try {
    // Decorate mode saves the furniture alone, through the member endpoint. It's not that the
    // rest wouldn't validate — it's that a member has no right to send it, and doing so would
    // 403 rather than quietly ignore the geometry.
    if (isDecorMode.value) {
      await saveObjects(objects.value)
    }
    else {
      await save({
        name: name.value,
        width: width.value,
        height: height.value,
        tiles: tiles.value,
        zones: zones.value,
        objects: objects.value,
        spawn: spawn.value,
      })
    }
    emit('saved')
  }
  catch (e: any) {
    // The API's structural complaints are the useful ones ("Row 5 is not 20 characters"), so
    // show whichever it sent rather than a generic failure.
    const errors = e?.data?.errors as Record<string, string[]> | undefined
    error.value = errors ? Object.values(errors).flat()[0]! : (e?.data?.message ?? 'Could not save this room.')
  }
  finally {
    saving.value = false
  }
}

// --- rendering ---

let palette: MapTheme | null = null
let paletteAt = 0
let probe: HTMLElement | null = null

/** Same trick as the stage: custom properties need resolving through a real element. */
function theme(): MapTheme {
  const now = performance.now()
  if (palette && now - paletteAt < 1000) return palette
  paletteAt = now

  if (!probe) {
    probe = document.createElement('span')
    probe.style.display = 'none'
    document.body.appendChild(probe)
  }

  const resolve = (expr: string, fallback: string) => {
    probe!.style.color = ''
    probe!.style.color = expr

    return getComputedStyle(probe!).color || fallback
  }

  palette = {
    zone: 'rgb(99 102 241 / 0.10)',
    zoneBorder: 'rgb(99 102 241 / 0.45)',
    text: resolve('var(--foreground)', '#0f172a'),
    muted: resolve('var(--muted-foreground)', '#64748b'),
  }

  return palette
}

function resize() {
  const el = canvas.value
  const box = wrap.value
  if (!el || !box) return

  const dpr = window.devicePixelRatio || 1
  const w = box.clientWidth
  const h = box.clientHeight

  el.width = Math.round(w * dpr)
  el.height = Math.round(h * dpr)
  el.style.width = `${w}px`
  el.style.height = `${h}px`
  el.getContext('2d')?.setTransform(dpr, 0, 0, dpr, 0, 0)

  camera.width = w
  camera.height = h

  // A resize re-fits the room *unless* you've moved the camera yourself. On a phone the address
  // bar sliding away is a resize, and having it throw away the corner you'd zoomed into would
  // make the editor feel like it was fighting you.
  if (!moved) fit()
}

/** Show the whole room at once — you can't lay out a floor you can only see a corner of. */
function fit() {
  camera.x = width.value / 2 - 0.5
  camera.y = height.value / 2 - 0.5
  camera.zoom = fitZoom()
  moved = false
}

/** The scale at which the whole room is on screen, with a little air around it. */
function fitZoom() {
  return Math.min(
    camera.width / (width.value * TILE),
    camera.height / (height.value * TILE),
  ) * 0.95
}

// --- getting closer to the work ---

/**
 * How far in the editor may be pushed.
 *
 * Both ends are relative to {@link fitZoom}, not absolute, because "the whole room on screen" is
 * a different number of pixels per tile for a 10×10 room than for an 80×80 one. Out to most of
 * the way past that (so a big room can still be seen whole on a phone) and in to eight times it,
 * which is where a single tile is comfortably bigger than a fingertip.
 */
const zoomBounds = () => ({ min: fitZoom() * 0.9, max: fitZoom() * 8 })

/** Zoom about a point on the canvas — the tile under the pointer is the one you close in on. */
function zoomAt(factor: number, px: number, py: number) {
  const { min, max } = zoomBounds()
  zoomAround(camera, factor, px, py, min, max)
  moved = true
}

/** The buttons have no pointer to zoom about, so they use the middle of the view. */
function zoomStep(factor: number) {
  zoomAt(factor, camera.width / 2, camera.height / 2)
}

/**
 * The wheel zooms rather than scrolls — see the same decision in SideSpaceStage. Bound in
 * `onMounted` rather than in the template so it can be non-passive and actually say so.
 */
function onWheel(e: WheelEvent) {
  e.preventDefault()

  const rect = canvas.value?.getBoundingClientRect()
  if (!rect) return

  zoomAt(e.deltaY < 0 ? ZOOM_STEP : 1 / ZOOM_STEP, e.clientX - rect.left, e.clientY - rect.top)
}

/**
 * Two fingers: pinch to zoom, and drag the pair to pan.
 *
 * Both at once, because they're one gesture — you spread your fingers and shift them at the same
 * time without meaning two separate things by it. The midpoint moving pans, the gap changing
 * zooms, and neither is a brush stroke: `startPinch` throws away whatever painting or dragging
 * the first finger had begun, so a two-fingered gesture never leaves a trail of desks behind it.
 */
function startPinch() {
  const [a, b] = [...touches.values()]
  if (!a || !b) return

  pinch = { gap: Math.hypot(a.x - b.x, a.y - b.y), x: (a.x + b.x) / 2, y: (a.y + b.y) / 2 }
  painting = false
  dragging = null
  zoneDrag = null
}

function trackPinch() {
  const [a, b] = [...touches.values()]
  const rect = canvas.value?.getBoundingClientRect()
  if (!pinch || !a || !b || !rect) return

  const gap = Math.hypot(a.x - b.x, a.y - b.y)
  const x = (a.x + b.x) / 2
  const y = (a.y + b.y) / 2

  // Pan first, by however far the pair of fingers slid, then scale about where they now are.
  panBy(x - pinch.x, y - pinch.y)

  if (pinch.gap > 20 && gap > 20) zoomAt(gap / pinch.gap, x - rect.left, y - rect.top)

  pinch = { gap, x, y }
}

/** A middle-drag pans, the way it does in every other canvas people have used. */
function panBy(dx: number, dy: number) {
  const size = TILE * camera.zoom
  camera.x -= dx / size
  camera.y -= dy / size
  moved = true
}

function draw() {
  frame = requestAnimationFrame(draw)

  const ctx = canvas.value?.getContext('2d')
  if (!ctx) return

  const p = theme()
  ctx.clearRect(0, 0, camera.width, camera.height)
  drawMap(ctx, draft.value, camera, p, (performance.now() - openedAt) / 1000)

  drawGrid(ctx, p)
  drawSpawn(ctx)
  drawZoneDrag(ctx)
  drawSelection(ctx)
}

/**
 * A ring round the piece you're holding.
 *
 * Round its *footprint*, which is the thing being moved — a bookshelf's sprite reaches a third of
 * a tile higher than the floor it stands on, and outlining the picture rather than the floor
 * would show you a box the piece doesn't actually occupy.
 */
function drawSelection(ctx: CanvasRenderingContext2D) {
  const box = selectionBox.value
  if (!box) return

  ctx.save()
  ctx.strokeStyle = 'rgb(99 102 241)'
  ctx.lineWidth = 2
  ctx.setLineDash([5, 3])
  ctx.strokeRect(box.x + 1, box.y + 1, box.w - 2, box.h - 2)
  ctx.restore()
}

/** Faint tile lines — you're placing individual squares, so you need to see the squares. */
function drawGrid(ctx: CanvasRenderingContext2D, p: MapTheme) {
  const size = TILE * camera.zoom

  ctx.save()
  ctx.globalAlpha = 0.25
  ctx.strokeStyle = p.muted
  ctx.lineWidth = 0.5
  ctx.beginPath()

  for (let x = 0; x <= width.value; x++) {
    const s = toScreen(camera, x - 0.5, -0.5)
    ctx.moveTo(s.x, s.y)
    ctx.lineTo(s.x, s.y + height.value * size)
  }
  for (let y = 0; y <= height.value; y++) {
    const s = toScreen(camera, -0.5, y - 0.5)
    ctx.moveTo(s.x, s.y)
    ctx.lineTo(s.x + width.value * size, s.y)
  }

  ctx.stroke()
  ctx.restore()
}

function drawSpawn(ctx: CanvasRenderingContext2D) {
  const size = TILE * camera.zoom
  const s = toScreen(camera, spawn.value.x - 0.5, spawn.value.y - 0.5)

  ctx.fillStyle = 'rgb(34 197 94 / 0.35)'
  ctx.fillRect(s.x, s.y, size, size)
  ctx.strokeStyle = 'rgb(34 197 94)'
  ctx.lineWidth = 2
  ctx.strokeRect(s.x + 1, s.y + 1, size - 2, size - 2)
}

function drawZoneDrag(ctx: CanvasRenderingContext2D) {
  if (!zoneDrag) return

  const size = TILE * camera.zoom
  const x = Math.min(zoneDrag.x0, zoneDrag.x1)
  const y = Math.min(zoneDrag.y0, zoneDrag.y1)
  const w = Math.abs(zoneDrag.x1 - zoneDrag.x0) + 1
  const h = Math.abs(zoneDrag.y1 - zoneDrag.y0) + 1
  const s = toScreen(camera, x - 0.5, y - 0.5)

  ctx.fillStyle = 'rgb(99 102 241 / 0.18)'
  ctx.fillRect(s.x, s.y, w * size, h * size)
}

/** Choosing anything clears the last refusal — it was about the last thing you tried. */
function chooseTile(char: string) {
  tool.value = 'tile'
  tile.value = char
  refused.value = ''
  chose()
}

function chooseDecor(kindName: string) {
  tool.value = 'decor'
  decor.value = kindName
  refused.value = ''
  chose()
}

function chooseTool(id: Tool) {
  tool.value = id
  refused.value = ''
  chose()
}

/**
 * Reaching for another tool puts down whatever you were holding.
 *
 * The chips hang over the room, so leaving them there while you paint a wall would leave three
 * buttons floating over the tile you were trying to aim at. Turning the *brush* is still possible
 * with nothing selected, which is what makes clearing the selection safe rather than annoying.
 */
watch(tool, () => {
  selectedId.value = null
  dragging = null
})

watch([width, height], () => fit())

onMounted(() => {
  openedAt = performance.now()
  resize()
  ro = new ResizeObserver(resize)
  if (wrap.value) ro.observe(wrap.value)
  frame = requestAnimationFrame(draw)
  window.addEventListener('keydown', onKeydown)
  // Non-passive, or the sheet scrolls behind the grid instead of zooming it — see onWheel.
  canvas.value?.addEventListener('wheel', onWheel, { passive: false })
})

onBeforeUnmount(() => {
  if (frame) cancelAnimationFrame(frame)
  ro?.disconnect()
  probe?.remove()
  probe = null
  window.removeEventListener('keydown', onKeydown)
  canvas.value?.removeEventListener('wheel', onWheel)
})
</script>

<template>
  <!-- A sheet over the whole app rather than a panel: laying out a floor needs the room. -->
  <div class="fixed inset-0 z-50 flex flex-col bg-background">
    <header class="flex h-12 shrink-0 items-center justify-between gap-3 border-b px-4">
      <div class="flex min-w-0 items-center gap-3">
        <span class="flex items-center gap-2 font-semibold">
          {{ isDecorMode ? 'Decorate the room' : 'Edit the room' }}
          <AlphaBadge stage="Beta" hint="The editor is new — save often, and tell us what it gets wrong." />
        </span>
        <!-- Renaming is part of rebuilding the room, so it's the owner's alone. Off the phone's
             header entirely: it's the one control here that isn't needed to finish a change. -->
        <Input v-if="!isDecorMode && !narrow" v-model="name" class="h-8 w-48" placeholder="Room name" />
        <span v-else class="text-sm text-muted-foreground">{{ name }}</span>
      </div>

      <div class="flex shrink-0 items-center gap-2">
        <p v-if="error" class="max-w-sm truncate text-xs text-destructive" :title="error">{{ error }}</p>
        <Button v-if="narrow" variant="outline" size="sm" class="gap-1.5" @click="showTools = !showTools">
          <Sofa class="h-4 w-4" /> Tools
        </Button>
        <!-- The X beside it does the same thing, and a phone header has room for one of them. -->
        <Button v-if="!narrow" variant="outline" size="sm" :disabled="saving" @click="emit('close')">Cancel</Button>
        <Button size="sm" :disabled="saving" @click="onSave">
          <Loader2 v-if="saving" class="mr-1.5 h-4 w-4 animate-spin" />
          {{ saving ? 'Saving…' : (isDecorMode ? 'Save furniture' : 'Save room') }}
        </Button>
        <button class="rounded p-1 text-muted-foreground hover:text-foreground" aria-label="Close" @click="emit('close')">
          <X class="h-4 w-4" />
        </button>
      </div>
    </header>

    <div class="relative flex min-h-0 flex-1">
      <!-- Tapping the grid behind the open drawer puts it away rather than painting through it. -->
      <div v-if="narrow && showTools" class="absolute inset-0 z-10 bg-black/30" @click="showTools = false" />

      <!-- Tools: a rail beside the grid, or a drawer over it on a phone. -->
      <aside
        v-if="!narrow || showTools"
        class="w-64 shrink-0 space-y-4 overflow-y-auto border-r bg-background p-3"
        :class="narrow ? 'absolute inset-y-0 left-0 z-20 max-w-[85%] shadow-xl' : undefined"
      >
        <!-- The ground. Absent in decorate mode, which is the furniture layer alone. -->
        <div v-if="!isDecorMode" class="space-y-1.5">
          <Label class="text-xs text-muted-foreground">Ground</Label>
          <div class="grid grid-cols-4 gap-1.5">
            <button
              v-for="b in TILE_BRUSHES"
              :key="b.tile"
              type="button"
              class="flex flex-col items-center gap-1 rounded-md border p-1.5 transition-colors"
              :class="tool === 'tile' && tile === b.tile ? 'border-primary bg-muted' : 'hover:bg-muted/50'"
              :title="b.hint"
              @click="chooseTile(b.tile)"
            >
              <!-- A transparent swatch shows as a chequer, the way every editor draws "no
                   colour here" — an empty outlined square just reads as white. -->
              <span
                class="h-6 w-6 rounded-sm border bg-[length:8px_8px]"
                :class="b.swatch === 'transparent' ? 'bg-[linear-gradient(45deg,var(--muted)_25%,transparent_25%,transparent_75%,var(--muted)_75%),linear-gradient(45deg,var(--muted)_25%,transparent_25%,transparent_75%,var(--muted)_75%)] bg-[position:0_0,4px_4px]' : undefined"
                :style="b.swatch === 'transparent' ? undefined : { backgroundColor: b.swatch }"
              />
              <span class="text-[9px] leading-none">{{ b.label }}</span>
            </button>
          </div>
        </div>

        <!--
          What a room you drag comes out as.

          Sits with the brushes rather than in the room list below, because it's a setting for
          the *tool* — you choose the style and then draw, the way you choose a tile and then
          paint. "Empty" is the default and is the behaviour dragging a room has always had;
          everything else lays a floor and furnishes it in one go.
        -->
        <div v-if="!isDecorMode && tool === 'zone'" class="space-y-1.5">
          <Label class="flex items-center gap-1.5 text-xs text-muted-foreground">
            <SquareDashed class="h-3.5 w-3.5" /> New rooms come as
          </Label>

          <div class="grid gap-1">
            <button
              v-for="p in roomPresets"
              :key="p.key"
              type="button"
              class="rounded border px-2 py-1 text-left transition-colors"
              :class="roomStyle === p.key ? 'border-primary bg-muted' : 'hover:bg-muted/50'"
              @click="roomStyle = p.key"
            >
              <span class="block text-xs font-medium">{{ p.label }}</span>
              <span class="block text-[10px] leading-snug text-muted-foreground">{{ p.description }}</span>
            </button>
          </div>

          <p class="text-[11px] leading-snug text-muted-foreground">
            Drag out a room and it's laid out for you. The furniture spreads to fit — anything
            that won't fit is left out.
          </p>
        </div>

        <!-- The furniture. -->
        <div class="space-y-1.5">
          <Label class="flex items-center gap-1.5 text-xs text-muted-foreground">
            <Sofa class="h-3.5 w-3.5" /> Furniture
          </Label>

          <div v-for="group in DECOR_GROUPS" :key="group.title" class="space-y-1">
            <p class="text-[10px] uppercase tracking-wide text-muted-foreground/70">{{ group.title }}</p>
            <div class="flex flex-wrap gap-1">
              <button
                v-for="k in group.kinds"
                :key="k"
                type="button"
                class="rounded border px-1.5 py-1 text-[11px] transition-colors"
                :class="tool === 'decor' && decor === k ? 'border-primary bg-muted font-medium' : 'hover:bg-muted/50'"
                :title="DECOR[k]?.interact ? `${DECOR[k]?.label} — opens the ${DECOR[k]?.interact}` : DECOR[k]?.label"
                @click="chooseDecor(k)"
              >
                {{ DECOR[k]?.label }}
                <span v-if="DECOR[k]?.interact" class="text-primary">·</span>
              </button>
            </div>
          </div>

          <!-- Which way the next piece goes down. With something selected the same two buttons
               turn *it* instead, which is why the label changes rather than the controls. -->
          <div class="flex items-center gap-1.5 rounded-md border bg-muted/30 p-1.5">
            <span class="flex-1 text-[11px] leading-snug text-muted-foreground">
              {{ selected ? `Turn the ${selectionBox?.label?.toLowerCase()}` : `New pieces face ${FACING_LABELS[facing]}` }}
            </span>
            <button
              type="button"
              class="rounded border p-1 text-muted-foreground transition-colors hover:bg-background hover:text-foreground"
              title="Turn anticlockwise (Shift+R)"
              @click="turn(-1)"
            >
              <RotateCcw class="h-3.5 w-3.5" />
            </button>
            <button
              type="button"
              class="rounded border p-1 text-muted-foreground transition-colors hover:bg-background hover:text-foreground"
              title="Turn clockwise (R)"
              @click="turn(1)"
            >
              <RotateCw class="h-3.5 w-3.5" />
            </button>
          </div>

          <p class="text-[11px] leading-snug text-muted-foreground">
            Things marked <span class="text-primary">·</span> open something when somebody walks up and presses E.
          </p>
        </div>

        <!-- Everything that isn't a brush. -->
        <div class="space-y-1.5">
          <Label class="text-xs text-muted-foreground">Tools</Label>
          <button
            v-for="t in TOOLS"
            :key="t.id"
            type="button"
            class="flex w-full items-start gap-2 rounded-md border p-2 text-left text-sm transition-colors"
            :class="tool === t.id ? 'border-primary bg-muted' : 'hover:bg-muted/50'"
            @click="chooseTool(t.id)"
          >
            <component :is="t.icon" class="mt-0.5 h-4 w-4 shrink-0 text-muted-foreground" />
            <span>
              <span class="block font-medium">{{ t.label }}</span>
              <span class="block text-[11px] leading-snug text-muted-foreground">{{ t.hint }}</span>
            </span>
          </button>
        </div>

        <div v-if="!isDecorMode" class="space-y-1.5">
          <Label class="text-xs text-muted-foreground">Size</Label>
          <div class="flex items-center gap-2">
            <Input v-model.number="width" type="number" min="8" max="80" class="h-8" @change="applySize" />
            <span class="text-xs text-muted-foreground">×</span>
            <Input v-model.number="height" type="number" min="8" max="80" class="h-8" @change="applySize" />
          </div>
          <p class="text-[11px] leading-snug text-muted-foreground">
            8–80 each way. Growing keeps what's already there and re-walls the edge.
          </p>
        </div>

        <div v-if="!isDecorMode && zones.length" class="space-y-1.5">
          <Label class="text-xs text-muted-foreground">Rooms</Label>
          <div v-for="z in zones" :key="z.id" class="space-y-1">
            <div class="flex items-center gap-1.5">
              <Input v-model="z.name" class="h-7 text-xs" />
              <button
                class="rounded p-1 text-muted-foreground hover:text-destructive"
                :title="`Remove ${z.name}`"
                @click="zones = zones.filter(o => o.id !== z.id)"
              >
                <Trash2 class="h-3.5 w-3.5" />
              </button>
            </div>

            <!-- Re-furnish a room that already exists. Replaces its floor and everything
                 standing on it, which is why it says so and why it isn't a one-click chip. -->
            <select
              class="h-6 w-full rounded border bg-background px-1 text-[10px] text-muted-foreground"
              :title="`Lay ${z.name} out again — replaces its floor and furniture`"
              @focus="loadRoomPresets()"
              @change="restyleZone(z, ($event.target as HTMLSelectElement).value, $event.target as HTMLSelectElement)"
            >
              <option value="">Lay it out as…</option>
              <option v-for="p in roomPresets" :key="p.key" :value="p.key">{{ p.label }}</option>
            </select>
          </div>
        </div>

        <!--
          Swap the whole room for one of the starting layouts. Behind a toggle because it replaces
          everything: a picker sitting open next to the brushes would be one stray click from
          wiping a floor somebody just laid.
        -->
        <div v-if="!isDecorMode" class="space-y-1.5">
          <Button variant="outline" size="sm" class="w-full gap-1.5" @click="togglePresets">
            <LayoutTemplate class="h-3.5 w-3.5" />
            {{ showPresets ? 'Close layouts' : 'Load a layout' }}
          </Button>

          <div v-if="showPresets" class="space-y-1.5 rounded-md border p-2">
            <p class="text-[11px] leading-snug text-muted-foreground">
              Replaces the size, ground, rooms, furniture and entrance — everything but the name.
              Nothing is saved until you hit Save room.
            </p>

            <p v-if="loadingPresets" class="flex items-center gap-1.5 py-1 text-xs text-muted-foreground">
              <Loader2 class="h-3.5 w-3.5 animate-spin" /> Loading layouts…
            </p>

            <!-- Under the same headings the creation page uses, from the same grouping — see
                 useSpacePresets. In a rail this narrow, seventeen unlabelled thumbnails is a
                 scroll you give up on. -->
            <div v-for="group in groupedPresets" :key="group.title" class="space-y-1">
              <p class="pt-1 text-[10px] uppercase tracking-wide text-muted-foreground/70">{{ group.title }}</p>

              <button
                v-for="p in group.items"
                :key="p.key"
                type="button"
                class="w-full space-y-1 rounded-md border p-1.5 text-left transition-colors"
                :class="loadedPreset === p.key ? 'border-primary bg-muted' : 'hover:bg-muted/50'"
                :title="p.description"
                @click="applyPreset(p)"
              >
                <!-- The real grid, so what you pick is what you get. -->
                <SideSpaceMapThumbnail :tiles="p.tiles" :width="p.width" :height="p.height" />
                <span class="block text-xs font-medium">{{ p.label }}</span>
                <span class="block text-[10px] leading-snug text-muted-foreground">{{ p.width }}×{{ p.height }}</span>
              </button>
            </div>
          </div>
        </div>

        <Button v-if="!isDecorMode" variant="outline" size="sm" class="w-full gap-1.5" @click="clearRoom">
          <Trash2 class="h-3.5 w-3.5" /> Start over
        </Button>

        <p v-else class="text-[11px] leading-snug text-muted-foreground">
          You're decorating — add and move furniture. Only the room's owner can change the walls,
          floor and the way in.
        </p>
      </aside>

      <!-- The grid -->
      <div ref="wrap" class="relative min-w-0 flex-1 bg-muted/20">
        <canvas
          ref="canvas"
          class="block h-full w-full touch-none"
          :class="tool === 'select' ? 'cursor-move' : 'cursor-crosshair'"
          @pointerdown="onPointerDown"
          @pointermove="onPointerMove"
          @pointerup="onPointerUp"
          @pointercancel="onPointerUp"
        />

        <!--
          The handles on the selected piece: turn it, or take it away.

          Pinned to the piece rather than parked in the panel, because "this one" is the whole
          question a delete button has to answer — a Remove button across the room needs you to
          remember what's selected, and a trash icon touching the couch does not. It sits above
          the piece unless the piece is at the top of the room, where it flips below rather than
          off the edge, and it stops pointer events reaching the canvas so pressing ✕ can't also
          be a click on the floor behind it.
        -->
        <div
          v-if="selectionBox"
          class="absolute z-10 flex -translate-x-1/2 items-center gap-0.5 rounded-md border bg-background/95 p-0.5 shadow-lg backdrop-blur"
          :style="{
            left: `${selectionBox.x + selectionBox.w / 2}px`,
            top: selectionBox.y > 44 ? `${selectionBox.y - 38}px` : `${selectionBox.y + selectionBox.h + 6}px`,
          }"
          @pointerdown.stop
        >
          <span class="max-w-24 truncate px-1.5 text-[11px] text-muted-foreground">{{ selectionBox.label }}</span>
          <button
            type="button"
            class="rounded p-1 text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
            title="Turn anticlockwise (Shift+R)"
            @click="turn(-1)"
          >
            <RotateCcw class="h-3.5 w-3.5" />
          </button>
          <button
            type="button"
            class="rounded p-1 text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
            title="Turn clockwise (R)"
            @click="turn(1)"
          >
            <RotateCw class="h-3.5 w-3.5" />
          </button>
          <button
            type="button"
            class="rounded p-1 text-destructive transition-colors hover:bg-destructive hover:text-destructive-foreground"
            title="Take it away (Delete)"
            @click="removeSelected"
          >
            <Trash2 class="h-3.5 w-3.5" />
          </button>
        </div>

        <p class="pointer-events-none absolute bottom-2 left-2 rounded bg-background/85 px-2 py-1 text-[11px] text-muted-foreground">
          {{ tool === 'select' ? `${narrow ? 'Tap' : 'Click'} a thing to pick it up, then drag it. Its handles turn and remove it.`
            : tool === 'decor' ? `${narrow ? 'Tap' : 'Click'} to put it down. Furniture places one at a time.`
              : tool === 'erase-decor' ? `${narrow ? 'Tap' : 'Click'} a piece of furniture to remove it.`
                : 'Drag to paint. The green square is where people walk in.' }}
        </p>
        <p
          v-if="refused"
          class="pointer-events-none absolute bottom-2 left-1/2 -translate-x-1/2 rounded bg-destructive px-2 py-1 text-[11px] text-destructive-foreground"
        >
          {{ refused }}
        </p>
        <p class="pointer-events-none absolute right-2 top-2 rounded bg-background/85 px-2 py-1 text-[11px] text-muted-foreground">
          {{ objects.length }} thing{{ objects.length === 1 ? '' : 's' }} in the room
        </p>

        <!--
          Getting closer to the work. The wheel and a two-finger pinch already do this; these are
          for the phone, where laying out a 40-tile room at fit-the-screen size means aiming a
          finger at an eight-pixel square. "Fit" is the way back, and doubles as the way back from
          having panned somewhere strange.
        -->
        <div class="absolute bottom-2 right-2 flex flex-col overflow-hidden rounded-lg border bg-background/90 shadow-sm backdrop-blur">
          <button
            type="button"
            class="flex h-8 w-8 items-center justify-center text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
            title="Closer"
            aria-label="Zoom in"
            @click="zoomStep(ZOOM_STEP)"
          >
            <ZoomIn class="h-4 w-4" />
          </button>
          <button
            type="button"
            class="flex h-8 w-8 items-center justify-center border-t text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
            title="Further out"
            aria-label="Zoom out"
            @click="zoomStep(1 / ZOOM_STEP)"
          >
            <ZoomOut class="h-4 w-4" />
          </button>
          <button
            type="button"
            class="flex h-8 w-8 items-center justify-center border-t text-[10px] font-medium text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
            title="Show the whole room again"
            @click="fit"
          >
            Fit
          </button>
        </div>
      </div>
    </div>
  </div>
</template>
