<?php

use App\Services\SafeUrlFetcher;
use Illuminate\Support\Facades\Http;

/**
 * The guard around unfurling. Every case here is a URL a user could paste into a chat
 * message to make *our server* make a request on their behalf — so the assertion is
 * not just "returns null" but "never put a packet on the wire".
 */
beforeEach(function () {
    Http::fake(); // if the guard leaks, this records the request and the assertion below catches it
    $this->fetcher = new SafeUrlFetcher();
});

it('refuses to fetch anything that resolves inside the network', function (string $url) {
    expect($this->fetcher->get($url))->toBeNull();

    Http::assertNothingSent();
})->with([
    'loopback by name' => 'http://localhost/',
    'loopback by ip' => 'http://127.0.0.1/',
    'loopback, dressed as ipv6' => 'http://[::1]/',
    'ipv4-mapped ipv6 loopback' => 'http://[::ffff:127.0.0.1]/',
    'cloud metadata endpoint' => 'http://169.254.169.254/latest/meta-data/',
    'private class A' => 'http://10.0.0.1/',
    'private class B' => 'http://172.16.0.1/',
    'private class C' => 'http://192.168.1.1/',
    'the unspecified address' => 'http://0.0.0.0/',
]);

it('refuses schemes and ports that have no business being unfurled', function (string $url) {
    expect($this->fetcher->get($url))->toBeNull();

    Http::assertNothingSent();
})->with([
    'file' => 'file:///etc/passwd',
    'ftp' => 'ftp://example.com/',
    'gopher' => 'gopher://example.com/',
    'javascript' => 'javascript:alert(1)',
    // Reachable-looking, but a port we never want the server probing.
    'ssh port' => 'http://example.com:22/',
    'postgres port' => 'http://example.com:5432/',
    'redis port' => 'http://example.com:6379/',
]);

it('refuses a url whose credentials disguise the host it connects to', function () {
    // Reads as example.com, connects to localhost.
    expect($this->fetcher->get('http://example.com@localhost/'))->toBeNull();
    expect($this->fetcher->get('http://user:pass@example.com/'))->toBeNull();

    Http::assertNothingSent();
});

it('resolves a relative redirect or og:image against the page it came from', function () {
    $fetcher = new SafeUrlFetcher();

    expect($fetcher->resolveAgainst('/a/b.png', 'https://example.com/x/y.html'))
        ->toBe('https://example.com/a/b.png')
        ->and($fetcher->resolveAgainst('b.png', 'https://example.com/x/y.html'))
        ->toBe('https://example.com/x/b.png')
        ->and($fetcher->resolveAgainst('//cdn.example.com/b.png', 'https://example.com/x/y.html'))
        ->toBe('https://cdn.example.com/b.png')
        ->and($fetcher->resolveAgainst('https://other.com/b.png', 'https://example.com/x/y.html'))
        ->toBe('https://other.com/b.png');
});
