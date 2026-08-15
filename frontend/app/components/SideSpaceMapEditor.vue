<script setup lang="ts">
import {
  DoorOpen, Eraser, Frame, LayoutGrid, LayoutTemplate, Loader2, MonitorPlay, MousePointer2,
  RotateCcw, RotateCw, Sofa, Megaphone, SquareDashed, Trash2, Undo2, Unlink, X, ZoomIn, ZoomOut,
} from 'lucide-vue-next'
import type { BackdropPlacement } from '~/lib/spaceBackdrops'
import { BACKDROPS } from '~/lib/spaceBackdrops'
import type { Camera, MapTheme, PortalActivation, Projection, SpaceExhibit, SpaceMap, SpacePortal, SpaceScreen, SpaceZone } from '~/lib/spaceMapEngine'
import type { DecorFacing, SpaceObject } from '~/lib/spaceDecor'
import type { MapPreset } from '~/composables/useSpacePresets'
import {
  STAGE_SPEAKERS,
  TILE_BRUSHES,
  VOID,
  ZOOM_STEP,
  blankTiles,
  cropTiles,
  drawMap,
  firstWalkableIn,
  growToFit,
  FLOOR,
  isWalkable,
  MAIN_MAP,
  MAX_EXHIBITS,
  MAX_MAP,
  MAX_SCREENS,
  MIN_MAP,
  repairConnectivity,
  resizeTiles,
  stampMap,
  toScreen,
  toTile,
  toWorld,
  zoomAround,
} from '~/lib/spaceMapEngine'
import { DECOR, DECOR_FACINGS, decorCovers, decorSize } from '~/lib/spaceDecor'
import { isWalkableTile, WOOD } from '~/lib/spaceTiles'
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
/**
 * `open` is the editor asking to be pointed at a different map of this same Side Space.
 *
 * Emitted rather than done here, because opening a map is not an editing act — it moves the
 * avatar of whoever pressed it into that room. The stage owns where people are standing, so it
 * owns this; the editor is simply the place the request is made from.
 */
const emit = defineEmits<{ close: [], saved: [], open: [slug: string], 'rooms-changed': [] }>()

/*
 * Addressed at the room being edited, not at the channel's way in.
 *
 * A Side Space holds several maps and every write below is scoped to one of them by slug. This
 * instance exists only to save through — the map on screen was handed in as a prop — so the one
 * thing it must get right is *which* room it is writing to.
 */
const { save, saveObjects, createMap, deleteMap, hangExhibit, unhangExhibit } = useSpaceMap(props.channelId, props.map.slug)

const isDecorMode = computed(() => props.mode === 'decor')

type Tool = 'select' | 'tile' | 'decor' | 'spawn' | 'zone' | 'stage' | 'place' | 'portal' | 'screen' | 'art' | 'erase-zone' | 'erase-decor' | 'erase-tile'

/** The full toolbox. In decorate mode only the furniture tools survive — see {@link TOOLS}. */
const ALL_TOOLS: { id: Tool, label: string, icon: any, hint: string, decor?: boolean }[] = [
  { id: 'select', label: 'Move things', icon: MousePointer2, hint: 'Pick a thing up, turn it, or take it away', decor: true },
  { id: 'spawn', label: 'Entrance', icon: DoorOpen, hint: 'Where people arrive' },
  { id: 'zone', label: 'Room', icon: SquareDashed, hint: 'Drag out a sealed room — inside hears inside only' },
  // Next to the room rather than off in a mode of its own, because it *is* a room — one whose
  // occupants are heard outside it. Anybody who has understood the Room tool has understood
  // three quarters of this one.
  { id: 'stage', label: 'Stage', icon: Megaphone, hint: 'Drag out a stage — step on it and the whole room hears you' },
  // Dragging out *where* new land goes, rather than naming an edge and a number of tiles. Sits
  // with Room and Stage because the gesture is identical — drag a rectangle — and the only thing
  // that differs is what lands in it.
  { id: 'place', label: 'Place a layout', icon: LayoutGrid, hint: 'Drag anywhere, even off the edge — the map grows to fit and the layout drops in' },
  // Beside the rectangles it is drawn like, not beside the furniture: you drag a doorway out, you
  // don't stand one up.
  { id: 'portal', label: 'Doorway', icon: DoorOpen, hint: 'Drag out a wormhole — stand in it and press E to come out somewhere else' },
  // Next to the doorway rather than with the furniture, because like a doorway it is an *area*
  // of the map rather than a thing standing in one — see the migration that added screens.
  { id: 'screen', label: 'Screen', icon: MonitorPlay, hint: 'Drag out a surface that shows whatever the room is watching' },
  // Next to the screen: both are things across the room that you look at rather than walk on.
  { id: 'art', label: 'Artwork', icon: Frame, hint: 'Drag a frame over a painting or statue, then hang a picture in it' },
  // Taking the ground away is its own tool rather than only the last swatch in the Ground row.
  // It was reachable there — "Nothing" paints the void — but a transparent square at the end of
  // twelve coloured ones is not where anybody looks for an eraser, and rubbing a wall out is a
  // dragged gesture like painting one, not a colour you happen to choose.
  { id: 'erase-tile', label: 'Erase ground', icon: Eraser, hint: 'Drag to rub out wall and floor, back to nothing' },
  { id: 'erase-zone', label: 'Erase room', icon: SquareDashed, hint: 'Click a room, stage or doorway to remove it' },
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
  { title: 'Bits and pieces', kinds: ['plant', 'plush', 'plush_vessel', 'plush_pickachu', 'plush_gundam', 'plush_cubone', 'plush_tanjiro', 'crate', 'barrel', 'campfire', 'rug', 'mat'] },
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
/**
 * Which way the room is drawn. Part of the working copy like everything else here, so switching
 * it is undone by leaving without saving.
 *
 * It changes the *view* as well as the document, which is deliberate and is the only way this
 * could work: furniture is placed by clicking where you want it, so an editor that let you author
 * an isometric room through a flat view would have you arranging a room you can't see.
 */
const projection = ref<Projection>(props.map.projection ?? 'flat')
/**
 * The artwork drawn instead of the tile grid, with the rectangle each piece covers.
 *
 * No picker, deliberately: a piece of artwork is cut to a particular grid, and dropping one onto
 * a map whose streets were drawn round something else gives a room whose pavement and collision
 * disagree everywhere. You get artwork by loading a preset that has some, or by extending the map
 * with one — both of which bring the matching collision grid along with it.
 */
const backdrops = ref<BackdropPlacement[]>((props.map.backdrops ?? []).map(b => ({ ...b })))
const portals = ref<SpacePortal[]>((props.map.portals ?? []).map(p => ({ ...p, to: { ...p.to } })))

/**
 * The surfaces that show whatever the room is watching.
 *
 * Held here like every other part of the map, and — importantly — listed in {@link draft} below.
 * A new map field that the editor can change but doesn't send is the exact bug that cost this
 * file three separate regressions; see the note on `save` in useSpaceMap.
 */
const screens = ref<SpaceScreen[]>((props.map.screens ?? []).map(s => ({ ...s })))
const selectedScreen = ref<string | null>(null)

/**
 * The frames — rectangles over the artwork a drawn room already paints.
 *
 * Geometry only, and part of the draft like everything else here. What *hangs* in one is a file
 * and a wall label, uploaded through its own endpoint and owned by the server: it is not in this
 * draft, is not touched by Save, and is read straight off the prop.
 */
const exhibits = ref<SpaceExhibit[]>((props.map.exhibits ?? []).map(e => ({ ...e })))
const selectedExhibit = ref<string | null>(null)

/**
 * The other Side Spaces in this server — the places a doorway can lead to.
 *
 * Taken from the sidebar's own channel list rather than fetched, because it is already loaded and
 * already scoped to what this person can see: a doorway you cannot even name is one you cannot
 * accidentally build. Discussions count and this room itself does not, since a doorway back into
 * the room you are standing in is a doorway to nowhere.
 */
const { channels: serverChannels } = useServer()

const spaceRooms = computed(() => serverChannels.value
  .flatMap(c => [c, ...((c as any).discussions ?? [])])
  .filter(c => c.type === 'space' && c.id !== props.channelId))

/** Which doorway the panel is editing, by id. */
const selectedPortal = ref<string | null>(null)

/**
 * Where a *new* doorway will lead. Chosen before you drag one out, like the room style is.
 *
 * A tagged string rather than a bare id, because there are now three kinds of destination and
 * two of them are identified by different things:
 *
 *   - `''`          — somewhere on this map. The exit is picked by clicking, afterwards.
 *   - `map:<slug>`  — one of this Side Space's own interiors. You stay in the channel and the
 *                     call; only the grid changes.
 *   - `room:<id>`   — another Side Space in this server. A journey, and a fresh call.
 *
 * Tagged so the two never collide: a channel id and a map slug are different namespaces, and a
 * picker whose value was sometimes a number and sometimes a name is one bad `Number()` away from
 * building a doorway into channel `NaN`.
 */
const portalTo = ref('')

/** The name for the room a "A new room…" doorway is about to make. */
const newDoorMapName = ref('')

/**
 * How *new* doorways are taken — walked into, or pressed.
 *
 * Chosen before the drag, like the destination is, and stored per doorway so a map can mix the
 * two. Walking is the default because it is what a wormhole between two parts of one place should
 * do; pressing is for the doors worth deciding about. Existing doorways are switched in the list
 * below.
 */
const portalActivation = ref<PortalActivation>('walk')

/**
 * This Side Space's other rooms — the interiors a doorway can lead into.
 *
 * Comes with the map (see the resource's `siblings`) rather than being fetched, for the same
 * reason the list of other Side Spaces is taken from the sidebar: it is already here. The map
 * being edited is excluded — a doorway into the room it is cut into is a doorway to nowhere,
 * and the server refuses one anyway.
 */
const interiors = computed(() => (props.map.siblings ?? []).filter(m => m.slug !== props.map.slug))

/**
 * Somewhere to step back to.
 *
 * Only the *structural* edits push a snapshot — extending, dropping a layout in, loading a preset
 * over the room, starting over, resizing. Those are the ones that change everything at once and
 * that you cannot put back by hand: painting a tile the wrong colour is one click to fix, while
 * an extension you didn't want is a map you have to rebuild.
 *
 * Whole snapshots rather than a diff. A map at the largest size we allow is 6,400 characters plus
 * its furniture — a few tens of kilobytes a step, held for twenty steps, in a component that only
 * exists while somebody has the editor open. A diff would be less memory and considerably more
 * ways to be wrong.
 */
interface Snapshot {
  label: string
  width: number
  height: number
  tiles: string[]
  zones: SpaceZone[]
  objects: SpaceObject[]
  backdrops: BackdropPlacement[]
  portals: SpacePortal[]
  screens: SpaceScreen[]
  exhibits: SpaceExhibit[]
  spawn: { x: number, y: number }
}

const history = ref<Snapshot[]>([])
const HISTORY_DEPTH = 20

/**
 * Has this map been edited since it was opened or last saved?
 *
 * Read off the undo stack rather than tracked separately, because the stack is already the
 * record of "something changed here" — every edit pushes to it, and a successful save clears it.
 * Used to warn before walking into another map of this Side Space, which is the one action in
 * the editor that throws the draft away without an undo.
 */
const touched = computed(() => history.value.length > 0)

/** Remember the room as it is now, under the name of the thing about to change it. */
function remember(label: string) {
  history.value = [
    ...history.value.slice(-(HISTORY_DEPTH - 1)),
    {
      label,
      width: width.value,
      height: height.value,
      tiles: [...tiles.value],
      zones: zones.value.map(z => ({ ...z })),
      objects: objects.value.map(o => ({ ...o })),
      backdrops: backdrops.value.map(b => ({ ...b })),
      portals: portals.value.map(p => ({ ...p, to: { ...p.to } })),
      screens: screens.value.map(s => ({ ...s })),
      exhibits: exhibits.value.map(e => ({ ...e })),
      spawn: { ...spawn.value },
    },
  ]
}

function undo() {
  const last = history.value.at(-1)
  if (!last) return

  history.value = history.value.slice(0, -1)

  width.value = last.width
  height.value = last.height
  tiles.value = last.tiles
  zones.value = last.zones
  objects.value = last.objects
  backdrops.value = last.backdrops
  portals.value = last.portals
  screens.value = last.screens
  exhibits.value = last.exhibits
  spawn.value = last.spawn

  selectedId.value = null
  refused.value = ''
  loadedPreset.value = null
  fit()
}

/** The four edges, as the picker names them. Compass words, because the code uses them too. */
const EXTEND_EDGES = [
  { key: 'west', label: '← West' },
  { key: 'north', label: '↑ North' },
  { key: 'south', label: '↓ South' },
  { key: 'east', label: 'East →' },
] as const

/** Which edge "Extend" grows, and by how many tiles. */
const extendEdge = ref<'east' | 'west' | 'south' | 'north'>('east')
const extendBy = ref(20)
/** Which preset "Extend" stamps into the new space, or '' to leave it as bare ground. */
const extendWith = ref('')

/** The two views, as the picker names them. Kept beside the ref rather than in the template. */
const PROJECTION_CHOICES: Array<{ key: Projection, label: string, description: string }> = [
  { key: 'flat', label: 'Top-down', description: 'Straight down at the grid' },
  { key: 'iso', label: 'Isometric', description: 'Turned, for room art' },
]

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

/** Boards, like every stage anybody has stood on. Laid under a stage as it's dragged out. */
const STAGE_FLOOR = WOOD

watch(tool, (t) => { if (t === 'zone') void loadRoomPresets() }, { immediate: true })
const showPresets = ref(false)
/** Which preset was last loaded, so the panel can show what you're now working from. */
const loadedPreset = ref<string | null>(null)

/** An in-progress zone drag, in tiles. */
/*
 * The kind rides along with the drag rather than being read from `tool` when the pointer comes
 * up: they are the same value in every ordinary case, and in the one case they aren't — the tool
 * changed mid-drag, by keyboard shortcut or a stray click — what you asked for is what you
 * started drawing, not what happened to be selected when you let go.
 */
let zoneDrag: { x0: number, y0: number, x1: number, y1: number, kind: SpaceZone['kind'] } | null = null
/**
 * The rectangle being dragged out for a layout, in tile coordinates.
 *
 * Unclamped, unlike a zone drag: a zone has to be inside the map, and this one is allowed — meant,
 * even — to run off the edge, because that is how you say "put it over there, past where the map
 * currently stops". Negative coordinates are legitimate and are what makes the map grow left.
 */
let placeDrag: { x0: number, y0: number, x1: number, y1: number } | null = null
let portalDrag: { x0: number, y0: number, x1: number, y1: number } | null = null
let screenDrag: { x0: number, y0: number, x1: number, y1: number } | null = null
let artDrag: { x0: number, y0: number, x1: number, y1: number } | null = null

/** The doorway waiting for somebody to click where it comes out, if any. */
const awaitingExit = ref<string | null>(null)
/**
 * The piece being dragged, and *where in it* the pointer took hold.
 *
 * The offset is the whole reason this isn't just "put the piece under the cursor": grabbing a
 * two-tile couch by its right end and having it jump so its left end is under your finger is the
 * kind of small wrongness that makes a room impossible to lay out.
 */
let dragging: { id: string, ox: number, oy: number } | null = null

/**
 * Which piece of placed artwork is selected, and where it was grabbed.
 *
 * Artwork is picked up with the same tool and the same gesture as furniture, but it is not
 * furniture: dragging it has to carry **the ground with it**. A backdrop is a picture of streets
 * whose collision lives in the tiles underneath, so moving the picture alone would leave a city
 * you could see and a set of walls you couldn't — the two would agree nowhere.
 */
const selectedBackdrop = ref<number | null>(null)
let backdropDrag: { index: number, ox: number, oy: number, moved?: boolean } | null = null
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

/**
 * The working map, in the shape the renderer and the API both want.
 *
 * **The one list of what a map is made of.** It used to be two — this, and a second literal in
 * the save below — and keeping two in step is a thing nobody manages: `portals` was added here,
 * missed there, and doorways silently failed to save. Saving now sends this object, so anything
 * the editor can change is something the editor stores, and the two cannot drift.
 */
const draft = computed<SpaceMap>(() => ({
  id: props.map.id,
  channel_id: props.map.channel_id,
  // Carried so the draft really is a whole map — which is what makes the renderer and the type
  // agree. Stripped again on the way out: which room is being written to is said by the URL,
  // never by the body. See useSpaceMap's SERVER_OWNED.
  slug: props.map.slug,
  siblings: props.map.siblings,
  name: name.value,
  width: width.value,
  height: height.value,
  tiles: tiles.value,
  zones: zones.value,
  objects: objects.value,
  spawn: spawn.value,
  projection: projection.value,
  backdrops: backdrops.value,
  portals: portals.value,
  screens: screens.value,
  exhibits: exhibits.value,
  // Read back unchanged: the pictures are the server's, and a whole-map save must neither carry
  // them nor be read as clearing them. See SERVER_OWNED in useSpaceMap.
  exhibit_pieces: props.map.exhibit_pieces,
}))

/*
 * The camera draws with whatever projection the draft currently claims.
 *
 * A watcher rather than a line in the render loop, because the projection is also what the
 * *pointer* handlers unproject through: a click that arrived between a switch and the next frame
 * has to resolve against the view the person was actually looking at when they clicked.
 */
/*
 * Set once up front rather than through the watcher below, because the watcher refits — and at
 * setup the canvas has no size yet, so a fit here would divide by a zero-width room.
 */
camera.projection = projection.value

watch(projection, (next) => {
  camera.projection = next

  // Refit, because the room is a different shape on screen now and the view that framed it flat
  // frames it half off the edge turned. Skipped if the person has panned or zoomed themselves —
  // the same rule the rest of the editor uses for not stealing somebody's view.
  if (!moved) fit()
})

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
    const inside = (r: { x: number, y: number, w: number, h: number }) =>
      x >= r.x && x < r.x + r.w && y >= r.y && y < r.y + r.h

    // Doorways go with rooms and stages: all three are rectangles you drew on the floor, and
    // reaching for the eraser is what anybody does to get rid of one. The doorway list has its
    // own delete too — this is the same act done where the thing actually is.
    const doorway = portals.value.find(inside)
    if (doorway) removePortal(doorway.id)

    zones.value = zones.value.filter(z => !inside(z))
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

  /*
   * The screen-aligned *bounding box* of the footprint, not the footprint itself. The chips that
   * hang off this are HTML, positioned with `left`/`top`, so they need an upright box whatever
   * the floor is doing — under `iso` the footprint is a parallelogram and the box is the smallest
   * upright rectangle containing it, which puts the chips just clear of the piece on every side.
   * The dashed outline that marks the piece is drawn as the true shape; see drawSelection.
   */
  const corners = [
    toScreen(camera, piece.x - 0.5, piece.y - 0.5),
    toScreen(camera, piece.x + w - 0.5, piece.y - 0.5),
    toScreen(camera, piece.x + w - 0.5, piece.y + h - 0.5),
    toScreen(camera, piece.x - 0.5, piece.y + h - 0.5),
  ]

  const xs = corners.map(c => c.x)
  const ys = corners.map(c => c.y)
  const x = Math.min(...xs)
  const y = Math.min(...ys)

  return { x, y, w: Math.max(...xs) - x, h: Math.max(...ys) - y, label: kind.label }
})

