<?php

namespace App\Services\Automation;

use App\Support\Automation\Subject;

/**
 * Every "when" a rule can be built on, and what each one puts in the context.
 *
 * Data, not classes: a trigger has no behaviour. It is fired from wherever the thing
 * actually happens (a listener, an action, a console command) and all the registry does is
 * tell the dashboard what exists and which fields a condition may name. Keeping that list
 * in one place is what stops the UI's idea of `message.created` drifting from the code's.
 *
 * The fields are the *promise*. A trigger that stops supplying one silently turns every
 * rule that filtered on it into a rule that never matches, so adding a field is free and
 * removing one is a breaking change.
 */
final class TriggerRegistry
{
    public const MEMBER_JOINED = 'member.joined';

    public const MEMBER_LEFT = 'member.left';

    public const ROLE_ASSIGNED = 'member.role_assigned';

    public const MESSAGE_CREATED = 'message.created';

    public const REACTION_ADDED = 'reaction.added';

    /**
     * The other half of a reaction.
     *
     * Phase 1 deliberately didn't fire this — "no rule has asked for one, and a trigger
     * nobody listens to is a promise to keep supplying it". Reaction roles are the rule that
     * asked: un-reacting to give up a badge is what everybody expects, and reading the state
     * back on the next add would mean a badge you can never lose.
     */
    public const REACTION_REMOVED = 'reaction.removed';

    public const COMMAND_INVOKED = 'command.invoked';

    public const BADGE_GRANTED = 'badge.granted';

    public const SCHEDULE_DUE = 'schedule.due';

    /**
     * The fields every trigger about a person supplies — see {@see Subject}.
     *
     * One constant rather than the same four strings repeated per trigger, for the same
     * reason Subject exists: when they were written out by hand they drifted, and a field
     * the builder offers but the context never supplies is a filter that silently never
     * matches.
     */
    private const SUBJECT = ['user_id', 'user_name', 'user_nickname', 'user_email'];

    /**
     * @return array<string, array{label: string, description: string, fields: array<int, string>}>
     */
    public function all(): array
    {
        return [
            self::MEMBER_JOINED => [
                'label' => 'Member joined',
                // Says *approved* rather than "joined", because in Side Chat those are two
                // different moments: an invite files a request, and membership only starts
                // when staff approve it. "Somebody joined" reads as the click, and people
                // testing a welcome message on the click alone conclude it's broken.
                'description' => 'A join request was approved. Opening an invite only files the request — this fires when staff accept it.',
                'fields' => self::SUBJECT,
            ],
            self::MEMBER_LEFT => [
                'label' => 'Member left',
                'description' => 'Somebody left the server, or was removed from it.',
                'fields' => self::SUBJECT,
            ],
            self::ROLE_ASSIGNED => [
                'label' => 'Role assigned',
                'description' => "A member's role changed — the pair before and after are both supplied.",
                'fields' => [...self::SUBJECT, 'role', 'previous_role'],
            ],
            self::MESSAGE_CREATED => [
                'label' => 'Message sent',
                'description' => 'Somebody posted in a channel this server can see. Never fires for a bot’s own message.',
                'fields' => [...self::SUBJECT, 'channel_id', 'channel_name', 'message_id', 'body'],
            ],
            self::REACTION_ADDED => [
                'label' => 'Reaction added',
                'description' => 'Somebody reacted to a message.',
                'fields' => [...self::SUBJECT, 'channel_id', 'message_id', 'emoji', 'message_author_id'],
            ],
            self::REACTION_REMOVED => [
                'label' => 'Reaction removed',
                'description' => 'Somebody took their reaction back.',
                'fields' => [...self::SUBJECT, 'channel_id', 'message_id', 'emoji', 'message_author_id'],
            ],
            self::COMMAND_INVOKED => [
                'label' => 'Command used',
                'description' => 'Somebody ran a slash command here.',
                'fields' => [...self::SUBJECT, 'channel_id', 'command', 'args'],
            ],
            self::SCHEDULE_DUE => [
                'label' => 'A schedule ran',
                'description' => 'One of this server’s recurring posts came due.',
                'fields' => ['schedule_id', 'schedule_name', 'channel_id'],
            ],
            self::BADGE_GRANTED => [
                'label' => 'Badge granted',
                'description' => 'A member was given a badge, by a rule or by hand.',
                'fields' => [...self::SUBJECT, 'badge_id', 'badge_name'],
            ],
        ];
    }

    public function has(string $trigger): bool
    {
        return array_key_exists($trigger, $this->all());
    }

    public function names(): array
    {
        return array_keys($this->all());
    }

    /** The context keys a condition on this trigger may name. Feeds the builder's field list. */
    public function fieldsFor(string $trigger): array
    {
        return $this->all()[$trigger]['fields'] ?? [];
    }
}
