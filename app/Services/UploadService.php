<?php
declare(strict_types=1);

final class UploadService
{
    private const MAX_BYTES = 8_388_608; // 8 MB
    private const MAX_PIXELS = 25_000_000;
    private const MIME_EXT = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
    ];

    public function __construct(private string $rootDir) {}

    public function storeImage(array $file, int $userId, string $bucket, int $minWidth = 240, int $minHeight = 240): ?string
    {
        $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_NO_FILE) return null;
        if ($error !== UPLOAD_ERR_OK) throw new InvalidArgumentException('That image could not be uploaded. Please try another file.');

        $tmp = (string)($file['tmp_name'] ?? '');
        $size = (int)($file['size'] ?? 0);
        if ($tmp === '' || !is_file($tmp)) throw new InvalidArgumentException('The uploaded image is missing.');
        if ($size <= 0 || $size > self::MAX_BYTES) throw new InvalidArgumentException('Profile images must be 8 MB or smaller.');
        if (PHP_SAPI !== 'cli' && !is_uploaded_file($tmp)) throw new InvalidArgumentException('Invalid upload.');

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = (string)$finfo->file($tmp);
        $ext = self::MIME_EXT[$mime] ?? null;
        if (!$ext) throw new InvalidArgumentException('Use a JPEG, PNG, or WebP image.');

        $dimensions = @getimagesize($tmp);
        if (!$dimensions || empty($dimensions[0]) || empty($dimensions[1])) throw new InvalidArgumentException('Vacation Brain could not read that image.');
        $width = (int)$dimensions[0]; $height = (int)$dimensions[1];
        if ($width < $minWidth || $height < $minHeight) throw new InvalidArgumentException('That image is too small for this use.');
        if (($width * $height) > self::MAX_PIXELS) throw new InvalidArgumentException('That image is too large. Please use a smaller photo.');

        $bucket = preg_replace('/[^a-z0-9_-]/i', '', $bucket) ?: 'profile';
        $relativeDir = 'uploads/' . $bucket . '/' . $userId;
        $absoluteDir = rtrim($this->rootDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativeDir);
        if (!is_dir($absoluteDir) && !mkdir($absoluteDir, 0775, true) && !is_dir($absoluteDir)) {
            throw new RuntimeException('Vacation Brain could not create the upload folder.');
        }

        $filename = bin2hex(random_bytes(18)) . '.' . $ext;
        $destination = $absoluteDir . DIRECTORY_SEPARATOR . $filename;
        $moved = PHP_SAPI === 'cli' ? rename($tmp, $destination) : move_uploaded_file($tmp, $destination);
        if (!$moved) throw new RuntimeException('Vacation Brain could not save that image.');
        @chmod($destination, 0644);

        return app_url($relativeDir . '/' . $filename);
    }

    public function deleteLocal(?string $url): void
    {
        if (!$url) return;
        $path = (string)(parse_url($url, PHP_URL_PATH) ?? '');
        $marker = '/uploads/';
        $pos = strpos($path, $marker);
        if ($pos === false) return;
        $relative = ltrim(substr($path, $pos + 1), '/');
        if (str_contains($relative, '..')) return;
        $absolute = rtrim($this->rootDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $uploadsRoot = realpath(rtrim($this->rootDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'uploads');
        $real = realpath($absolute);
        if ($uploadsRoot && $real && str_starts_with($real, $uploadsRoot . DIRECTORY_SEPARATOR) && is_file($real)) @unlink($real);
    }
}
