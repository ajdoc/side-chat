<?php

namespace App\Support\Tracker;

/**
 * The closed sets a task's status and priority are drawn from.
 *
 * Strings in the schema, a list here, and icons/colours in the client. Kept server-side too
 * because these are the values validation refuses anything outside of — the client's copy
 * decides how a status *looks*, this one decides what may be stored.
 *
 * Status order is the board's order, top to bottom, and it is also the order the client shows
 * the groups in. `DONE` is the one with behaviour attached: reaching it stamps `completed_at`
 * and leaving it clears the stamp, which is what the project's progress bar counts.
 */
final class TrackerFields
{
    public const STATUSES = ['backlog', 'todo', 'in_progress', 'in_review', 'done'];

    public const PRIORITIES = ['low', 'mid', 'high', 'urgent'];

    /** The status a task lands in when created without one. */
    public const DEFAULT_STATUS = 'todo';

    public const DEFAULT_PRIORITY = 'mid';

    /** The one status that means the work is finished. */
    public const DONE = 'done';

    /**
     * The named colours a tag may take — the same closed-catalogue reasoning as the calendar's
     * event colours, so the palette stays re-tunable and nobody can store a hex.
     */
    public const TAG_COLORS = ['slate', 'primary', 'green', 'amber', 'red', 'violet', 'sky'];
}
