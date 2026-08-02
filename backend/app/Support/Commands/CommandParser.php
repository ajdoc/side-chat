<?php

namespace App\Support\Commands;

/**
 * Turns a message body into a {@see ParsedCommand}, or nothing.
 *
 * A command is a single line beginning `<x>!<verb>` where `<x>` is a known widget prefix
 * — `m` for music, `v` for the watch-along video player, `k` for kanban, `p` for a poll,
 * `g` for the co-op shooter, `r` for the co-op racer, `s` for Skribbl, `h` for hold'em (the poker table — `p` was
 * long since spoken for by polls).
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
    public const NAMESPACES = ['m' => 'music', 'v' => 'video', 'k' => 'kanban', 'p' => 'poll', 'g' => 'shooter', 'r' => 'racing', 's' => 'skribbl', 'h' => 'poker'];

    /** The app launcher's prefix — `a!<app>`. Not a widget type; handled on its own path. */
    public const APP_NAMESPACE = 'a';

    /**
     * The slash namespace — `/roll 2d6`, `/help`, and whatever a bot has registered.
     *
     * A different shape rather than another letter because the letters are gone: every one
     * of them either means a widget already or would have to be memorised as meaning one
     * particular bot. `/` is the convention every chat app has trained people into, it
     * reads as "a command" without knowing which, and it leaves room for a name rather than
     * a single character — which is what a bot needs. See SlashCommandService.
     */
    public const SLASH_NAMESPACE = '/';

    public function parse(?string $body): ?ParsedCommand
    {
        if ($body === null) {
            return null;
        }

        $line = trim($body);

        if (($slash = $this->parseSlash($line)) !== null) {
            return $slash;
        }

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

    /**
     * `/verb [args]` — a whole message, nothing before the slash.
     *
     * The verb allows digits and dashes as well as letters, unlike the widget verbs: a bot
     * names its own commands, `/deploy-v2` is a reasonable thing to want, and `/8ball` is
     * the name everybody already knows that command by. What it must contain is at least
     * one *letter* — so `/8ball` is a command and `/1`, `/2024` and `/123-45` are not,
     * which keeps a message that opens with a number or a date out of the command path.
     *
     * A bare `/` is left alone too. Somebody typing a path or a URL fragment has not issued
     * a command, and swallowing their message would be worse than the feature is useful.
     */
    private function parseSlash(string $line): ?ParsedCommand
    {
        if (! preg_match('/^\/((?=[a-zA-Z0-9-]*[a-zA-Z])[a-zA-Z0-9][a-zA-Z0-9-]*)(?:\s+(.*))?$/s', $line, $m)) {
            return null;
        }

        return new ParsedCommand(
            namespace: self::SLASH_NAMESPACE,
            verb: strtolower($m[1]),
            args: trim($m[2] ?? ''),
        );
    }
}
