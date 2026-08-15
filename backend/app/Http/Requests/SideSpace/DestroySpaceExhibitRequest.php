<?php

namespace App\Http\Requests\SideSpace;

use App\Http\Requests\ServerStaffRequest;

/**
 * Taking a picture back down. Staff only, like hanging one.
 *
 * The frame stays: it is geometry in the map document and this endpoint has no business editing
 * that. What is left is an empty frame on the wall, which is a room somebody is still curating
 * rather than a room that has lost something.
 */
class DestroySpaceExhibitRequest extends ServerStaffRequest
{
}
