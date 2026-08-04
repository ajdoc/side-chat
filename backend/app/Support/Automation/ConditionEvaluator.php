<?php

namespace App\Support\Automation;

use Illuminate\Support\Str;

/**
 * "Only when…" — the filter between a trigger firing and a rule running.
 *
 * A flat list of `{field, operator, value}` predicates, joined by **one** connective chosen
 * per rule: all of them must hold, or any one of them will do.
 *
 * One connective, not an expression tree. Grouping and precedence are what would need a
 * parser, an editor that can draw a tree, and an error message for a rule that is
 * syntactically fine but means nothing — this needs none of those, and it covers the case
 * that actually comes up ("either of these two names"). Anything genuinely needing nested
 * logic is still two rules.
 *
 * Unknown fields evaluate as null rather than throwing. A trigger's context varies by what
 * fired it, and a rule that mentions a field this particular event didn't carry has simply
 * not matched — that isn't a broken rule, it's a rule that didn't apply.
 */
final class ConditionEvaluator
{
    /**
     * The operators, and what the UI calls them.
     *
     * `matches` is a substring-anchored wildcard (`Str::is`), not a regex. A regex box in a
     * web form is a denial-of-service waiting for someone to type `(a+)+`, and nobody
     * configuring a welcome message wanted one.
     */
    public const OPERATORS = [
        'equals' => 'is',
        'not_equals' => 'is not',
        'contains' => 'contains',
        'not_contains' => 'does not contain',
        'matches' => 'matches (use * as a wildcard)',
        'in' => 'is one of',
        'gt' => 'is greater than',
        'lt' => 'is less than',
        'is_empty' => 'is empty',
        'is_not_empty' => 'is not empty',
    ];

    /** Every filter must hold. */
    public const MATCH_ALL = 'all';

    /** Any one of them is enough. */
    public const MATCH_ANY = 'any';

    public const MATCHES = [self::MATCH_ALL, self::MATCH_ANY];

    /**
     * Do this rule's conditions hold?
     *
     * No conditions means yes, whichever connective is set — an unfiltered rule runs on every
     * occurrence of its trigger, which is what "when someone joins, greet them" should mean
     * without further ceremony. (Note that `any` of an empty list would be *false* under a
     * strict reading; that reading would make deleting your last filter silently switch the
     * rule off, so it isn't the one used.)
     *
     * @param  array<int, array<string, mixed>>|null  $conditions
     */
    public function passes(?array $conditions, AutomationContext $context, string $match = self::MATCH_ALL): bool
    {
        $conditions = array_values($conditions ?? []);

        if ($conditions === []) {
            return true;
        }

        $any = $match === self::MATCH_ANY;

        foreach ($conditions as $condition) {
            if (! is_array($condition) || ! isset($condition['field'], $condition['operator'])) {
                // A malformed row is not a licence to run: a rule whose filter we can't read
                // has not been shown to apply. Under `any` it's simply not the one that
                // matched, so it's skipped rather than failing the whole rule.
                if ($any) {
                    continue;
                }

                return false;
            }

            $held = $this->test(
                (string) $condition['operator'],
                $context->get((string) $condition['field']),
                $condition['value'] ?? null,
            );

            // Short-circuit either way: the first hit settles `any`, the first miss settles
            // `all`.
            if ($held === $any) {
                return $any;
            }
        }

        return ! $any;
    }

    private function test(string $operator, mixed $actual, mixed $expected): bool
    {
        return match ($operator) {
            'equals' => $this->scalar($actual) === $this->scalar($expected),
            'not_equals' => $this->scalar($actual) !== $this->scalar($expected),
            // Case-insensitive, both ways: somebody filtering messages for "hello" means
            // the word, not the casing they happened to type it in.
            'contains' => $expected !== null && Str::contains($this->text($actual), $this->text($expected), true),
            'not_contains' => $expected === null || ! Str::contains($this->text($actual), $this->text($expected), true),
            'matches' => Str::is($this->text($expected), $this->text($actual)),
            'in' => in_array($this->scalar($actual), array_map($this->scalar(...), $this->list($expected)), true),
            // Numeric only. Comparing strings with > is a source of surprises nobody wants
            // from a rule they wrote once and forgot.
            'gt' => is_numeric($actual) && is_numeric($expected) && (float) $actual > (float) $expected,
            'lt' => is_numeric($actual) && is_numeric($expected) && (float) $actual < (float) $expected,
            'is_empty' => $actual === null || $actual === '' || $actual === [],
            'is_not_empty' => ! ($actual === null || $actual === '' || $actual === []),
            // An operator we don't know is not one that passed.
            default => false,
        };
    }

    /** Compared as strings so `7` from a form and `7` from the database are the same value. */
    private function scalar(mixed $value): string
    {
        return is_bool($value) ? ($value ? '1' : '0') : (string) (is_scalar($value) ? $value : '');
    }

    private function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    /** @return array<int, mixed> */
    private function list(mixed $value): array
    {
        if (is_array($value)) {
            return array_values($value);
        }

        // A comma-separated string is what a single-line form field gives us, and asking
        // people to type JSON into a web form would be a strange thing to do to them.
        return is_string($value) ? array_map(trim(...), explode(',', $value)) : [];
    }
}
