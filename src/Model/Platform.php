<?php

namespace Akyos\NativePushBundle\Model;

enum Platform: string
{
    case Ios = 'ios';
    case Android = 'android';

    public static function fromUserAgent(string $userAgent): ?self
    {
        return match (true) {
            str_contains($userAgent, 'Hotwire Native iOS'), str_contains($userAgent, 'Turbo Native iOS') => self::Ios,
            str_contains($userAgent, 'Hotwire Native Android'), str_contains($userAgent, 'Turbo Native Android') => self::Android,
            default => null,
        };
    }

    public function isValidToken(string $token): bool
    {
        return (bool) preg_match(match ($this) {
            self::Ios => '/^[0-9a-f]{64,200}$/i',
            self::Android => '/^[A-Za-z0-9_:\-.]{20,512}$/',
        }, $token);
    }
}
