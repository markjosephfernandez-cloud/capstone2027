<?php

namespace App\Libraries;

use CodeIgniter\HTTP\DownloadResponse;
use RuntimeException;

/**
 * Stream an authorized media file or byte range without buffering its body.
 * DownloadResponse keeps CodeIgniter's output collector/debug toolbar out of
 * the binary response and clears PHP output buffers before sending it.
 */
final class MediaStreamResponse extends DownloadResponse
{
    /** @var resource|null */
    private $stream = null;

    public function __construct(
        string $path,
        string $mimeType,
        int $offset,
        private readonly int $length,
        bool $partial,
        private readonly bool $headOnly = false,
    ) {
        parent::__construct(basename($path), false);
        $this->stream = @fopen($path, 'rb');
        if ($this->stream === false || fseek($this->stream, $offset) !== 0) {
            $this->closeStream();
            throw new RuntimeException('Media preview not found.');
        }

        $this->statusCode = $partial ? 206 : 200;
        $this->reason = $partial ? 'Partial Content' : 'OK';
        $this->setContentType($mimeType, '')
            ->setHeader('Accept-Ranges', 'bytes')
            ->setHeader('Content-Length', (string) $length)
            ->inline()
            ->noCache();
    }

    public function getContentLength(): int
    {
        return $this->length;
    }

    public function sendBody()
    {
        $previousTimeLimit = (int) ini_get('max_execution_time');
        try {
            if (! $this->headOnly && is_resource($this->stream)) {
                // Network media can legitimately take longer than a page request.
                if ($previousTimeLimit > 0) set_time_limit(0);
                $remaining = $this->length;
                while ($remaining > 0 && ! feof($this->stream) && ! connection_aborted()) {
                    $chunk = fread($this->stream, min(1048576, $remaining));
                    if ($chunk === false || $chunk === '') {
                        break;
                    }
                    $remaining -= strlen($chunk);
                    echo $chunk;
                    unset($chunk);
                    // HTTP sends have no output buffers; flush for prompt playback
                    // and detection of clients closing or seeking the video.
                    if (ob_get_level() > 0) 
                        flush();
                }
            }
        } finally {
            $this->closeStream();
            if ((int) ini_get('max_execution_time') !== $previousTimeLimit) {
                set_time_limit($previousTimeLimit);
            }
        }

        return $this;
    }

    public function __destruct()
    {
        $this->closeStream();
    }

    private function closeStream(): void
    {
        if (is_resource($this->stream)) {
            fclose($this->stream);
        }
        $this->stream = null;   
    }
}
