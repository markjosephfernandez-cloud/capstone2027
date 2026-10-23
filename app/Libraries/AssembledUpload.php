<?php

namespace App\Libraries;

use CodeIgniter\HTTP\Files\UploadedFile;
use RuntimeException;

/** Internal adapter for a completed, server-owned chunk assembly. */
final class AssembledUpload extends UploadedFile
{
    public function __construct(string $path, string $originalName, ?string $mimeType = null, ?int $size = null, ?int $error = null, ?string $clientPath = null)
    {
        parent::__construct($path, $originalName, $mimeType, filesize($path), UPLOAD_ERR_OK, $clientPath);
    }

    public function isValid(): bool
    {
        return ! $this->hasMoved() && is_file($this->path);
    }

    public function move(string $targetPath, ?string $name = null, bool $overwrite = false)
    {
        $name ??= basename($this->getClientName());
        if (! $this->isValid() || basename($name) !== $name || str_contains($name, '\\') || str_contains($name, "\0")) {
            throw new RuntimeException('The assembled upload cannot be moved.');
        }
        $destination = rtrim($targetPath, '/\\') . DIRECTORY_SEPARATOR . $name;
        if (! $overwrite) {
            $destination = $this->getDestination($destination);
        }
        if (! @rename($this->path, $destination)) {
            throw new RuntimeException('The assembled upload could not be stored. Check available disk space.');
        }
        $this->hasMoved = true;
        $this->path = $destination;
        $this->name = basename($destination);
        return true;
    }
}
