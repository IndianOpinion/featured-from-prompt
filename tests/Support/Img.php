<?php

declare(strict_types=1);

namespace IndianOpinion\FeaturedFromPrompt\Tests\Support;

/**
 * Test images made with GD, plus hand-built headers.
 */
final class Img
{
    /** @var array<string, string> */
    private static array $cache = [];

    public static function png(int $w, int $h): string
    {
        return self::make('png', $w, $h);
    }

    public static function jpeg(int $w, int $h): string
    {
        return self::make('jpeg', $w, $h);
    }

    public static function webp(int $w, int $h): string
    {
        return self::make('webp', $w, $h);
    }

    private static function make(string $type, int $w, int $h): string
    {
        $key = $type . $w . 'x' . $h;
        if (isset(self::$cache[$key])) {
            return self::$cache[$key];
        }
        $im = imagecreatetruecolor($w, $h);
        mt_srand($w * 7 + $h);
        for ($i = 0; $i < 40; $i++) {
            $c = imagecolorallocate($im, mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255));
            imagefilledrectangle($im, mt_rand(0, $w), mt_rand(0, $h), mt_rand(0, $w), mt_rand(0, $h), (int) $c);
        }
        ob_start();
        match ($type) {
            'jpeg' => imagejpeg($im, null, 80),
            'webp' => imagewebp($im, null, 80),
            default => imagepng($im),
        };
        return self::$cache[$key] = (string) ob_get_clean();
    }

    /** A valid PNG whose header declares a huge size, padded past 1 KB. */
    public static function png_header(int $w, int $h): string
    {
        $chunk = static function (string $type, string $data): string {
            return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
        };
        $ihdr = pack('NNCCCCC', $w, $h, 8, 2, 0, 0, 0);
        return "\x89PNG\r\n\x1a\n" . $chunk('IHDR', $ihdr) . $chunk('tEXt', 'Comment' . "\0" . str_repeat('x', 2048))
            . $chunk('IDAT', (string) gzcompress("\0\0\0\0")) . $chunk('IEND', '');
    }
}
