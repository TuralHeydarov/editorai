<?php

return [
    'enabled' => (bool) env('SHARED_SSO_ENABLED', false),
    'linking_enabled' => (bool) env('SHARED_SSO_LINKING_ENABLED', false),
    'issuer' => env('SHARED_SSO_ISSUER', 'https://id.tural.ai/auth/v1'),
    'client_id' => env('SHARED_SSO_CLIENT_ID', ''),
    'client_secret' => env('SHARED_SSO_CLIENT_SECRET', ''),
    'api_key' => env('SHARED_SSO_API_KEY', ''),
    'callback' => rtrim(env('APP_URL', ''), '/').'/api/auth/sso/callback',
    'audience' => 'authenticated',
];
