<?php

namespace App\Http\Requests\Upload;

use App\DTOs\Message\SendMessageData;
use App\Models\ChunkedUpload;

/**
 * One piece of a staged file. Ownership is inherited from {@see OwnedUploadRequest}; what this
 * adds is the chunk itself.
 */
class AppendChunkRequest extends OwnedUploadRequest
{
    /**
     * A chunk arrives as multipart/form-data, where every field is a string — so `index` shows
     * up as "3", not 3. The same subtraction {@see SendMessageData} makes for
     * `reply_to_id`, for the same reason. Only a genuinely numeric field is cast: anything else
     * is left alone so it fails validation rather than quietly becoming chunk zero.
     */
    protected function prepareForValidation(): void
    {
        $index = $this->input('index');

        if (is_string($index) && ctype_digit($index)) {
            $this->merge(['index' => (int) $index]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // Which piece this is. The server only ever accepts the next one it's expecting.
            'index' => ['required', 'integer', 'min:0'],
            'chunk' => ['required', 'file', 'max:'.ChunkedUpload::maxChunkKb()],
        ];
    }
}
