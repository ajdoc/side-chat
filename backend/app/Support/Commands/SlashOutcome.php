<?php

namespace App\Support\Commands;

/**
 * What a slash command wants to happen: a private note, or a message in the channel.
 *
 * Two shapes because the commands really do divide in two, and the division isn't cosmetic
 * — it's about who the answer belongs to. `/roll 2d6` is a thing the channel is watching
 * for, so it becomes an ordinary message from the person who typed it, with everything that
 * follows from that (it broadcasts, it can be replied to, it survives a reload). `/help` is
 * an answer to one person's question and belongs to nobody else.
 *
 * A handler returns one of these rather than a Message so it never has to know how a
 * message gets created — SendMessageAction already knows, and a handler that persisted and
 * broadcast its own would be a second, quietly diverging copy of the send path.
 */
final readonly class SlashOutcome
{
    private function __construct(
        public ?string $ephemeral,
        public ?string $body,
    ) {}

    /** A note only the person who typed the command sees. */
    public static function note(string $text): self
    {
        return new self(ephemeral: $text, body: null);
    }

    /**
     * Post this in the channel, as the person who typed the command.
     *
     * The command's text is *replaced* rather than added to: the message that lands is the
     * result, not the instruction. Nobody wants to read `/roll 2d6` above the roll.
     */
    public static function say(string $body): self
    {
        return new self(ephemeral: null, body: $body);
    }
}
