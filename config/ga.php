<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cache TTL (seconds)
    |--------------------------------------------------------------------------
    */

    'cache_ttl_core' => (int) env('GA_CACHE_TTL_CORE', 3600),
    'cache_ttl_realtime' => (int) env('GA_CACHE_TTL_REALTIME', 60),
    'cache_ttl_funnel' => (int) env('GA_CACHE_TTL_FUNNEL', 7200),

    /*
    |--------------------------------------------------------------------------
    | Token Encryption Key
    |--------------------------------------------------------------------------
    */

    'token_encryption_key' => env('GA_TOKEN_ENCRYPTION_KEY'),

];
