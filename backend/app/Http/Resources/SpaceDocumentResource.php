<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A Side Space document as the Docs app lists and previews it: its name and size, the derived
 * `kind` that picks a viewer, and the signed inline / download URLs (mirrors how
 * {@see AttachmentResource} hands out an attachment's URLs).
 *
 * @mixin \App\Models\SpaceDocument
 */
class SpaceDocumentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'mime_type' => $this->mime_type,
            'extension' => $this->extension,
            'size' => $this->size,
            'kind' => $this->kind(),
            // 'shelf' — uploaded to the Docs app. Its chat-shared twin is 'chat'
            // ({@see AttachmentDocResource}); the client keys and gates actions on this.
            'source' => 'shelf',
            'message_id' => null,
            'url' => $this->url(),
            'download_url' => $this->downloadUrl(),
            'uploaded_by' => new UserResource($this->whenLoaded('user')),
            'created_at' => $this->created_at,
        ];
    }
}
