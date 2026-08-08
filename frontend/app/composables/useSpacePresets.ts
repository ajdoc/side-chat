import type { BackdropPlacement } from '~/lib/spaceBackdrops'
import type { SpaceObject } from '~/lib/spaceDecor'
import type { SpaceZone } from '~/lib/spaceMapEngine'

/**
 * A room you can build a Side Space as — a complete map, minus the ids a saved one has.
 *
 * The server owns these ({@link file://../../../backend/app/Support/SideSpace/MapPresets.php}) and
 * we only draw and apply them. Whole rather than thumbnail-shaped on purpose: the editor *loads*
 * one over the room being edited, so it needs the entrance and the furniture as much as the grid.
 */
export interface MapPreset {
  key: string
  label: string
  description: string
  /** The heading it's filed under in the picker — "Rooms", "Themed", "Gyms". Server-decided. */
  group: string
  width: number
  height: number
  tiles: string[]
  /** The artwork it's drawn with and where, or empty for a room drawn from its tiles. */
  backdrops: BackdropPlacement[]
  zones: SpaceZone[]
  objects: SpaceObject[]
  spawn: { x: number, y: number }
}

/**
 * The catalogue of starting rooms, fetched once per session.
 *
 * Shared by the channel-creation page (which picks one to seed a new room) and the room editor
 * (which loads one over an existing room). Held in `useState` so the two never fetch it twice and
 * can't disagree about what "Office" is — the list is identical for everybody and never changes
 * between requests, which is exactly the shape worth caching app-wide.
 */
export function useSpacePresets() {
  const api = useApi()

  const presets = useState<MapPreset[]>('space:presets', () => [])
  const loading = ref(false)
  const error = ref('')

  /** Fetched rather than hardcoded, so a picker can't drift from the rooms the server builds. */
  async function load() {
    if (presets.value.length || loading.value) return presets.value

    loading.value = true
    error.value = ''

    try {
      const res = await api<{ data: MapPreset[] }>('/api/space/map-presets')
      presets.value = res.data
    } catch {
      error.value = 'Could not load the room layouts.'
    } finally {
      loading.value = false
    }

    return presets.value
  }

  /**
   * The same list, under its headings, in the order the server sent them.
   *
   * Grouped here rather than in the two pickers, so the creation page and the editor's "load a
   * layout" sheet can't end up disagreeing about what's themed and what isn't. A `Map` because
   * it keeps insertion order, which *is* the ordering — the server's list is already sorted.
   */
  const grouped = computed(() => {
    const groups = new Map<string, MapPreset[]>()

    for (const p of presets.value) {
      const list = groups.get(p.group)
      if (list) list.push(p)
      else groups.set(p.group, [p])
    }

    return [...groups].map(([title, items]) => ({ title, items }))
  })

  return { presets, grouped, loading, error, load }
}
