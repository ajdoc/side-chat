<?php

namespace App\Services\Sfu;

use App\Models\Channel;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The configured SFUs, in the order they should be tried.
 *
 * This is the failover half of the design. Providers are asked one at a time and the first
 * that yields a credential wins; one that is unconfigured, broken, or out of quota is stepped
 * over. Callers get a credential or null, and null is not an error — it means "hold this call
 * in a mesh", which is a working call.
 *
 * Drivers are resolved by name so a new provider is a class plus a config entry. Nothing here
 * needs to change to add one, and nothing outside needs to know which one answered.
 */
final class SfuManager
{
    /**
     * The drivers shipped with the app. A `driver` in config may also be a class name
     * implementing SfuProvider, which is the extension point: a provider living outside this
     * namespace — or one that only exists in a test — needs no entry here and no change to
     * this class.
     *
     * @var array<string, class-string<SfuProvider>>
     */
    private const DRIVERS = [
        'livekit' => LiveKitProvider::class,
    ];

    /**
     * The first provider that could serve this channel, or null if none can.
     *
     * A provider that throws is treated exactly like one that declines: logged, stepped over,
     * and never allowed to take the join down with it. This method sits on the join path.
     */
    public function credentialsFor(Channel $channel, User $user): ?SfuCredentials
    {
        foreach ($this->providers() as $provider) {
            try {
                if ($credentials = $provider->credentialsFor($channel, $user)) {
                    return $credentials;
                }
            } catch (Throwable $e) {
                Log::warning('SFU provider failed to mint a credential', [
                    'driver' => $provider->driver(),
                    'message' => $e->getMessage(),
                ]);
            }
        }

        return null;
    }

    /** Whether any provider is configured at all — the cheap check, with no minting. */
    public function isAvailable(): bool
    {
        foreach ($this->providers() as $provider) {
            if ($provider->isConfigured()) {
                return true;
            }
        }

        return false;
    }

    /**
     * The configured providers, in `sfu.order`.
     *
     * Names that match no provider, providers with no recognised driver, and providers with
     * nothing filled in are all dropped here rather than guarded against downstream — so the
     * common deployment (one provider configured, the rest of the file left as shipped)
     * produces a one-entry list and everything after this is uniform.
     *
     * @return list<SfuProvider>
     */
    private function providers(): array
    {
        $configured = (array) config('sfu.providers', []);
        $providers = [];

        foreach ((array) config('sfu.order', []) as $name) {
            $config = $configured[$name] ?? null;

            if (! is_array($config)) {
                continue;
            }

            $driver = (string) ($config['driver'] ?? '');
            $class = self::DRIVERS[$driver] ?? $driver;

            if (! is_subclass_of($class, SfuProvider::class)) {
                continue;
            }

            /** @var SfuProvider $provider */
            $provider = new $class($name, $config);

            if ($provider->isConfigured()) {
                $providers[] = $provider;
            }
        }

        return $providers;
    }
}
