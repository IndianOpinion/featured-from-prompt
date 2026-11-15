<?php

declare(strict_types=1);

namespace IndianOpinion\FeaturedFromPrompt;

/**
 * Cases 46 to 55: check the real bytes, never the request or the hints.
 */
final class ImageChecker
{
    private const TYPES = [
        'png' => ['mime' => 'image/png', 'type' => IMAGETYPE_PNG],
        'jpg' => ['mime' => 'image/jpeg', 'type' => IMAGETYPE_JPEG],
        'webp' => ['mime' => 'image/webp', 'type' => IMAGETYPE_WEBP],
    ];

    /**
     * Rules for a post, after the ffp_image_rules filter (case 54).
     *
     * @return array{min_width: int, min_height: int, min_ratio: float, max_ratio: float, max_bytes: int, max_pixels: int}
     */
    public static function rules(int $post_id): array
    {
        $s = Settings::all();
        $rules = [
            'min_width' => (int) $s['min_width'],
            'min_height' => (int) $s['min_height'],
            'min_ratio' => (float) $s['min_ratio'],
            'max_ratio' => (float) $s['max_ratio'],
            'max_bytes' => (int) $s['max_mb'] * 1024 * 1024,
            'max_pixels' => (int) $s['max_mp'] * 1000000,
        ];
        $filtered = apply_filters('ffp_image_rules', $rules, $post_id);
        if (is_array($filtered)) {
            foreach ($rules as $key => $value) {
                if (isset($filtered[$key]) && is_numeric($filtered[$key]) && $filtered[$key] > 0) {
                    $rules[$key] = is_float($value) ? (float) $filtered[$key] : (int) $filtered[$key];
                }
            }
        }
        return $rules;
    }

    /** Sniff by magic number. Returns png, jpg, webp or null. */
    public static function sniff(string $bytes): ?string
    {
        if (strncmp($bytes, "\x89PNG\r\n\x1a\n", 8) === 0) {
            return 'png';
        }
        if (strncmp($bytes, "\xFF\xD8\xFF", 3) === 0) {
            return 'jpg';
        }
        if (strlen($bytes) >= 12 && substr($bytes, 0, 4) === 'RIFF' && substr($bytes, 8, 4) === 'WEBP') {
            return 'webp';
        }
        return null;
    }

    /**
     * @param array{min_width: int, min_height: int, min_ratio: float, max_ratio: float, max_bytes: int, max_pixels: int} $rules
     * @param array{crop: bool, target_ratio: float, output_format: string, quality: int} $options
     * @throws CheckFailed
     */
    public static function check(string $bytes, array $rules, array $options): CheckedImage
    {
        $length = strlen($bytes);
        if ($length > $rules['max_bytes']) {
            throw new CheckFailed(Reason::FILE_TOO_LARGE);
        }
        if ($length < 1024) {
            throw new CheckFailed(Reason::NOT_IMAGE, 'Empty or under 1 KB.');
        }
        $ext = self::sniff($bytes);
        if ($ext === null) {
            throw new CheckFailed(Reason::NOT_IMAGE, 'Not PNG, JPEG or WebP by magic number.');
        }
        if (!self::complete($bytes, $ext)) {
            throw new CheckFailed(Reason::DECODE_FAILED, 'The file is truncated.');
        }
        $info = getimagesizefromstring($bytes);
        if (!is_array($info) || (int) $info[0] <= 0 || (int) $info[1] <= 0 || (int) $info[2] !== self::TYPES[$ext]['type']) {
            throw new CheckFailed(Reason::DECODE_FAILED);
        }
        $width = (int) $info[0];
        $height = (int) $info[1];
        if ($width * $height > $rules['max_pixels']) {
            throw new CheckFailed(Reason::TOO_MANY_PIXELS);
        }
        self::check_size($width, $height, $rules);

        $path = self::temp_file($ext);
        if (file_put_contents($path, $bytes) !== $length) {
            self::remove($path);
            throw new CheckFailed(Reason::STORE_FAILED, 'Cannot write the temporary file.');
        }

        $mime = self::TYPES[$ext]['mime'];
        $cropped = false;
        $crop_skipped = false;
        $converted = false;
        $convert_skipped = false;
        $final_w = $width;
        $final_h = $height;
        try {
            $ratio = $width / $height;
            if ($ratio < $rules['min_ratio'] || $ratio > $rules['max_ratio']) {
                if (!$options['crop']) {
                    throw new CheckFailed(Reason::BAD_ASPECT);
                }
                $dims = self::crop($path, $mime, $width, $height, $options['target_ratio']);
                if ($dims === null) {
                    $crop_skipped = true;
                    throw new CheckFailed(Reason::BAD_ASPECT, 'No image editor available to crop.');
                }
                [$final_w, $final_h] = $dims;
                $cropped = true;
                self::check_size($final_w, $final_h, $rules);
                $ratio = $final_w / $final_h;
                if ($ratio < $rules['min_ratio'] || $ratio > $rules['max_ratio']) {
                    throw new CheckFailed(Reason::BAD_ASPECT);
                }
            }

            $want = $options['output_format'];
            $want_ext = $want === 'jpeg' ? 'jpg' : ($want === 'webp' ? 'webp' : $ext);
            if ($want !== 'keep' && $want_ext !== $ext) {
                $new = self::convert($path, self::TYPES[$want_ext]['mime'], $want_ext, $options['quality']);
                if ($new === null) {
                    $convert_skipped = true;
                } else {
                    self::remove($path);
                    $path = $new;
                    $ext = $want_ext;
                    $mime = self::TYPES[$want_ext]['mime'];
                    $converted = true;
                }
            }
        } catch (CheckFailed $e) {
            self::remove($path);
            throw $e;
        }

        return new CheckedImage($path, $mime, $ext, $final_w, $final_h, $width, $height, $cropped, $converted, $convert_skipped, $crop_skipped);
    }

