<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    // 'attachments/*' and 'space-documents/*' are the signed file routes (routes/web.php).
    // They must allow cross-origin requests because the Docs app fetch()es a spreadsheet's or
    // Word doc's bytes to render them (SheetJS / mammoth) — unlike a PDF iframe or an <img>,
    // a fetch() is gated by CORS. Serving from a non-CORS path is what triggers the error.
    'paths' => ['api/*', 'attachments/*', 'space-documents/*', 'broadcasting/auth', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    // Comma-separated list of allowed browser origins, from env. Defaults to the
    // local Nuxt dev server; in production set CORS_ALLOWED_ORIGINS to the
    // frontend's Render URL, e.g. https://sidechat-web.onrender.com
    'allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('CORS_ALLOWED_ORIGINS', 'http://localhost:3000')),
    ))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
