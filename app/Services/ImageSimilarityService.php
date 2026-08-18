<?php

namespace App\Services;

use CodeIgniter\Database\BaseConnection;

/** Creates compact perceptual fingerprints for visually similar image detection. */
class ImageSimilarityService
{
    public const MATCH_THRESHOLD = 88.0;

    public function __construct(private ?BaseConnection $db = null)
    {
        $this->db ??= db_connect();
    }

    public function fingerprint(string $path, ?string $mimeType = null): ?string
    {
        if (! extension_loaded('gd') || ! is_file($path)) {
            return null;
        }

        $imageInfo = @getimagesize($path);
        if ($imageInfo === false || $imageInfo[0] < 1 || $imageInfo[1] < 1 || ($imageInfo[0] * $imageInfo[1]) > 80_000_000) {
            return null;
        }

        $mimeType = strtolower((string) ($mimeType ?: ($imageInfo['mime'] ?? '')));
        $source = match ($mimeType) {
            'image/jpeg' => @imagecreatefromjpeg($path),
            'image/png'  => @imagecreatefrompng($path),
            'image/gif'  => @imagecreatefromgif($path),
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
            'image/bmp'  => function_exists('imagecreatefrombmp') ? @imagecreatefrombmp($path) : false,
            default      => false,
        };
        if ($source === false) {
            return null;
        }

        $sample = imagecreatetruecolor(9, 8);
        if ($sample === false || ! imagecopyresampled($sample, $source, 0, 0, 0, 0, 9, 8, imagesx($source), imagesy($source))) {
            imagedestroy($source);
            if ($sample !== false) imagedestroy($sample);
            return null;
        }

        $bits = '';
        for ($y = 0; $y < 8; $y++) {
            for ($x = 0; $x < 8; $x++) {
                $bits .= $this->luminance(imagecolorat($sample, $x, $y)) > $this->luminance(imagecolorat($sample, $x + 1, $y)) ? '1' : '0';
            }
        }
        imagedestroy($sample);
        imagedestroy($source);

        $hex = '';
        foreach (str_split($bits, 4) as $nibble) {
            $hex .= dechex(bindec($nibble));
        }

        return str_pad($hex, 16, '0', STR_PAD_LEFT);
    }

    public function similarity(string $left, string $right): float
    {
        $left = strtolower(trim($left));
        $right = strtolower(trim($right));
        if (! preg_match('/^[0-9a-f]{16}$/', $left) || ! preg_match('/^[0-9a-f]{16}$/', $right)) {
            return 0.0;
        }

        $differentBits = 0;
        $bitCounts = [0, 1, 1, 2, 1, 2, 2, 3, 1, 2, 2, 3, 2, 3, 3, 4];
        for ($index = 0; $index < 16; $index++) {
            $differentBits += $bitCounts[hexdec($left[$index]) ^ hexdec($right[$index])];
        }

        return round((1 - ($differentBits / 64)) * 100, 2);
    }

    /** @return array{file:array<string,mixed>, similarity:float}|null */
    public function findBestMatch(string $fingerprint, int $limit = 1000, ?string $batchId = null, string $scope = 'media_library'): ?array
    {
        if (! $this->db->tableExists('files') || ! in_array('image_fingerprint', $this->db->getFieldNames('files'), true)) {
            return null;
        }

        $files = $this->db->table('files')
            ->where('is_deleted', 0)
            ->groupStart()
                ->like('mime_type', 'image/', 'after')
                ->orWhereIn('filetype', ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'])
            ->groupEnd();
        if ($batchId !== null && in_array('upload_batch_id', $this->db->getFieldNames('files'), true)) {
            if ($scope === 'upload_batch') {
                $files->where('upload_batch_id', $batchId);
            } else {
                $files->groupStart()
                    ->where('upload_batch_id !=', $batchId)
                    ->orWhere('upload_batch_id IS NULL', null, false)
                    ->groupEnd();
            }
        }
        $files = $files->orderBy('dateupload', 'DESC')
            ->limit(max(1, min(5000, $limit)))
            ->get()
            ->getResultArray();

        $best = null;
        foreach ($files as $file) {
            $candidateFingerprint = trim((string) ($file['image_fingerprint'] ?? ''));
            if ($candidateFingerprint === '') {
                try {
                    $path = service('storage')->resolveDatabaseFile((string) $file['filepath']);
                    $candidateFingerprint = (string) ($this->fingerprint($path, (string) ($file['mime_type'] ?? '')) ?? '');
                    if ($candidateFingerprint !== '') {
                        $this->db->table('files')->where('id', (int) $file['id'])->update(['image_fingerprint' => $candidateFingerprint]);
                    }
                } catch (\Throwable) {
                    continue;
                }
            }
            if ($candidateFingerprint === '') continue;

            $similarity = $this->similarity($fingerprint, $candidateFingerprint);
            if ($similarity >= self::MATCH_THRESHOLD && ($best === null || $similarity > $best['similarity'])) {
                $best = ['file' => $file, 'similarity' => $similarity];
            }
        }

        return $best;
    }

    private function luminance(int $rgb): float
    {
        $red = ($rgb >> 16) & 0xFF;
        $green = ($rgb >> 8) & 0xFF;
        $blue = $rgb & 0xFF;

        return ($red * 0.299) + ($green * 0.587) + ($blue * 0.114);
    }
}
