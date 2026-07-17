<?php

namespace App\Support\Commands;

/**
 * A chat message recognised as a widget command — `m!p <link>`, `k!add <text>`, …
 *
 * `namespace` picks the widget ('m' → music, 'k' → kanban), `verb` the operation
 * ('p', 'pause', 'add', 'done'), and `args` is everything after it, untouched. The
 * handler for the namespace decides what a verb means; the parser only splits.
 */
final readonly class ParsedCommand
{
    public function __construct(
        public string $namespace,
        public string $verb,
        public string $args,
    ) {}

    /** The first whitespace-separated token of the args (e.g. the `2` in `k!done 2`). */
    public function firstArg(): string
    {
        return trim(explode(' ', trim($this->args), 2)[0] ?? '');
    }

    /** Everything after the first token (e.g. the text in `k!edit 2 new title`). */
    public function restAfterFirst(): string
    {
        return trim(explode(' ', trim($this->args), 2)[1] ?? '');
    }
}
