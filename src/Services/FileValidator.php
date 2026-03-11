<?php

declare(strict_types=1);

namespace ZephyrPHP\Cms\Services;

/**
 * Centralized file upload validation service.
 *
 * Validates uploads using real MIME detection (finfo), extension whitelisting,
 * dangerous extension blacklisting, and configurable per-field accept presets.
 */
class FileValidator
{
    /**
     * Preset groups mapping preset name => array of MIME types.
     */
    private static array $presets = [
        'images' => [
            'image/jpeg',
            'image/png',
            'image/gif',
            'image/webp',
            'image/svg+xml',
            'image/bmp',
            'image/tiff',
        ],
        'documents' => [
            'application/pdf',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.ms-powerpoint',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'text/plain',
            'text/csv',
            'application/rtf',
        ],
        'media' => [
            'image/jpeg',
            'image/png',
            'image/gif',
            'image/webp',
            'image/svg+xml',
            'video/mp4',
            'video/webm',
            'video/ogg',
            'audio/mpeg',
            'audio/wav',
            'audio/ogg',
            'audio/webm',
        ],
        'all' => [], // resolved at runtime from global config
    ];

    /**
     * Map of MIME types to their allowed file extensions.
     */
    private static array $mimeToExtensions = [
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/png' => ['png'],
        'image/gif' => ['gif'],
        'image/webp' => ['webp'],
        'image/svg+xml' => ['svg'],
        'image/bmp' => ['bmp'],
        'image/tiff' => ['tif', 'tiff'],
        'application/pdf' => ['pdf'],
        'application/msword' => ['doc'],
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => ['docx'],
        'application/vnd.ms-excel' => ['xls'],
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => ['xlsx'],
        'application/vnd.ms-powerpoint' => ['ppt'],
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => ['pptx'],
        'application/rtf' => ['rtf'],
        'text/plain' => ['txt'],
        'text/csv' => ['csv'],
        'video/mp4' => ['mp4'],
        'video/webm' => ['webm'],
        'video/ogg' => ['ogv'],
        'audio/mpeg' => ['mp3'],
        'audio/wav' => ['wav'],
        'audio/ogg' => ['ogg'],
        'audio/webm' => ['weba'],
    ];

    /**
     * Extensions that are ALWAYS blocked regardless of settings.
     * These can be used for server-side code execution.
     */
    private static array $dangerousExtensions = [
        'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phar',
        'exe', 'bat', 'cmd', 'sh', 'bash', 'cgi', 'pl', 'py', 'rb', 'jsp',
        'asp', 'aspx', 'com', 'scr', 'vbs', 'vbe', 'wsf', 'wsh', 'ps1',
        'htaccess', 'htpasswd', 'ini', 'env', 'config',
    ];

    /**
     * Validate an uploaded file.
     *
     * @param array      $file         The $_FILES entry for this upload
     * @param array|null $fieldOptions Field-level options (accept_preset, accept_custom, max_file_size)
     * @return array     ['valid' => bool, 'error' => ?string, 'mime' => ?string]
     */
    public static function validate(array $file, ?array $fieldOptions = null): array
    {
        // 1. Check upload error
        if (!isset($file['tmp_name']) || $file['error'] !== UPLOAD_ERR_OK) {
            $errorMsg = self::uploadErrorMessage($file['error'] ?? UPLOAD_ERR_NO_FILE);
            return ['valid' => false, 'error' => $errorMsg, 'mime' => null];
        }

        // 2. Check file actually exists and is an uploaded file
        if (!is_uploaded_file($file['tmp_name'])) {
            return ['valid' => false, 'error' => 'Invalid upload.', 'mime' => null];
        }

        // 3. Check dangerous extensions (ALWAYS blocked)
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (in_array($extension, self::$dangerousExtensions, true)) {
            return ['valid' => false, 'error' => "File type '.{$extension}' is not allowed for security reasons.", 'mime' => null];
        }

        // Also check for double extensions like "shell.php.jpg" — block if any segment is dangerous
        $nameParts = explode('.', strtolower($file['name']));
        array_shift($nameParts); // remove filename
        foreach ($nameParts as $part) {
            if (in_array($part, self::$dangerousExtensions, true)) {
                return ['valid' => false, 'error' => "File contains a blocked extension '.{$part}'.", 'mime' => null];
            }
        }

        // 4. Check file size
        $maxSize = self::getMaxSize($fieldOptions);
        if ($file['size'] > $maxSize) {
            $maxMB = round($maxSize / 1024 / 1024, 1);
            return ['valid' => false, 'error' => "File size exceeds {$maxMB}MB limit.", 'mime' => null];
        }

        // 5. Detect REAL MIME type using finfo (reads file bytes, not client header)
        $realMime = self::detectMimeType($file['tmp_name']);
        if (!$realMime) {
            return ['valid' => false, 'error' => 'Could not determine file type.', 'mime' => null];
        }

        // 6. Resolve allowed MIME types based on field options or global config
        $allowedMimes = self::resolveAllowedMimes($fieldOptions);

        // 7. Check MIME against allowed list
        if (!in_array($realMime, $allowedMimes, true)) {
            return ['valid' => false, 'error' => "File type '{$realMime}' is not allowed.", 'mime' => $realMime];
        }

        // 8. Verify extension matches the detected MIME type
        $allowedExtensions = self::$mimeToExtensions[$realMime] ?? [];
        if (!empty($allowedExtensions) && !in_array($extension, $allowedExtensions, true)) {
            return [
                'valid' => false,
                'error' => "File extension '.{$extension}' does not match detected type '{$realMime}'.",
                'mime' => $realMime,
            ];
        }

        // 9. Extra validation for images: verify with getimagesize()
        if (str_starts_with($realMime, 'image/') && $realMime !== 'image/svg+xml') {
            $imageInfo = @getimagesize($file['tmp_name']);
            if ($imageInfo === false) {
                return ['valid' => false, 'error' => 'File claims to be an image but is not a valid image file.', 'mime' => $realMime];
            }
        }

        // 10. SVG sanitization check
        if ($realMime === 'image/svg+xml') {
            $svgError = self::validateSvg($file['tmp_name']);
            if ($svgError) {
                return ['valid' => false, 'error' => $svgError, 'mime' => $realMime];
            }
        }

        return ['valid' => true, 'error' => null, 'mime' => $realMime];
    }

