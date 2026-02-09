<?php

declare(strict_types=1);

/**
 * Image upload processing using the GD library.
 *
 * Handles validation, resizing, EXIF stripping, thumbnail generation, and
 * file management for user-uploaded photos.
 *
 * Storage layout:
 *   www/uploads/temp/{userId}/          — temporary storage before moderation
 *   /usr/local/www/peoples.ru/www/photo/{path}/ — production storage after approval
 *
 * Generated files per upload:
 *   {timestamp}_{hex}.{ext}            — main image (max 1200px)
 *   thumb_{timestamp}_{hex}.{ext}      — thumbnail (150x150, cropped)
 *   card_{timestamp}_{hex}.{ext}       — card image (300x200, cropped)
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/encoding.php';
require_once __DIR__ . '/validation.php';

/** Maximum dimension for the main image (width or height) */
define('UPLOAD_MAX_DIMENSION', 1200);

/** Thumbnail dimensions */
define('UPLOAD_THUMB_WIDTH', 150);
define('UPLOAD_THUMB_HEIGHT', 150);

/** Card image dimensions */
define('UPLOAD_CARD_WIDTH', 300);
define('UPLOAD_CARD_HEIGHT', 200);

/** JPEG quality for saved images */
define('UPLOAD_JPEG_QUALITY', 85);

/** WebP quality for saved images */
define('UPLOAD_WEBP_QUALITY', 80);

/** PNG compression level (0-9, 6 is a good balance) */
define('UPLOAD_PNG_COMPRESSION', 6);

/**
 * Process an uploaded image file.
 *
 * Validates the file, strips EXIF metadata, resizes to max 1200px on the
 * longest side, generates a 150x150 thumbnail and a 300x200 card image,
 * and saves all three to the temp directory.
 *
 * @param array $file Entry from $_FILES
 * @param int $userId Uploader's user ID
 * @param int|null $submissionId Associated submission ID (optional)
 * @return array File info: [file_name, file_path, file_size, mime_type, width, height, thumb_path, card_path]
 * @throws RuntimeException On processing failure
 * @throws InvalidArgumentException On validation failure
 */
