<?php

namespace App\Http\Controllers;

use App\Models\DeviceToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Where the app says "here's how to reach this install".
 *
 * Called on every launch, not just the first: FCM rotates a token whenever it feels like
 * it (app restore, data cleared, long silence), and a client that only registered once
 * would go quietly unreachable. The upsert makes repeating it free.
 */
class DeviceTokenController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:512'],
            'platform' => ['required', Rule::in(DeviceToken::PLATFORMS)],
        ]);

        DeviceToken::register($request->user(), $data['token'], $data['platform']);

        return response()->json(['registered' => true]);
    }

    /**
     * Sign-out. Deleting only *this* token, and only if it's still ours: signing out on one
     * phone must not silence the others, and a token that has already been reassigned to
     * whoever signed in next is no longer this user's to revoke.
     */
    public function destroy(Request $request): JsonResponse
    {
        $token = (string) $request->input('token');

        if ($token !== '') {
            DeviceToken::where('token', $token)
                ->where('user_id', $request->user()->id)
                ->delete();
        }

        return response()->json(['revoked' => true]);
    }
}
