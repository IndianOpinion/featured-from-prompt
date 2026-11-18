<?php

// phpcs:disable PSR1.Files.SideEffects

declare(strict_types=1);

use IndianOpinion\FeaturedFromPrompt\AcceptResult;
use IndianOpinion\FeaturedFromPrompt\Api;
use IndianOpinion\FeaturedFromPrompt\PromptPreview;
use IndianOpinion\FeaturedFromPrompt\Provider\ImageProvider;
use IndianOpinion\FeaturedFromPrompt\RejectResult;
use IndianOpinion\FeaturedFromPrompt\RequestRecord;
use IndianOpinion\FeaturedFromPrompt\RequestResult;

if (!function_exists('ffp_request')) {
    /** @param array<string, mixed> $args */
    function ffp_request(int $post_id, array $args = []): RequestResult
    {
        return Api::request($post_id, $args);
    }
}

if (!function_exists('ffp_run_request')) {
    /** @param array<string, mixed> $args */
    function ffp_run_request(string $request_id, array $args = []): RequestRecord
    {
        return Api::run_request($request_id, $args);
    }
}

if (!function_exists('ffp_preview_prompt')) {
    function ffp_preview_prompt(int $post_id, ?string $override = null): PromptPreview
    {
        return Api::preview_prompt($post_id, $override);
    }
}

if (!function_exists('ffp_accept')) {
    function ffp_accept(int $attachment_id, int $user_id): AcceptResult
    {
        return Api::accept($attachment_id, $user_id);
    }
}

if (!function_exists('ffp_reject')) {
    function ffp_reject(int $attachment_id, int $user_id, string $reason = ''): RejectResult
    {
        return Api::reject($attachment_id, $user_id, $reason);
    }
}

if (!function_exists('ffp_status')) {
    /** @return array<string, mixed> */
    function ffp_status(int $post_id): array
    {
        return Api::status($post_id);
    }
}

if (!function_exists('ffp_is_generated')) {
    function ffp_is_generated(int $attachment_id): bool
    {
        return Api::is_generated($attachment_id);
    }
}

if (!function_exists('ffp_is_placeholder')) {
    function ffp_is_placeholder(int $attachment_id): bool
    {
        return Api::is_placeholder($attachment_id);
    }
}

if (!function_exists('ffp_register_provider')) {
    function ffp_register_provider(ImageProvider $provider): void
    {
        Api::register_provider($provider);
    }
}