/** Take hold of whatever is under the pointer — or, on empty floor, let go of what was held. */
function grab(px: number, py: number) {
  const { x, y } = toTile(camera, px, py)
  const piece = objectAt(x, y)

  refused.value = ''
  selectedId.value = piece?.id ?? null
  dragging = piece ? { id: piece.id, ox: x - piece.x, oy: y - piece.y } : null

  if (piece) {
    selectedBackdrop.value = null

    return
  }

  /*
   * Nothing on top of it, so the click falls through to whatever artwork is underneath.
   *
   * Last match wins, which is the same rule as drawing: placements are painted in order, so the
   * one you can see on top of the others is the one at the end of the list.
   */
  const hit = backdrops.value.reduce<number | null>(
    (found, b, i) => (x >= b.x && x < b.x + b.w && y >= b.y && y < b.y + b.h ? i : found),
    null,
  )

  selectedBackdrop.value = hit
  backdropDrag = hit === null ? null : { index: hit, ox: x - backdrops.value[hit]!.x, oy: y - backdrops.value[hit]!.y }
}

/**
 * Drag the held piece under the pointer.
 *
 * A move that breaks a rule simply doesn't happen — the piece stays where it last legally was
 * and the drag carries on, so dragging a couch across a wall slides it along the wall rather than
 * dropping it or filling the screen with complaints. The refusal text is left to the actions that
 * are one-shot (placing, turning), where there's no next frame to say it in.
 */
/**
 * Move a piece of placed artwork, and the ground under it, to where it's being dragged.
 *
 * The tiles inside the placement travel with the picture. What's left behind becomes void rather
 * than floor — the honest answer to "what is here now" is *nothing*, and leaving a rectangle of
 * walkable floor in the shape of a departed city would be a strange invisible plaza.
 *
 * Furniture and rooms standing inside the rectangle go too, because they were placed against
 * those streets and mean nothing without them.
 */
function dragBackdropTo(px: number, py: number) {
  if (!backdropDrag) return

  const placed = backdrops.value[backdropDrag.index]
  if (!placed) return (backdropDrag = null)

  const { x, y } = toTile(camera, px, py)
  const nx = x - backdropDrag.ox
  const ny = y - backdropDrag.oy

  if (nx === placed.x && ny === placed.y) return

  // Remembered on the first tile of actual movement rather than on the click that selected it,
  // so merely picking something up doesn't fill the undo stack with snapshots of nothing.
  if (!backdropDrag.moved) {
    backdropDrag.moved = true
    remember('move artwork')
  }

  const shiftX = nx - placed.x
  const shiftY = ny - placed.y
  const inRect = (ox: number, oy: number) =>
    ox >= placed.x && ox < placed.x + placed.w && oy >= placed.y && oy < placed.y + placed.h

  // Lift the ground out, leaving void, then set it down at the new position. Read from a copy so
  // an overlapping move doesn't paste tiles it has already overwritten.
  const before = [...tiles.value]
  const rows = tiles.value.map((row, ry) => {
    const chars = [...row]

    for (let cx = 0; cx < width.value; cx++) {
      if (inRect(cx, ry)) chars[cx] = VOID
    }

    return chars
  })

  for (let oy = placed.y; oy < placed.y + placed.h; oy++) {
    for (let ox = placed.x; ox < placed.x + placed.w; ox++) {
      const tx = ox + shiftX
      const ty = oy + shiftY
      if (tx < 1 || ty < 1 || tx >= width.value - 1 || ty >= height.value - 1) continue

      rows[ty]![tx] = before[oy]?.[ox] ?? VOID
    }
  }

  tiles.value = rows.map(r => r.join(''))
  objects.value = objects.value.map(o => (inRect(o.x, o.y) ? { ...o, x: o.x + shiftX, y: o.y + shiftY } : o))
  zones.value = zones.value.map(z => (inRect(z.x, z.y) ? { ...z, x: z.x + shiftX, y: z.y + shiftY } : z))

  backdrops.value = backdrops.value.map((b, i) => (i === backdropDrag!.index ? { ...b, x: nx, y: ny } : b))
}

