<?php

namespace App\Support;

use App\Models\AppSetting;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Logos uploaded in Configuración › Marca. Files live on the private disk and are served by
 * the branding.logo route, so the server needs no storage symlink.
 */
final class Branding
{
    /** Sidebar and header of the CRM. */
    public const PLATFORM = 'platform';

    /** Login, password reset and the participant portal. Falls back to the platform logo. */
    public const LOGIN = 'login';

    /** Header of campaign and QR emails. Falls back to the platform logo. */
    public const EMAIL = 'email';

    public const KINDS = [self::PLATFORM, self::LOGIN, self::EMAIL];

    private const DIRECTORY = 'branding';

    public static function store(string $kind, UploadedFile $file): void
    {
        $previous = AppSetting::get(self::key($kind));
        $path = $file->storeAs(self::DIRECTORY, $kind.'-'.now()->format('YmdHis').'.'.$file->extension(), 'local');
        AppSetting::put(self::key($kind), $path);

        if ($previous && $previous !== $path) {
            Storage::disk('local')->delete($previous);
        }
    }

    public static function remove(string $kind): void
    {
        $previous = AppSetting::get(self::key($kind));
        AppSetting::put(self::key($kind), null);

        if ($previous) {
            Storage::disk('local')->delete($previous);
        }
    }

    /**
     * Stored path of a logo, without fallbacks.
     */
    public static function path(string $kind): ?string
    {
        try {
            $path = AppSetting::get(self::key($kind));
        } catch (Throwable) {
            // Before the migration runs (first deploy) the CRM still renders with the default logo.
            return null;
        }

        return $path && Storage::disk('local')->exists($path) ? $path : null;
    }

    /**
     * Path that is actually shown for a kind: login and email fall back to the platform logo.
     */
    public static function resolvedPath(string $kind): ?string
    {
        return self::path($kind) ?? ($kind === self::PLATFORM ? null : self::path(self::PLATFORM));
    }

    /**
     * Absolute URL of the logo shown for a kind, or null to use the default bird icon / app name.
     */
    public static function url(string $kind): ?string
    {
        $path = self::resolvedPath($kind);

        // The file name changes on every upload, so it doubles as a cache buster.
        return $path ? route('branding.logo', ['kind' => $kind, 'v' => substr(md5($path), 0, 8)]) : null;
    }

    /**
     * @return array<string, string|null>
     */
    public static function urls(): array
    {
        return collect(self::KINDS)->mapWithKeys(fn (string $kind) => [$kind => self::url($kind)])->all();
    }

    private static function key(string $kind): string
    {
        return "logo.{$kind}";
    }
}
