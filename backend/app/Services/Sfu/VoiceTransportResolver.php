<?php

namespace App\Services\Sfu;

use App\Models\Channel;
use App\Models\User;

/**
 * Decides what a call may use an SFU *for*, and whether it should by default.
 *
 * The decision lives on the server, and that is the load-bearing choice in this whole feature.
 * The server is the only party that knows the admin's policy, which providers exist, and what
 * their credentials are — so it proposes, and the client is free to disagree by falling back
 * (see the frontend's VoiceTransport). That one rule is what makes the admin toggle, provider
 * failover and mesh fallback three faces of the same mechanism rather than three features.
 *
 * The split is by *medium*, not by call. Voices and cameras stay peer-to-peer whatever this
 * returns: they are cheap (a few dozen kbps each), they are the thing latency is most audible
 * in, and a mesh carries them comfortably. A shared screen is the opposite on every count — it
 * is the one stream big enough that sending a copy per person breaks the sender's upload — so
 * it is the only thing an SFU is offered for here.
 *
 * Which means this returns a *capability plus a default*, not an instruction: credentials are
 * minted whenever the SFU is permitted at all, so a sharer can move a live share onto it the
 * moment they notice it struggling, and `transport` only says which way to start.
 *
 * ICE servers accompany the answer regardless, because the mesh is always running underneath —
 * it is carrying the voices even when the screen has gone elsewhere.
 */
final class VoiceTransportResolver
{
    public const MESH = 'mesh';

    public const SFU = 'sfu';

    public function __construct(private readonly SfuManager $sfu) {}

    /**
     * @param  int  $occupancy  People already in the call. The joiner is added here, because
     *                          the question is what the call is about to be, not what it was.
     * @return array{transport: string, sfu: array<string, mixed>|null, reason: string}
     */
    public function resolve(Channel $channel, User $user, int $occupancy): array
    {
        if (! $channel->sfuEnabled()) {
            return $this->mesh('disabled');
        }

        if (! $this->sfu->isAvailable()) {
            return $this->mesh('unconfigured');
        }

        // Minted regardless of how busy the call is, because the sharer may turn the SFU on at
        // any point — including in a call of two, if that's where their upload gives out.
        // Signing a token costs an HMAC and nothing else, so an unused one is not worth
        // withholding; what it buys is a switch that works instantly instead of after a
        // round-trip nobody asked for.
        $credentials = $this->sfu->credentialsFor($channel, $user);

        // Every provider declined or fell over — a quota running out mid-evening takes this
        // path. Screen sharing still works, peer-to-peer, which is where it started.
        if ($credentials === null) {
            return $this->mesh('mint_failed');
        }

        // Two people sharing directly beat two people sharing through a datacentre: lower
        // latency, no egress bill, and no dependency on a third party being up. So a small call
        // *starts* peer-to-peer and the sharer can move it; a busy one starts on the SFU,
        // because that is where a mesh share is already in trouble.
        $suggested = $occupancy + 1 >= (int) config('sfu.threshold', 4)
            ? self::SFU
            : self::MESH;

        return [
            'transport' => $suggested,
            'sfu' => $credentials->toArray(),
            'reason' => 'ok',
        ];
    }

    private function mesh(string $reason): array
    {
        return ['transport' => self::MESH, 'sfu' => null, 'reason' => $reason];
    }
}