/** Take a piece of placed artwork away, along with the ground and furniture that came with it. */
function removeBackdrop(index: number) {
  const placed = backdrops.value[index]
  if (!placed) return

  remember('remove artwork')

  const inRect = (ox: number, oy: number) =>
    ox >= placed.x && ox < placed.x + placed.w && oy >= placed.y && oy < placed.y + placed.h

  tiles.value = tiles.value.map((row, ry) =>
    [...row].map((c, cx) => (inRect(cx, ry) ? VOID : c)).join(''))

  objects.value = objects.value.filter(o => !inRect(o.x, o.y))
  zones.value = zones.value.filter(z => !inRect(z.x, z.y))
  backdrops.value = backdrops.value.filter((_, i) => i !== index)

  selectedBackdrop.value = null
  refused.value = ''
}

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

  if (tool.value === 'zone' || tool.value === 'stage') {
    const t = toTile(camera, px, py)
    zoneDrag = { x0: t.x, y0: t.y, x1: t.x, y1: t.y, kind: tool.value === 'stage' ? 'stage' : 'private' }

    return
  }

  if (tool.value === 'portal') {
    const t = toTile(camera, px, py)

    // Placing the *exit* of a doorway that's waiting for one: the next click is the far end, not
    // a new doorway. Doing it as a click rather than a pair of coordinate boxes is the whole
    // difference between "set the destination" and "point at where you want to come out".
    if (awaitingExit.value) return setPortalExit(t.x, t.y)

    portalDrag = { x0: t.x, y0: t.y, x1: t.x, y1: t.y }

    return
  }

  if (tool.value === 'screen') {
    const t = toTile(camera, px, py)
    screenDrag = { x0: t.x, y0: t.y, x1: t.x, y1: t.y }

    return
  }

  if (tool.value === 'art') {
    const t = toTile(camera, px, py)
    artDrag = { x0: t.x, y0: t.y, x1: t.x, y1: t.y }

    return
  }

  if (tool.value === 'place') {
    const t = toTile(camera, px, py)
    placeDrag = { x0: t.x, y0: t.y, x1: t.x, y1: t.y }

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
  if (backdropDrag) return dragBackdropTo(px, py)

  if (placeDrag) {
    const t = toTile(camera, px, py)
    placeDrag.x1 = t.x
    placeDrag.y1 = t.y

    return
  }

  if (artDrag) {
    const t = toTile(camera, px, py)
    artDrag.x1 = t.x
    artDrag.y1 = t.y

    return
  }

  if (screenDrag) {
    const t = toTile(camera, px, py)
    screenDrag.x1 = t.x
    screenDrag.y1 = t.y

    return
  }

  if (portalDrag) {
    const t = toTile(camera, px, py)
    portalDrag.x1 = t.x
    portalDrag.y1 = t.y

    return
  }

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
  backdropDrag = null
  panning = null

  // Lifting a finger out of a pinch ends it; the one still down doesn't become a brush, or
  // every zoom would paint a wall on its way out.
  if (pinch) {
    if (touches.size < 2) pinch = null

    return
  }

  if (artDrag) {
    const drag = artDrag
    artDrag = null

    addExhibit({
      x: Math.max(0, Math.min(drag.x0, drag.x1)),
      y: Math.max(0, Math.min(drag.y0, drag.y1)),
      w: Math.min(width.value, Math.max(drag.x0, drag.x1) + 1) - Math.max(0, Math.min(drag.x0, drag.x1)),
      h: Math.min(height.value, Math.max(drag.y0, drag.y1) + 1) - Math.max(0, Math.min(drag.y0, drag.y1)),
    })

    return
  }

  if (screenDrag) {
    const drag = screenDrag
    screenDrag = null

    addScreen({
      x: Math.max(0, Math.min(drag.x0, drag.x1)),
      y: Math.max(0, Math.min(drag.y0, drag.y1)),
      w: Math.min(width.value, Math.max(drag.x0, drag.x1) + 1) - Math.max(0, Math.min(drag.x0, drag.x1)),
      h: Math.min(height.value, Math.max(drag.y0, drag.y1) + 1) - Math.max(0, Math.min(drag.y0, drag.y1)),
    })

    return
  }

  if (portalDrag) {
    const drag = portalDrag
    portalDrag = null

    addPortal({
      x: Math.max(0, Math.min(drag.x0, drag.x1)),
      y: Math.max(0, Math.min(drag.y0, drag.y1)),
      w: Math.min(width.value, Math.max(drag.x0, drag.x1) + 1) - Math.max(0, Math.min(drag.x0, drag.x1)),
      h: Math.min(height.value, Math.max(drag.y0, drag.y1) + 1) - Math.max(0, Math.min(drag.y0, drag.y1)),
    })

    return
  }

  if (placeDrag) {
    const drag = placeDrag
    placeDrag = null

    placeInto({
      x: Math.min(drag.x0, drag.x1),
      y: Math.min(drag.y0, drag.y1),
      w: Math.abs(drag.x1 - drag.x0) + 1,
      h: Math.abs(drag.y1 - drag.y0) + 1,
    })

    return
  }

  if (!zoneDrag) return

  const x = Math.max(0, Math.min(zoneDrag.x0, zoneDrag.x1))
  const y = Math.max(0, Math.min(zoneDrag.y0, zoneDrag.y1))
  const w = Math.min(width.value, Math.max(zoneDrag.x0, zoneDrag.x1) + 1) - x
  const h = Math.min(height.value, Math.max(zoneDrag.y0, zoneDrag.y1) + 1) - y
  const kind = zoneDrag.kind

  zoneDrag = null
  placeDrag = null

  if (w < 1 || h < 1) return

  const stage = kind === 'stage'

  /*
   * A room style that brings its own ground decides the zone's size, not the drag.
   *
   * Otherwise the sealed rectangle and the room inside it disagree: you would drag 6×4, get a
   * 22×12 loft laid from that corner, and the zone that decides who can hear whom would cover a
   * fifth of it. The zone is meant to *be* the room.
   */
  const style = stage ? null : roomPresets.value.find(p => p.key === roomStyle.value)
  const sized = style && fixedSize(style) ? { w: style.w, h: style.h } : { w, h }

  const zone: SpaceZone = {
    id: `z-${Date.now().toString(36)}`,
    name: stage
      ? `Stage ${zones.value.filter(z => z.kind === 'stage').length + 1}`
      : (style && fixedSize(style) ? style.label : `Room ${zones.value.filter(z => z.kind !== 'stage').length + 1}`),
    kind,
    x,
    y,
    w: Math.min(sized.w, width.value - x),
    h: Math.min(sized.h, height.value - y),
  }

  zones.value = [...zones.value, zone]

  // A room you dragged comes out furnished, if you asked for one that does. The old behaviour
  // is still there and still the default — it's the `empty` style, which lays a floor and
  // stops, and naming it is better than hiding it.
  //
  // A stage is left bare whatever the room style says: the styles furnish a *room* — desks,
  // couches, a meeting table — and a platform covered in sofas is somewhere to sit rather than
  // somewhere to be heard from. It still gets a floor, because a zone with nowhere to stand in
  // it is one the API refuses to save.
  if (stage) {
    for (let dy = zone.y; dy < zone.y + zone.h; dy++) {
      for (let dx = zone.x; dx < zone.x + zone.w; dx++) setTile(dx, dy, STAGE_FLOOR)
    }

    return
  }

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
function furnishZone(zone: { x: number, y: number, w: number, h: number }, key: string) {
  const preset = roomPresets.value.find(p => p.key === key)
  if (!preset) return

  /*
   * A room that brings its own ground is laid exactly as authored, at its own size, from the
   * corner you started the drag at — see `fixedSize`. Everything else is paved with its floor
   * character across whatever rectangle you dragged, which is what room presets have always done.
   */
  const laid = fixedSize(preset)
    ? { x: zone.x, y: zone.y, w: preset.w, h: preset.h }
    : zone

  if (preset.tiles?.length) {
    preset.tiles.forEach((row, dy) => {
      [...row].forEach((tile, dx) => setTile(laid.x + dx, laid.y + dy, tile))
    })

    // Artwork last, over the ground it belongs to, shifted to wherever the room landed.
    backdrops.value = [
      ...backdrops.value,
      ...(preset.backdrops ?? []).map(b => ({ ...b, x: b.x + laid.x, y: b.y + laid.y })),
    ]
  }
  else {
    for (let dy = laid.y; dy < laid.y + laid.h; dy++) {
      for (let dx = laid.x; dx < laid.x + laid.w; dx++) setTile(dx, dy, preset.floor)
    }
  }

  objects.value = objects.value.filter(o => !objectInZone(o, laid))

  const stamp = Date.now().toString(36)
  let n = 0

  for (const piece of anchorObjects(preset, laid)) {
    const face = piece.facing ?? 'down'
    if (refusalFor(piece.kind, piece.x, piece.y, face)) continue

    objects.value = [...objects.value, { ...piece, facing: face, id: `d-${stamp}-${n++}` }]
  }

  /*
   * A room with its own ground gets joined to the map around it, exactly as a stamped *map* does.
   *
   * Its border is wall — that is what makes it a room rather than a patch of floor — so dropped
   * into a map it arrives sealed, and a beautifully drawn loft you cannot get into is the same
   * complaint this feature has produced at every previous step. The link cuts one wall tile,
   * which is what a doorway is.
   *
   * Only for the artwork-backed rooms: paving a rectangle with floorboards never sealed anything,
   * and running a repair after it would be looking for a problem that cannot exist.
   */
  if (preset.tiles?.length) tiles.value = repairConnectivity(tiles.value).tiles

  refused.value = ''
}

// --- doorways ---

/**
 * Make a doorway out of a dragged rectangle.
 *
 * A doorway to another *room* is finished the moment it exists — the destination was chosen in
 * the picker before the drag. A doorway to somewhere on *this* map is not: it needs an exit, and
 * asking for one as two number boxes would be asking somebody to read coordinates off a grid they
 * are looking at. So it goes into a waiting state and the next click on the canvas is the exit.
 */
function addPortal(rect: { x: number, y: number, w: number, h: number }) {
  if (rect.w < 1 || rect.h < 1) return

  const standable = firstWalkableIn(tiles.value, rect)
  if (!standable) return refuse("A doorway needs somewhere to stand in it.")

  const choice = portalTo.value

  /*
   * "A new room" — the doorway makes the place it leads to.
   *
   * The whole point of offering it is that the two halves get built together. Doing this by hand
   * is three steps in the wrong order — add a map, come back, hang a door, then go into the new
   * room and hang another one back — and the middle one is easy to forget, which leaves a door
   * into a room with no way out.
   *
   * It has to be its own path because it is the only one that *asks the server something* before
   * the doorway can exist: the room needs a slug before anything can point at it.
   */
  if (choice === 'new') return void addPortalToNewMap(rect)

  const id = `p-${Date.now().toString(36)}`
  const interior = choice.startsWith('map:') ? choice.slice(4) : null
  const channelId = choice.startsWith('room:') ? Number(choice.slice(5)) : 0

  remember('add a doorway')

  /*
   * Where it goes, and what it is called by default.
   *
   * A doorway into an interior or another room is finished the moment it exists — the
   * destination was chosen in the picker before the drag, and the far end is left unnamed, which
   * means "come out at that room's own entrance". A doorway to somewhere on *this* map is not
   * finished: it needs an exit, and asking for one as two number boxes would be asking somebody
   * to read coordinates off a grid they are looking at. So it is parked on its own entrance —
   * a legal portal that happens to go nowhere, so an interrupted edit still saves — and the next
   * click on the canvas is the exit.
   */
  const to: SpacePortal['to'] = interior
    ? { kind: 'map', map: interior }
    : channelId
      ? { kind: 'room', channel_id: channelId }
      : { kind: 'point', x: standable.x, y: standable.y }

  const portal: SpacePortal = {
    id,
    name: interior
      ? (interiors.value.find(m => m.slug === interior)?.name ?? 'Doorway')
      : channelId
        ? (spaceRooms.value.find(r => r.id === channelId)?.name ?? 'Doorway')
        : `Doorway ${portals.value.length + 1}`,
    ...rect,
    activation: portalActivation.value,
    to,
  }

  portals.value = [...portals.value, portal]
  selectedPortal.value = id
  refused.value = ''

  if (to.kind === 'point') {
    awaitingExit.value = id
    refused.value = 'Now click where it should come out.'
  }
}

/**
 * Drag out a doorway and let it build the room on the other side.
 *
 * Both halves are wired here, and each is hung on the map that owns it. The way *in* is the
 * portal added to this draft; the way *out* is created by the server as part of the new room,
 * pointing back at *this map* — and at no particular tile on it.
 *
 * That last part is deliberate and is what makes you come back out of the door you went in by.
 * The far end finds this doorway when somebody travels rather than remembering where it was, so
 * dragging it somewhere else later moves where you come back out, with nothing to keep in step.
 * See the stage's arrivalIn.
 */
async function addPortalToNewMap(rect: { x: number, y: number, w: number, h: number }) {
  const name = newDoorMapName.value.trim()
  const slug = slugify(name)

  if (!slug) return refuse('Give the new room a name first.')

  // Names are the author's and may repeat; keys may not, and the key is what doors store. Rather
  // than refuse a second "Lobby", number it — the name they typed is kept either way.
  const taken = new Set((props.map.siblings ?? []).map(m => m.slug))
  let key = slug
  for (let i = 2; taken.has(key); i++) key = `${slug}-${i}`

  addingMap.value = true

  try {
    await createMap({ slug: key, name, return_to: props.map.slug })
  }
  catch (e: any) {
    return refuse(e?.data?.message ?? 'Could not make that room.')
  }
  finally {
    addingMap.value = false
  }

  remember('add a doorway')

  portals.value = [...portals.value, {
    id: `p-${Date.now().toString(36)}`,
    name,
    ...rect,
    activation: portalActivation.value,
    to: { kind: 'map', map: key },
  }]

  newDoorMapName.value = ''
  // Said out loud because the room is already real on the server while the doorway into it is
  // not — this draft has to be saved, and that asymmetry is worth one sentence.
  refused.value = `"${name}" is ready. Save this map to hang the doorway into it.`
}

/** The second click of building a same-map doorway: where you come out. */
function setPortalExit(x: number, y: number) {
  const id = awaitingExit.value
  if (!id) return

  if (!isWalkable(draft.value, x, y)) return refuse('A doorway has to come out somewhere you can stand.')

  portals.value = portals.value.map(p => (p.id === id ? { ...p, to: { kind: 'point', x, y } } : p))
  awaitingExit.value = null
  refused.value = ''
}

/**
 * What a doorway says it leads to, under its name in the list.
 *
 * In the script rather than inline in the template because the three destination kinds are a
 * discriminated union, and Vue's template compiler doesn't narrow one through a chained ternary
 * — it type-checks each branch against the whole union, so `p.to.map` fails on the `point` arm
 * even where the ternary has already ruled it out.
 */
function portalDestination(p: SpacePortal) {
  // Pulled into a const first: narrowing a *property* is lost again inside the callbacks below,
  // since TypeScript can't know `find` won't reassign it.
  const to = p.to

  if (to.kind === 'point') return `to ${to.x},${to.y} on this map`

  if (to.kind === 'map') {
    return `to ${interiors.value.find(m => m.slug === to.map)?.name ?? to.map}`
  }

  return spaceRooms.value.find(r => r.id === to.channel_id)?.name ?? 'another Side Space'
}

function removePortal(id: string) {
  remember('remove a doorway')

  portals.value = portals.value.filter(p => p.id !== id)
  if (selectedPortal.value === id) selectedPortal.value = null
  if (awaitingExit.value === id) awaitingExit.value = null
}

// --- screens ---

/**
 * Hang a surface that shows whatever the room is watching.
 *
 * Unlike a doorway, it is *not* checked for somewhere to stand: the whole point of a screen is
 * that it goes where you cannot walk — up a wall, over a stage, across the back of a room.
 * Requiring a standable tile would refuse every sensible placement and accept only the daft ones.
 */
function addScreen(rect: { x: number, y: number, w: number, h: number }) {
  if (rect.w < 1 || rect.h < 1) return

  if (screens.value.length >= MAX_SCREENS) {
    return refuse(`A map can hold ${MAX_SCREENS} screens.`)
  }

  remember('add a screen')

  const id = `s-${Date.now().toString(36)}`

  screens.value = [...screens.value, {
    id,
    name: `Screen ${screens.value.length + 1}`,
    ...rect,
  }]

  selectedScreen.value = id
  refused.value = ''
}

/**
 * Nudge one of a screen's four numbers, keeping it on the map.
 *
 * Clamped here rather than left to the save, because the save refuses the *whole map* for a
 * screen that runs off the edge — so typing a width one digit too long would mean an editor that
 * silently cannot be saved until you found which field did it. Width and height stay at least 1:
 * a screen of zero tiles draws nothing and reads as the feature being broken.
 */
function resizeScreen(screen: SpaceScreen, field: 'x' | 'y' | 'w' | 'h', value: number) {
  if (!Number.isFinite(value)) return

  remember('resize a screen')

  const next = { ...screen, [field]: Math.max(field === 'w' || field === 'h' ? 1 : 0, Math.round(value)) }

  // Keep it inside the grid, moving the origin back rather than shrinking what was just typed —
  // the number you touched is the one you meant.
  next.w = Math.min(next.w, width.value)
  next.h = Math.min(next.h, height.value)
  next.x = Math.min(next.x, width.value - next.w)
  next.y = Math.min(next.y, height.value - next.h)

  screens.value = screens.value.map(s => (s.id === screen.id ? next : s))
  selectedScreen.value = screen.id
}

function removeScreen(id: string) {
  remember('remove a screen')

  screens.value = screens.value.filter(s => s.id !== id)
  if (selectedScreen.value === id) selectedScreen.value = null
}

// --- the gallery ---

/**
 * Draw a frame over a painting the artwork already contains.
 *
 * Not checked for standability, like a screen and for the same reason: a painting hangs on a wall
 * and a statue stands where you can't. What matters is that you can get *near* it, and the room's
 * own floor decides that.
 */
function addExhibit(rect: { x: number, y: number, w: number, h: number }) {
  if (rect.w < 1 || rect.h < 1) return

  if (exhibits.value.length >= MAX_EXHIBITS) {
    return refuse(`A map can hold ${MAX_EXHIBITS} frames.`)
  }

  remember('add a frame')

  const id = `a-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 6)}`

  exhibits.value = [...exhibits.value, { id, name: `Artwork ${exhibits.value.length + 1}`, ...rect }]
  selectedExhibit.value = id
  refused.value = ''
}

function removeExhibit(id: string) {
  remember('remove a frame')

  exhibits.value = exhibits.value.filter(e => e.id !== id)
  if (selectedExhibit.value === id) selectedExhibit.value = null
}

/** What's hanging in a frame, straight off the prop — the pictures are the server's, not the draft's. */
function pieceFor(id: string) {
  return (props.map.exhibit_pieces ?? []).find(p => p.exhibit_id === id) ?? null
}

/** The frame whose upload form is open, and what's being typed into it. */
const hangingIn = ref<string | null>(null)
const hangTitle = ref('')
const hangArtist = ref('')
const hangCaption = ref('')
const hangFile = ref<File | null>(null)
const hanging = ref(false)

function openHangForm(id: string) {
  const piece = pieceFor(id)

  hangingIn.value = id
  // Prefilled from what's already hanging, so replacing just the image doesn't mean retyping
  // the label — which is the common case when a better scan of the same painting turns up.
  hangTitle.value = piece?.title ?? exhibits.value.find(e => e.id === id)?.name ?? ''
  hangArtist.value = piece?.artist ?? ''
  hangCaption.value = piece?.caption ?? ''
  hangFile.value = null
}

function onHangFile(e: Event) {
  hangFile.value = (e.target as HTMLInputElement).files?.[0] ?? null
}

/**
 * Upload the picture.
 *
 * Sent straight away rather than waiting for Save, and that's the honest shape: the picture is
 * *not part of the map document*. It goes to its own endpoint, against its own table, under a
 * staff-only check that a whole-map save doesn't have — so folding it into Save would mean one
 * button doing two things with two different permissions and two different failure modes.
 *
 * The frame it hangs in does have to be saved first, though, because the server refuses to hang a
 * picture in a frame nobody drew — see storeExhibit. Said plainly below rather than worked
 * around, since the alternative is a save happening behind somebody's back.
 */
async function hangPicture() {
  const id = hangingIn.value
  if (!id || !hangFile.value || !hangTitle.value.trim()) return

  const body = new FormData()
  body.append('image', hangFile.value)
  body.append('title', hangTitle.value.trim())
  if (hangArtist.value.trim()) body.append('artist', hangArtist.value.trim())
  if (hangCaption.value.trim()) body.append('caption', hangCaption.value.trim())

  hanging.value = true

  try {
    await hangExhibit(id, body)
    hangingIn.value = null
    hangFile.value = null
    refused.value = ''
    // The map on the stage behind this now has a picture it didn't have. Told, not left to the
    // broadcast — the editor's own upload shouldn't wait on a websocket round trip to show.
    emit('rooms-changed')
  }
  catch (e: any) {
    const errors = e?.data?.errors as Record<string, string[]> | undefined
    refuse(errors ? Object.values(errors).flat()[0]! : (e?.data?.message ?? 'Could not hang that picture.'))
  }
  finally {
    hanging.value = false
  }
}

async function takeDownPicture(id: string) {
  try {
    await unhangExhibit(id)
    refused.value = ''
    emit('rooms-changed')
  }
  catch (e: any) {
    refuse(e?.data?.message ?? 'Could not take that picture down.')
  }
}

// --- the grid's size ---

/**
 * Resize the room, keeping what still fits.
 *
 * Zones, furniture and the entrance are all pulled back inside, since a grid that shrinks past
 * them would otherwise produce a map the API rejects for reasons that aren't visible on screen.
 */
function applySize() {
  remember('resize')
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
  return Math.max(MIN_MAP, Math.min(MAX_MAP, Math.round(n) || MIN_MAP))
}

function firstFloor() {
  for (let y = 0; y < height.value; y++) {
    for (let x = 0; x < width.value; x++) {
      if (isWalkable(draft.value, x, y)) return { x, y }
    }
  }

  return null
}

/**
 * Start over: four walls and nothing in them.
 *
 * The artwork goes with everything else, and that is not a detail. A backdrop is cut to a
 * particular grid and is the *ground* of the map it belongs to — left behind over a blank room it
 * would draw a city you cannot walk into, on collision tiles that no longer have anything to do
 * with the streets in the picture.
 */
function clearRoom() {
  remember('start over')
  tiles.value = blankTiles(width.value, height.value)
  zones.value = []
  objects.value = []
  backdrops.value = []
  portals.value = []
  screens.value = []
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
  remember('load a layout')
  width.value = preset.width
  height.value = preset.height
  // Copied, never referenced: these live in a session-wide cache (see useSpacePresets), and
  // painting a wall must not edit the catalogue every other picker reads from.
  tiles.value = [...preset.tiles]
  zones.value = preset.zones.map(z => ({ ...z }))
  objects.value = preset.objects.map(o => ({ ...o }))
  spawn.value = { ...preset.spawn }
  // Swapped with the rest of the room, in both directions: loading an artwork-backed preset
  // brings its picture, and loading a tile-built one clears it. A backdrop left behind over a
  // different grid would be streets that no longer line up with anywhere you can stand.
  backdrops.value = (preset.backdrops ?? []).map(b => ({ ...b }))
  // Doorways and screens swap with the rest, in both directions, for exactly the reason the
  // artwork does: they are geometry cut for *this* grid, and a screen left hanging over a room
  // that is now a different shape is a rectangle floating in the wrong place.
  portals.value = (preset.portals ?? []).map(p => ({ ...p, to: { ...p.to } }))
  screens.value = (preset.screens ?? []).map(s => ({ ...s }))

  loadedPreset.value = preset.key
  refused.value = ''
  showPresets.value = false

  // The new room is a different size, so the camera has to re-frame it.
  fit()
}


/** The selected artwork, with the catalogue's own name for it, for the panel. */
const selectedBackdropInfo = computed(() => {
  const placed = selectedBackdrop.value === null ? null : backdrops.value[selectedBackdrop.value]
  if (!placed) return null

  return { ...placed, label: BACKDROPS[placed.key]?.label ?? placed.key }
})

/** The map preset currently chosen in the picker, if the choice is a map at all. */
const chosenMap = computed(() => {
  const [family, key] = extendWith.value.split(':')
  if (family !== 'map') return null

  return groupedPresets.value.flatMap(g => g.items).find(p => p.key === key) ?? null
})

/**
 * Grow the map at one edge, and put the chosen thing in the new ground.
 *
 * A thin wrapper over {@link placeInto} that works out *where* the new rectangle goes, because
 * naming an edge and a number is a second way of saying the same thing as dragging one out — and
 * having two code paths for it was how the drag version ended up with connection rules the form
 * version didn't have.
 *
 * A whole map preset gets as much room as it actually needs, on **both** axes: extending an
 * office east by twenty and dropping a sixty-four-wide city into it used to hand you a city
 * cropped to twenty columns, which is not the map anybody chose. The number you type is a
 * minimum, not a budget.
 */
function extend() {
  const by = Math.max(1, Math.round(extendBy.value) || 1)
  const edge = extendEdge.value
  const horizontal = edge === 'east' || edge === 'west'

  const preset = chosenMap.value
  const needW = horizontal ? Math.max(by, preset?.width ?? 0) : Math.max(width.value, preset?.width ?? 0)
  const needH = horizontal ? Math.max(height.value, preset?.height ?? 0) : Math.max(by, preset?.height ?? 0)

  const rect = {
    east: { x: width.value, y: 0, w: needW, h: needH },
    west: { x: -needW, y: 0, w: needW, h: needH },
    south: { x: 0, y: height.value, w: needW, h: needH },
    north: { x: 0, y: -needH, w: needW, h: needH },
  }[edge]

  placeInto(rect, 'extend')
}

/**
 * Open up whatever is walled off, on the map as it stands.
 *
 * The gap that made three rounds of connection fixes useless. Every one of them ran *at the moment
 * two maps were joined* — so a map that was already saved kept whatever collision grid it was
 * saved with, and no amount of improving the join helped somebody who had already done the
 * joining. There was no way to say "this map is broken now, fix it".
 *
 * This is that way. It also clears the collisions along the very edge, because a map whose rim is
 * solid cannot be walked out of into anything placed beside it later, and that is the shape the
 * artwork-backed maps arrive in: an island whose border is open water.
 */
function reconnect() {
  remember('open up the map')

  // The rim first. Two tiles rather than one, so somebody walking the boundary is never pinched
  // against the edge of the world, and so a map placed alongside meets walkable ground.
  const margin = 2
  tiles.value = tiles.value.map((row, y) => [...row].map((tile, x) => {
    const nearEdge = x < margin || y < margin || x >= width.value - margin || y >= height.value - margin

    return nearEdge && !isWalkableTile(tile) ? FLOOR : tile
  }).join(''))

  const repair = repairConnectivity(tiles.value)
  tiles.value = repair.tiles

  // Said out loud, because the whole failure mode here has been changes that looked like they
  // worked. A count of what moved is the difference between "done" and "apparently done".
  refused.value = repair.links
    ? `Opened ${repair.links} way${repair.links === 1 ? '' : 's'} through, laying ${repair.dug} tiles.`
    : 'Everywhere was already reachable.'

  selectedId.value = null
  loadedPreset.value = null
}

/**
 * Take tiles off one edge.
 *
 * The counterpart to {@link extend}, and a different thing from Undo: undo restores the map you
 * had a moment ago, while this crops whatever map you have now, however it got that way. You
 * reach for one after a mistake and the other after a change of mind.
 *
 * Everything that falls outside goes with it — furniture, rooms, and any artwork whose rectangle
 * no longer has a map under it. Anything clipped rather than lost keeps the part that survived,
 * because a backdrop half off the edge is still the ground for the half that remains.
 */
function reduce() {
  const by = Math.max(1, Math.round(extendBy.value) || 1)
  const edge = extendEdge.value
  const horizontal = edge === 'east' || edge === 'west'

  if ((horizontal ? width.value : height.value) - by < MIN_MAP) {
    return refuse(`A map can't be smaller than ${MIN_MAP} tiles.`)
  }

  remember('reduce')

  const cropped = cropTiles(tiles.value, edge, by)
  const { dx, dy } = cropped

  width.value = cropped.width
  height.value = cropped.height
  tiles.value = cropped.tiles

  const inside = (x: number, y: number) => x >= 0 && y >= 0 && x < cropped.width && y < cropped.height

  zones.value = zones.value
    .map(z => ({ ...z, x: z.x + dx, y: z.y + dy }))
    .filter(z => inside(z.x, z.y) && inside(z.x + z.w - 1, z.y + z.h - 1))

  objects.value = objects.value
    .map(o => ({ ...o, x: o.x + dx, y: o.y + dy }))
    .filter(o => inside(o.x, o.y))

  // Artwork is clipped rather than dropped: a picture half off the edge is still the ground for
  // the half that is left, and cropping ten tiles off a city should not delete the city.
  backdrops.value = backdrops.value
    .map(b => ({ ...b, x: b.x + dx, y: b.y + dy }))
    .filter(b => b.x + b.w > 0 && b.y + b.h > 0 && b.x < cropped.width && b.y < cropped.height)

  const spawnAt = { x: spawn.value.x + dx, y: spawn.value.y + dy }
  spawn.value = inside(spawnAt.x, spawnAt.y) && isWalkable(draft.value, spawnAt.x, spawnAt.y)
    ? spawnAt
    : (firstWalkableIn(cropped.tiles, { x: 0, y: 0, w: cropped.width, h: cropped.height }) ?? { x: 1, y: 1 })

  // Cropping can cut a map in half as easily as growing one can — a district reached only through
  // the strip you just removed is stranded, so it gets the same guarantee.
  tiles.value = repairConnectivity(tiles.value).tiles

  selectedId.value = null
  refused.value = ''
  loadedPreset.value = null
  fit()
}

/**
 * Grow the map to contain a rectangle and put the chosen thing inside it.
 *
 * The one path both the Extend form and the Place tool go through. The rectangle may lie partly
 * or wholly outside the current grid — that is the point of the drag version. Growing to meet a
 * rectangle that starts at a negative coordinate shifts the whole existing map right and down, so
 * every zone, object, backdrop and the spawn shift with it.
 */
function placeInto(rect: { x: number, y: number, w: number, h: number }, label = 'place a layout') {
  if (rect.w < 2 || rect.h < 2) return refuse('Drag out a bigger area than that.')

  const [family, key] = extendWith.value.split(':')

  remember(label)


  const grown = growToFit(tiles.value, rect)
  const { dx, dy } = grown

  // Where the dragged rectangle ended up once the map moved under it.
  const at = { x: rect.x + dx, y: rect.y + dy, w: rect.w, h: rect.h }

  let patch = {
    width: grown.width,
    height: grown.height,
    tiles: grown.tiles,
    zones: zones.value.map(z => ({ ...z, x: z.x + dx, y: z.y + dy })),
    objects: objects.value.map(o => ({ ...o, x: o.x + dx, y: o.y + dy })),
    backdrops: backdrops.value.map(b => ({ ...b, x: b.x + dx, y: b.y + dy })),
  }

  if (family === 'map') {
    const preset = groupedPresets.value.flatMap(g => g.items).find(p => p.key === key)
    if (preset) patch = stampMap(patch, preset, at.x, at.y)
  }

  width.value = patch.width
  height.value = patch.height
  tiles.value = patch.tiles
  zones.value = patch.zones ?? []
  objects.value = patch.objects ?? []
  backdrops.value = patch.backdrops ?? []
  spawn.value = { x: spawn.value.x + dx, y: spawn.value.y + dy }

  if (family === 'room' && key) {
    // Clipped to the grid's interior — a drag that ran past the size ceiling still furnishes the
    // part of it that became real map.
    const ground = {
      x: Math.max(1, at.x),
      y: Math.max(1, at.y),
      w: Math.min(at.w, patch.width - 1 - Math.max(1, at.x)),
      h: Math.min(at.h, patch.height - 1 - Math.max(1, at.y)),
    }

    if (ground.w > 0 && ground.h > 0) furnishZone(ground, key)
  }

  /*
   * Leave nothing stranded.
   *
   * This has been wrong twice. First a doorway punched at the join, which assumes the two halves
   * meet along a *wall* — and a map generated from artwork meets you with open water, four tiles
   * deep in places and sixteen in others. Then a dug route between two chosen points, which was
   * worse: the point it chose inside New York was a misclassified speck in the night sky, so the
   * map was joined to a scrap and the city itself stayed sealed.
   *
   * The requirement was never "join these two"; it is "no pocket left stranded". See
   * repairConnectivity, which links every walkable region to the mainland and prefers to cross
   * water rather than tunnel through a building.
   */
  const repair = repairConnectivity(tiles.value)
  tiles.value = repair.tiles

  refused.value = ''
  loadedPreset.value = null
  fit()
}

// --- the building: this Side Space's other maps ---

const newMapName = ref('')
const addingMap = ref(false)

/**
 * A slug from a typed name — the one place in the app that derives one.
 *
 * The server takes the slug rather than deriving it, because it is what every doorway stores
 * and a name is renameable: deriving it *there* would silently re-slug a map on rename and break
 * every door into it. Deriving it here, once, at the moment the map is created, is the same
 * convenience without the trap — after this the two are independent, and renaming "Screen One"
 * to "The Big Screen" leaves its key, and its doors, alone.
 */
function slugify(name: string) {
  return name
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '')
    .slice(0, 40)
}

