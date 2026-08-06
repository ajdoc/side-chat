import { useLocalStorage } from '@vueuse/core'
import type { SideDeskAppId, Widget } from '~/types'

/**
 * Opening an app straight from the channel header, without going through the Side Desk.
 *
 * The desk is where a place *arranges* its apps; this is the shortcut for the times you only
 * want one of them in front of you — press it, and the app arrives as a floating window that
 * follows you around the app (see {@link useFloatingWindows}). Music is the exception, as
 * always: it goes through {@link useMusicPin}, because the pin is what keeps one engine
 * playing one song across every navigation. Pinning already opens the shelf window itself, so
 * from the header both paths look identical.
 *
 * Nothing new is stored per app. A widget app resolves the *channel's* widget of its type via
 * the same `widgets/ensure` call a desk tab or a canvas card makes, so the header button, the
 * tab, the card and the chat message are four doors onto one row.
 */
export function useAppLauncher() {
  const api = useApi()
  const floating = useFloatingWindows()
  const music = useMusicPin()

  /**
   * The one app that earns a button of its own in the header.
   *
   * A *local* preference, like the music pin's: which app you reach for is a habit, not
   * something a channel should decide for everyone on it. Music is the default because it's
   * what people actually open, over and over, and the pin makes it the one app you want back
   * with a single press from anywhere.
   */
  const favorite = import.meta.client
    ? useLocalStorage<SideDeskAppId>('apps:favorite', 'music')
    : ref<SideDeskAppId>('music')

  /**
   * Open `app` for `channelId`, floating.
   *
   * @returns an error message when the app couldn't be opened, else null — the caller has the
   *   button, so it's the one with somewhere to put the news.
   */
  async function launch(app: SideDeskAppId, channelId: number): Promise<string | null> {
    const meta = deskApp(app)
    if (!meta) return 'Unknown app.'

    if (!isWidgetApp(app)) {
      floating.open({
        kind: 'surface',
        app,
        basePath: `/api/channels/${channelId}`,
        streamName: `channel.${channelId}`,
        canEdit: true,
        title: meta.label,
      })
      return null
    }

    let widget: Widget
    try {
      const res = await api<{ data: Widget }>(`/api/channels/${channelId}/widgets/ensure`, {
        method: 'POST',
        body: { type: app },
      })
      widget = res.data
    } catch (e: any) {
      return e?.data?.message ?? 'Could not open this app.'
    }

    // Music keeps its own brain: pinning is what makes the sound survive navigation, and it
    // opens the shelf window on its own — calling floating.open here as well would be the
    // second half of a job already done.
    if (app === 'music') music.pin(widget)
    else floating.open({ kind: 'widget', widgetId: widget.id, channelId, widgetType: app, title: meta.label })

    return null
  }

  return { favorite, launch }
}
