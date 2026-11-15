<?php

declare(strict_types=1);

namespace IndianOpinion\FeaturedFromPrompt;

use IndianOpinion\FeaturedFromPrompt\Provider\ProviderCapabilities;

/**
 * Case 36: which size to ask for.
 */
final class SizeChooser
{
    /**
     * @return array{width: int, height: int, aspect_ratio: string, how: string}
     */
    public static function choose(
        ProviderCapabilities $caps,
        int $aspect_w,
        int $aspect_h,
        int $target_w,
        int $target_h,
        int $min_w,
        int $min_h
    ): array {
        $target = $aspect_w / $aspect_h;

        if ($caps->sizes !== []) {
            $best_diff = INF;
            foreach ($caps->sizes as [$w, $h]) {
                if ($w > 0 && $h > 0) {
                    $best_diff = min($best_diff, abs($w / $h - $target));
                }
            }
            $closest = [];
            foreach ($caps->sizes as [$w, $h]) {
                if ($w > 0 && $h > 0 && abs($w / $h - $target) <= $best_diff + 0.01) {
                    $closest[] = [$w, $h];
                }
            }
            usort($closest, static fn (array $a, array $b): int => $a[0] * $a[1] <=> $b[0] * $b[1]);
            $pick = null;
            foreach ($closest as $size) {
                if ($size[0] >= $min_w && $size[1] >= $min_h) {
                    $pick = $size;
                    break;
                }
            }
            $pick ??= $closest[count($closest) - 1];
            return ['width' => $pick[0], 'height' => $pick[1], 'aspect_ratio' => self::ratio($pick[0], $pick[1]), 'how' => 'listed_size'];
        }

        if ($caps->aspect_ratios !== []) {
            $best = null;
            $best_diff = INF;
            foreach ($caps->aspect_ratios as $ratio) {
                if (preg_match('/^(\d+):(\d+)$/', $ratio, $m) !== 1 || (int) $m[2] === 0) {
                    continue;
                }
                $diff = abs((int) $m[1] / (int) $m[2] - $target);
                if ($diff < $best_diff) {
                    $best_diff = $diff;
                    $best = [(int) $m[1], (int) $m[2], $ratio];
                }
            }
            if ($best !== null) {
                $height = (int) round($target_w * $best[1] / $best[0]);
                return ['width' => $target_w, 'height' => $height, 'aspect_ratio' => $best[2], 'how' => 'listed_ratio'];
            }
        }

        $w = max($caps->min_side, min($caps->max_side, $target_w));
        $h = max($caps->min_side, min($caps->max_side, $target_h));
        return ['width' => $w, 'height' => $h, 'aspect_ratio' => $aspect_w . ':' . $aspect_h, 'how' => 'any_size'];
    }

    public static function ratio(int $w, int $h): string
    {
        $a = $w;
        $b = $h;
        while ($b !== 0) {
            [$a, $b] = [$b, $a % $b];
        }
        $a = max(1, $a);
        return intdiv($w, $a) . ':' . intdiv($h, $a);
    }
}
