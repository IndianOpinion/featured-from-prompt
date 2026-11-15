<?php

declare(strict_types=1);

namespace IndianOpinion\FeaturedFromPrompt\Tests\Unit;

use IndianOpinion\FeaturedFromPrompt\PromptBuilder;
use IndianOpinion\FeaturedFromPrompt\Provider\ProviderCapabilities;
use IndianOpinion\FeaturedFromPrompt\Queue;
use IndianOpinion\FeaturedFromPrompt\Reason;
use IndianOpinion\FeaturedFromPrompt\Settings;
use IndianOpinion\FeaturedFromPrompt\Tests\Support\FakeProvider;
use IndianOpinion\FeaturedFromPrompt\Tests\TestCase;
use WP;

/**
 * Cases 12 to 24.
 */
final class PromptTest extends TestCase
{
    private function terms(int $post, string $tax, array $names): void
    {
        $terms = [];
        foreach ($names as $i => $name) {
            $terms[] = new \WP_Term(500 + $i, $name, strtolower($name), $tax);
        }
        WP::$terms[$post][$tax] = $terms;
    }

    public function test_case_12_template_renders_each_variable_and_keeps_unknown(): void
    {
        $post = $this->post(['post_title' => 'Title', 'post_excerpt' => 'Short summary']);
        $this->terms($post, 'category', ['Weather']);
        $this->terms($post, 'post_tag', ['rain', 'farms']);
        $this->settings(['template' => 'T={title} S={summary} C={category} G={tags} P={post_type} Y={style} L={site_language} U={foo}', 'style' => 'ink']);
        $preview = PromptBuilder::preview($post);
        $this->assertSame('T=Title S=Short summary C=Weather G=rain, farms P=post Y=ink L=en-GB U={foo}', $preview->prompt);
        $this->assertTrue($this->has_log('Unknown prompt template variables'));
    }

    public function test_case_13_title_entities_and_tags_render_as_plain_text(): void
    {
        $post = $this->post(['post_title' => 'Rain &amp; <em>hail</em> &#8216;soon&#8217;']);
        $vars = PromptBuilder::variables($post, Settings::all());
        $this->assertSame("Rain & hail \u{2018}soon\u{2019}", $vars['title']);
        $this->assertStringContainsString("titled \"Rain & hail 'soon'\"", PromptBuilder::preview($post)->prompt);
    }

    public function test_case_14_summary_from_excerpt_or_cleaned_content(): void
    {
        $with_excerpt = $this->post(['post_excerpt' => 'The excerpt wins.', 'post_content' => 'Body text']);
        $this->assertSame('The excerpt wins.', PromptBuilder::variables($with_excerpt, Settings::all())['summary']);

        $content = '<!-- wp:paragraph --><p>Read [gallery ids="1,2"] more at https://example.org/x or www.example.org, write to a.person@example.org today.</p><!-- /wp:paragraph -->';
        $post = $this->post(['post_content' => $content]);
        $summary = PromptBuilder::variables($post, Settings::all())['summary'];
        $this->assertSame('Read more at or write to today.', $summary);

        $this->settings(['summary_words' => 3]);
        $this->assertSame('Read more at', PromptBuilder::variables($post, Settings::all())['summary']);
        $this->settings(['summary_words' => 0]);
        $this->assertSame('', PromptBuilder::variables($post, Settings::all())['summary']);
    }

    public function test_case_15_primary_term_then_first_category_and_uncategorized_is_empty(): void
    {
        $post = $this->post();
        $this->terms($post, 'category', ['Politics', 'Economy']);
        $this->assertSame('Politics', PromptBuilder::variables($post, Settings::all())['category']);
        add_filter('ffp_primary_term', static fn () => new \WP_Term(9, 'Economy', 'economy', 'category'));
        $this->assertSame('Economy', PromptBuilder::variables($post, Settings::all())['category']);
        remove_all_filters('ffp_primary_term');
        $this->terms($post, 'category', ['Uncategorized']);
        $this->assertSame('', PromptBuilder::variables($post, Settings::all())['category']);
    }

    public function test_case_16_tags_limit_in_order(): void
    {
        $post = $this->post();
        $this->terms($post, 'post_tag', ['a', 'b', 'c', 'd', 'e', 'f', 'g']);
        $this->assertSame('a, b, c, d, e', PromptBuilder::variables($post, Settings::all())['tags']);
    }

