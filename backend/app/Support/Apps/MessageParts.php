<?php

namespace App\Support\Apps;

/**
 * Reading a chat message as the thing an app wants.
 *
 * A message is prose typed into a box. An app item is a title, or a title with a body, or a
 * question with options — and the shapes people already type when they mean those things are
 * remarkably consistent: the first line is the name of it, and a markdown list underneath is the
 * set of choices. So rather than ask somebody to re-type their message into a form, this reads
 * the message they already sent.
 *
 * Kept apart from the handlers in {@see MessageToApp} because the *client previews it* — the
 * "Add to app" dialog shows the parsed question and options before you commit — and a rule that
 * lives in one place is a rule the preview and the result can't disagree about. The client's
 * preview mirrors this; this is the authority.
 */
final class MessageParts
{
    /**
     * The message's first meaningful line — the title of whatever it's becoming.
     *
     * Markdown decoration is stripped: somebody who typed `## Retro actions` meant the words,
     * and a project called "## Retro actions" is a project nobody can search for.
     */
    public static function title(?string $body, int $limit = 120): string
    {
        foreach (preg_split('/\R/', (string) $body) ?: [] as $line) {
            $clean = self::strip($line);

            if ($clean !== '') {
                return mb_substr($clean, 0, $limit);
            }
        }

        return '';
    }

    /** Everything after the title line, kept as it was written — it's a description, not a label. */
    public static function rest(?string $body): string
    {
        $lines = preg_split('/\R/', (string) $body) ?: [];

        foreach ($lines as $i => $line) {
            if (self::strip($line) !== '') {
                return trim(implode("\n", array_slice($lines, $i + 1)));
            }
        }

        return '';
    }

    /**
     * A question and its options, read out of a markdown list.
     *
     * ```
     * Where are we eating?
     * - Thai
     * - Pizza
     * - Somewhere with chairs
     * ```
     *
     * The first non-list line is the question; every `-`, `*`, `+` or `1.` item under it is an
     * option. Deliberately loose about the marker, because the point is to catch what people
     * type without being told the syntax — and deliberately strict about *order*: a list with no
     * line above it has no question, and is reported as such rather than being given the first
     * option as its title.
     *
     * @return array{question: string, options: array<int, string>}
     */
    public static function poll(?string $body, int $maxOptions = 20): array
    {
        $question = '';
        $options = [];

        foreach (preg_split('/\R/', (string) $body) ?: [] as $line) {
            if (preg_match('/^\s*(?:[-*+]|\d+[.)])\s+(.*\S)\s*$/u', $line, $match) === 1) {
                if (count($options) < $maxOptions) {
                    $options[] = mb_substr(self::strip($match[1]), 0, 200);
                }

                continue;
            }

            $clean = self::strip($line);

            // The first non-list line wins. A later one is prose *about* the question — the
            // "let me know by friday" that follows every poll anybody has ever posted.
            if ($clean !== '' && $question === '' && $options === []) {
                $question = mb_substr($clean, 0, 280);
            }
        }

        return ['question' => $question, 'options' => array_values(array_filter($options))];
    }

    /** One line, minus the markdown that was decorating it. */
    private static function strip(string $line): string
    {
        $line = preg_replace('/^\s*#{1,6}\s+/u', '', $line) ?? $line;      // headings
        $line = preg_replace('/^\s*>\s?/u', '', $line) ?? $line;           // block quotes
        $line = preg_replace('/^\s*(?:[-*+]|\d+[.)])\s+/u', '', $line) ?? $line; // list markers
        $line = preg_replace('/\*\*|__|[*_`~]/u', '', $line) ?? $line;     // inline emphasis

        return trim($line);
    }
}
