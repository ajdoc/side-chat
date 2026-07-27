<?php

namespace App\Support\Commands;

/**
 * Turns a message body into a {@see ParsedCommand}, or nothing.
 *
 * A command is a single line beginning `<x>!<verb>` where `<x>` is a known widget prefix
 * — `m` for music, `v` for the watch-along video player, `k` for kanban, `p` for a poll,
 * `g` for the co-op shooter, `r` for the co-op racer, `s` for Skribbl.
 *
 * One prefix isn't a widget: `a` is the *app launcher* ({@see self::APP_NAMESPACE}). Its verb
 * is an app id rather than an action — `a!poll`, `a!board`, `a!notes` — so a single command
 * reaches everything on the Side Desk catalogue, including the apps that have no widget row
 * behind them and therefore no prefix of their own. See WidgetService::handleAppCommand.
 *
 * Anything else (including a
 * stray "hey!" or a message
 * that merely mentions `k!add` mid-sentence) is left alone: the parser anchors to the
 * start and requires a recognised namespace, so ordinary chat never trips it.
 */
final class CommandParser
{
    /** Prefix letter → widget type. The set of things `<x>!…` is allowed to be. */
    public const NAMESPACES = ['m' => 'music', 'v' => 'video', 'k' => 'kanban', 'p' => 'poll', 'g' => 'shooter', 'r' => 'racing', 's' => 'skribbl'];

    /** The app launcher's prefix — `a!<app>`. Not a widget type; handled on its own path. */
    public const APP_NAMESPACE = 'a';

    public function parse(?string $body): ?ParsedCommand
    {
        if ($body === null) {
            return null;
        }

        $line = trim($body);

        // ^<letter>!<verb>[ <args>]$ — a single, whole-message command. The verb is
        // letters only so "m!123" isn't a command; args are whatever's left.
        if (! preg_match('/^([a-zA-Z])!([a-zA-Z]+)(?:\s+(.*))?$/s', $line, $m)) {
            return null;
        }

        $namespace = strtolower($m[1]);
        if ($namespace !== self::APP_NAMESPACE && ! array_key_exists($namespace, self::NAMESPACES)) {
            return null;
        }

        return new ParsedCommand(
            namespace: $namespace,
            verb: strtolower($m[2]),
            args: trim($m[3] ?? ''),
        );
    }
}