    public function test_case_17_style_appears(): void
    {
        $this->settings(['style' => 'linocut print']);
        $this->assertStringContainsString('Style: linocut print.', PromptBuilder::preview($this->post())->prompt);
    }

    public function test_case_18_default_template_is_generic(): void
    {
        $post = $this->post(['post_title' => 'Bridge opens', 'post_excerpt' => 'A new bridge links two towns.']);
        $this->terms($post, 'category', ['Infrastructure']);
        $expected = 'An editorial illustration for an article titled "Bridge opens". It is about: A new bridge links two towns. Topic: Infrastructure. Style: clean editorial illustration, muted colours, wide composition. Do not include any text, letters, numbers, logos, watermarks, signatures or captions. Do not depict any real, identifiable person.';
        $this->assertSame($expected, PromptBuilder::preview($post)->prompt);
        $this->assertStringNotContainsString(get_bloginfo('name'), $expected);
    }

    public function test_case_19_negative_prompt_only_to_supporting_provider(): void
    {
        $this->settings(['negative_prompt' => 'blurry']);
        $this->provider->caps = new ProviderCapabilities(negative_prompt: true);
        $this->generate($this->post());
        $this->assertSame('blurry', $this->provider->requests[0]->negative_prompt);

        $this->provider->caps = new ProviderCapabilities(negative_prompt: false);
        $this->generate($this->post());
        $this->assertSame('', $this->provider->requests[1]->negative_prompt);
    }

    public function test_case_20_empty_category_drops_the_labelled_fragment(): void
    {
        $prompt = PromptBuilder::preview($this->post(['post_excerpt' => 'Something.']))->prompt;
        $this->assertStringNotContainsString('Topic:', $prompt);
        $this->assertStringNotContainsString('..', $prompt);
        $this->assertSame('A b. C.', PromptBuilder::clean("A\x07 b.. Topic: . C ."));
    }

    public function test_case_21_cut_at_word_boundary_in_characters(): void
    {
        [$cut, $was] = PromptBuilder::cut('नमस्ते दुनिया यह एक परीक्षण है', 12);
        $this->assertTrue($was);
        $this->assertSame('नमस्ते', $cut);
        [$cut] = PromptBuilder::cut('😀😀 😀😀😀 😀', 5);
        $this->assertSame('😀😀', $cut);
        $this->assertSame(2, mb_strlen($cut));

        $this->provider->caps = new ProviderCapabilities(max_prompt_chars: 120);
        $post = $this->post(['post_title' => str_repeat('विस्तार ', 40)]);
        $result = $this->queue($post);
        $row = $this->storage->get_request((string) $result->request_id);
        $this->assertLessThanOrEqual(120, mb_strlen((string) $row['prompt']));
        $this->assertTrue($row['record']['prompt_truncated']);
        $this->assertSame(Reason::PROMPT_TRUNCATED, get_post_meta($post, Queue::LAST_META, true)['reason']);
    }

    public function test_case_22_empty_title_ends_prompt_empty_without_counting(): void
    {
        $post = $this->post(['post_title' => '  ']);
        $this->assertSame(Reason::PROMPT_EMPTY, $this->queue($post)->reason);
        $this->assertSame(0, $this->storage->count('day', $this->clock->day()));
        $this->assertSame([], $this->provider->requests);
    }

    public function test_case_23_override_replaces_and_is_cleaned_and_cut(): void
    {
        $post = $this->post();
        $this->settings(['prompt_limit' => 100]);
        $preview = PromptBuilder::preview($post, "  A   lighthouse\x01 at dusk..  " . str_repeat('word ', 40));
        $this->assertTrue($preview->override);
        $this->assertStringStartsWith('A lighthouse at dusk.', $preview->prompt);
        $this->assertLessThanOrEqual(100, mb_strlen($preview->prompt));
        $this->assertTrue($preview->truncated);
        $this->assertStringNotContainsString('Rain returns', $preview->prompt);
    }

    public function test_case_24_non_string_filter_result_ignored_and_logged(): void
    {
        add_filter('ffp_prompt', static fn () => ['not', 'a', 'string']);
        $prompt = PromptBuilder::preview($this->post())->prompt;
        $this->assertStringContainsString('Rain returns', $prompt);
        $this->assertTrue($this->has_log('non-string'));
    }
}
