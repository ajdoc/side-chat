<?php

namespace App\Http\Requests\Search;

use App\Search\SearchFilters;
use App\Search\Term;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Note what this does *not* authorize: the scope filters.
 *
 * Every other request in this app that names a channel authorizes membership of it (see
 * MemberRequest), and this one deliberately doesn't need to. A scope filter here only ever
 * *intersects* with the visible set the queries are built from — asking for `channel_id`
 * of a channel you can't read returns an empty list, not a 403, and never its contents.
 * That is the stronger arrangement: it holds for the ids you didn't name too, which on a
 * search endpoint is nearly all of them.
 */
class SearchRequest extends FormRequest
{
    /** What may be asked for. `all` is the command palette; the rest are the full lists. */
    public const TYPES = [
        'all',
        'messages',
        'channels',
        'conversations',
        'servers',
        // The named places inside a channel. Searchable in their own right because a title
        // is the thing somebody wrote so it could be found again — see SearchService.
        'side_chats',
        'threads',
        'side_chat_groups',
    ];

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'q' => ['required', 'string', 'min:1', 'max:'.Term::MAX_LENGTH],
            'type' => ['sometimes', Rule::in(self::TYPES)],
            'channel_id' => ['sometimes', 'integer'],
            'server_id' => ['sometimes', 'integer'],
            'conversation_id' => ['sometimes', 'integer'],
            'from' => ['sometimes', 'integer'],
            'after' => ['sometimes', 'date'],
            'before' => ['sometimes', 'date'],
            'has' => ['sometimes', Rule::in(SearchFilters::HAS)],
        ];
    }

    public function term(): string
    {
        return Term::normalize($this->validated('q'));
    }

    public function type(): string
    {
        return (string) $this->validated('type', 'all');
    }

    public function filters(): SearchFilters
    {
        return SearchFilters::fromRequest($this);
    }
}
