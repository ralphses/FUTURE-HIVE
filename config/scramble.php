<?php

use App\Http\Middleware\ApiDocsAccess;
use App\Support\OpenApi\UnknownTypeToSchema;

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
        'description' => 'SchoolOS API contract. Implemented routes are generated from the canonical runtime route registry and include frontend flow metadata. This read-only contract is the frontend integration source of truth.',
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

    'extensions' => [
        UnknownTypeToSchema::class,
    ],

    'security_strategy' => null,

    'docs_enabled' => env('SCRAMBLE_DOCS_ENABLED', false),
];
