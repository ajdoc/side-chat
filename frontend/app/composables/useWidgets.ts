// Card actions for widgets (the music player's buttons, dragging a kanban card).
//
// Deliberately fire-and-forget: the server mutates the shared state and broadcasts
// `WidgetUpdated`, which *every* client — including this one — receives and folds in via
// useMessages().patchWidget. So we never send `X-Socket-ID`: we want our own echo too, so
// every listener converges on one authoritative state rather than each guessing locally.
export function useWidgets() {
  const api = useApi()

  async function action(widgetId: number, action: string, payload: Record<string, unknown> = {}) {
    await api(`/api/widgets/${widgetId}/action`, {
      method: 'POST',
      body: { action, payload },
    })
  }

  return { action }
}
