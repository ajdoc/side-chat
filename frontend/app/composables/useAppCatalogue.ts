import { LayoutGrid } from 'lucide-vue-next'
import type { DeskApp } from '~/composables/useDeskApps'
import { DESK_APPS } from '~/composables/useDeskApps'

/**
 * The app catalogue as the *server* reports it — built-ins plus anything installed.
 *
 * ## Why this exists alongside DESK_APPS
 *
 * `DESK_APPS` is the client's registry: it knows every built-in app's label, icon, card size
 * and which component renders it. That can't be dynamic — a component has to be in the bundle.
 *
 * But which apps *may be used* has to be, or a third-party app could never appear without a
 * client release. So the server owns the list and the client owns the rendering, and this
 * merges them: built-ins keep their local definition (the server sends only flags, never a
 * label, so there's one source for each fact), and installed apps arrive whole and get a
 * generic icon.
 *
 * ## Failure is quiet on purpose
 *
 * If the catalogue request fails, the built-ins stand alone. That's the pre-existing behaviour
 * and it's the right one: a picker that offers the six apps everybody uses beats a picker that
 * refuses to open because an optional list didn't load.
 */

export interface InstalledAppRow {
  id: string
  name: string
  description: string | null
  icon: string | null
  version: string
}

/** A server flag row for a built-in — no label or icon, which the client already has. */
interface BuiltInFlags {
  id: string
  family: 'surface' | 'widget'
  desk: boolean
  channel: boolean
}

export function useAppCatalogue() {
  const api = useApi()

  const installed = useState<InstalledAppRow[]>('app-catalogue:installed', () => [])
  const builtInFlags = useState<BuiltInFlags[]>('app-catalogue:built-in', () => [])
  const loaded = useState<boolean>('app-catalogue:loaded', () => false)

  async function load(force = false) {
    if (loaded.value && !force) return
    try {
      const res = await api<{ built_in: BuiltInFlags[], installed: InstalledAppRow[] }>('/api/apps/catalogue')
      builtInFlags.value = res.built_in
      installed.value = res.installed
    }
    catch {
      // See the class note — the built-ins carry on without it.
    }
    finally {
      loaded.value = true
    }
  }

  /**
   * An installed app as a {@link DeskApp}, so every picker can treat it like any other.
   *
   * A single generic icon rather than resolving the server's `icon` name against lucide: that
   * lookup pulls the whole icon set into the bundle, and an app the client can't render yet
   * doesn't earn that. Revisit when the external renderer lands.
   */
  function asDeskApp(row: InstalledAppRow): DeskApp {
    return {
      id: row.id as any,
      label: row.name,
      icon: LayoutGrid,
      family: 'surface',
      removable: true,
      canvasable: false,
      channelable: true,
      group: 'workspace',
    }
  }

  /** Every app that may be a whole channel, built-in and installed, for the create dialog. */
  const channelable = computed<DeskApp[]>(() => {
    const flags = new Map(builtInFlags.value.map(f => [f.id, f]))

    // When the catalogue hasn't loaded, fall back to the client's own opinion rather than an
    // empty list — see the note about quiet failure.
    const builtIns = DESK_APPS.filter(a =>
      flags.size ? flags.get(a.id)?.channel === true : a.channelable)

    return [
      ...builtIns.sort((a, b) => Number(b.id === 'tracker') - Number(a.id === 'tracker')),
      ...installed.value.map(asDeskApp),
    ]
  })

  return { installed, loaded, load, channelable, asDeskApp }
}
