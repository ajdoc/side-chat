<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A channel attachment presented as a Docs-app item, so files shared in chat show up on the
 * Docs shelf alongside its own uploads. Same shape as {@see SpaceDocumentResource} but tagged
 * `source: 'chat'` and carrying its `message_id` — the client uses those to gate actions (a
 * chat file isn't deletable from the shelf and is already in chat, so it offers neither).
 *
 * @mixin \App\Models\Attachment
 */
class AttachmentDocResource extends JsonResource
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
            'kind' => $this->docKind(),
            'source' => 'chat',
            'message_id' => $this->message_id,
            'url' => $this->url(),
            'download_url' => $this->downloadUrl(),
            'uploaded_by' => $this->message?->user
                ? new UserResource($this->message->user)
                : null,
            'created_at' => $this->created_at,
        ];
    }

    /** Same kinds {@see \App\Models\SpaceDocument::kind()} derives, from the attachment. */
    private function docKind(): string
    {
        return match (true) {
            $this->mime_type === 'application/pdf' => 'pdf',
            in_array($this->extension, ['xls', 'xlsx', 'csv'], true) => 'sheet',
            in_array($this->extension, ['doc', 'docx'], true) => 'word',
            default => 'other',
        };
    }
}
