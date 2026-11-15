<?php

declare(strict_types=1);

namespace IndianOpinion\FeaturedFromPrompt;

use IndianOpinion\FeaturedFromPrompt\Provider\ImageProvider;

/** @param array<string, mixed> $args */
function request(int $post_id, array $args = []): RequestResult
{
    return Api::request($post_id, $args);
}

/** @param array<string, mixed> $args */
function run_request(string $request_id, array $args = []): RequestRecord
{
    return Api::run_request($request_id, $args);
}

function preview_prompt(int $post_id, ?string $override = null): PromptPreview
{
    return Api::preview_prompt($post_id, $override);
}

function accept(int $attachment_id, int $user_id): AcceptResult
{
    return Api::accept($attachment_id, $user_id);
}

function reject(int $attachment_id, int $user_id, string $reason = ''): RejectResult
{
    return Api::reject($attachment_id, $user_id, $reason);
}

/** @return array<string, mixed> */
function status(int $post_id): array
{
    return Api::status($post_id);
}

function is_generated(int $attachment_id): bool
{
    return Api::is_generated($attachment_id);
}

function is_placeholder(int $attachment_id): bool
{
    return Api::is_placeholder($attachment_id);
}

function register_provider(ImageProvider $provider): void
{
    Api::register_provider($provider);
}
