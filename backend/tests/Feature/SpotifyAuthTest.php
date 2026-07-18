<?php

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Laravel\Passport\Passport;

beforeEach(function () {
    config([
        'services.spotify.client_id' => 'id',
        'services.spotify.client_secret' => 'secret',
        'services.spotify.redirect' => 'http://localhost:8000/api/spotify/callback',
        'app.frontend_url' => 'http://localhost:3000',
    ]);
});

it('hands back an authorize URL carrying an encrypted state', function () {
    Passport::actingAs(User::factory()->create());

    $url = $this->getJson('/api/spotify/connect')->assertOk()->json('url');

    expect($url)->toContain('accounts.spotify.com/authorize')
        ->toContain('scope=')
        ->toContain('state=');
});

it('links the account on callback, recording Premium status', function () {
    Http::fake([
        'accounts.spotify.com/api/token' => Http::response(['access_token' => 'AT', 'refresh_token' => 'RT', 'expires_in' => 3600]),
        'api.spotify.com/v1/me' => Http::response(['id' => 'spuser', 'product' => 'premium']),
    ]);

    $user = User::factory()->create();
    Passport::actingAs($user);

    // Pull a real, valid state out of the connect URL, then play the callback Spotify makes.
    $url = $this->getJson('/api/spotify/connect')->json('url');
    parse_str(parse_url($url, PHP_URL_QUERY), $q);

    $this->get('/api/spotify/callback?code=THECODE&state='.urlencode($q['state']))
        ->assertRedirect('http://localhost:3000/?spotifyLinked=1');

    $user->refresh();
    expect($user->spotify_id)->toBe('spuser')
        ->and($user->spotify_product)->toBe('premium')
        ->and($user->spotify_refresh_token)->toBe('RT')  // decrypts via the model cast
        ->and($user->spotifyPremium())->toBeTrue();
});

it('redirects with a failure flag when the code exchange fails', function () {
    Http::fake(['accounts.spotify.com/api/token' => Http::response('bad', 400)]);

    $user = User::factory()->create();
    Passport::actingAs($user);
    $url = $this->getJson('/api/spotify/connect')->json('url');
    parse_str(parse_url($url, PHP_URL_QUERY), $q);

    $this->get('/api/spotify/callback?code=x&state='.urlencode($q['state']))
        ->assertRedirect('http://localhost:3000/?spotifyLinked=0');

    expect($user->fresh()->spotify_refresh_token)->toBeNull();
});

it('rejects a tampered/foreign state', function () {
    Passport::actingAs(User::factory()->create());

    $this->get('/api/spotify/callback?code=x&state=garbage')
        ->assertRedirect('http://localhost:3000/?spotifyLinked=0');
});

it('reports link status and serves a fresh SDK token', function () {
    $user = User::factory()->create([
        'spotify_access_token' => 'AT',
        'spotify_refresh_token' => 'RT',
        'spotify_token_expires_at' => now()->addHour(),
        'spotify_product' => 'premium',
    ]);
    Passport::actingAs($user);

    $this->getJson('/api/spotify/status')->assertOk()
        ->assertJson(['linked' => true, 'premium' => true, 'product' => 'premium']);

    // Token still valid → returned without hitting Spotify.
    $this->getJson('/api/spotify/token')->assertOk()->assertJson(['access_token' => 'AT']);
});

it('refreshes an expired token before serving it', function () {
    Http::fake(['accounts.spotify.com/api/token' => Http::response(['access_token' => 'NEW', 'expires_in' => 3600])]);

    $user = User::factory()->create([
        'spotify_access_token' => 'OLD',
        'spotify_refresh_token' => 'RT',
        'spotify_token_expires_at' => now()->subMinute(), // expired
        'spotify_product' => 'premium',
    ]);
    Passport::actingAs($user);

    $this->getJson('/api/spotify/token')->assertOk()->assertJson(['access_token' => 'NEW']);
    expect($user->fresh()->spotify_access_token)->toBe('NEW');
});

it('409s the token endpoint when the user has not linked Spotify', function () {
    Passport::actingAs(User::factory()->create());

    $this->getJson('/api/spotify/token')->assertStatus(409);
    $this->getJson('/api/spotify/status')->assertOk()->assertJson(['linked' => false, 'premium' => false]);
});

it('disconnects, clearing the stored tokens', function () {
    $user = User::factory()->create([
        'spotify_refresh_token' => 'RT',
        'spotify_product' => 'premium',
    ]);
    Passport::actingAs($user);

    $this->postJson('/api/spotify/disconnect')->assertNoContent();
    expect($user->fresh()->spotify_refresh_token)->toBeNull()
        ->and($user->fresh()->spotify_product)->toBeNull();
});
