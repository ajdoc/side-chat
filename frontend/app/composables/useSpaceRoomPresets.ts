import type { BackdropPlacement } from '~/lib/spaceBackdrops'
import type { SpaceObject } from '~/lib/spaceDecor'
import type { SpaceZone } from '~/lib/spaceMapEngine'
import { decorSize, DECOR } from '~/lib/spaceDecor'

/**
 * A way to furnish a **room** — the rectangle you drag inside a map — as opposed to a way to
 * build a whole map. See {@link file://../../../backend/app/Support/SideSpace/RoomPresets.php},
 * which owns these; and {@link useSpacePresets}, which is the other, larger thing.
 *
 * `w`/`h` is the size it was laid out at, not a size it imposes: a room preset goes into
 * whatever rectangle you dragged, and {@link anchorObjects} is what reconciles the two.
 */
export interface RoomPreset {
  key: string
  label: string
  description: string
  /** The tile the room's floor is paved with. */
  floor: string
  w: number
  h: number
  /** Positions relative to the room's top-left corner. No ids — see the PHP note. */
  objects: Array<{ kind: string, x: number, y: number, facing?: SpaceObject['facing'] }>
  /**
   * A grid of ground to lay, instead of paving the whole zone with {@link floor}.
   *
   * Present only on rooms that *are* a picture. Their collision was derived from artwork, so it
   * has to be laid exactly as authored — which also means such a room has a **fixed size** and
   * ignores how big a rectangle you dragged. See `fixedSize`.
   */
  tiles?: string[]
  /** Artwork to place over the stamped ground, offset to wherever the room lands. */
  backdrops?: BackdropPlacement[]
}

/**
 * The catalogue, fetched once per session.
 *
 * Same `useState` treatment as the map presets next door and for the same reason: the list is
 * identical for everybody, never changes between requests, and is wanted by more than one part
 * of the editor.
 */
/**
 * Whether a room preset must be laid at its own size rather than stretched to fit a drag.
 *
 * True exactly when it brings its own ground. A room made of catalogue furniture can be any size
 * — that is what {@link anchorObjects} is for — but a room whose walls and couch are *pixels*
 * cannot: stretch the collision away from the picture and the couch stops being where the couch
 * is.
 */
export function fixedSize(preset: RoomPreset): boolean {
  return !!preset.tiles?.length
}

export function useSpaceRoomPresets() {
  const api = useApi()

  const presets = useState<RoomPreset[]>('space:room-presets', () => [])
  const loading = ref(false)
  const error = ref('')

  async function load() {
    if (presets.value.length || loading.value) return presets.value

    loading.value = true
    error.value = ''

    try {
      const res = await api<{ data: RoomPreset[] }>('/api/space/room-presets')
      presets.value = res.data
    } catch {
      error.value = 'Could not load the room styles.'
    } finally {
      loading.value = false
    }

    return presets.value
  }

  return { presets, loading, error, load }
}

/**
 * Where a preset's furniture actually lands in a room of a different size.
 *
 * The problem: a preset is laid out at, say, 9×7, and you dragged a room 14×5. Scaling the
 * layout would put a two-tile couch across one and a half tiles; cropping it would throw away
 * the half of the room that happened to be on the right. Neither is what somebody dragging a
 * bigger room wants, which is the *same room, roomier*.
 *
 * So each piece keeps its distance from **whichever edge it was nearest**. A bookshelf two
 * tiles from the top stays two tiles from the top; a lamp one tile from the right edge stays
 * one tile from the right edge whatever happens in between; and only the middle stretches.
 * Which is exactly how a person would re-lay the furniture, and it means a room three tiles
 * wider comes out looking deliberate rather than lopsided.
 *
 * Pieces that no longer fit are dropped rather than squeezed — a room smaller than the layout
 * loses its outermost furniture and stays a legal room, which beats refusing to be furnished.
 *
 * @param zone the rectangle being furnished, in map coordinates
 */
export function anchorObjects(preset: RoomPreset, zone: { x: number, y: number, w: number, h: number }): Array<Omit<SpaceObject, 'id'>> {
  const placed: Array<Omit<SpaceObject, 'id'>> = []

  for (const o of preset.objects) {
    const kind = DECOR[o.kind]
    if (!kind) continue

    const size = decorSize({ ...o, id: '' }, kind)

    // Nearest edge wins. `x` is measured from the left if the piece sat in the left half of the
    // layout, and from the right if it sat in the right half — the same question asked of the
    // piece's *centre*, so a two-tile desk straddling the middle doesn't flip on its width.
    const cx = o.x + size.w / 2
    const cy = o.y + size.h / 2
    const x = cx <= preset.w / 2 ? o.x : zone.w - (preset.w - o.x)
    const y = cy <= preset.h / 2 ? o.y : zone.h - (preset.h - o.y)

    // Off the edge of the room it's being put in: this is the smaller-room case, and the
    // honest answer is that the piece doesn't fit rather than that it goes half in the wall.
    if (x < 0 || y < 0 || x + size.w > zone.w || y + size.h > zone.h) continue

    placed.push({ kind: o.kind, x: zone.x + x, y: zone.y + y, ...(o.facing ? { facing: o.facing } : {}) })
  }

  return placed
}

/** Does a piece of furniture stand inside this room? Used to clear a room before re-furnishing it. */
export function objectInZone(object: SpaceObject, zone: SpaceZone): boolean {
  const kind = DECOR[object.kind]
  if (!kind) return false

  const { w, h } = decorSize(object, kind)

  return object.x >= zone.x && object.y >= zone.y
    && object.x + w <= zone.x + zone.w && object.y + h <= zone.y + zone.h
}
