<?php

namespace App\Support;

/**
 * The Side Desk app catalogue, as far as the API is concerned.
 *
 * This is a *validation* list, not a description of the apps: what each one looks like, which
 * icon it wears and how it renders are entirely the client's business (see the registry in
 * `frontend/app/composables/useDeskApps.ts`). The server's only stake is that a surface can't
 * store an id nothing will ever render, which would leave a permanently blank tab that survives
 * a reload and can't be removed from the UI that can't draw it.
 *
 * Two kinds of id live here, and the difference matters:
 *
 *   - **surface apps** own storage of their own, hanging off the surface (`board` → whiteboard
 *     strokes, `notes` → the shared document, `docs` → uploaded files, `calendar` → events).
 *   - **widget apps** are the interactive widgets, promoted from canvas cards to full tabs.
 *     They store nothing new: a tab renders the channel's existing {@see \App\Models\Widget} of
 *     that type — the same row the timeline card and the canvas card render. That's the whole
 *     of the "an app and a widget stay in sync" requirement; there is only ever one of them.
 *
 * `canvas` is absent on purpose. The Open Canvas is the one app that can't be removed (it's
 * where you *place* the others), so it is never part of a stored list.
 */
final class DeskApps
{
    /** Apps backed by their own per-surface storage. */
    public const SURFACE_APPS = ['board', 'notes', 'docs', 'calendar'];

    /**
     * Widgets promoted to apps. Must stay in step with the widget handler types — the same list
     * {@see \App\Http\Requests\Canvas\CanvasItemRules} validates a widget card against.
     */
    public const WIDGET_APPS = ['music', 'video', 'kanban', 'poll', 'shooter', 'racing', 'skribbl', 'poker'];

    /** Everything a surface may store, in no particular order (the stored array carries order). */
    public static function all(): array
    {
        return [...self::SURFACE_APPS, ...self::WIDGET_APPS];
    }
}
