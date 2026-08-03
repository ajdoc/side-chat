<?php

namespace App\Http\Requests\Automation;

use App\Http\Requests\ServerStaffRequest;

/**
 * The dashboard's read routes, and the writes that carry no payload.
 *
 * Staff, not owner-only. Running the place is what an admin is for, and a welcome message
 * or a scheduled reminder is squarely that. The one thing an admin must not be able to
 * configure is a rule that hands out *roles* — see StoreAutomationRequest, which checks for
 * it in the payload rather than closing the whole screen.
 */
class ManageAutomationsRequest extends ServerStaffRequest {}
