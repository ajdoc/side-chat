// Card actions for widgets (the music player's buttons, dragging a kanban card).
//
// Deliberately fire-and-forget: the server mutates the shared state and broadcasts
// `WidgetUpdated`, which *every* client — including this one — receives and folds in via
// useMessages().patchWidget. So we never send `X-Socket-ID`: we want our own echo too, so
// every listener converges on one authoritative state rather than each guessing locally.
export function useWidgets() {
  const api = useApi()

  // Returns an actor-only note when the action failed softly (a quota'd search, an unreadable
  // link) — the caller surfaces it, since the button that fired this has no chat line. Most
  // actions just change state and resolve to null.
  async function action(widgetId: number, action: string, payload: Record<string, unknown> = {}): Promise<string | null> {
    const res = await api<{ reply?: string } | null>(`/api/widgets/${widgetId}/action`, {
      method: 'POST',
      body: { action, payload },
    })
    return res?.reply ?? null
  }

  return { action }
}
