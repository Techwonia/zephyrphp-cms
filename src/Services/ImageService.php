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
}
