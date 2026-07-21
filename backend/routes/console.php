<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Files staged for a large upload but never claimed by a message. See PruneChunkedUploads.
Schedule::command('uploads:prune')->hourly();
