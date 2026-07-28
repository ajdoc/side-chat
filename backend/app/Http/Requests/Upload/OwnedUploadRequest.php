<?php

namespace App\Http\Requests\Upload;

use App\Models\ChunkedUpload;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Acting on an upload someone already opened, with nothing to validate beyond who's asking.
 *
 * The uuid is a handle on bytes being assembled under one person's name, not a shared one, so
 * only the user who opened the upload may finish it or bin it. That check is the whole request —
 * {@see AppendChunkRequest} extends this to add the body a chunk needs, and finishing or
 * cancelling carry no body at all.
 */
class OwnedUploadRequest extends FormRequest
{
    public function authorize(): bool
    {
        $upload = $this->route('upload');
        $user = $this->user();

        return $upload instanceof ChunkedUpload
            && $user !== null
            && $upload->user_id === $user->id;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }
}
