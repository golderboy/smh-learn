<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class LearningMediaService
{
    /** @return array<string, mixed> */
    public function store(string $source, string $originalName, string $type): array
    {
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($source);
        $size = filesize($source);
        if ($size === false || $size < 1 || $size > config('learning.upload_limit_kb') * 1024) {
            throw ValidationException::withMessages(['file' => 'ขนาดไฟล์ต้องมากกว่า 0 และไม่เกิน 100 MB']);
        }
        $imageMimes = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
        if (! in_array($type, ['video', 'document', 'image'], true) || ($type === 'video' && ($extension !== 'mp4' || $mime !== 'video/mp4')) || ($type === 'document' && ($extension !== 'pdf' || $mime !== 'application/pdf')) || ($type === 'image' && ($imageMimes[$extension] ?? null) !== $mime)) {
            throw ValidationException::withMessages(['file' => 'รองรับ MP4, PDF และภาพ JPG/PNG/WebP ที่มีชนิดไฟล์ถูกต้อง']);
        }
        if ($type === 'image') {
            $image = @getimagesize($source);
            if ($image === false || $image[0] * $image[1] > 40000000 || $image[0] < 1 || $image[1] < 1) {
                throw ValidationException::withMessages(['file' => 'ภาพต้องเปิดอ่านได้ และมีขนาดไม่เกิน 40 ล้านพิกเซล']);
            }
        }
        $duration = null;
        if ($type === 'video') {
            $metadata = (new \getID3)->analyze($source);
            if (! isset($metadata['video'], $metadata['playtime_seconds']) || isset($metadata['error']) || $metadata['playtime_seconds'] <= 0) {
                throw ValidationException::withMessages(['file' => 'อ่านข้อมูลวิดีโอไม่ได้ กรุณาตรวจสอบไฟล์ MP4']);
            }
            $duration = (int) ceil($metadata['playtime_seconds']);
        }
        $sha = hash_file('sha256', $source);
        $relative = 'media/'.substr($sha, 0, 2).'/'.$sha.'.'.$extension;
        $disk = Storage::disk('learning');
        if (! $disk->exists($relative)) {
            $stream = fopen($source, 'rb');
            try {
                $disk->put($relative, $stream);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
        }

        return ['storage_path' => $relative, 'original_name' => mb_substr(basename($originalName), 0, 255), 'mime_type' => $mime, 'file_size' => $size, 'sha256' => $sha, 'duration_seconds' => $duration];
    }
}
