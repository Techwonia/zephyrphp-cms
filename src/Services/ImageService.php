<?php

declare(strict_types=1);

namespace ZephyrPHP\Cms\Services;

class ImageService
{
    /**
     * Generate a thumbnail for an image file.
     *
     * @param string $sourcePath Absolute path to the source image
     * @param int    $maxWidth   Max width for thumbnail
     * @param int    $maxHeight  Max height for thumbnail
     * @param int    $quality    JPEG/WebP quality (1-100)
     * @return string|null Absolute path to the thumbnail, or null on failure
     */
    public static function createThumbnail(string $sourcePath, int $maxWidth = 400, int $maxHeight = 400, int $quality = 80): ?string
    {
        if (!file_exists($sourcePath) || !self::isGdAvailable()) {
            return null;
        }

        $imageInfo = @getimagesize($sourcePath);
        if (!$imageInfo) {
            return null;
        }

        [$origWidth, $origHeight, $imageType] = $imageInfo;

        // Skip if already smaller
        if ($origWidth <= $maxWidth && $origHeight <= $maxHeight) {
            return null;
        }

        // Pre-check memory: estimate bytes needed for source + thumbnail GD images
        if (!self::checkMemoryForImage($origWidth, $origHeight, $imageInfo['channels'] ?? 3)) {
            return null;
        }

        // Calculate new dimensions maintaining aspect ratio
        $ratio = min($maxWidth / $origWidth, $maxHeight / $origHeight);
        $newWidth = (int) round($origWidth * $ratio);
        $newHeight = (int) round($origHeight * $ratio);

        // Create source image resource
        $sourceImage = self::createImageFromFile($sourcePath, $imageType);
        if (!$sourceImage) {
            return null;
        }

        // Create thumbnail
        $thumbImage = imagecreatetruecolor($newWidth, $newHeight);

        // Preserve transparency for PNG/GIF
        if ($imageType === IMAGETYPE_PNG || $imageType === IMAGETYPE_GIF) {
            imagealphablending($thumbImage, false);
            imagesavealpha($thumbImage, true);
            $transparent = imagecolorallocatealpha($thumbImage, 0, 0, 0, 127);
            imagefill($thumbImage, 0, 0, $transparent);
        }

        imagecopyresampled($thumbImage, $sourceImage, 0, 0, 0, 0, $newWidth, $newHeight, $origWidth, $origHeight);

        // Generate thumbnail path
        $dir = dirname($sourcePath);
        $ext = pathinfo($sourcePath, PATHINFO_EXTENSION);
        $basename = pathinfo($sourcePath, PATHINFO_FILENAME);
        $thumbPath = $dir . '/' . $basename . '_thumb.' . $ext;

        // Save thumbnail
        $saved = self::saveImage($thumbImage, $thumbPath, $imageType, $quality);

        imagedestroy($sourceImage);
        imagedestroy($thumbImage);

        return $saved ? $thumbPath : null;
    }

    /**
     * Optimize an image by resizing if it exceeds max dimensions.
     * Modifies the file in place.
     *
     * @return bool True if image was resized
     */
    public static function optimizeImage(string $filePath, int $maxWidth = 1920, int $maxHeight = 1920, int $quality = 85): bool
    {
        if (!file_exists($filePath) || !self::isGdAvailable()) {
            return false;
        }

        $imageInfo = @getimagesize($filePath);
        if (!$imageInfo) {
            return false;
        }

        [$origWidth, $origHeight, $imageType] = $imageInfo;

        // Skip if already within limits
        if ($origWidth <= $maxWidth && $origHeight <= $maxHeight) {
            return false;
        }

        // Pre-check memory: estimate bytes needed for source + resized GD images
        if (!self::checkMemoryForImage($origWidth, $origHeight, $imageInfo['channels'] ?? 3)) {
            return false;
        }

        $ratio = min($maxWidth / $origWidth, $maxHeight / $origHeight);
        $newWidth = (int) round($origWidth * $ratio);
        $newHeight = (int) round($origHeight * $ratio);

        $sourceImage = self::createImageFromFile($filePath, $imageType);
        if (!$sourceImage) {
            return false;
        }

        $resized = imagecreatetruecolor($newWidth, $newHeight);

        if ($imageType === IMAGETYPE_PNG || $imageType === IMAGETYPE_GIF) {
            imagealphablending($resized, false);
            imagesavealpha($resized, true);
            $transparent = imagecolorallocatealpha($resized, 0, 0, 0, 127);
            imagefill($resized, 0, 0, $transparent);
        }

        imagecopyresampled($resized, $sourceImage, 0, 0, 0, 0, $newWidth, $newHeight, $origWidth, $origHeight);

        $saved = self::saveImage($resized, $filePath, $imageType, $quality);

        imagedestroy($sourceImage);
        imagedestroy($resized);

        return $saved;
    }

    private static function createImageFromFile(string $path, int $type): ?\GdImage
    {
        return match ($type) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($path) ?: null,
            IMAGETYPE_PNG => @imagecreatefrompng($path) ?: null,
            IMAGETYPE_GIF => @imagecreatefromgif($path) ?: null,
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? (@imagecreatefromwebp($path) ?: null) : null,
            default => null,
        };
    }

    private static function saveImage(\GdImage $image, string $path, int $type, int $quality): bool
    {
        return match ($type) {
            IMAGETYPE_JPEG => imagejpeg($image, $path, $quality),
            IMAGETYPE_PNG => imagepng($image, $path, (int) round(9 - ($quality / 100 * 9))),
            IMAGETYPE_GIF => imagegif($image, $path),
            IMAGETYPE_WEBP => function_exists('imagewebp') ? imagewebp($image, $path, $quality) : false,
            default => false,
        };
    }

    private static function isGdAvailable(): bool
    {
        return extension_loaded('gd');
    }

    /**
     * Check if there is enough memory available to process an image.
     * Estimates memory needed for GD image resources (source + output).
     *
     * @param int $width  Image width in pixels
     * @param int $height Image height in pixels
     * @param int $channels Number of color channels (3 for RGB, 4 for RGBA)
     * @return bool True if sufficient memory is available
     */
    private static function checkMemoryForImage(int $width, int $height, int $channels = 3): bool
    {
        $memoryLimit = self::getMemoryLimitBytes();
        if ($memoryLimit <= 0) {
            // Unlimited memory or unable to determine — proceed
            return true;
        }

        // GD uses ~(width * height * channels) bytes per image, with ~1.8x overhead
        // We need memory for both source and output images plus existing usage
        $estimatedBytes = (int) ($width * $height * $channels * 1.8 * 2);
        $currentUsage = memory_get_usage(true);
        $available = $memoryLimit - $currentUsage;

        // Require at least 4MB headroom beyond the estimate
        return $available > ($estimatedBytes + 4 * 1024 * 1024);
    }

    /**
     * Parse the PHP memory_limit into bytes.
     */
    private static function getMemoryLimitBytes(): int
    {
        $limit = ini_get('memory_limit');
        if ($limit === false || $limit === '' || $limit === '-1') {
            return 0; // Unlimited
        }

        $limit = trim($limit);
        $value = (int) $limit;
        $unit = strtolower(substr($limit, -1));

        return match ($unit) {
            'g' => $value * 1024 * 1024 * 1024,
            'm' => $value * 1024 * 1024,
            'k' => $value * 1024,
            default => $value,
        };
    }
}