    /**
     * Get the list of MIME types for a given preset name.
     */
    public static function getPresetMimes(string $preset): array
    {
        if ($preset === 'all') {
            return self::getAllConfiguredMimes();
        }

        return self::$presets[$preset] ?? [];
    }

    /**
     * Get all available preset names.
     */
    public static function getPresetNames(): array
    {
        return ['images', 'documents', 'media', 'all', 'custom'];
    }

    /**
     * Get all available MIME types with labels for UI display.
     */
    public static function getMimeTypeOptions(): array
    {
        return [
            'Images' => [
                'image/jpeg' => 'JPEG Image (.jpg, .jpeg)',
                'image/png' => 'PNG Image (.png)',
                'image/gif' => 'GIF Image (.gif)',
                'image/webp' => 'WebP Image (.webp)',
                'image/svg+xml' => 'SVG Image (.svg)',
                'image/bmp' => 'BMP Image (.bmp)',
                'image/tiff' => 'TIFF Image (.tif, .tiff)',
            ],
            'Documents' => [
                'application/pdf' => 'PDF Document (.pdf)',
                'application/msword' => 'Word Document (.doc)',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'Word Document (.docx)',
                'application/vnd.ms-excel' => 'Excel Spreadsheet (.xls)',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'Excel Spreadsheet (.xlsx)',
                'application/vnd.ms-powerpoint' => 'PowerPoint (.ppt)',
                'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'PowerPoint (.pptx)',
                'application/rtf' => 'Rich Text Format (.rtf)',
                'text/plain' => 'Plain Text (.txt)',
                'text/csv' => 'CSV File (.csv)',
            ],
            'Media' => [
                'video/mp4' => 'MP4 Video (.mp4)',
                'video/webm' => 'WebM Video (.webm)',
                'video/ogg' => 'OGG Video (.ogv)',
                'audio/mpeg' => 'MP3 Audio (.mp3)',
                'audio/wav' => 'WAV Audio (.wav)',
                'audio/ogg' => 'OGG Audio (.ogg)',
                'audio/webm' => 'WebM Audio (.weba)',
            ],
        ];
    }

    /**
     * Get the HTML accept attribute value for a given preset or custom MIME list.
     * Used in <input type="file" accept="...">
     */
    public static function getAcceptAttribute(?array $fieldOptions): string
    {
        if (!$fieldOptions) {
            return '';
        }

        $preset = $fieldOptions['accept_preset'] ?? 'all';

        if ($preset === 'custom') {
            $mimes = $fieldOptions['accept_custom'] ?? [];
        } elseif ($preset === 'all') {
            return '';
        } else {
            $mimes = self::getPresetMimes($preset);
        }

        if (empty($mimes)) {
            return '';
        }

        // Convert to extensions for better browser compatibility
        $extensions = [];
        foreach ($mimes as $mime) {
            $exts = self::$mimeToExtensions[$mime] ?? [];
            foreach ($exts as $ext) {
                $extensions[] = '.' . $ext;
            }
        }

        return implode(',', array_unique($extensions));
    }

    // ========================================================================
    // INTERNAL METHODS
    // ========================================================================

