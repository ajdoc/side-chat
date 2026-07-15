<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LinkPreviewResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'url' => $this->url,
            'kind' => $this->kind, // 'image' renders inline; 'link' renders a card
            'title' => $this->title,
            'description' => $this->description,
            'site_name' => $this->site_name,
            'image_url' => $this->image_url,
        ];
    }
}
