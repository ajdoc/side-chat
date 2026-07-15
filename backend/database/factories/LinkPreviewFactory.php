<?php

namespace Database\Factories;

use App\Models\LinkPreview;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<\App\Models\LinkPreview> */
class LinkPreviewFactory extends Factory
{
    public function definition(): array
    {
        $url = fake()->url();

        return [
            'url' => $url,
            'url_hash' => LinkPreview::hashFor($url),
            'status' => 'ok',
            'kind' => 'link',
            'title' => fake()->sentence(4),
            'description' => fake()->sentence(),
            'site_name' => fake()->domainName(),
            'image_url' => 'https://example.com/og.png',
            'fetched_at' => now(),
        ];
    }
}
