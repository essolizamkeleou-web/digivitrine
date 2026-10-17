<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default country
    |--------------------------------------------------------------------------
    |
    | ISO 3166-1 alpha-2 code assumed when a request does not give a country.
    | Cities are database rows: this only disambiguates a city slug.
    |
    */

    'default_country' => env('DIGIVITRINE_DEFAULT_COUNTRY', 'TG'),

    /*
    |--------------------------------------------------------------------------
    | Reference data cache
    |--------------------------------------------------------------------------
    |
    | Cache-Control max-age (seconds) of public reference data responses
    | (categories, cities, neighborhoods). 0 disables the header.
    |
    */

    'reference_cache_seconds' => (int) env('DIGIVITRINE_REFERENCE_CACHE_SECONDS', 300),

    /*
    |--------------------------------------------------------------------------
    | Photo gallery
    |--------------------------------------------------------------------------
    |
    | Storage disk of the artisan photos (public in development, an object storage disk later),
    | maximum number of photos per profile (rule RG-06) and maximum upload size in kilobytes.
    |
    */

    'photos_disk' => env('DIGIVITRINE_PHOTOS_DISK', 'public'),

    'photos_max_per_artisan' => (int) env('DIGIVITRINE_PHOTOS_MAX', 6),

    'photos_max_kb' => (int) env('DIGIVITRINE_PHOTOS_MAX_KB', 5120),

];
