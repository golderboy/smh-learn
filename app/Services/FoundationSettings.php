<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\SystemSetting;

class FoundationSettings
{
    public function value(string $key, mixed $default = null): mixed
    {
        return SystemSetting::query()->where('key', $key)->first(['value'])?->value['value'] ?? $default;
    }

    public function organizationName(): string
    {
        return (string) $this->value('organization_name', 'องค์กรของฉัน');
    }

    public function sessionMinutes(): int
    {
        return max(15, min(240, (int) $this->value('session_timeout_minutes', 120)));
    }

    /** @return array<string, string|bool> */
    public function brandingText(): array
    {
        $defaults = [
            'product_name' => (string) config('identity.product_name'),
            'header_title' => 'สวัสดี',
            'header_description' => 'พร้อมเรียนรู้สิ่งใหม่ในวันนี้หรือยัง?',
            'banner_text' => 'เรียนรู้ พัฒนาตัวเอง สร้างโอกาสใหม่',
            'login_heading' => 'ยินดีต้อนรับ',
            'login_description' => 'พื้นที่เรียนรู้และพัฒนาศักยภาพของคุณ',
            'footer_text' => 'องค์ความรู้ เพื่อการพัฒนาอย่างต่อเนื่อง',
            'support_email' => '', 'support_phone' => '', 'support_url' => '',
        ];
        $values = SystemSetting::query()->whereIn('key', [...array_keys($defaults), 'show_sidebar_art'])->get(['key', 'value'])->mapWithKeys(fn (SystemSetting $setting) => [$setting->key => $setting->value['value'] ?? null]);
        foreach ($defaults as $key => $default) {
            $defaults[$key] = (string) ($values[$key] ?? $default);
        }

        return [...$defaults, 'show_sidebar_art' => (bool) ($values['show_sidebar_art'] ?? true)];
    }

    /** @return array<string, mixed>|null */
    public function asset(string $kind): ?array
    {
        $asset = $this->value('branding_'.$kind);

        return is_array($asset) ? $asset : null;
    }

    /** @return array<string, string|null> */
    public function assetUrls(): array
    {
        $urls = [];
        $assets = SystemSetting::query()->whereIn('key', ['branding_logo', 'branding_banner', 'branding_favicon'])->get(['key', 'value'])->keyBy('key');
        foreach (['logo', 'banner', 'favicon'] as $kind) {
            $asset = $assets->get('branding_'.$kind)?->value['value'] ?? null;
            $urls[$kind.'_url'] = is_string($asset['sha256'] ?? null) ? '/branding/'.$kind.'/'.$asset['sha256'] : null;
        }

        return $urls;
    }
}
