<?php

use App\Http\Middleware\ApiDocsAccess;

return [
    'api_path' => 'api/v1',

    'api_domain' => null,

    'export_path' => 'openapi/openapi.json',

    'cache' => [
        'key' => 'schoolos.openapi',
        'store' => 'file',
    ],

    'info' => [
        'version' => env('API_VERSION', '0.1.0'),
        'description' => 'SchoolOS foundation API contract. Product-domain routes remain provisional until their owning slices are approved.',
    ],

    'ui' => [
        'title' => 'SchoolOS API Documentation',
    ],

    'dev_tools' => [
        'enabled' => env('SCRAMBLE_DEV_TOOLS', env('APP_DEBUG', false)),
    ],

    'renderer' => 'elements',

    'renderers' => [
        'elements' => [
            'view' => 'scramble::docs',
            'theme' => 'light',
            'hideTryIt' => false,
            'hideSchemas' => false,
            'logo' => '',
            'tryItCredentialsPolicy' => 'omit',
            'layout' => 'responsive',
            'router' => 'hash',
        ],
    ],

    'servers' => null,

    'enum_cases_description_strategy' => 'description',

    'enum_cases_names_strategy' => false,

    'flatten_deep_query_parameters' => true,

    'middleware' => [
        'web',
        ApiDocsAccess::class,
    ],

    'extensions' => [],

    'security_strategy' => null,

    'docs_enabled' => env('SCRAMBLE_DOCS_ENABLED', false),
];
