<?php

namespace App\Services\Sfu;

use App\Models\Channel;
use App\Models\User;

/**
 * One SFU we could send a call to.
 *
 * The interface is small on purpose. Everything an SFU does that matters to this app happens
 * either in the browser (publishing and subscribing, via the provider's client SDK) or on the
 * SFU itself; the server's entire job is to say "here is where to connect, and here is proof
 * this person may". So that is all this asks for.
 *
 * Adding a provider is a class implementing this plus an entry in config/sfu.php. Nothing
 * else in the backend has to know it exists — SfuManager finds it by name, and the client
 * picks its adapter from SfuCredentials::$driver.
 */
interface SfuProvider
{
    /** The driver name, matching the client-side adapter. Stable — clients switch on it. */
    public function driver(): string;

    /**
     * Whether this provider has everything it needs to be tried at all.
     *
     * Checked before minting so an unconfigured entry (the common case — most deployments
     * fill in one provider and leave the rest blank) is skipped silently rather than
     * failing loudly on a path where failure means a degraded call for a real person.
     */
    public function isConfigured(): bool;

    /**
     * Mint a credential, or return null if this provider can't serve this request.
     *
     * Null rather than an exception for the same reason CloudflareTurn returns null: a
     * provider being unreachable is an expected operational state, and the caller's response
     * to it is to try the next one and then fall back to a mesh — never to fail the join.
     */
    public function credentialsFor(Channel $channel, User $user): ?SfuCredentials;
}
