<?php

namespace App\Http\Requests\SideSpace;

use App\Http\Requests\ServerStaffRequest;

/**
 * Pulling a room out of a Side Space.
 *
 * Staff only, where building one is open to any member — and the asymmetry is the point. Every
 * other edit in this feature is undone by editing it back: a wall painted over is repainted, a
 * couch moved is moved again, an interior added is deleted. Deleting an interior takes its grid,
 * its furniture, its zones, its room owners and its locks with it, and nothing in the app can
 * put them back. "Visible, attributed and reversible" is what lets the rest of the editor stay
 * open; this is the one operation that fails the third test.
 *
 * The main map is refused outright, by the controller rather than here — a Side Space with no
 * way in is a channel that opens to a blank canvas, and that is not a permission question.
 */
class DestroySideSpaceMapRequest extends ServerStaffRequest
{
}
