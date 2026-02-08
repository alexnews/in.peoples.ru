# Photo Upload System

## Requirements

- Max file size: 10MB
- Accepted formats: JPEG, PNG, WebP
- Auto-resize: max 1200px on longest side
- Thumbnails: 150x150 (square crop), 300x200 (card crop)
- EXIF stripping for privacy
- Max 10 photos per submission
- Temporary storage before approval, permanent after

## Upload Pipeline

```
User Browser                  Server                        Filesystem
────────────                  ──────                        ──────────
Drag & drop photo ───────────▶ POST /api/v1/photos/upload.php
                               │
                               ├─ Validate session (auth)
                               ├─ Validate submission_id (belongs to user)
                               ├─ Validate file:
                               │   ├─ Size ≤ 10MB
                               │   ├─ MIME: image/jpeg, image/png, image/webp
                               │   ├─ getimagesize() confirms image
                               │   └─ No PHP/script content
                               │
                               ├─ Generate unique filename:
                               │   {timestamp}_{random8}.{ext}
                               │
                               ├─ Process image: ──────────▶ www/uploads/temp/{user_id}/
                               │   ├─ Strip EXIF             ├─ {filename}.jpg     (resized)
                               │   ├─ Resize to max 1200px   ├─ thumb_{filename}.jpg (150x150)
                               │   └─ Generate thumbnails    └─ card_{filename}.jpg  (300x200)
                               │
                               ├─ INSERT INTO submission_photos
                               │   (submission_id, user_id, file_name, file_path,
                               │    file_size, mime_type, width, height, caption)
                               │
                               └─ Return JSON ──────────────▶ Show preview in UI
```

## On Moderation Approval

```
Moderator approves
  │
  ├─ Look up person: SELECT AllUrlInSity FROM persons WHERE Persons_id = ?
  │
  ├─ Determine target directory:
  │   www/photo/{AllUrlInSity}/
  │   (Create if doesn't exist)
  │
  ├─ Move files:
  │   www/uploads/temp/{user_id}/{filename}
  │   → www/photo/{AllUrlInSity}/{filename}
  │
  ├─ INSERT INTO photo (KodPersons, NamePhoto, path_photo, ...)
  │
  └─ DELETE temp files
```

## On Rejection / Deletion

```
Submission rejected or deleted
  │
  ├─ DELETE FROM submission_photos WHERE submission_id = ?
  │
  └─ rm www/uploads/temp/{user_id}/{filename}*
     (removes original + all thumbnails)
```

## Temp Cleanup Cron

Add to MySQL events or server cron:
```
-- Delete orphaned temp photos older than 7 days
-- (user abandoned upload without submitting)
```

```bash
# Cron: daily at 3am
find www/uploads/temp/ -type f -mtime +7 -delete
find www/uploads/temp/ -type d -empty -delete
```

## Image Processing (PHP GD)

```php
// www/includes/upload.php

function processUpload($file, $userId, $submissionId) {
    // 1. Validate
    $maxSize = 10 * 1024 * 1024; // 10MB
    $allowedTypes = ['image/jpeg', 'image/png', 'image/webp'];

    if ($file['size'] > $maxSize) throw new Exception('File too large');
    if (!in_array($file['type'], $allowedTypes)) throw new Exception('Invalid type');

    $imageInfo = getimagesize($file['tmp_name']);
    if (!$imageInfo) throw new Exception('Not a valid image');

    // 2. Generate filename
    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = time() . '_' . bin2hex(random_bytes(4)) . '.' . strtolower($ext);

    // 3. Create user temp directory
    $tempDir = __DIR__ . '/../uploads/temp/' . $userId;
    if (!is_dir($tempDir)) mkdir($tempDir, 0755, true);

    // 4. Load image
    $image = loadImage($file['tmp_name'], $imageInfo[2]);

    // 5. Strip EXIF (by re-encoding)
    // 6. Resize if needed (max 1200px)
    $image = resizeIfNeeded($image, 1200);

    // 7. Save main image
    $mainPath = $tempDir . '/' . $filename;
    saveImage($image, $mainPath, $imageInfo[2]);

    // 8. Generate thumbnails
    $thumb = cropResize($image, 150, 150);
    saveImage($thumb, $tempDir . '/thumb_' . $filename, $imageInfo[2]);

    $card = cropResize($image, 300, 200);
    saveImage($card, $tempDir . '/card_' . $filename, $imageInfo[2]);

    // 9. Get final dimensions
    $finalWidth = imagesx($image);
    $finalHeight = imagesy($image);

    imagedestroy($image);
    imagedestroy($thumb);
    imagedestroy($card);

    return [
        'file_name' => $filename,
        'file_path' => '/uploads/temp/' . $userId . '/' . $filename,
        'file_size' => filesize($mainPath),
        'mime_type' => $file['type'],
        'width' => $finalWidth,
        'height' => $finalHeight
    ];
}

function resizeIfNeeded($image, $maxDimension) {
    $width = imagesx($image);
    $height = imagesy($image);

    if ($width <= $maxDimension && $height <= $maxDimension) {
        return $image;
    }

    if ($width > $height) {
        $newWidth = $maxDimension;
        $newHeight = intval($height * $maxDimension / $width);
    } else {
        $newHeight = $maxDimension;
        $newWidth = intval($width * $maxDimension / $height);
    }

    $resized = imagecreatetruecolor($newWidth, $newHeight);
    imagecopyresampled($resized, $image, 0, 0, 0, 0,
                       $newWidth, $newHeight, $width, $height);
    imagedestroy($image);
    return $resized;
}

function cropResize($image, $targetWidth, $targetHeight) {
    $srcWidth = imagesx($image);
    $srcHeight = imagesy($image);

    // Calculate crop area (center crop)
    $srcRatio = $srcWidth / $srcHeight;
    $targetRatio = $targetWidth / $targetHeight;

    if ($srcRatio > $targetRatio) {
        // Source is wider: crop sides
        $cropHeight = $srcHeight;
        $cropWidth = intval($srcHeight * $targetRatio);
        $cropX = intval(($srcWidth - $cropWidth) / 2);
        $cropY = 0;
    } else {
        // Source is taller: crop top/bottom
        $cropWidth = $srcWidth;
        $cropHeight = intval($srcWidth / $targetRatio);
        $cropX = 0;
        $cropY = intval(($srcHeight - $cropHeight) / 2);
    }

    $thumb = imagecreatetruecolor($targetWidth, $targetHeight);
    imagecopyresampled($thumb, $image, 0, 0, $cropX, $cropY,
                       $targetWidth, $targetHeight, $cropWidth, $cropHeight);
    return $thumb;
}
```

## Security Checks

1. **File type validation** — Check both MIME type and `getimagesize()` return
2. **No PHP in images** — Scan first bytes for `<?php`, `<?=`, `<script`
3. **Filename sanitization** — Generate new filename, never use original
4. **Directory traversal** — User ID from session, never from input
5. **Upload directory** — `.htaccess` in `uploads/` disables PHP execution:
   ```apache
   # www/uploads/.htaccess
   php_flag engine off
   RemoveHandler .php .phtml .php3 .php4 .php5
   AddType text/plain .php .phtml .php3 .php4 .php5
   ```
6. **Size limits** — Both client-side and server-side checks
7. **Rate limiting** — Max 50 photo uploads per user per day
