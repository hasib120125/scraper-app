<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default scraper settings
    |--------------------------------------------------------------------------
    */

    'delay_ms'    => env('SCRAPER_DELAY_MS',    1500),
    'max_retries' => env('SCRAPER_MAX_RETRIES', 3),
    'timeout'     => env('SCRAPER_TIMEOUT',     30),
    'proxy'       => env('SCRAPER_PROXY',       null),
    'output_dir'  => env('SCRAPER_OUTPUT_DIR',  storage_path('exports')),
    'follow_js'   => env('SCRAPER_FOLLOW_JS',   false),

    /*
    |--------------------------------------------------------------------------
    | Per-site overrides
    |--------------------------------------------------------------------------
    | You can specify site-specific settings here.
    | Key: domain (without www/https)
    */

    'site_overrides' => [
        // 'spandexhouse.com' => ['delay_ms' => 2000, 'proxy' => 'http://...'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Output CSV columns (order matters)
    |--------------------------------------------------------------------------
    */

    'csv_columns' => [
        'product_title',
        'full_description',
        'short_description',
        'sku',
        'price',
        'compare_at_price',
        'category',
        'sub_category',
        'brand',
        'variants',
        'stock_status',
        'fabric_specs',
        'min_order_qty',
        'product_url',
        'image_urls',
        'image_alt_texts',
        'tags',
    ],

];