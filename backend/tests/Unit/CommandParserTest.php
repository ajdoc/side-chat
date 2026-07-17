<?php

use App\Support\Commands\CommandParser;

beforeEach(function () {
    $this->parser = new CommandParser();
});

it('parses a widget command into namespace, verb and args', function () {
    $c = $this->parser->parse('m!p https://youtu.be/abc');

    expect($c)->not->toBeNull()
        ->and($c->namespace)->toBe('m')
        ->and($c->verb)->toBe('p')
        ->and($c->args)->toBe('https://youtu.be/abc');
});

it('lowercases the namespace and verb but leaves args untouched', function () {
    $c = $this->parser->parse('M!Play Daft Punk');

    expect($c->namespace)->toBe('m')
        ->and($c->verb)->toBe('play')
        ->and($c->args)->toBe('Daft Punk');
});

it('splits args into a first token and the rest', function () {
    $c = $this->parser->parse('k!edit 4 a new title');

    expect($c->firstArg())->toBe('4')
        ->and($c->restAfterFirst())->toBe('a new title');
});

it('handles a verb with no args', function () {
    $c = $this->parser->parse('m!pause');

    expect($c->verb)->toBe('pause')
        ->and($c->args)->toBe('')
        ->and($c->firstArg())->toBe('');
});

it('trims surrounding whitespace', function () {
    $c = $this->parser->parse('   k!add   buy milk   ');

    expect($c->verb)->toBe('add')
        ->and($c->args)->toBe('buy milk');
});

it('returns null for ordinary chat and unknown namespaces', function (?string $body) {
    expect($this->parser->parse($body))->toBeNull();
})->with([
    'plain text' => ['hello there'],
    'trailing bang' => ['hey!'],
    'unknown namespace' => ['a!b test'],
    'mention mid-sentence' => ['run k!add later'],
    'numeric verb' => ['m!123'],
    'null body' => [null],
    'empty' => [''],
]);
