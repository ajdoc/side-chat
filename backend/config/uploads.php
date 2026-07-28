<?php

/**
 * Where attachments live, and how big they're allowed to get.
 *
 * `disk` is the disk *new* files are written to. It is deliberately not `FILESYSTEM_DISK`:
 * that one is the framework's general default, and attachments are a specific enough thing
 * (they outlive the container that received them) to be worth naming on their own. Every
 * attachment row records the disk it was written to and is read back through that, so
 * changing this only affects new files — the ones already stored keep resolving.
 *
 * On a single machine `local` is right. On any host that runs more than one container, or
 * replaces containers on deploy, it is not: a local disk is per-container and ephemeral, so
 * files land on whichever instance served the request and vanish when it's recycled. Point
 * this at `s3` (which reaches any S3-compatible store — R2, B2, Storj — via `AWS_ENDPOINT`)
 * anywhere that's true.
 *
 * `max_bytes` is a policy about storage and patience rather than a technical ceiling. Keep
 * `NUXT_PUBLIC_MAX_UPLOAD_MB` in step with `MAX_UPLOAD_MB` — the browser checks the same
 * limit before it starts, so an impossible file fails instantly instead of part-way up.
 *
 * `chunk_kb` is the technical one, and applies only to the chunked path a local disk uses: a
 * single chunk still has to fit inside PHP's `upload_max_filesize` / `post_max_size` and
 * whatever body limit the web server and any proxy in front of it enforce. Direct-to-bucket
 * uploads bypass all of that, so it doesn't constrain them.
 */
return [
    'disk' => env('ATTACHMENT_DISK', 'local'),
    'max_bytes' => (int) env('MAX_UPLOAD_MB', 2048) * 1024 * 1024,
    'chunk_kb' => (int) env('MAX_UPLOAD_CHUNK_KB', 8192),
];
