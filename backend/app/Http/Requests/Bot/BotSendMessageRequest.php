<?php

namespace App\Http\Requests\Bot;

use App\DTOs\Message\SendMessageData;
use App\Http\Requests\MemberRequest;
use App\Models\Bot;
use Illuminate\Validation\Rule;

/**
 * A bot posting into a channel.
 *
 * Membership is inherited from {@link MemberRequest} — the bot's account is on the roster
 * like anyone else, so a private channel it hasn't been added to refuses it for exactly
 * the same reason it refuses a person. On top of that the channel has to belong to the
 * server the token was issued for: without that, one leaked token would reach every
 * channel its account had ever been added to, in any server.
 *
 * No attachments and no GIFs, deliberately — a bot sends text. File upload from a
 * long-lived credential is its own problem (quota, scanning, who owns the bytes) and it
 * can wait until something needs it.
 */
class BotSendMessageRequest extends MemberRequest
{
    public function authorize(): bool
    {
        $bot = $this->attributes->get('bot');
        $channel = $this->route('channel');

        if (! $bot instanceof Bot || $channel === null || $channel->server_id !== $bot->server_id) {
            return false;
        }

        return parent::authorize();
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $channel = $this->route('channel');

        return array_merge(SendMessageData::validationRules(), [
            'body' => ['required', 'string', 'max:2000'],
            'reply_to_id' => [
                'nullable',
                Rule::exists('messages', 'id')
                    ->where('channel_id', $channel->id)
                    ->whereNull('thread_id'),
            ],
        ]);
    }
}
