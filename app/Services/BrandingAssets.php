<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class BrandingAssets
{
    /** @return array<string, mixed> */
    public function store(UploadedFile $file, string $kind): array
    {
        $source = $file->getPathname();
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($source);
        $extension = strtolower($file->getClientOriginalExtension());
        $allowed = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp'];
        $dimensions = @getimagesize($source);
        if (($allowed[$extension] ?? null) !== $mime || $dimensions === false || $dimensions[0] * $dimensions[1] > 16000000) {
            throw ValidationException::withMessages([$kind.'_file' => 'ใช้ภาพ PNG, JPG หรือ WebP ที่เปิดอ่านได้ ไม่เกิน 16 ล้านพิกเซล ไม่รองรับ SVG']);
        }
        $path = 'branding/'.Str::uuid().'.'.$extension;
        if (Storage::disk('local')->putFileAs('branding', $file, basename($path)) === false) {
            throw new \RuntimeException('Unable to store branding image.');
        }

        return ['path' => $path, 'mime' => $mime, 'sha256' => hash_file('sha256', $source), 'width' => $dimensions[0], 'height' => $dimensions[1]];
    }
}
