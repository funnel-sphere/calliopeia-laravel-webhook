<?php

return [
    // API credentials are independent from inbound webhook verification tokens.
    'graphql_url' => env('CALLIOPEIA_GRAPHQL_URL'),
    'appsync_api_key' => env('CALLIOPEIA_APPSYNC_API_KEY'),
    'api_key' => env('CALLIOPEIA_API_KEY'),
    'pull_url' => env('CALLIOPEIA_PULL_URL'),
    'timeout' => (int) env('CALLIOPEIA_TIMEOUT', 60),
    'upload_timeout' => (int) env('CALLIOPEIA_UPLOAD_TIMEOUT', 600),
];