async function addSibling() {
  const name = newMapName.value.trim()
  const slug = slugify(name)

  if (!slug) return refuse('Give the map a name with a letter or number in it.')

  addingMap.value = true

  try {
    await createMap({ slug, name })
    newMapName.value = ''
    refused.value = ''
    /*
     * Not `saved`, which closes the editor — you have just added a map and the next thing you
     * want is the door into it, which is three tools away in this same panel. And not opened
     * either: adding a map and walking into it are separate acts, and moving whoever pressed Add
     * off the map they are halfway through editing is not what Add means.
     */
    emit('rooms-changed')
  }
  catch (e: any) {
    refuse(e?.data?.message ?? 'Could not add that map.')
  }
  finally {
    addingMap.value = false
  }
}

/**
 * Walk into another of this Side Space's maps.
 *
 * Guarded, because the editor's draft is *this* map and opening another one replaces it. Every
 * other way of losing work here is undoable (there's an undo stack); this one isn't, and it's a
 * click away from a list you're browsing.
 */
function openSibling(slug: string) {
  if (slug === props.map.slug) return

  // Unsaved work is the only reason this needs asking about, so it only asks when there is some.
  if (touched.value) {
    leavingTo.value = slug

    return
  }

  emit('open', slug)
}

/** Which map we are about to walk into, once the unsaved-changes question is answered. */
const leavingTo = ref<string | null>(null)