    /** @param array{min_width: int, min_height: int, min_ratio: float, max_ratio: float, max_bytes: int, max_pixels: int} $rules */
    private static function check_size(int $width, int $height, array $rules): void
    {
        if ($width < $rules['min_width'] || $height < $rules['min_height']) {
            throw new CheckFailed(Reason::TOO_SMALL, sprintf('%dx%d is under the minimum.', $width, $height));
        }
    }

    /** Cheap end-of-file checks so a truncated file fails before anything decodes it. */
    private static function complete(string $bytes, string $ext): bool
    {
        if ($ext === 'png') {
            return strpos(substr($bytes, -16), 'IEND') !== false;
        }
        if ($ext === 'jpg') {
            return strpos(substr($bytes, -64), "\xFF\xD9") !== false;
        }
        $unpacked = unpack('V', substr($bytes, 4, 4));
        $size = is_array($unpacked) ? (int) $unpacked[1] : 0;
        return $size + 8 <= strlen($bytes) && $size > 4;
    }

    /** @return array{0: int, 1: int}|null */
    private static function crop(string $path, string $mime, int $width, int $height, float $target): ?array
    {
        if ($width / $height > $target) {
            $new_w = (int) round($height * $target);
            $new_h = $height;
        } else {
            $new_w = $width;
            $new_h = (int) round($width / $target);
        }
        $x = intdiv($width - $new_w, 2);
        $y = intdiv($height - $new_h, 2);
        $editor = wp_get_image_editor($path);
        if (is_wp_error($editor)) {
            return null;
        }
        $result = $editor->crop($x, $y, $new_w, $new_h);
        if (is_wp_error($result)) {
            return null;
        }
        $saved = $editor->save($path, $mime);
        if (is_wp_error($saved) || !is_array($saved)) {
            return null;
        }
        $info = getimagesize($path);
        if (!is_array($info)) {
            return null;
        }
        return [(int) $info[0], (int) $info[1]];
    }

    private static function convert(string $path, string $mime, string $ext, int $quality): ?string
    {
        $editor = wp_get_image_editor($path);
        if (is_wp_error($editor)) {
            return null;
        }
        $editor->set_quality($quality);
        $target = self::temp_file($ext);
        $saved = $editor->save($target, $mime);
        if (is_wp_error($saved) || !is_array($saved) || !is_file($target) || filesize($target) === 0) {
            self::remove($target);
            return null;
        }
        return $target;
    }

    private static function temp_file(string $ext): string
    {
        $base = wp_tempnam('ffp-check');
        self::remove($base);
        return $base . '.' . $ext;
    }

    public static function remove(string $path): void
    {
        if ($path !== '' && is_file($path)) {
            unlink($path);
        }
    }
}