function processUpload(array $file, int $userId, ?int $submissionId = null): array
{
    // Validate the uploaded file
    $validation = validateUploadedFile($file);
    if ($validation !== true) {
        throw new InvalidArgumentException($validation);
    }

    // Determine the actual MIME type
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo->file($file['tmp_name']);

    // Map MIME types to extensions and GD functions
    $typeMap = [
        'image/jpeg' => ['ext' => 'jpg', 'create' => 'imagecreatefromjpeg'],
        'image/png'  => ['ext' => 'png', 'create' => 'imagecreatefrompng'],
        'image/webp' => ['ext' => 'webp', 'create' => 'imagecreatefromwebp'],
    ];

    if (!isset($typeMap[$mimeType])) {
        throw new InvalidArgumentException("Unsupported image type: {$mimeType}");
    }

    $ext = $typeMap[$mimeType]['ext'];
    $createFunc = $typeMap[$mimeType]['create'];

    // Create upload directory
    $uploadDir = dirname(__DIR__) . '/uploads/temp/' . $userId;
    if (!is_dir($uploadDir)) {
        if (!mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
            throw new RuntimeException('Failed to create upload directory');
        }
    }

    // Generate unique filename
    $baseName = time() . '_' . bin2hex(random_bytes(4));
    $mainFilename = $baseName . '.' . $ext;
    $thumbFilename = 'thumb_' . $baseName . '.' . $ext;
    $cardFilename = 'card_' . $baseName . '.' . $ext;

    $mainPath = $uploadDir . '/' . $mainFilename;
    $thumbPath = $uploadDir . '/' . $thumbFilename;
    $cardPath = $uploadDir . '/' . $cardFilename;

    // Load the source image using the appropriate GD function
    $source = @$createFunc($file['tmp_name']);
    if ($source === false) {
        throw new RuntimeException('Failed to read image file. The file may be corrupted.');
    }

    // Get original dimensions
    $origWidth = imagesx($source);
    $origHeight = imagesy($source);

    if ($origWidth === 0 || $origHeight === 0) {
        imagedestroy($source);
        throw new RuntimeException('Image has invalid dimensions');
    }

    // Handle EXIF orientation for JPEG images
    if ($mimeType === 'image/jpeg' && function_exists('exif_read_data')) {
        $exif = @exif_read_data($file['tmp_name']);
        if ($exif !== false && isset($exif['Orientation'])) {
            $source = applyExifOrientation($source, (int) $exif['Orientation']);
            // Recalculate dimensions after rotation
            $origWidth = imagesx($source);
            $origHeight = imagesy($source);
        }
    }

    // Resize main image to max dimension
    $mainImage = resizeImage($source, $origWidth, $origHeight, UPLOAD_MAX_DIMENSION);
    $finalWidth = imagesx($mainImage);
    $finalHeight = imagesy($mainImage);

    // Generate thumbnail (center-cropped)
    $thumbImage = cropResize($source, $origWidth, $origHeight, UPLOAD_THUMB_WIDTH, UPLOAD_THUMB_HEIGHT);

    // Generate card image (center-cropped)
    $cardImage = cropResize($source, $origWidth, $origHeight, UPLOAD_CARD_WIDTH, UPLOAD_CARD_HEIGHT);

    // Save all three images (this also strips EXIF since GD creates new images)
    saveImage($mainImage, $mainPath, $mimeType);
    saveImage($thumbImage, $thumbPath, $mimeType);
    saveImage($cardImage, $cardPath, $mimeType);

    // Get final file size
    $fileSize = filesize($mainPath);

    // Clean up GD resources
    imagedestroy($source);
    if ($mainImage !== $source) {
        imagedestroy($mainImage);
    }
    imagedestroy($thumbImage);
    imagedestroy($cardImage);

    // Build relative paths for storage/API responses
    $relativeDir = '/uploads/temp/' . $userId;

    return [
        'file_name'  => $mainFilename,
        'file_path'  => $relativeDir . '/' . $mainFilename,
        'file_size'  => $fileSize ?: 0,
        'mime_type'  => $mimeType,
        'width'      => $finalWidth,
        'height'     => $finalHeight,
        'thumb_path' => $relativeDir . '/' . $thumbFilename,
        'card_path'  => $relativeDir . '/' . $cardFilename,
    ];
}

/**
 * Move an approved upload from temp storage to the production photo directory.
 *
 * Looks up the person's URL path via persons.AllUrlInSity and moves the file
 * (plus its thumbnails) to the production photo directory on the main site.
 *
 * @param string $tempPath Relative path within temp storage (e.g., /uploads/temp/42/file.jpg)
 * @param int $kodPersons Person ID from the persons table
 * @return string New production path relative to the photo root
 * @throws RuntimeException If person not found or file operations fail
 */