function confirmLeave() {
  const slug = leavingTo.value
  leavingTo.value = null

  if (slug) emit('open', slug)
}

/** The map the delete confirm is about, and whether the delete is in flight. */
const deletingMap = ref<{ slug: string, name: string } | null>(null)
const deletingMapBusy = ref(false)

function removeSibling(slug: string, name: string) {
  deletingMap.value = { slug, name }
}

async function confirmRemoveSibling() {
  const target = deletingMap.value
  if (!target) return

  const { slug } = target
  deletingMapBusy.value = true

  try {
    await deleteMap(slug)
    deletingMap.value = null
    refused.value = ''

    // Deleting the map you are standing on has to move you, and the only place to go is the way
    // in. `deleteMap` has already pointed its own composable there; this moves the editor too.
    if (slug === props.map.slug) emit('open', MAIN_MAP)
    else emit('rooms-changed')
  }
  catch (e: any) {
    refuse(e?.data?.message ?? 'Could not delete that map.')
    deletingMap.value = null
  }
  finally {
    deletingMapBusy.value = false
  }
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
      // The whole draft. `save` strips the fields the server owns — see SERVER_OWNED there.
      await save(draft.value)
    }

    // What is on screen is now what is stored, so there is nothing left to warn about losing.
    // The undo stack goes with it: undoing past a save would silently re-dirty the map.
    history.value = []
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
    // Amber against the room's indigo: a stage is the one rectangle you can step into by
    // accident and start broadcasting, so it doesn't share a colour with anything else.
    stage: 'rgb(245 158 11 / 0.14)',
    stageBorder: 'rgb(217 119 6 / 0.75)',
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

