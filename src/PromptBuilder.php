<?php

declare(strict_types=1);

namespace IndianOpinion\FeaturedFromPrompt;

use IndianOpinion\FeaturedFromPrompt\Provider\ProviderCapabilities;

/**
 * Builds the prompt from the post (cases 12 to 24).
 */
final class PromptBuilder
{
    public const VARIABLES = ['title', 'summary', 'category', 'tags', 'post_type', 'style', 'site_language'];
    public const WARNED_OPTION = 'ffp_template_warned';

    public static function preview(int $post_id, ?string $override = null, ?ProviderCapabilities $caps = null): PromptPreview
    {
        $settings = Settings::all();
        $max = (int) $settings['prompt_limit'];
        if ($caps !== null && $caps->max_prompt_chars > 0) {
            $max = min($max, $caps->max_prompt_chars);
        }
        $variables = self::variables($post_id, $settings);
        $negative = (string) $settings['negative_prompt'];
        $is_override = $override !== null && trim($override) !== '';

        if ($is_override) {
            $prompt = self::clean((string) $override);
        } else {
            $template = self::template($post_id, (string) $settings['template']);
            $prompt = $variables['title'] === '' ? '' : self::clean(self::render($template, $variables));
        }

        $truncated = false;
        if ($prompt !== '') {
            [$prompt, $truncated] = self::cut($prompt, $max);
            $filtered = apply_filters('ffp_prompt', $prompt, $post_id, [
                'template' => (string) $settings['template'],
                'variables' => $variables,
                'override' => $is_override,
                'negative_prompt' => $negative,
            ]);
            if (is_string($filtered)) {
                [$prompt, $cut_again] = self::cut(self::clean($filtered), $max);
                $truncated = $truncated || $cut_again;
            } else {
                Plugin::logger()->notice('The ffp_prompt filter returned a non-string; ignored.', [
                    'post_id' => $post_id,
                    'type' => get_debug_type($filtered),
                ]);
            }
        }

        return new PromptPreview($prompt, $variables, $truncated, $max, $is_override, $negative, $variables['title']);
    }

    /**
     * @param array<string, mixed> $settings
     * @return array<string, string>
     */
    public static function variables(int $post_id, array $settings): array
    {
        $post = get_post($post_id);
        $title = $post instanceof \WP_Post ? self::plain($post->post_title) : '';
        $vars = [
            'title' => $title,
            'summary' => $post instanceof \WP_Post ? self::summary($post, (int) $settings['summary_words']) : '',
            'category' => self::category($post_id),
            'tags' => self::tags($post_id, (int) $settings['tags_in_prompt']),
            'post_type' => $post instanceof \WP_Post ? $post->post_type : '',
            'style' => (string) $settings['style'],
            'site_language' => (string) get_bloginfo('language'),
        ];
        $filtered = apply_filters('ffp_prompt_variables', $vars, $post_id);
        if (is_array($filtered)) {
            foreach ($filtered as $key => $value) {
                if (is_string($key) && is_scalar($value)) {
                    $vars[$key] = (string) $value;
                }
            }
        }
        return $vars;
    }

    /** Resolve the template: prompt-library first when present, then the filter. */
    private static function template(int $post_id, string $template): string
    {
        if (Integrations::prompt_library_active()) {
            $library = apply_filters('prompt_library_get_template', null, 'featured-from-prompt/featured-image', [
                'post_id' => $post_id,
                'default' => $template,
            ]);
            if (is_string($library) && trim($library) !== '') {
                $template = $library;
            }
        }
        $filtered = apply_filters('ffp_prompt_template', $template, $post_id);
        return is_string($filtered) && $filtered !== '' ? $filtered : $template;
    }

    /** @param array<string, string> $variables */
    public static function render(string $template, array $variables): string
    {
        $unknown = [];
        $out = (string) preg_replace_callback('/\{([a-z_][a-z0-9_]*)\}/i', static function (array $m) use ($variables, &$unknown): string {
            if (array_key_exists($m[1], $variables)) {
                return $variables[$m[1]];
            }
            $unknown[] = $m[1];
            return $m[0];
        }, $template);
        if ($unknown !== []) {
            $hash = md5($template);
            if (get_option(self::WARNED_OPTION, '') !== $hash) {
                update_option(self::WARNED_OPTION, $hash, false);
                Plugin::logger()->notice('Unknown prompt template variables left as typed.', ['variables' => array_values(array_unique($unknown))]);
            }
        }
        return $out;
    }