function moveToProduction(string $tempPath, int $kodPersons): string
{
    $db = getDb();

    // Look up person's URL path
    $stmt = $db->prepare('SELECT AllUrlInSity FROM persons WHERE Persons_id = :id LIMIT 1');
    $stmt->execute([':id' => $kodPersons]);
    $person = $stmt->fetch();

    if (!$person || empty($person['AllUrlInSity'])) {
        throw new RuntimeException("Person not found or has no URL path: {$kodPersons}");
    }

    $personUrl = fromDb($person['AllUrlInSity']);
    // Strip domain: https://www.peoples.ru/art/music/name/ -> art/music/name/
    $personPath = preg_replace('#^https?://[^/]+/#', '', $personUrl);
    $personPath = trim($personPath, '/');

    $productionBase = '/usr/local/www/peoples.ru/www/' . $personPath;

    // Create production directory if needed
    if (!is_dir($productionBase)) {
        if (!mkdir($productionBase, 0755, true) && !is_dir($productionBase)) {
            throw new RuntimeException("Failed to create production directory: {$productionBase}");
        }
    }

    // Resolve temp path to absolute
    $wwwRoot = dirname(__DIR__);
    $tempAbsolute = $wwwRoot . $tempPath;

    if (!is_file($tempAbsolute)) {
        throw new RuntimeException("Temp file not found: {$tempAbsolute}");
    }

    $filename = basename($tempPath);
    $productionPath = $productionBase . '/' . $filename;

    // Move main file
    if (!rename($tempAbsolute, $productionPath)) {
        // Fallback: copy + delete
        if (!copy($tempAbsolute, $productionPath)) {
            throw new RuntimeException("Failed to move file to production: {$productionPath}");
        }
        unlink($tempAbsolute);
    }

    // Move thumbnails if they exist
    $tempDir = dirname($tempAbsolute);
    $baseNameNoExt = pathinfo($filename, PATHINFO_FILENAME);

    // If the filename starts with a base pattern, find and move thumb_ and card_ variants
    foreach (['thumb_', 'card_'] as $prefix) {
        // Build expected thumbnail filename
        // Original: {time}_{hex}.ext -> thumb_{time}_{hex}.ext
        $thumbName = $prefix . $filename;
        $thumbTemp = $tempDir . '/' . $thumbName;
        $thumbProd = $productionBase . '/' . $thumbName;

        if (is_file($thumbTemp)) {
            if (!rename($thumbTemp, $thumbProd)) {
                if (copy($thumbTemp, $thumbProd)) {
                    unlink($thumbTemp);
                }
            }
        }
    }

    // Return relative path from the photo root
    return $personPath . '/' . $filename;
}

/**
 * Delete an uploaded file and its associated thumbnails.
 *
 * @param string $filePath Relative path (e.g., /uploads/temp/42/file.jpg) or absolute path
 * @return void
 */
function deleteUpload(string $filePath): void
{
    // Resolve to absolute path if relative
    if (!str_starts_with($filePath, '/usr/') && !str_starts_with($filePath, '/tmp/')) {
        $filePath = dirname(__DIR__) . $filePath;
    }

    if (!is_file($filePath)) {
        return; // Already deleted, nothing to do
    }

    $dir = dirname($filePath);
    $filename = basename($filePath);

    // Delete main file
    @unlink($filePath);

    // Delete thumbnail variants
    foreach (['thumb_', 'card_'] as $prefix) {
        $variant = $dir . '/' . $prefix . $filename;
        if (is_file($variant)) {
            @unlink($variant);
        }
    }

    // Clean up empty user directory
    if (is_dir($dir) && count(scandir($dir)) <= 2) {
        @rmdir($dir);
    }
}

/**
 * Resize an image proportionally so neither dimension exceeds $maxDimension.
 *
 * If the image is already smaller than $maxDimension, it is returned as-is.
 *
 * @param \GdImage $source Source image resource
 * @param int $origWidth Original width
 * @param int $origHeight Original height
 * @param int $maxDimension Maximum width or height
 * @return \GdImage Resized image (may be the same resource if no resize needed)
 */
function resizeImage(\GdImage $source, int $origWidth, int $origHeight, int $maxDimension): \GdImage
{
    if ($origWidth <= $maxDimension && $origHeight <= $maxDimension) {
        return $source;
    }

    if ($origWidth >= $origHeight) {
        $newWidth = $maxDimension;
        $newHeight = (int) round($origHeight * ($maxDimension / $origWidth));
    } else {
        $newHeight = $maxDimension;
        $newWidth = (int) round($origWidth * ($maxDimension / $origHeight));
    }

    $newWidth = max(1, $newWidth);
    $newHeight = max(1, $newHeight);

    $resized = imagecreatetruecolor($newWidth, $newHeight);
    preserveTransparency($resized);

    imagecopyresampled(
        $resized, $source,
        0, 0, 0, 0,
        $newWidth, $newHeight,
        $origWidth, $origHeight
    );

    return $resized;
}

/**
 * Resize and center-crop an image to exact target dimensions.
 *
 * @param \GdImage $source Source image resource
 * @param int $origWidth Original width
 * @param int $origHeight Original height
 * @param int $targetWidth Desired width
 * @param int $targetHeight Desired height
 * @return \GdImage Cropped and resized image
 */
