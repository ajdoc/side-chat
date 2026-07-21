<?php

namespace App\Http\Requests\Message;

use Illuminate\Validation\Rule;

/**
 * The `uploads` half of a send — files that came up the chunked path rather than in the request
 * body ({@see \App\Http\Controllers\ChunkedUploadController}). Shared by every surface a message
 * can be posted to (a channel, a thread, a side chat), the way {@see \App\Http\Requests\
 * Whiteboard\StrokeRules} is shared by every board.
 *
 * The id must name a *completed* upload of *yours*: the ids arrive from the client, so neither
 * is taken on trust. Ten per message, matching the direct-attachment limit.
 */
trait UploadRules
{
    /** @return array<string, mixed> */
    protected function uploadRules(): array
    {
        return [
            'uploads' => ['nullable', 'array', 'max:10'],
            'uploads.*' => [
                'string',
                Rule::exists('chunked_uploads', 'uuid')
                    ->where('user_id', $this->user()?->id)
                    ->whereNotNull('completed_at'),
            ],
        ];
    }
}
