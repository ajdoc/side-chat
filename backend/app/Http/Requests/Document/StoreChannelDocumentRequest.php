<?php

namespace App\Http\Requests\Document;

use App\Http\Requests\MemberRequest;

/**
 * Upload a document to a channel's Docs app. A channel has no roster, so membership is the
 * whole gate (via {@see MemberRequest}); the body is the file itself.
 */
class StoreChannelDocumentRequest extends MemberRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return DocumentRules::upload();
    }
}