function cropResize(\GdImage $source, int $origWidth, int $origHeight, int $targetWidth, int $targetHeight): \GdImage
{
    $sourceRatio = $origWidth / $origHeight;
    $targetRatio = $targetWidth / $targetHeight;

    if ($sourceRatio > $targetRatio) {
        // Source is wider: crop sides
        $cropHeight = $origHeight;
        $cropWidth = (int) round($origHeight * $targetRatio);
        $cropX = (int) round(($origWidth - $cropWidth) / 2);
        $cropY = 0;
    } else {
        // Source is taller: crop top/bottom
        $cropWidth = $origWidth;
        $cropHeight = (int) round($origWidth / $targetRatio);
        $cropX = 0;
        $cropY = (int) round(($origHeight - $cropHeight) / 2);
    }

    $result = imagecreatetruecolor($targetWidth, $targetHeight);
    preserveTransparency($result);

    imagecopyresampled(
        $result, $source,
        0, 0, $cropX, $cropY,
        $targetWidth, $targetHeight,
        $cropWidth, $cropHeight
    );

    return $result;
}

/**
 * Save a GD image resource to disk in the appropriate format.
 *
 * @param \GdImage $image Image resource
 * @param string $path Output file path
 * @param string $mimeType Target MIME type
 * @return void
 * @throws RuntimeException If saving fails
 */
function saveImage(\GdImage $image, string $path, string $mimeType): void
{
    $result = match ($mimeType) {
        'image/jpeg' => imagejpeg($image, $path, UPLOAD_JPEG_QUALITY),
        'image/png'  => imagepng($image, $path, UPLOAD_PNG_COMPRESSION),
        'image/webp' => imagewebp($image, $path, UPLOAD_WEBP_QUALITY),
        default      => throw new RuntimeException("Unsupported MIME type for saving: {$mimeType}"),
    };

    if ($result === false) {
        throw new RuntimeException("Failed to save image: {$path}");
    }
}

/**
 * Apply EXIF orientation to an image.
 *
 * JPEG files may contain EXIF orientation data indicating the image should be
 * rotated or flipped. This function applies the transformation so the image
 * displays correctly after EXIF is stripped.
 *
 * @param \GdImage $image Source image
 * @param int $orientation EXIF Orientation value (1-8)
 * @return \GdImage Corrected image (may be the same resource if orientation is 1)
 */
function applyExifOrientation(\GdImage $image, int $orientation): \GdImage
{
    return match ($orientation) {
        2 => imageflip($image, IMG_FLIP_HORIZONTAL) ? $image : $image,
        3 => imagerotate($image, 180, 0) ?: $image,
        4 => imageflip($image, IMG_FLIP_VERTICAL) ? $image : $image,
        5 => (function () use ($image) {
            $rotated = imagerotate($image, -90, 0);
            if ($rotated === false) {
                return $image;
            }
            imageflip($rotated, IMG_FLIP_HORIZONTAL);
            imagedestroy($image);
            return $rotated;
        })(),
        6 => (function () use ($image) {
            $rotated = imagerotate($image, -90, 0);
            if ($rotated === false) {
                return $image;
            }
            imagedestroy($image);
            return $rotated;
        })(),
        7 => (function () use ($image) {
            $rotated = imagerotate($image, 90, 0);
            if ($rotated === false) {
                return $image;
            }
            imageflip($rotated, IMG_FLIP_HORIZONTAL);
            imagedestroy($image);
            return $rotated;
        })(),
        8 => (function () use ($image) {
            $rotated = imagerotate($image, 90, 0);
            if ($rotated === false) {
                return $image;
            }
            imagedestroy($image);
            return $rotated;
        })(),
        default => $image, // Orientation 1 = normal, no change
    };
}

/**
 * Preserve transparency for PNG and WebP images on a truecolor GD canvas.
 *
 * @param \GdImage $image Target image resource
 * @return void
 */
function preserveTransparency(\GdImage $image): void
{
    imagealphablending($image, false);
    imagesavealpha($image, true);
    $transparent = imagecolorallocatealpha($image, 0, 0, 0, 127);
    imagefill($image, 0, 0, $transparent);
    imagealphablending($image, true);
}
