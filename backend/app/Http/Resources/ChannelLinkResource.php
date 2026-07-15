<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A link as it appears in the channel's Links tab: the unfurled preview, plus who shared
 * it and the message to jump back to. The extra columns come from the join in
 * LinkPreviewService::forChannel().
 */
class ChannelLinkResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'url' => $this->url,
            'kind' => $this->kind,
            // Null whenever the unfurl failed or hasn't run yet — the UI shows the host instead.
            'title' => $this->title,
            'description' => $this->description,
            'site_name' => $this->site_name,
            'image_url' => $this->image_url,
            'message_id' => $this->shared_in_message_id,
            'thread_id' => $this->shared_in_thread_id,
            'shared_by' => $this->shared_by,
            'shared_at' => $this->shared_at,
        ];
    }
}
