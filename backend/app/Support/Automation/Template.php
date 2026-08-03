<?php

namespace App\Support\Automation;

/**
 * `{user}` and friends, filled in from the event that fired.
 *
 * Placeholders are the whole templating language, deliberately. A welcome message wants to
 * say the person's name and nothing more clever than that, and every step beyond simple
 * substitution — conditionals, loops, expressions — is a step towards a template engine
 * running strings a stranger typed into a web form.
 *
 * A placeholder naming something this event didn't carry renders as empty rather than as
 * the literal `{user}`. Somebody who wrote `{user}` into a rule for a trigger that has no
 * member would rather have a slightly awkward sentence than braces in the channel.
 */
final class Template
{
    /**
     * Aliases, so a template can say `{user}` rather than `{user_name}`.
     *
     * These are the names the dashboard offers as chips, and they're the names people will
     * copy from the screenshots.
     */
    private const ALIASES = [
        'user' => 'user_name',
        'channel' => 'channel_name',
        'message' => 'body',
    ];

    public static function render(string $template, AutomationContext $context): string
    {
        $values = $context->data;
        $values['server'] = $values['server_name'] ?? '';

        return (string) preg_replace_callback(
            '/\{([a-z_]+)\}/i',
            function (array $match) use ($values): string {
                $key = strtolower($match[1]);
                $key = self::ALIASES[$key] ?? $key;
                $value = $values[$key] ?? '';

                return is_scalar($value) ? (string) $value : '';
            },
            $template,
        );
    }
}
