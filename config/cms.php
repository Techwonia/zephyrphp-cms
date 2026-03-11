<?php

return [
    'table_prefix' => 'cms_',

    'upload_path' => 'storage/cms/uploads',

    'max_upload_size' => 10 * 1024 * 1024, // 10MB

    'allowed_mime_types' => [
        // Images
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
        'image/svg+xml',
        'image/bmp',
        'image/tiff',

        // Documents
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.ms-powerpoint',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'application/rtf',
        'text/plain',
        'text/csv',

        // Audio/Video
        'video/mp4',
        'video/webm',
        'video/ogg',
        'audio/mpeg',
        'audio/wav',
        'audio/ogg',
        'audio/webm',
    ],

    'entries_per_page' => 20,

    'api' => [
        'enabled' => true,
        'per_page' => 15,
        'max_per_page' => 100,
    ],
];
