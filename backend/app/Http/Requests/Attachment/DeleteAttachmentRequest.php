<?php

namespace App\Http\Requests\Attachment;

use App\Models\Attachment;
use Illuminate\Foundation\Http\FormRequest;

/** Only the sender of the owning message may delete its attachments. */
class DeleteAttachmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $attachment = $this->route('attachment');

        if (! $attachment instanceof Attachment || $this->user() === null) {
            return false;
        }

        $attachment->loadMissing('message');

        return $attachment->message->user_id === $this->user()->id;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }
}
