<?php

namespace App\Http\Requests;

use App\Models\Message;
use Illuminate\Foundation\Http\FormRequest;

/** Only the sender may edit or delete their own message (business rule 1). */
abstract class MessageOwnerRequest extends FormRequest
{
    public function authorize(): bool
    {
        $message = $this->route('message');

        return $message instanceof Message
            && $this->user() !== null
            && $message->user_id === $this->user()->id;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }
}
