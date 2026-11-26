<?php

return [
    'pages' => [
        'ensure_pages_exist' => false,
        'paths' => [resource_path('js/Pages')],
        'extensions' => ['vue'],
    ],

    'testing' => [
        'ensure_pages_exist' => true,
        // Older inertia-laravel releases read these keys.
        'page_paths' => [resource_path('js/Pages')],
        'page_extensions' => ['vue'],
    ],
];
