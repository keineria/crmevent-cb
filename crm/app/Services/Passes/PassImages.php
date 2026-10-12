<?php

namespace App\Services\Passes;

use App\Support\Branding;
use GdImage;
use Illuminate\Support\Facades\Storage;

/**
 * Icon and logo PNGs for wallet passes, drawn from the email logo in Configuración › Marca,
 * or a plain tile in the pass colour when no logo was uploaded.
 */
class PassImages
{
    /**
     * PNG of the logo fitted inside a transparent box of the given size.
     */
    public static function png(int $width, int $height): string
    {
        $canvas = imagecreatetruecolor($width, $height);
        imagesavealpha($canvas, true);
        imagefill($canvas, 0, 0, imagecolorallocatealpha($canvas, 0, 0, 0, 127));

        $logo = self::logo();
        if ($logo) {
            $scale = min($width / imagesx($logo), $height / imagesy($logo));
            $w = max(1, (int) round(imagesx($logo) * $scale));
            $h = max(1, (int) round(imagesy($logo) * $scale));
            imagecopyresampled($canvas, $logo, intdiv($width - $w, 2), intdiv($height - $h, 2), 0, 0, $w, $h, imagesx($logo), imagesy($logo));
        } else {
            [$r, $g, $b] = self::rgb((string) config('crm.wallet.background'));
            $side = min($width, $height);
            imagefilledellipse($canvas, intdiv($width, 2), intdiv($height, 2), $side, $side, imagecolorallocate($canvas, $r, $g, $b));
        }

        ob_start();
        imagepng($canvas);

        return (string) ob_get_clean();
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    public static function rgb(string $hex): array
    {
        $hex = ltrim($hex, '#');
        if (! preg_match('/^[0-9a-f]{6}$/i', $hex)) {
            $hex = '2F5D2A';
        }

        return array_map(hexdec(...), str_split($hex, 2));
    }

    private static function logo(): ?GdImage
    {
        $path = Branding::resolvedPath(Branding::EMAIL);
        if (! $path) {
            return null;
        }

        $image = @imagecreatefromstring((string) Storage::disk('local')->get($path));

        return $image ?: null;
    }
}
