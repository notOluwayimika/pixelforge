<?php

return [
    'base_url' => env('PIXELFORGE_BASE_URL', 'https://pixelforge.promptedit.dev/v1'),

    // One key per environment: each key has its own webhook URL, signing secret and usage report.
    'api_key' => env('PIXELFORGE_API_KEY'),
    'webhook_secret' => env('PIXELFORGE_WEBHOOK_SECRET'),

    // Reject webhook signatures older (or newer) than this many seconds, to stop replays.
    'signature_tolerance' => (int) env('PIXELFORGE_SIGNATURE_TOLERANCE', 300),

    // Pixelforge image URLs expire after 24h, so succeeded images are copied here.
    'image_disk' => env('PIXELFORGE_IMAGE_DISK', 'public'),

    // How long to keep retrying a submission that is rate limited / overloaded before giving up.
    'submit_give_up_after_minutes' => (int) env('PIXELFORGE_SUBMIT_GIVE_UP_AFTER', 10),

    // The reconciler polls in-flight generations that haven't changed for this many seconds.
    'reconcile_after_seconds' => (int) env('PIXELFORGE_RECONCILE_AFTER', 120),
];
