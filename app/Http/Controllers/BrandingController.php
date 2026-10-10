<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\FoundationSettings;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class BrandingController extends Controller
{
    public function show(string $asset, string $hash, FoundationSettings $settings): BinaryFileResponse
    {
        $metadata = $settings->asset($asset);
        abort_unless(is_array($metadata) && ($metadata['sha256'] ?? null) === $hash, 404);
        $path = $metadata['path'] ?? null;
        abort_unless(is_string($path) && preg_match('/\Abranding\/[a-f0-9-]+\.(png|jpe?g|webp)\z/', $path) === 1, 404);
        abort_unless(Storage::disk('local')->exists($path), 404);

        return response()->file(Storage::disk('local')->path($path), [
            'Content-Type' => $metadata['mime'], 'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }
}
