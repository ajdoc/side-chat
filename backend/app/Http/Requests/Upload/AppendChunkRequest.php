<?php

namespace App\Http\Requests\Upload;

use App\Models\ChunkedUpload;
use Illuminate\Foundation\Http\FormRequest;

/**
 * One piece of a staged file. Only the person who opened the upload may add to it (or cancel
 * it) — the uuid is a handle on bytes being assembled under someone's name, not a shared one.
 */
class AppendChunkRequest extends FormRequest
{
    public function authorize(): bool
    {
        $upload = $this->route('upload');
        $user = $this->user();

        return $upload instanceof ChunkedUpload
            && $user !== null
            && $upload->user_id === $user->id;
    }

    /**
     * A chunk arrives as multipart/form-data, where every field is a string — so `index` shows
     * up as "3", not 3. The same subtraction {@see \App\DTOs\Message\SendMessageData} makes for
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