/**
 * The scale at which the whole room is on screen, with a little air around it.
 *
 * Measured by projecting the room's four corners at zoom 1 and asking how wide and tall the
 * result is, rather than by assuming the room's screen size is its tile size. Under `iso` those
 * are quite different numbers — a wide room is *taller* on screen than a flat view of it and half
 * again as wide — so the old arithmetic would have fitted a rectangle the room doesn't occupy and
 * put a corner of an isometric map off the edge of the canvas.
 */
function fitZoom() {
  const probe: Camera = { ...camera, x: 0, y: 0, zoom: 1 }

  const corners = [
    toScreen(probe, -0.5, -0.5),
    toScreen(probe, width.value - 0.5, -0.5),
    toScreen(probe, width.value - 0.5, height.value - 0.5),
    toScreen(probe, -0.5, height.value - 0.5),
  ]

  const spanX = Math.max(...corners.map(c => c.x)) - Math.min(...corners.map(c => c.x))
  const spanY = Math.max(...corners.map(c => c.y)) - Math.min(...corners.map(c => c.y))

  return Math.min(camera.width / spanX, camera.height / spanY) * 0.95
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

/**
 * A middle-drag pans, the way it does in every other canvas people have used.
 *
 * The pixel delta is turned into a tile delta by unprojecting *both ends of it*, rather than by
 * dividing by the tile size. Dividing is only right when the axes are the screen's own: under the
 * isometric view a drag straight to the right is a move along the grid's diagonal, and a room
 * that slid sideways when you dragged sideways would be a room that doesn't follow the mouse.
 *
 * Taken from the canvas origin because the projection is affine — the difference between two
 * unprojected points depends on the delta and not on where it started — so any pair will do.
 */
function panBy(dx: number, dy: number) {
  const from = toWorld(camera, 0, 0)
  const to = toWorld(camera, dx, dy)

  camera.x -= to.x - from.x
  camera.y -= to.y - from.y
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
  drawPlaceDrag(ctx)
  drawPortals(ctx)
  drawBackdropSelection(ctx)
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
  const piece = selected.value
  const kind = piece ? DECOR[piece.kind] : null
  if (!piece || !kind) return

  const { w, h } = decorSize(piece, kind)

  ctx.save()
  ctx.strokeStyle = 'rgb(99 102 241)'
  ctx.lineWidth = 2
  ctx.setLineDash([5, 3])
  traceTiles(ctx, piece.x, piece.y, w, h)
  ctx.stroke()
  ctx.restore()
}

/**
 * Trace a rectangle *of tiles* as a path on the canvas.
 *
 * Every overlay in this editor marks out some block of the grid — the tile under the cursor, the
 * spawn square, the zone you're dragging, the footprint of the selected piece — and every one of
 * them used to do it with `fillRect`. That was the same shape as the thing it was marking only
 * because the projection was the identity. Turn the grid and a screen-space rectangle agrees with
 * the tiles it claims to cover at exactly one point, which for a spawn marker means a green box
 * sitting somewhere near, but not on, the square people will actually appear in.
 *
 * So the corners are projected and joined. Under `flat` that traces the same rectangle as before,
 * to the pixel; under `iso` it traces the parallelogram those tiles really occupy.
 */
function traceTiles(ctx: CanvasRenderingContext2D, x: number, y: number, w = 1, h = 1) {
  const corners = [
    toScreen(camera, x - 0.5, y - 0.5),
    toScreen(camera, x + w - 0.5, y - 0.5),
    toScreen(camera, x + w - 0.5, y + h - 0.5),
    toScreen(camera, x - 0.5, y + h - 0.5),
  ]

  ctx.beginPath()
  ctx.moveTo(corners[0]!.x, corners[0]!.y)
  for (const c of corners.slice(1)) ctx.lineTo(c.x, c.y)
  ctx.closePath()
}

/** Faint tile lines — you're placing individual squares, so you need to see the squares. */
function drawGrid(ctx: CanvasRenderingContext2D, p: MapTheme) {
  ctx.save()
  ctx.globalAlpha = 0.25
  ctx.strokeStyle = p.muted
  ctx.lineWidth = 0.5
  ctx.beginPath()

  // Drawn as two families of lines across the whole grid rather than a box per tile: the lines
  // are straight in both projections (a projection is affine, so it takes straight lines to
  // straight lines), and only their direction changes.
  for (let x = 0; x <= width.value; x++) {
    const a = toScreen(camera, x - 0.5, -0.5)
    const b = toScreen(camera, x - 0.5, height.value - 0.5)
    ctx.moveTo(a.x, a.y)
    ctx.lineTo(b.x, b.y)
  }
  for (let y = 0; y <= height.value; y++) {
    const a = toScreen(camera, -0.5, y - 0.5)
    const b = toScreen(camera, width.value - 0.5, y - 0.5)
    ctx.moveTo(a.x, a.y)
    ctx.lineTo(b.x, b.y)
  }

  ctx.stroke()
  ctx.restore()
}

function drawSpawn(ctx: CanvasRenderingContext2D) {
  traceTiles(ctx, spawn.value.x, spawn.value.y)

  ctx.fillStyle = 'rgb(34 197 94 / 0.35)'
  ctx.fill()
  ctx.strokeStyle = 'rgb(34 197 94)'
  ctx.lineWidth = 2
  ctx.stroke()
}

/**
 * The rectangle being dragged out for a layout.
 *
 * Drawn in full even where it leaves the grid, because that overhang *is* the gesture — it says
 * how far the map is about to grow. A preview clipped to the current map would show you the one
 * part of the drag that isn't the interesting part.
 */
function drawPlaceDrag(ctx: CanvasRenderingContext2D) {
  if (!placeDrag) return

  const x = Math.min(placeDrag.x0, placeDrag.x1)
  const y = Math.min(placeDrag.y0, placeDrag.y1)
  const w = Math.abs(placeDrag.x1 - placeDrag.x0) + 1
  const h = Math.abs(placeDrag.y1 - placeDrag.y0) + 1

  traceTiles(ctx, x, y, w, h)

  ctx.fillStyle = 'rgb(16 185 129 / 0.18)'
  ctx.fill()
  ctx.strokeStyle = 'rgb(16 185 129)'
  ctx.lineWidth = 2
  ctx.setLineDash([6, 4])
  ctx.stroke()
  ctx.setLineDash([])
}

/**
 * Doorways, and the rectangle being dragged out for a new one.
 *
 * Drawn with a line to where each one comes out, because "walk in here, appear there" is a fact
 * about a *pair* of places and a rectangle on its own cannot say it. Without the line you would
 * have to click each doorway to find out where it goes, which for a map with a station in every
 * district is the difference between reading the network and interrogating it.
 */
function drawPortals(ctx: CanvasRenderingContext2D) {
  for (const portal of portals.value) {
    const chosen = portal.id === selectedPortal.value

    traceTiles(ctx, portal.x, portal.y, portal.w, portal.h)
    ctx.fillStyle = 'rgb(56 189 248 / 0.2)'
    ctx.fill()
    ctx.strokeStyle = chosen ? 'rgb(125 211 252)' : 'rgb(56 189 248 / 0.75)'
    ctx.lineWidth = chosen ? 3 : 2
    ctx.stroke()

    if (portal.to.kind !== 'point') continue

    const from = toScreen(camera, portal.x + (portal.w - 1) / 2, portal.y + (portal.h - 1) / 2)
    const to = toScreen(camera, portal.to.x, portal.to.y)

    ctx.strokeStyle = chosen ? 'rgb(125 211 252 / 0.9)' : 'rgb(56 189 248 / 0.4)'
    ctx.lineWidth = 2
    ctx.setLineDash([4, 4])
    ctx.beginPath()
    ctx.moveTo(from.x, from.y)
    ctx.lineTo(to.x, to.y)
    ctx.stroke()
    ctx.setLineDash([])

    // A ring at the far end, so the exit reads as a place rather than as the end of a line.
    ctx.beginPath()
    ctx.arc(to.x, to.y, Math.max(4, TILE_ON_SCREEN() * 0.28), 0, Math.PI * 2)
    ctx.stroke()
  }

  if (artDrag) {
    const x = Math.min(artDrag.x0, artDrag.x1)
    const y = Math.min(artDrag.y0, artDrag.y1)
    traceTiles(ctx, x, y, Math.abs(artDrag.x1 - artDrag.x0) + 1, Math.abs(artDrag.y1 - artDrag.y0) + 1)
    ctx.fillStyle = 'rgb(250 204 21 / 0.25)'
    ctx.fill()
  }

  if (screenDrag) {
    const x = Math.min(screenDrag.x0, screenDrag.x1)
    const y = Math.min(screenDrag.y0, screenDrag.y1)
    traceTiles(ctx, x, y, Math.abs(screenDrag.x1 - screenDrag.x0) + 1, Math.abs(screenDrag.y1 - screenDrag.y0) + 1)
    ctx.fillStyle = 'rgb(15 23 42 / 0.55)'
    ctx.fill()
  }

  if (!portalDrag) return

  const x = Math.min(portalDrag.x0, portalDrag.x1)
  const y = Math.min(portalDrag.y0, portalDrag.y1)
  traceTiles(ctx, x, y, Math.abs(portalDrag.x1 - portalDrag.x0) + 1, Math.abs(portalDrag.y1 - portalDrag.y0) + 1)
  ctx.fillStyle = 'rgb(56 189 248 / 0.25)'
  ctx.fill()
}

/** One tile in screen pixels — for the few overlays that size something against the grid. */
function TILE_ON_SCREEN() {
  return 32 * camera.zoom
}

/** The outline round whichever piece of placed artwork is selected. */
function drawBackdropSelection(ctx: CanvasRenderingContext2D) {
  const placed = selectedBackdrop.value === null ? null : backdrops.value[selectedBackdrop.value]
  if (!placed) return

  traceTiles(ctx, placed.x, placed.y, placed.w, placed.h)

  ctx.strokeStyle = 'rgb(56 189 248)'
  ctx.lineWidth = 2
  ctx.setLineDash([8, 5])
  ctx.stroke()
  ctx.setLineDash([])
}

function drawZoneDrag(ctx: CanvasRenderingContext2D) {
  if (!zoneDrag) return

  const x = Math.min(zoneDrag.x0, zoneDrag.x1)
  const y = Math.min(zoneDrag.y0, zoneDrag.y1)
  const w = Math.abs(zoneDrag.x1 - zoneDrag.x0) + 1
  const h = Math.abs(zoneDrag.y1 - zoneDrag.y0) + 1

  traceTiles(ctx, x, y, w, h)

  // Matching the tint the finished thing is drawn in, so a drag previews what you're making.
  ctx.fillStyle = zoneDrag.kind === 'stage' ? 'rgb(245 158 11 / 0.22)' : 'rgb(99 102 241 / 0.18)'
  ctx.fill()
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
  <!-- Leaving unsaved geometry behind. The one action in this editor that discards work with no
       undo to get it back, which is why it is the one that asks. -->
  <ConfirmDialog
    :open="!!leavingTo"
    title="Leave your changes behind?"
    description="This map has edits you haven't saved. Opening another one discards them."
    confirm-label="Discard and open"
    variant="destructive"
    @update:open="leavingTo = $event ? leavingTo : null"
    @confirm="confirmLeave"
  />

  <ConfirmDialog
    :open="!!deletingMap"
    :title="`Delete “${deletingMap?.name}”?`"
    description="Its floor, furniture, rooms and locked doors go with it. Doorways elsewhere that pointed at it will lead nowhere until you fix them. This cannot be undone."
    confirm-label="Delete map"
    busy-label="Deleting…"
    :busy="deletingMapBusy"
    @update:open="deletingMap = $event ? deletingMap : null"
    @confirm="confirmRemoveSibling"
  />

  <!-- A sheet over the whole app rather than a panel: laying out a floor needs the room. -->
  <div class="fixed inset-0 z-50 flex flex-col bg-background">
    <header class="flex h-12 shrink-0 items-center justify-between gap-3 border-b px-4">
      <div class="flex min-w-0 items-center gap-3">
        <span class="flex items-center gap-2 font-semibold">
          {{ isDecorMode ? 'Decorate the room' : 'Edit the room' }}
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

        <!--
          The stage. No styles to choose from — see the comment where one is created — so this
          is purely the rule, written down where somebody is about to drag one out. Worth the
          space: it's the only rectangle in the editor that changes who can hear whom *outside*
          itself, and finding that out by being heard is the wrong way round.
        -->
        <div v-if="!isDecorMode && tool === 'stage'" class="space-y-1.5">
          <Label class="flex items-center gap-1.5 text-xs text-muted-foreground">
            <Megaphone class="h-3.5 w-3.5" /> How a stage works
          </Label>

          <p class="text-[11px] leading-snug text-muted-foreground">
            Step onto it and the whole room hears you and sees your camera, however far away
            they're standing. Step off and you're back to talking to whoever is nearby.
          </p>
          <p class="text-[11px] leading-snug text-muted-foreground">
            {{ STAGE_SPEAKERS }} people can be live at once, first on first. Anyone else standing
            on it waits in the wings — they can hear the speakers, and nobody outside hears them.
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
            <Input v-model.number="width" type="number" :min="MIN_MAP" :max="MAX_MAP" class="h-8" @change="applySize" />
            <span class="text-xs text-muted-foreground">×</span>
            <Input v-model.number="height" type="number" :min="MIN_MAP" :max="MAX_MAP" class="h-8" @change="applySize" />
          </div>
          <p class="text-[11px] leading-snug text-muted-foreground">
            {{ MIN_MAP }}–{{ MAX_MAP }} each way. Growing keeps what's already there and re-walls the edge.
          </p>
        </div>

        <!--
          What you can do with a piece of placed artwork once you've clicked it.

          Sits with the select tool rather than in a layer list, because artwork is picked up
          exactly like furniture is — same tool, same gesture — and the only thing that differs
          is that dragging it carries the ground along too.
        -->
        <div v-if="!isDecorMode && selectedBackdropInfo" class="space-y-1.5">
          <Label class="flex items-center gap-1.5 text-xs text-muted-foreground">
            <LayoutGrid class="h-3.5 w-3.5" /> {{ selectedBackdropInfo.label }}
          </Label>

          <p class="text-[11px] leading-snug text-muted-foreground">
            {{ selectedBackdropInfo.w }}×{{ selectedBackdropInfo.h }} at
            {{ selectedBackdropInfo.x }},{{ selectedBackdropInfo.y }}. Drag it to move it — the
            streets and everything standing on them travel with the picture.
          </p>

          <Button
            size="sm"
            variant="outline"
            class="w-full gap-1.5"
            @click="removeBackdrop(selectedBackdrop!)"
          >
            <Trash2 class="h-3.5 w-3.5" /> Remove it
          </Button>
        </div>

        <!--
          Doorways. Shown with the tool, like the room styles are with the Room tool.
        -->
        <div v-if="!isDecorMode && tool === 'portal'" class="space-y-1.5">
          <Label class="flex items-center gap-1.5 text-xs text-muted-foreground">
            <DoorOpen class="h-3.5 w-3.5" /> New doorways lead to
          </Label>

          <select v-model="portalTo" class="h-8 w-full rounded border bg-background px-2 text-xs">
            <option value="">Somewhere on this map</option>
            <!--
              First, because it is the one that builds both halves. Everything below it links to
              somewhere that already exists; this makes the place as well as the door, and hangs
              the way back inside it pointing at this very doorway.
            -->
            <option value="new">A new room…</option>
            <!--
              The interiors first, and labelled as being *inside* this space — because they are
              the ones that behave like a door. Walking into one keeps you in the call; the
              Side Spaces below it do not.
            -->
            <optgroup v-if="interiors.length" label="Inside this space">
              <option v-for="m in interiors" :key="m.slug" :value="`map:${m.slug}`">{{ m.name }}</option>
            </optgroup>
            <optgroup v-if="spaceRooms.length" label="Another Side Space">
              <option v-for="r in spaceRooms" :key="r.id" :value="`room:${r.id}`">{{ r.name }}</option>
            </optgroup>
          </select>

          <!-- The new room's name. Only asked for when that is what was chosen. -->
          <Input
            v-if="portalTo === 'new'"
            v-model="newDoorMapName"
            class="h-8 text-xs"
            placeholder="Name the new room, e.g. Screen One"
          />

          <!--
            Walked into, or pressed. Per doorway rather than a setting, because a wormhole across
            an island and a door out of the room want different answers.
          -->
          <div class="grid grid-cols-2 gap-1">
            <button
              v-for="a in ([
                { key: 'walk', label: 'Walk in', hint: 'Steps you through as soon as you are on it' },
                { key: 'press', label: 'Press E', hint: 'Stops there and offers — safer for a way out' },
              ] as const)"
              :key="a.key"
              type="button"
              class="rounded border px-2 py-1 text-left transition-colors"
              :class="portalActivation === a.key ? 'border-primary bg-muted' : 'hover:bg-muted/50'"
              @click="portalActivation = a.key"
            >
              <span class="block text-xs font-medium">{{ a.label }}</span>
              <span class="block text-[10px] leading-snug text-muted-foreground">{{ a.hint }}</span>
            </button>
          </div>

          <p class="text-[11px] leading-snug text-muted-foreground">
            {{ portalTo === 'new'
              ? 'Drag out the wormhole. The room is made for you, with a way back to this very spot.'
              : portalTo.startsWith('map:')
                ? 'Drag out the wormhole. It takes you straight there — nobody leaves the call.'
                : portalTo
                  ? 'Drag out the wormhole. It takes you to that Side Space, which means rejoining its call.'
                  : 'Drag out the wormhole, then click where it should come out.' }}
          </p>

          <p v-if="awaitingExit" class="rounded border border-sky-500/40 bg-sky-500/10 p-1.5 text-[11px] leading-snug">
            Click where this doorway comes out.
          </p>

          <div v-if="portals.length" class="grid gap-1">
            <div
              v-for="p in portals"
              :key="p.id"
              class="flex items-center gap-1 rounded border px-1.5 py-1"
              :class="selectedPortal === p.id ? 'border-primary bg-muted' : ''"
            >
              <button type="button" class="min-w-0 flex-1 text-left" @click="selectedPortal = p.id">
                <Input v-model="p.name" class="h-6 text-xs" @click.stop />
                <span class="block truncate text-[10px] text-muted-foreground">
                  {{ portalDestination(p) }}
                </span>
              </button>
              <!-- Flip an existing doorway between walked and pressed. A toggle rather than a
                   select: there are two states and the label says which one it is in. -->
              <button
                type="button"
                class="shrink-0 rounded border px-1.5 py-0.5 text-[10px] text-muted-foreground transition-colors hover:bg-muted"
                :title="(p.activation ?? 'walk') === 'press'
                  ? 'Pressed: stops you and offers. Click to make it walk-in.'
                  : 'Walk-in: takes you as soon as you step on it. Click to require E.'"
                @click.stop="p.activation = (p.activation ?? 'walk') === 'press' ? 'walk' : 'press'"
              >
                {{ (p.activation ?? 'walk') === 'press' ? 'E' : 'walk' }}
              </button>
              <button
                type="button"
                class="rounded p-1 text-muted-foreground transition-colors hover:text-destructive"
                title="Remove this doorway"
                @click="removePortal(p.id)"
              >
                <Trash2 class="h-3.5 w-3.5" />
              </button>
            </div>
          </div>
        </div>

        <!-- Artwork. Shown with the tool, like the doorways and screens. -->
        <div v-if="!isDecorMode && tool === 'art'" class="space-y-1.5">
          <Label class="flex items-center gap-1.5 text-xs text-muted-foreground">
            <Frame class="h-3.5 w-3.5" /> Artwork
          </Label>

          <p class="text-[11px] leading-snug text-muted-foreground">
            Drag a frame over a painting or statue in the artwork, save the map, then hang a
            picture in it. Walking up to a hung frame offers to open it.
          </p>

          <div v-if="exhibits.length" class="grid gap-1">
            <div
              v-for="art in exhibits"
              :key="art.id"
              class="rounded border px-1.5 py-1"
              :class="selectedExhibit === art.id ? 'border-primary bg-muted' : ''"
            >
              <div class="flex items-center gap-1">
                <button type="button" class="min-w-0 flex-1 text-left" @click="selectedExhibit = art.id">
                  <Input v-model="art.name" class="h-6 text-xs" @click.stop />
                  <span class="block truncate text-[10px]" :class="pieceFor(art.id) ? 'text-muted-foreground' : 'text-amber-600'">
                    {{ pieceFor(art.id) ? pieceFor(art.id)!.title : 'empty — nothing hung yet' }}
                  </span>
                </button>
                <button
                  type="button"
                  class="shrink-0 rounded border px-1.5 py-0.5 text-[10px] text-muted-foreground transition-colors hover:bg-muted"
                  :title="pieceFor(art.id) ? 'Replace the picture' : 'Hang a picture here'"
                  @click.stop="openHangForm(art.id)"
                >
                  {{ pieceFor(art.id) ? 'Replace' : 'Hang' }}
                </button>
                <button
                  type="button"
                  class="shrink-0 rounded p-1 text-muted-foreground transition-colors hover:text-destructive"
                  title="Remove this frame"
                  @click="removeExhibit(art.id)"
                >
                  <Trash2 class="h-3.5 w-3.5" />
                </button>
              </div>

              <!--
                The upload. Sent on its own rather than with Save, because the picture is not part
                of the map document — different endpoint, different table, staff-only.
              -->
              <div v-if="hangingIn === art.id" class="mt-1.5 space-y-1 border-t pt-1.5">
                <Input v-model="hangTitle" class="h-7 text-xs" placeholder="Title" />
                <Input v-model="hangArtist" class="h-7 text-xs" placeholder="Artist (optional)" />
                <textarea
                  v-model="hangCaption"
                  rows="3"
                  class="w-full rounded border bg-background px-2 py-1 text-xs"
                  placeholder="Wall label (optional)"
                />
                <input type="file" accept="image/*" class="w-full text-[11px]" @change="onHangFile">

                <div class="flex items-center gap-1">
                  <Button size="sm" class="h-7 px-2 text-xs" :disabled="!hangFile || !hangTitle.trim() || hanging" @click="hangPicture">
                    {{ hanging ? 'Hanging…' : 'Hang it' }}
                  </Button>
                  <Button size="sm" variant="ghost" class="h-7 px-2 text-xs" :disabled="hanging" @click="hangingIn = null">
                    Cancel
                  </Button>
                  <Button
                    v-if="pieceFor(art.id)"
                    size="sm"
                    variant="ghost"
                    class="h-7 px-2 text-xs text-destructive"
                    :disabled="hanging"
                    @click="takeDownPicture(art.id)"
                  >
                    Take down
                  </Button>
                </div>

                <p class="text-[10px] leading-snug text-muted-foreground">
                  Save the map first if this frame is new — a picture can only hang in a frame the
                  server already knows about.
                </p>
              </div>
            </div>
          </div>
        </div>

        <!-- Screens. Shown with the tool, like the doorways above. -->
        <div v-if="!isDecorMode && tool === 'screen'" class="space-y-1.5">
          <Label class="flex items-center gap-1.5 text-xs text-muted-foreground">
            <MonitorPlay class="h-3.5 w-3.5" /> Screens
          </Label>

          <p class="text-[11px] leading-snug text-muted-foreground">
            Drag out the surface. Whatever anyone in the room shares plays on it, and standing in
            front of it offers to watch fullscreen.
          </p>

          <div v-if="screens.length" class="grid gap-1">
            <div
              v-for="sc in screens"
              :key="sc.id"
              class="flex items-center gap-1 rounded border px-1.5 py-1"
              :class="selectedScreen === sc.id ? 'border-primary bg-muted' : ''"
            >
              <div class="min-w-0 flex-1">
                <button type="button" class="w-full text-left" @click="selectedScreen = sc.id">
                  <Input v-model="sc.name" class="h-6 text-xs" @click.stop />
                </button>
                <!--
                  Editable rather than a read-out.

                  A screen is hung over artwork somebody drew, and dragging a rectangle onto an
                  isometric picture by eye gets you close and never exact. Nudging a number and
                  watching the canvas is how it actually gets lined up — so the four numbers that
                  place it, and the shear that lays it flat onto the art, are all fields.
                -->
                <div class="mt-1 grid grid-cols-4 gap-1">
                  <label v-for="f in (['x', 'y', 'w', 'h'] as const)" :key="f" class="flex items-center gap-0.5 text-[10px] text-muted-foreground">
                    {{ f }}
                    <Input
                      :model-value="sc[f]"
                      type="number"
                      class="h-6 w-full px-1 text-xs"
                      @click.stop
                      @update:model-value="resizeScreen(sc, f, Number($event))"
                    />
                  </label>
                </div>
              </div>
              <!--
                Shear. Isometric artwork paints a screen as a parallelogram, and a share drawn
                square onto one sits visibly crooked — `-w / 2` is the usual 2:1 slope, which is
                what the Movie Theatre uses. Zero is a plain rectangle.
              -->
              <label class="flex shrink-0 items-center gap-1 text-[10px] text-muted-foreground" title="Raise the right edge, in tiles — for screens painted at an isometric angle">
                skew
                <Input
                  :model-value="sc.skew ?? 0"
                  type="number"
                  class="h-6 w-12 px-1 text-xs"
                  @click.stop
                  @update:model-value="sc.skew = Number($event) || 0"
                />
              </label>
              <button
                type="button"
                class="rounded p-1 text-muted-foreground transition-colors hover:text-destructive"
                title="Remove this screen"
                @click="removeScreen(sc.id)"
              >
                <Trash2 class="h-3.5 w-3.5" />
              </button>
            </div>
          </div>
        </div>

        <!--
          The drag-it-out half of extending. Shown with the Place tool selected, and it carries
          the same picker the edge form uses, because it is the same choice.
        -->
        <div v-if="!isDecorMode && tool === 'place'" class="space-y-1.5">
          <Label class="flex items-center gap-1.5 text-xs text-muted-foreground">
            <LayoutGrid class="h-3.5 w-3.5" /> What lands in it
          </Label>

          <select
            v-model="extendWith"
            class="h-8 w-full rounded border bg-background px-2 text-xs"
            @focus="loadPresets(); loadRoomPresets()"
          >
            <option value="">Empty ground</option>
            <optgroup label="Layouts — furniture on a floor">
              <option v-for="p in roomPresets" :key="p.key" :value="`room:${p.key}`">{{ p.label }}</option>
            </optgroup>
            <optgroup v-for="g in groupedPresets" :key="g.title" :label="`Maps — ${g.title}`">
              <option v-for="p in g.items" :key="p.key" :value="`map:${p.key}`">{{ p.label }}</option>
            </optgroup>
          </select>

          <p class="text-[11px] leading-snug text-muted-foreground">
            Drag out where you want it — including off the edge of the map. The grid grows to
            reach your rectangle, everything already built shifts with it, and the wall between
            the two is opened so you can walk across.
          </p>
        </div>

        <!--
          Growing the map at one edge, and optionally filling the new ground with a whole preset.

          Separate from Size above, and the difference is the point: Size *resizes* the room and
          re-walls its border, which is right when you want a bigger room. This *extends* it —
          the seam opens so you can walk out of what you built into the new land, everything
          already placed stays where it is, and a preset stamped into the new space brings its
          own artwork and collision with it.
        -->
        <div v-if="!isDecorMode" class="space-y-1.5">
          <Label class="text-xs text-muted-foreground">Extend</Label>

          <div class="grid grid-cols-4 gap-1">
            <button
              v-for="e in EXTEND_EDGES"
              :key="e.key"
              type="button"
              class="rounded border py-1 text-[11px] transition-colors"
              :class="extendEdge === e.key ? 'border-primary bg-muted' : 'hover:bg-muted/50'"
              @click="extendEdge = e.key"
            >{{ e.label }}</button>
          </div>

          <div class="flex items-center gap-2">
            <Input v-model.number="extendBy" type="number" min="1" max="80" class="h-8 w-20" />
            <span class="text-xs text-muted-foreground">tiles</span>
          </div>

          <!--
            Shared with the Place tool: pick what goes in the new ground once, then either drag
            it out or use the edge form below.

            Two families, and they behave differently enough to be labelled rather than mixed:
            a *layout* is furniture on a floor and spreads to fill whatever new ground there is;
            a *map* is a whole place with its own walls, artwork and rooms, dropped in beside
            what you had.
          -->
          <select
            v-model="extendWith"
            class="h-8 w-full rounded border bg-background px-2 text-xs"
            @focus="loadPresets(); loadRoomPresets()"
          >
            <option value="">Empty ground</option>
            <optgroup label="Layouts — furniture on a floor">
              <option v-for="p in roomPresets" :key="p.key" :value="`room:${p.key}`">{{ p.label }}</option>
            </optgroup>
            <optgroup v-for="g in groupedPresets" :key="g.title" :label="`Maps — ${g.title}`">
              <option v-for="p in g.items" :key="p.key" :value="`map:${p.key}`">{{ p.label }}</option>
            </optgroup>
          </select>

          <div class="grid grid-cols-2 gap-1">
            <Button size="sm" variant="secondary" @click="extend()">
              Extend {{ extendEdge }}
            </Button>
            <!-- Cropping is destructive in a way growing isn't, so it reads as the quieter of
                 the pair rather than sitting there as an equal-weight twin. -->
            <Button size="sm" variant="outline" @click="reduce()">
              Reduce {{ extendEdge }}
            </Button>
          </div>

          <p class="text-[11px] leading-snug text-muted-foreground">
            Keeps everything you've already built and opens the wall between the two, so you can
            walk from one into the other.
          </p>
        </div>

        <!--
          Which way the room is drawn.

          Beside Size rather than with the brushes, because it isn't a tool: it's a fact about the
          room, like how big it is, and it changes nothing about what's in it. The grid, the
          furniture and where everybody can walk are identical either way — only the camera moves.
          Switching takes effect immediately in the canvas, which is the point: you furnish an
          isometric room by looking at one.
        -->
        <div v-if="!isDecorMode" class="space-y-1.5">
          <Label class="text-xs text-muted-foreground">View</Label>
          <div class="grid grid-cols-2 gap-1">
            <button
              v-for="p in PROJECTION_CHOICES"
              :key="p.key"
              type="button"
              class="rounded border px-2 py-1 text-left transition-colors"
              :class="projection === p.key ? 'border-primary bg-muted' : 'hover:bg-muted/50'"
              @click="projection = p.key"
            >
              <span class="block text-xs font-medium">{{ p.label }}</span>
              <span class="block text-[10px] leading-snug text-muted-foreground">{{ p.description }}</span>
            </button>
          </div>
          <p class="text-[11px] leading-snug text-muted-foreground">
            Only the camera changes — the same tiles, the same furniture, the same places to walk.
          </p>
        </div>

        <!--
          The building. A Side Space holds an overworld and the interiors behind its doors, and
          this is where they are added, opened and removed.

          Separate from the "Rooms" list below it, which is a different thing wearing a similar
          word: a *zone* is a sealed rectangle on this one grid, and a *map* is a whole other
          grid you reach by walking through a doorway.
        -->
        <div v-if="!isDecorMode" class="space-y-1.5">
          <Label class="flex items-center gap-1.5 text-xs text-muted-foreground">
            <DoorOpen class="h-3.5 w-3.5" /> Maps in this space
          </Label>

          <div class="grid gap-1">
            <div
              v-for="m in (props.map.siblings ?? [])"
              :key="m.slug"
              class="flex items-center gap-1 rounded border px-1.5 py-1"
              :class="m.slug === props.map.slug ? 'border-primary bg-muted' : ''"
            >
              <button
                type="button"
                class="min-w-0 flex-1 truncate text-left text-xs"
                :title="m.slug === props.map.slug ? 'You are editing this one' : `Walk into ${m.name}`"
                :disabled="m.slug === props.map.slug"
                @click="openSibling(m.slug)"
              >
                {{ m.name }}
                <span class="text-[10px] text-muted-foreground">{{ m.slug === 'main' ? '(the way in)' : '' }}</span>
              </button>
              <!-- The way in has no delete: a Side Space with no main map opens to nothing. -->
              <button
                v-if="m.slug !== 'main'"
                type="button"
                class="rounded p-1 text-muted-foreground transition-colors hover:text-destructive"
                :title="`Delete ${m.name} and everything in it`"
                @click="removeSibling(m.slug, m.name)"
              >
                <Trash2 class="h-3.5 w-3.5" />
              </button>
            </div>
          </div>

          <div class="flex items-center gap-1">
            <Input v-model="newMapName" class="h-7 flex-1 text-xs" placeholder="New map, e.g. Screen One" />
            <Button size="sm" class="h-7 px-2 text-xs" :disabled="!newMapName.trim() || addingMap" @click="addSibling">
              Add
            </Button>
          </div>

          <p class="text-[11px] leading-snug text-muted-foreground">
            A new map starts empty, with a wormhole home on its entrance. Hang one *into* it with
            the Doorway tool — stepping through keeps everybody in the same call.
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

        <!--
          Stepping back from the big changes.

          Only the structural ones are on the stack — see `remember` — so this says what it will
          undo rather than a bare "Undo": the button is next to Start over, and "undo" with no
          object next to a destructive control is the wrong thing to be vague about.
        -->
        <Button
          v-if="!isDecorMode && history.length"
          variant="outline"
          size="sm"
          class="w-full gap-1.5"
          @click="undo"
        >
          <Undo2 class="h-3.5 w-3.5" /> Undo {{ history.at(-1)?.label }}
        </Button>

        <!--
          For a map that is already blocked off, which no amount of improving the *join* can fix.
          Sits above Start over because it is the thing to reach for before giving up on a map.
        -->
        <Button v-if="!isDecorMode" variant="outline" size="sm" class="w-full gap-1.5" @click="reconnect">
          <Unlink class="h-3.5 w-3.5" /> Open up blocked areas
        </Button>

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
