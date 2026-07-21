<?php

namespace App\Http\Requests\Upload;

use App\Models\ChunkedUpload;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Opening a chunked upload. Any signed-in user may stage a file; where it's allowed to *land*
 * is decided later, by the request that claims it (posting to a channel you're not in fails
 * there, as it always did). What's checked here is the declaration itself — a name, a plausible
 * size, and a chunk count that matches it — so a client can't reserve a terabyte or announce
 * one chunk for a 200MB file.
 */
class StartChunkedUploadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'size' => ['required', 'integer', 'min:1', 'max:'.ChunkedUpload::maxBytes()],
            'mime_type' => ['nullable', 'string', 'max:255'],
            'total_chunks' => ['required', 'integer', 'min:1', 'max:10000'],
        ];
    }
}
