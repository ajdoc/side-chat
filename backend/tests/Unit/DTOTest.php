<?php

use App\DTOs\Channel\CreateChannelData;
use App\DTOs\Message\SendMessageData;
use App\DTOs\Server\CreateServerData;
use App\DTOs\User\UpdatePreferencesData;
use Illuminate\Validation\ValidationException;

it('builds a valid server DTO', function () {
    $dto = CreateServerData::fromArray(['name' => 'Design Team']);

    expect($dto->name)->toBe('Design Team');
});

it('rejects an invalid server DTO', function () {
    CreateServerData::fromArray(['name' => '']);
})->throws(ValidationException::class);

it('rejects an unknown channel type', function () {
    CreateChannelData::fromArray(['name' => 'general', 'type' => 'video']);
})->throws(ValidationException::class);

it('defaults reply_to_id to null', function () {
    $dto = SendMessageData::fromArray(['body' => 'hello']);

    expect($dto->body)->toBe('hello')
        ->and($dto->reply_to_id)->toBeNull();
});

it('carries a reply reference when given', function () {
    $dto = SendMessageData::fromArray(['body' => 'hi', 'reply_to_id' => 7]);

    expect($dto->reply_to_id)->toBe(7);
});

it('rejects an unknown theme colour', function () {
    UpdatePreferencesData::fromArray(['theme_color' => 'purple']);
})->throws(ValidationException::class);

it('exposes the same rules used by the form request', function () {
    expect(CreateServerData::validationRules())->toHaveKey('name');
});
