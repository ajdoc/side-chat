<?php

namespace App\Http\Resources;

use App\Models\Attachment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Attachment */
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
            /*
             * Ciphertext on disk. The client fetches it, decrypts it in the browser and
             * renders from a blob URL — so `url` below is still where the bytes come from,
             * it just can't be pointed at an <img> directly.
             *
             * `name` and `mime_type` above are placeholders for these; the real ones are
             * sealed in the message envelope. See AttachmentService::describe().
             */
            'encrypted' => $this->isEncrypted(),
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
