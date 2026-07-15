<?php

use App\Models\User;
use Laravel\Passport\ClientRepository;

beforeEach(function () {
    // Passport needs a personal access client in the (refreshed) test database
    // before it can issue tokens.
    app(ClientRepository::class)->createPersonalAccessGrantClient('Testing');
});

it('registers a user and returns an access token', function () {
    $response = $this->postJson('/api/auth/register', [
        'name' => 'Alice',
        'email' => 'alice@example.com',
        'password' => 'Password123',
        'password_confirmation' => 'Password123',
    ]);

    $response->assertCreated()
        ->assertJsonPath('user.email', 'alice@example.com')
        ->assertJsonPath('user.theme_mode', 'system')   // model defaults
        ->assertJsonPath('user.theme_color', 'blue')
        ->assertJsonStructure(['user', 'token', 'token_type']);

    expect(User::where('email', 'alice@example.com')->exists())->toBeTrue();
});

it('rejects a duplicate email', function () {
    User::factory()->create(['email' => 'taken@example.com']);

    $this->postJson('/api/auth/register', [
        'name' => 'Bob',
        'email' => 'taken@example.com',
        'password' => 'Password123',
        'password_confirmation' => 'Password123',
    ])->assertStatus(422)->assertJsonValidationErrors('email');
});

it('requires the password confirmation to match', function () {
    $this->postJson('/api/auth/register', [
        'name' => 'Bob',
        'email' => 'bob@example.com',
        'password' => 'Password123',
        'password_confirmation' => 'Different123',
    ])->assertStatus(422)->assertJsonValidationErrors('password');
});

it('logs in with valid credentials', function () {
    User::factory()->create([
        'email' => 'carol@example.com',
        'password' => 'Password123',
    ]);

    $this->postJson('/api/auth/login', [
        'email' => 'carol@example.com',
        'password' => 'Password123',
    ])->assertOk()->assertJsonStructure(['user', 'token']);
});

it('rejects invalid credentials', function () {
    User::factory()->create([
        'email' => 'dave@example.com',
        'password' => 'Password123',
    ]);

    $this->postJson('/api/auth/login', [
        'email' => 'dave@example.com',
        'password' => 'WrongPassword',
    ])->assertStatus(422)->assertJsonValidationErrors('email');
});

it('returns the current user and can log out', function () {
    $token = $this->postJson('/api/auth/register', [
        'name' => 'Eve',
        'email' => 'eve@example.com',
        'password' => 'Password123',
        'password_confirmation' => 'Password123',
    ])->json('token');

    $auth = ['Authorization' => "Bearer {$token}"];

    $this->getJson('/api/auth/me', $auth)
        ->assertOk()
        ->assertJsonPath('data.email', 'eve@example.com');

    $this->postJson('/api/auth/logout', [], $auth)->assertOk();

    // The guard caches the resolved user in-process; drop it so the next request
    // really re-authenticates the (now revoked) token.
    $this->app['auth']->forgetGuards();

    $this->getJson('/api/auth/me', $auth)->assertUnauthorized();
});

it('rejects unauthenticated access', function () {
    $this->getJson('/api/auth/me')->assertUnauthorized();
});
