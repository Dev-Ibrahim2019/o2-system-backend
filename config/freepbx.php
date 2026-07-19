<?php

return [
    'base_url' => env('FREEPBX_BASE_URL', 'http://192.168.2.250:83'),
    'auth_url' => env('FREEPBX_AUTH_URL', null),
    'graphql_url' => env('FREEPBX_GRAPHQL_URL', null),
    'rest_url' => env('FREEPBX_REST_URL', null),
    'client_id' => env('FREEPBX_CLIENT_ID', ''),
    'client_secret' => env('FREEPBX_CLIENT_SECRET', ''),
    'scope' => env('FREEPBX_SCOPE', 'api'),
    'grant_type' => env('FREEPBX_GRANT_TYPE', 'client_credentials'),
];
