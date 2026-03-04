<?php

return [
    'table_prefix' => 'cms_',

    'upload_path' => 'storage/cms/uploads',

    'max_upload_size' => 10 * 1024 * 1024, // 10MB

    'allowed_mime_types' => [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
        'image/svg+xml',
        'application/pdf',
        'text/plain',
        'text/csv',
    ],

    'entries_per_page' => 20,

    'api' => [
        'enabled' => true,
        'per_page' => 15,
        'max_per_page' => 100,
    ],
];
