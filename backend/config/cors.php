<?php

/*
 * CORS (Cross-Origin Resource Sharing) configuration.
 *
 * Required for:
 *   - /api/v1/*        — called by web frontend on a different subdomain
 *                        (enstorage.enpiistudio.com → api-enstorage.enpiistudio.com)
 *   - /broadcasting/auth — pusher-js (frontend) subscribes to private Reverb
 *                        channels. The WS connection itself goes over WSS, but
 *                        the auth handshake is an HTTPS POST that needs CORS
 *                        headers so the browser lets the response back into
 *                        pusher-js's `auth` callback.
 *   - /s/{token}        — public share pages are often linked cross-origin
 *                        and embed assets.
 *
 * If you only want to allow the production frontend origin, replace '*' with
 * a comma-separated list:
 *   'allowed_origins' => ['https://enstorage.enpiistudio.com'],
 * But for development with curl/Postman/Insomnia, '*' is more forgiving.
 */

return [
    'paths' => ['*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => [
        'https://enstorage.enpiistudio.com',
        'https://api-enstorage.enpiistudio.com',
        'http://localhost:3000',
        'http://localhost:3001',
    ],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,
];
