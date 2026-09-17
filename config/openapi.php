<?php

return [

    // Shown in the generated spec's info block.
    'title' => env('APP_NAME', 'Laravel').' API',
    'version' => '1.0.0',
    'description' => '',

    'servers' => [
        ['url' => env('APP_URL', 'http://localhost')],
    ],

    // Route URI patterns to include/exclude, matched with Str::is() (supports '*').
    'include' => ['api/*'],
    'exclude' => [],

    // Where the UI and raw spec JSON are served from.
    'ui_route' => 'api/documentation',
    'spec_route' => 'api/documentation.json',

    // Where `php artisan openapi:generate` writes the static spec file.
    'output_path' => storage_path('app/openapi.json'),

    'security_schemes' => [
        'bearerAuth' => [
            'type' => 'http',
            'scheme' => 'bearer',
            'bearerFormat' => 'JWT',
        ],
    ],

];
