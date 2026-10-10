<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Validation\ValidationException;

class ExternalVideo
{
    /** @return array{external_url: string, video_source: string, video_id: ?string} */
    public function parse(string $url, string $source): array
    {
        $parts = parse_url($url);
        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass']) || (isset($parts['port']) && $parts['port'] !== 443)) {
            $this->invalid();
        }
        $host = strtolower($parts['host'] ?? '');
        $path = $parts['path'] ?? '';
        if ($source === 'remote') {
            if (! str_ends_with(strtolower($path), '.mp4') || $host === '') {
                $this->invalid();
            }

            return ['external_url' => $url, 'video_source' => 'remote', 'video_id' => null];
        }
        $id = null;
        if ($host === 'youtu.be') {
            $id = ltrim($path, '/');
        } elseif (in_array($host, ['youtube.com', 'www.youtube.com', 'm.youtube.com'], true)) {
            if ($path === '/watch') {
                parse_str($parts['query'] ?? '', $query);
                $id = $query['v'] ?? null;
            } elseif (preg_match('#^/(embed|shorts)/([^/]+)$#', $path, $matches) === 1) {
                $id = $matches[2];
            }
        }
        if (! is_string($id) || preg_match('/^[A-Za-z0-9_-]{11}$/', $id) !== 1) {
            $this->invalid();
        }

        return ['external_url' => 'https://www.youtube.com/watch?v='.$id, 'video_source' => 'youtube', 'video_id' => $id];
    }

    private function invalid(): never
    {
        throw ValidationException::withMessages(['external_url' => 'ใช้ลิงก์ HTTPS ของ YouTube หรือไฟล์ .mp4 โดยตรงตามชนิดที่เลือก']);
    }
}
