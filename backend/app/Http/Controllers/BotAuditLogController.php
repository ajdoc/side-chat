<?php

namespace App\Http\Controllers;

use App\Http\Requests\Automation\ManageAutomationsRequest;
use App\Models\BotAuditLog;
use App\Models\Server;
use Illuminate\Http\JsonResponse;

/**
 * The Logging page: everything the bot did, filterable.
 *
 * The Overview shows the last twenty as a glance; this is the same table with paging and
 * filters, for the case the glance is for — "it stopped working on Tuesday". Filtering on
 * outcome is the one that earns its place: a server with busy rules has thousands of `ok`
 * lines and a handful of failures, and finding the failures by scrolling is not finding them.
 *
 * Retention is a nightly prune (`bot:prune-audit-log`), so "as far back as it goes" is a
 * month by default rather than forever.
 */
class BotAuditLogController extends Controller
{
    public function index(ManageAutomationsRequest $request, Server $server): JsonResponse
    {
        $lines = BotAuditLog::with('automation:id,name', 'subject:id,name')
            ->where('server_id', $server->getKey())
            ->when($request->query('outcome'), fn ($query, $outcome) => $query->where('outcome', $outcome))
            ->when($request->query('automation_id'), fn ($query, $id) => $query->where('automation_id', $id))
            ->latest('id')
            ->paginate(50);

        return response()->json([
            'data' => $lines->getCollection()->map(fn (BotAuditLog $line) => [
                'id' => $line->id,
                'action' => $line->action,
                'outcome' => $line->outcome,
                'message' => $line->message,
                'context' => $line->context,
                'automation' => $line->automation?->name,
                'automation_id' => $line->automation_id,
                'subject' => $line->subject?->name,
                'created_at' => $line->created_at,
            ]),
            'meta' => [
                'current_page' => $lines->currentPage(),
                'last_page' => $lines->lastPage(),
                'total' => $lines->total(),
            ],
        ]);
    }
}