    /** Case 20: tidy the rendered text. */
    public static function clean(string $text): string
    {
        $text = (string) preg_replace('/[\x{0000}-\x{0008}\x{000B}\x{000C}\x{000E}-\x{001F}\x{007F}-\x{009F}]/u', '', $text);
        $text = str_replace(["\r\n", "\r", "\n", "\t"], ' ', $text);
        $text = str_replace(
            ["\u{2018}", "\u{2019}", "\u{201A}", "\u{201B}", "\u{201C}", "\u{201D}", "\u{201E}", "\u{00AB}", "\u{00BB}"],
            ["'", "'", "'", "'", '"', '"', '"', '"', '"'],
            $text
        );
        $text = (string) preg_replace('/\s+/u', ' ', $text);
        // Drop empty labelled fragments such as "Topic: ." and empty quotes.
        $sentences = preg_split('/(?<=[.!?])\s+/u', trim($text)) ?: [];
        $kept = [];
        foreach ($sentences as $sentence) {
            if (preg_match('/^[^:.!?]{1,60}:\s*[.!?]*$/u', $sentence) === 1) {
                continue;
            }
            if (preg_match('/^[\p{P}\s]*$/u', $sentence) === 1) {
                continue;
            }
            $kept[] = $sentence;
        }
        $text = implode(' ', $kept);
        $text = (string) preg_replace('/\s+([.,;:!?])/u', '$1', $text);
        $text = (string) preg_replace('/([,;:])\s*(?=[.!?])/u', '', $text);
        $text = (string) preg_replace('/([.,;:!?])(\s*\1)+/u', '$1', $text);
        $text = (string) preg_replace('/\s+/u', ' ', $text);
        return trim($text);
    }

    /**
     * Case 21: cut at the last word boundary, counted in characters.
     *
     * @return array{0: string, 1: bool}
     */
    public static function cut(string $text, int $max): array
    {
        if ($max <= 0 || mb_strlen($text) <= $max) {
            return [$text, false];
        }
        $cut = mb_substr($text, 0, $max);
        $next = mb_substr($text, $max, 1);
        if ($next !== '' && preg_match('/\s/u', $next) !== 1) {
            $space = self::last_space($cut);
            if ($space > 0) {
                $cut = mb_substr($cut, 0, $space);
            }
        }
        $cut = rtrim($cut, " \t,;:-");
        return [$cut, true];
    }

    private static function last_space(string $text): int
    {
        $len = mb_strlen($text);
        for ($i = $len - 1; $i > 0; $i--) {
            if (preg_match('/\s/u', mb_substr($text, $i, 1)) === 1) {
                return $i;
            }
        }
        return 0;
    }

    public static function plain(string $text): string
    {
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = wp_strip_all_tags($text);
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    private static function summary(\WP_Post $post, int $words): string
    {
        if ($words <= 0) {
            return '';
        }
        $source = trim($post->post_excerpt) !== '' ? $post->post_excerpt : $post->post_content;
        $text = strip_shortcodes($source);
        $text = wp_strip_all_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = (string) preg_replace('~\b(?:https?://|www\.)\S+~iu', ' ', $text);
        $text = (string) preg_replace('/[^\s@<>]+@[^\s@<>]+\.[^\s@<>]+/u', ' ', $text);
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        if ($text === '') {
            return '';
        }
        $parts = preg_split('/\s+/u', $text) ?: [];
        return implode(' ', array_slice($parts, 0, $words));
    }

    private static function category(int $post_id): string
    {
        $primary = apply_filters('ffp_primary_term', null, $post_id);
        if ($primary instanceof \WP_Term) {
            return self::plain($primary->name);
        }
        $terms = wp_get_object_terms($post_id, 'category', ['orderby' => 'term_order', 'order' => 'ASC']);
        if (!is_array($terms)) {
            return '';
        }
        $default = (int) get_option('default_category', 0);
        foreach ($terms as $term) {
            if (!$term instanceof \WP_Term) {
                continue;
            }
            if ((int) $term->term_id === $default || $term->slug === 'uncategorized') {
                return '';
            }
            return self::plain($term->name);
        }
        return '';
    }

    private static function tags(int $post_id, int $limit): string
    {
        if ($limit <= 0) {
            return '';
        }
        $terms = wp_get_object_terms($post_id, 'post_tag', ['orderby' => 'term_order', 'order' => 'ASC']);
        if (!is_array($terms)) {
            return '';
        }
        $names = [];
        foreach ($terms as $term) {
            if ($term instanceof \WP_Term) {
                $names[] = self::plain($term->name);
            }
            if (count($names) >= $limit) {
                break;
            }
        }
        return implode(', ', $names);
    }
}
