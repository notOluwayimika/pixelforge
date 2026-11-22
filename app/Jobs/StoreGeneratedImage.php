<?php

namespace App\Jobs;

use App\Models\Generation;
use App\Pixelforge\PixelforgeClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Pixelforge's signed image URLs stop working 24h after completion, so we keep our own copy.
 * Purely cosmetic: failing here never affects status or money.
 */
class StoreGeneratedImage implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [10, 60, 300, 900];

    public function __construct(public readonly string $generationId) {}

    public function handle(PixelforgeClient $pixelforge): void
    {
        $generation = Generation::find($this->generationId);

        if ($generation === null || $generation->image_path !== null || $generation->provider_id === null) {
            return;
        }

        $url = $generation->provider_image_url;

        // Signed URL already expired (e.g. the queue was down): GET returns a fresh one, though it
        // expires at the same time, so this only helps while we're still inside the 24h window.
        if ($url === null || $generation->image_url_expires_at?->isPast()) {
            $url = $pixelforge->getGeneration($generation->provider_id)->imageUrl;
        }

        if ($url === null) {
            return;
        }

        $png = Http::timeout(30)->get($url)->throw()->body();
        $path = "generations/{$generation->id}.png";

        // No per-file visibility: Cloud Object Storage (R2) sets visibility per bucket and rejects ACLs.
        Storage::disk(config('pixelforge.image_disk'))->put($path, $png);
        $generation->update(['image_path' => $path]);
    }
}
