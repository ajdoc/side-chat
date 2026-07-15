<?php

namespace App\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Fetches a *user-supplied* URL without letting it point back inside our own network.
 *
 * Unfurling means our server makes a request to whatever someone pastes into a chat
 * message, which is textbook SSRF: `http://169.254.169.254/…` or `http://redis:6379/…`
 * would otherwise be fetched with all the trust of an inside-the-perimeter caller.
 * The defences here, in order:
 *
 *  1. Only http/https, only ports 80/443 — no gopher://, no poking at internal ports.
 *  2. No `user:pass@` — a URL that reads as one host but connects to another.
 *  3. Every address the hostname resolves to must be public. One private answer
 *     disqualifies the name, because a name can round-robin between them.
 *  4. The validated IP is *pinned* into curl for the actual connection, so the name
 *     can't resolve to something public for our check and private for the request
 *     (DNS rebinding). This is the bit a plain "validate then fetch" gets wrong.
 *  5. Redirects are followed by hand, re-running all of the above on every hop —
 *     otherwise a public URL could just 302 us to localhost.
 *  6. The body is read to a cap, so a multi-gigabyte response can't exhaust memory.
 */
class SafeUrlFetcher
{
    public const TIMEOUT = 5;

    public const MAX_REDIRECTS = 3;

    public const MAX_BYTES = 512 * 1024;

    private const ALLOWED_PORTS = [80, 443];

    private const USER_AGENT = 'SideChatBot/1.0 (+link-preview)';

    /**
     * @return array{url: string, content_type: string, body: string}|null  null if the URL
     *                                                                      is unsafe, unreachable, or not worth previewing
     */
    public function get(string $url): ?array
    {
        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            $target = $this->validate($url);

            if ($target === null) {
                return null;
            }

            [$host, $port, $ip] = $target;

            try {
                $response = Http::withHeaders([
                    'User-Agent' => self::USER_AGENT,
                    'Accept' => 'text/html,application/xhtml+xml,image/*;q=0.8',
                ])
                    ->timeout(self::TIMEOUT)
                    ->connectTimeout(self::TIMEOUT)
                    ->withOptions([
                        'allow_redirects' => false, // we follow them ourselves, re-validating each hop
                        'stream' => true,           // so we can stop reading at MAX_BYTES
                        'curl' => [CURLOPT_RESOLVE => ["{$host}:{$port}:{$ip}"]],
                    ])
                    ->get($url);
            } catch (Throwable) {
                return null; // DNS failure, timeout, TLS error — nothing to preview
            }

            if ($response->redirect()) {
                $location = (string) $response->header('Location');

                if ($location === '') {
                    return null;
                }

                $url = $this->resolveAgainst($location, $url);

                continue;
            }

            if (! $response->successful()) {
                return null;
            }

            return [
                'url' => $url,
                'content_type' => Str::lower(trim(Str::before((string) $response->header('Content-Type'), ';'))),
                'body' => $this->readCapped($response),
            ];
        }

        return null; // redirect loop, or a chain too long to be worth chasing
    }

    /** Turn a possibly-relative URL (a Location header, an og:image) into an absolute one. */
    public function resolveAgainst(string $url, string $base): string
    {
        if (Str::contains($url, '://')) {
            return $url;
        }

        $parts = parse_url($base);
        $scheme = $parts['scheme'] ?? 'https';

        if (Str::startsWith($url, '//')) {
            return "{$scheme}:{$url}";
        }

        $root = $scheme.'://'.($parts['host'] ?? '').(isset($parts['port']) ? ':'.$parts['port'] : '');

        if (Str::startsWith($url, '/')) {
            return $root.$url;
        }

        $path = $parts['path'] ?? '/';
        $dir = substr($path, 0, (int) strrpos($path, '/') + 1) ?: '/';

        return $root.$dir.$url;
    }

    /**
     * Validate a URL and pin the address we'll connect to.
     *
     * @return array{0: string, 1: int, 2: string}|null  [host, port, ip]
     */
    private function validate(string $url): ?array
    {
        $parts = parse_url($url);

        if (! is_array($parts)) {
            return null;
        }

        $scheme = Str::lower($parts['scheme'] ?? '');

        if (! in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        // `http://trusted.com@evil.internal/` reads as trusted.com but connects to evil.internal.
        if (isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        $host = $parts['host'] ?? '';

        if ($host === '') {
            return null;
        }

        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);

        if (! in_array($port, self::ALLOWED_PORTS, true)) {
            return null;
        }

        $ips = $this->resolve($host);

        if ($ips === []) {
            return null;
        }

        foreach ($ips as $ip) {
            if (! $this->isPublicIp($ip)) {
                return null;
            }
        }

        return [$host, $port, $ips[0]];
    }

    /**
     * Every IP a hostname answers with (an IP literal answers with itself).
     *
     * @return array<int, string>
     */
    private function resolve(string $host): array
    {
        $literal = trim($host, '[]'); // [::1] → ::1

        if (filter_var($literal, FILTER_VALIDATE_IP) !== false) {
            return [$literal];
        }

        $v4 = gethostbynamel($host) ?: [];
        $v6 = array_column(@dns_get_record($host, DNS_AAAA) ?: [], 'ipv6');

        return array_values(array_unique([...$v4, ...$v6]));
    }

    private function isPublicIp(string $ip): bool
    {
        // `::ffff:127.0.0.1` is loopback wearing an IPv6 costume — the v6 filters don't
        // see through it, so unwrap the embedded v4 address and judge that instead.
        if (Str::startsWith(Str::lower($ip), '::ffff:')) {
            $embedded = substr($ip, 7);

            if (filter_var($embedded, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                $ip = $embedded;
            }
        }

        // NO_PRIV_RANGE drops 10/8, 172.16/12, 192.168/16 and fc00::/7;
        // NO_RES_RANGE drops loopback, link-local (incl. the 169.254.169.254 metadata
        // endpoint), 0.0.0.0/8 and the reserved blocks.
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }

    /** Read at most MAX_BYTES off the response stream — enough for any sane <head>. */
    private function readCapped(Response $response): string
    {
        $stream = $response->toPsrResponse()->getBody();
        $body = '';

        while (! $stream->eof() && strlen($body) < self::MAX_BYTES) {
            $chunk = $stream->read(self::MAX_BYTES - strlen($body));

            if ($chunk === '') {
                break;
            }

            $body .= $chunk;
        }

        return $body;
    }
}
