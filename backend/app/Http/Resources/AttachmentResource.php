<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Attachment */
class AttachmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'message_id' => $this->message_id,
            'name' => $this->name,
            'mime_type' => $this->mime_type,
            'extension' => $this->extension,
            'size' => $this->size,
            'is_image' => $this->isImage(),
            'is_pdf' => $this->isPdf(),
            'is_gif' => $this->isGif(),
            // Short-lived signed URLs; the files live on a private disk.
            'url' => $this->url(),
            'download_url' => $this->downloadUrl(),
            'uploaded_by' => $this->whenLoaded('message', fn () => $this->message->user?->name),
            'created_at' => $this->created_at,
        ];
    }
}
