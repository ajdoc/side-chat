<?php

namespace App\Actions\Invite;

use App\Actions\Message\PostSystemMessageAction;
use App\Events\JoinRequestResolved;
use App\Models\Server;
use App\Services\Automation\AutomationEngine;
use App\Services\Automation\TriggerRegistry;
use App\Support\Automation\AutomationContext;
use Illuminate\Support\Facades\DB;

final class ApproveJoinRequestsAction
{
    public function __construct(
        private readonly PostSystemMessageAction $postSystemMessage,
        private readonly AutomationEngine $automations,
    ) {}

    /**
     * Approves one or many join requests: each user becomes a member, the request is
     * removed, and a system message announces them in the server's first text channel.
     * If the server has no text channel, no notification is posted.
     *
     * @param  array<int, int>  $requestIds
     * @return int number of users admitted
     */
    public function handle(Server $server, array $requestIds): int
    {
        $requests = $server->joinRequests()
            ->with('user')
            ->whereIn('id', $requestIds)
            ->get();

        if ($requests->isEmpty()) {
            return 0;
        }

        DB::transaction(function () use ($server, $requests): void {
            foreach ($requests as $request) {
                if (! $server->hasMember($request->user)) {
                    $server->members()->attach($request->user_id, ['role' => 'member']);
                }
            }

            $server->joinRequests()->whereIn('id', $requests->pluck('id'))->delete();
        });

        // Broadcast outside the transaction, so every member's list/badge drops these.
        JoinRequestResolved::dispatch($server, $requests->pluck('id')->all(), 'approved');

        // Null channel = no text channel = no notice.
        if ($channel = $server->firstTextChannel()) {
            foreach ($requests as $request) {
                $this->postSystemMessage->handle(
                    $channel,
                    $request->user,
                    "{$request->user->name} joined the server"
                );
            }
        }

        // After the notice, and after the transaction: a rule that greets somebody should
        // land below "X joined the server", and it must not run against a membership that
        // could still roll back.
        foreach ($requests as $request) {
            $this->automations->fire(new AutomationContext(
                $server->getKey(),
                TriggerRegistry::MEMBER_JOINED,
                ['user_id' => $request->user_id, 'user_name' => $request->user?->name],
            ));
        }

        return $requests->count();
    }
}
