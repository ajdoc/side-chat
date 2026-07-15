<?php

use App\Models\User;
use Laravel\Passport\Passport;

it('updates the user appearance preferences', function () {
    $user = User::factory()->create();
    Passport::actingAs($user);

    $this->patchJson('/api/preferences', ['theme_mode' => 'dark', 'theme_color' => 'green'])
        ->assertOk()
        ->assertJsonPath('data.theme_mode', 'dark')
        ->assertJsonPath('data.theme_color', 'green');

    expect($user->fresh()->theme_mode)->toBe('dark')
        ->and($user->fresh()->theme_color)->toBe('green');
});

it('allows a partial update', function () {
    $user = User::factory()->create(['theme_mode' => 'light', 'theme_color' => 'red']);
    Passport::actingAs($user);

    $this->patchJson('/api/preferences', ['theme_color' => 'blue'])->assertOk();

    expect($user->fresh()->theme_mode)->toBe('light')  // untouched
        ->and($user->fresh()->theme_color)->toBe('blue');
});

it('accepts every accent the frontend offers', function () {
    $user = User::factory()->create();
    Passport::actingAs($user);

    foreach (['slate', 'blue', 'violet', 'rose', 'red', 'amber', 'green', 'teal'] as $color) {
        $this->patchJson('/api/preferences', ['theme_color' => $color])
            ->assertOk()
            ->assertJsonPath('data.theme_color', $color);
    }
});

it('rejects unknown theme values', function () {
    Passport::actingAs(User::factory()->create());

    $this->patchJson('/api/preferences', ['theme_color' => 'chartreuse'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('theme_color');
});

it('requires authentication', function () {
    $this->patchJson('/api/preferences', ['theme_color' => 'blue'])->assertUnauthorized();
});
