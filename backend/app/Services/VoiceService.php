<?php

namespace App\Services;

use App\Models\Channel;
use App\Models\Server;
use App\Models\VoiceParticipant;
use Illuminate\Database\Eloquent\Collection;

/**
 * Who is sitting in which voice channel.
 *
 * Worth being clear about why this exists at all, because the call itself doesn't need
 * it: the media session is held together by the Reverb *presence* channel `voice.{id}`,
 * which already tells the people in a call exactly who else is there and hands them a
 * leave event the instant a socket dies. Nothing in this class is on the path of the
 * audio.
 *
 * It exists for everyone *outside* the call. You cannot observe a presence channel
 * without joining it, and joining it is what "being in the call" means — so a member
 * browsing the server has no way to see that three people are already talking in
 * #general unless we keep that roster somewhere they can read. Hence these rows, and
 * the VoiceStateUpdated broadcast on the server-wide stream that keeps them live.
 *
 * The cost of a second source of truth is that it can drift: a crashed tab leaves a row
 * behind. So rows carry a heartbeat and anything gone quiet for long enough is ignored
 * and swept — see pruneStale(). Presence stays authoritative for the call; this is only
 * ever authoritative for the sidebar.
 */
final class VoiceService
{
    /** Everyone currently in a channel, freshest join first. Ghosts excluded. */
    public function participants(Channel $channel): Collection
    {
        $this->pruneStale();

        return VoiceParticipant::query()
            ->with('user')
            ->where('channel_id', $channel->id)
            ->alive()
            ->orderBy('created_at')
            ->get();
    }

    /**
     * Every occupied voice channel in a server, as `channel_id => participants`. One
     * query for the whole sidebar rather than one per voice channel.
     *
     * @return array<int, Collection>
     */
    public function rosterForServer(Server $server): array
    {
        $this->pruneStale();

        return VoiceParticipant::query()
            ->with('user')
            ->whereIn('channel_id', $server->channels()->where('type', 'voice')->select('id'))
            ->alive()
            ->orderBy('created_at')
            ->get()
            ->groupBy('channel_id')
            ->all();
    }

    public function isFull(Channel $channel): bool
    {
        return $this->participants($channel)->count() >= (int) config('webrtc.max_participants');
    }

    /**
     * The ICE servers the browser should use. Served from the API rather than baked into
     * the bundle because TURN credentials are a secret, and short-lived ones (coturn's
     * HMAC scheme) have to be minted per session — this is the seam where that goes.
     *
     * @return array<int, array<string, mixed>>
     */
    public function iceServers(): array
    {
        $servers = [];

        if ($stun = config('webrtc.stun_urls')) {
            $servers[] = ['urls' => $stun];
        }

        // Every configured TURN entry is handed over, each with its own credentials — ICE
        // fails over between providers on its own (see config/webrtc.php). Empty entries are
        // already filtered out in config, so a single-TURN deployment yields a single entry.
        foreach ((array) config('webrtc.turn', []) as $turn) {
            if (empty($turn['urls'])) {
                continue;
            }

            $servers[] = [
                'urls' => $turn['urls'],
                'username' => $turn['username'] ?? null,
                'credential' => $turn['credential'] ?? null,
            ];
        }

        return $servers;
    }

    /**
     * Drop rows whose owner stopped heartbeating. Called on every read: rosters are the
     * only thing these rows are for, so a ghost that nobody ever looks at costs nothing,
     * and one that somebody *does* look at is swept in the same breath. No scheduler,
     * nothing to forget to run.
     */
    public function pruneStale(): void
    {
        VoiceParticipant::query()
            ->where('last_seen_at', '<', VoiceParticipant::staleBefore())
            ->delete();
    }
}
