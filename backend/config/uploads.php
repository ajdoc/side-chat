<?php

/**
 * Limits for the chunked upload path — the staging area a large attachment travels through
 * ({@see \App\Http\Controllers\ChunkedUploadController}).
 *
 * `max_bytes` is a policy about disk and patience, not a technical ceiling: the transfer
 * itself is a sequence of small requests, so raising it costs storage rather than reliability.
 * `chunk_kb` is the technical one — a single chunk still has to fit inside PHP's
 * `upload_max_filesize` / `post_max_size` and whatever body limit the web server and any proxy
 * in front of it enforce. The client sends smaller chunks than this ceiling allows.
 *
 * The browser checks the same size limit before it starts, so an impossible file fails
 * instantly rather than after the first chunk — keep `NUXT_PUBLIC_MAX_UPLOAD_MB` in step with
 * `MAX_UPLOAD_MB` if you change it.
 */
return [
    'max_bytes' => (int) env('MAX_UPLOAD_MB', 2048) * 1024 * 1024,
    'chunk_kb' => (int) env('MAX_UPLOAD_CHUNK_KB', 8192),
];