    /**
     * Detect MIME type using finfo (reads actual file bytes).
     */
    private static function detectMimeType(string $filePath): ?string
    {
        if (!file_exists($filePath)) {
            return null;
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($filePath);

        if ($mime === false || $mime === '') {
            return null;
        }

        // finfo sometimes returns generic types for office docs — normalize
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        if ($mime === 'application/zip' || $mime === 'application/octet-stream') {
            $mime = self::normalizeOfficeMime($filePath, $extension, $mime);
        }

        return $mime;
    }

    /**
     * Office documents (.docx, .xlsx, .pptx) are ZIP files internally.
     * finfo detects them as application/zip, so we check the extension to return the correct MIME.
     */
    private static function normalizeOfficeMime(string $filePath, string $extension, string $detectedMime): string
    {
        $officeMap = [
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'doc' => 'application/msword',
            'xls' => 'application/vnd.ms-excel',
            'ppt' => 'application/vnd.ms-powerpoint',
        ];

        if (isset($officeMap[$extension])) {
            // Extra safety: for ZIP-based formats, verify they contain expected content
            if (in_array($extension, ['docx', 'xlsx', 'pptx']) && class_exists('ZipArchive')) {
                $zip = new \ZipArchive();
                if ($zip->open($filePath) === true) {
                    $hasContentTypes = $zip->locateName('[Content_Types].xml') !== false;
                    $zip->close();
                    if ($hasContentTypes) {
                        return $officeMap[$extension];
                    }
                }
                // If ZIP doesn't contain expected Office content, keep original MIME
                return $detectedMime;
            }
            return $officeMap[$extension];
        }

        return $detectedMime;
    }

    /**
     * Resolve allowed MIME types from field options or global config.
     */
    private static function resolveAllowedMimes(?array $fieldOptions): array
    {
        if ($fieldOptions) {
            $preset = $fieldOptions['accept_preset'] ?? null;

            if ($preset === 'custom') {
                $customMimes = $fieldOptions['accept_custom'] ?? [];
                if (!empty($customMimes)) {
                    // Intersect with global config to prevent bypassing global restrictions
                    $globalMimes = self::getAllConfiguredMimes();
                    return array_values(array_intersect($customMimes, $globalMimes));
                }
            } elseif ($preset && $preset !== 'all' && isset(self::$presets[$preset])) {
                $presetMimes = self::$presets[$preset];
                // Intersect with global config
                $globalMimes = self::getAllConfiguredMimes();
                return array_values(array_intersect($presetMimes, $globalMimes));
            }
        }

        // Fallback: use global config
        return self::getAllConfiguredMimes();
    }

    /**
     * Get all MIME types from global CMS config.
     */
    private static function getAllConfiguredMimes(): array
    {
        if (function_exists('config')) {
            $mimes = config('cms.allowed_mime_types', []);
            if (!empty($mimes)) {
                return $mimes;
            }
        }

        // Hardcoded fallback if config unavailable
        return [
            'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml',
            'image/bmp', 'image/tiff',
            'application/pdf',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.ms-powerpoint',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'application/rtf',
            'text/plain', 'text/csv',
            'video/mp4', 'video/webm', 'video/ogg',
            'audio/mpeg', 'audio/wav', 'audio/ogg', 'audio/webm',
        ];
    }

    /**
     * Get max upload size from field options or global config.
     */
    private static function getMaxSize(?array $fieldOptions): int
    {
        // Per-field override
        if ($fieldOptions && isset($fieldOptions['max_file_size'])) {
            return (int) $fieldOptions['max_file_size'];
        }

        // Global config
        if (function_exists('config')) {
            return (int) config('cms.max_upload_size', 10 * 1024 * 1024);
        }

        return 10 * 1024 * 1024; // 10MB default
    }

    /**
     * Validate SVG content for dangerous elements/attributes.
     */
    private static function validateSvg(string $filePath): ?string
    {
        $content = file_get_contents($filePath);
        if ($content === false) {
            return 'Could not read SVG file.';
        }

        // Block <script> tags
        if (preg_match('/<script[\s>]/i', $content)) {
            return 'SVG contains script tags which are not allowed.';
        }

        // Block event handler attributes (on*)
        if (preg_match('/\bon\w+\s*=/i', $content)) {
            return 'SVG contains event handler attributes which are not allowed.';
        }

        // Block javascript: URIs
        if (preg_match('/(?:href|xlink:href|src)\s*=\s*["\']?\s*javascript\s*:/i', $content)) {
            return 'SVG contains javascript URIs which are not allowed.';
        }

        // Block data: URIs (can embed executable content)
        if (preg_match('/(?:href|xlink:href|src)\s*=\s*["\']?\s*data\s*:/i', $content)) {
            return 'SVG contains data URIs which are not allowed.';
        }

        // Block <foreignObject> (can embed HTML/JS)
        if (preg_match('/<foreignObject[\s>]/i', $content)) {
            return 'SVG contains foreignObject elements which are not allowed.';
        }

        // Block <use> with external references
        if (preg_match('/<use[^>]+(?:href|xlink:href)\s*=\s*["\'](?!#)/i', $content)) {
            return 'SVG contains external use references which are not allowed.';
        }

        return null;
    }

    /**
     * Human-readable upload error messages.
     */
    private static function uploadErrorMessage(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE => 'File exceeds the server upload size limit.',
            UPLOAD_ERR_FORM_SIZE => 'File exceeds the form upload size limit.',
            UPLOAD_ERR_PARTIAL => 'File was only partially uploaded.',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded.',
            UPLOAD_ERR_NO_TMP_DIR => 'Server missing temporary folder.',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
            UPLOAD_ERR_EXTENSION => 'Upload blocked by a PHP extension.',
            default => 'Unknown upload error.',
        };
    }
}
