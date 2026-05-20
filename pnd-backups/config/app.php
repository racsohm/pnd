<?php

return [
    'name'      => env('APP_NAME', 'PND Respaldos'),
    'env'       => env('APP_ENV', 'production'),
    'debug'     => (bool) env('APP_DEBUG', false),
    'url'       => env('APP_URL', 'http://localhost:8090'),
    'timezone'  => env('APP_TIMEZONE', 'America/Mexico_City'),
    'locale'    => env('APP_LOCALE', 'es'),
    'fallback_locale' => 'en',
    'faker_locale'    => 'es_MX',
    'cipher'    => 'AES-256-CBC',
    'key'       => env('APP_KEY'),
    'previous_keys' => [],
    'maintenance' => [
        'driver' => 'file',
    ],
];
