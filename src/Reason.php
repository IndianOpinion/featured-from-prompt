<?php

declare(strict_types=1);

namespace IndianOpinion\FeaturedFromPrompt;

/**
 * Every reason code the plugin records. Codes are stable public strings:
 * they appear in post meta, logs, REST errors and the CLI.
 */
final class Reason
{
    // Eligibility (4.1).
    public const DISABLED = 'disabled';
    public const POST_TYPE_NOT_ENABLED = 'post_type_not_enabled';
    public const NO_THUMBNAIL_SUPPORT = 'no_thumbnail_support';
    public const POST_GONE = 'post_gone';
    public const POST_PROTECTED = 'post_protected';
    public const POST_OPTED_OUT = 'post_opted_out';
    public const FILTERED_OUT = 'filtered_out';
    public const HAS_IMAGE = 'has_image';

    // Triggers and queue (4.2, 4.12, 4.13).
    public const AUTO_OFF = 'auto_off';
    public const AUTO_ATTEMPTED = 'auto_attempted';
    public const WOULD_REQUEST = 'would_request';
    public const ALREADY_QUEUED = 'already_queued';
    public const QUEUED = 'queued';
    public const ALREADY_DONE = 'already_done';
    public const NOT_FOUND = 'not_found';
    public const EXPIRED_IN_QUEUE = 'expired_in_queue';
    public const WORKER_LOST = 'worker_lost';
    public const CANCELLED = 'cancelled';
    public const LOCKED = 'locked';
    public const SCHEMA_MISSING = 'schema_missing';
    public const NO_TITLE = 'no_title';

    // Prompt (4.3).
    public const PROMPT_EMPTY = 'prompt_empty';
    public const PROMPT_TRUNCATED = 'prompt_truncated';

    // Guards (4.4).
    public const PROVIDER_NOT_CONFIGURED = 'provider_not_configured';
    public const NO_PROVIDER = 'no_provider';
    public const PROVIDER_PAUSED = 'provider_paused';
    public const CAP_POST = 'cap_post';
    public const CAP_DAY = 'cap_day';
    public const SPEND_DENIED = 'spend_denied';

    // Provider call (4.5).
    public const DOWNLOAD_FAILED = 'download_failed';
    public const AUTH_FAILED = 'auth_failed';
    public const QUOTA_EXHAUSTED = 'quota_exhausted';
    public const RATE_LIMITED = 'rate_limited';
    public const PROMPT_REFUSED = 'prompt_refused';
    public const TIMEOUT = 'timeout';
    public const TRANSIENT = 'transient';
    public const BAD_RESPONSE = 'bad_response';
    public const CONFIG_ERROR = 'config_error';

    // Checks (4.6).
    public const NOT_IMAGE = 'not_image';
    public const FILE_TOO_LARGE = 'file_too_large';
    public const DECODE_FAILED = 'decode_failed';
    public const TOO_MANY_PIXELS = 'too_many_pixels';
    public const TOO_SMALL = 'too_small';
    public const BAD_ASPECT = 'bad_aspect';

    // Storing (4.7).
    public const RECORD_FAILED = 'record_failed';
    public const STORE_FAILED = 'store_failed';
    public const SUGGESTION_READY = 'suggestion_ready';

    // Review (4.8).
    public const NOT_PENDING = 'not_pending';
    public const NOT_GENERATED = 'not_generated';
    public const WRONG_POST = 'wrong_post';
    public const FORBIDDEN = 'forbidden';
    public const BAD_NONCE = 'bad_nonce';
    public const POST_LOCKED = 'post_locked';
    public const FILE_MISSING = 'file_missing';

    // Placeholder policy (4.9).
    public const PLACEHOLDER_SET = 'placeholder_set';

    /** Plain words for the panel and the CLI. */
    public static function describe(string $code): string
    {
        $map = [
            self::DISABLED => 'The plugin is switched off.',
            self::POST_TYPE_NOT_ENABLED => 'This post type is not enabled.',
            self::NO_THUMBNAIL_SUPPORT => 'This post type has no featured images.',
            self::POST_GONE => 'The post is gone or in the trash.',
            self::POST_PROTECTED => 'Private and password-protected posts are excluded.',
            self::POST_OPTED_OUT => 'This post is opted out.',
            self::FILTERED_OUT => 'A site filter excluded this post.',
            self::HAS_IMAGE => 'The post already has a featured image.',
            self::AUTO_OFF => 'Automatic requests are off.',
            self::AUTO_ATTEMPTED => 'An automatic attempt was already made for this post.',
            self::WOULD_REQUEST => 'Log-only mode: a request would have been made.',
            self::ALREADY_QUEUED => 'A request for this post is already waiting.',
            self::QUEUED => 'Waiting in the queue.',
            self::ALREADY_DONE => 'This request has already finished.',
            self::NOT_FOUND => 'No such request.',
            self::EXPIRED_IN_QUEUE => 'The automatic request waited too long and was dropped.',
            self::WORKER_LOST => 'The worker stopped mid-call. Not retried, because the call may have been billed.',
            self::CANCELLED => 'Cancelled.',
            self::LOCKED => 'Another worker holds the lock. Will try again.',
            self::SCHEMA_MISSING => 'The plugin\'s database table is missing columns. Deactivate and reactivate the plugin.',
            self::NO_TITLE => 'The post has no title.',
            self::PROMPT_EMPTY => 'The prompt is empty. Add a title or write a prompt.',
            self::PROMPT_TRUNCATED => 'The prompt was shortened to fit.',
            self::PROVIDER_NOT_CONFIGURED => 'No image provider is configured.',
            self::NO_PROVIDER => 'The selected image provider is not installed.',
            self::PROVIDER_PAUSED => 'The image provider is paused after an account error.',
            self::CAP_POST => 'The per-post daily limit is reached.',
            self::CAP_DAY => 'The site\'s daily limit is reached. Waiting for tomorrow.',
            self::SPEND_DENIED => 'The spend meter refused the request.',
            self::DOWNLOAD_FAILED => 'The image could not be downloaded.',
            self::AUTH_FAILED => 'The provider rejected the credentials. Provider paused.',
            self::QUOTA_EXHAUSTED => 'The provider account has no quota left. Provider paused.',
            self::RATE_LIMITED => 'Rate limited by the provider. Will try again.',
            self::PROMPT_REFUSED => 'The provider refused the prompt. Edit it and try again.',
            self::TIMEOUT => 'The provider timed out.',
            self::TRANSIENT => 'Temporary provider error.',
            self::BAD_RESPONSE => 'The provider\'s answer was unusable.',
            self::CONFIG_ERROR => 'The provider settings are unusable. Nothing was sent.',
            self::NOT_IMAGE => 'The result was not a PNG, JPEG or WebP image.',
            self::FILE_TOO_LARGE => 'The image file was too large.',
            self::DECODE_FAILED => 'The image could not be decoded.',
            self::TOO_MANY_PIXELS => 'The image had too many pixels.',
            self::TOO_SMALL => 'The image was too small.',
            self::BAD_ASPECT => 'The image had the wrong shape.',
            self::RECORD_FAILED => 'The generation record could not be written. Image discarded.',
            self::STORE_FAILED => 'The image could not be stored.',
            self::SUGGESTION_READY => 'A suggestion is ready for review.',
            self::NOT_PENDING => 'This suggestion is not pending.',
            self::NOT_GENERATED => 'This is not a generated suggestion.',
            self::WRONG_POST => 'This suggestion belongs to another post.',
            self::FORBIDDEN => 'You are not allowed to do that.',
            self::BAD_NONCE => 'The security token expired. Reload the page.',
            self::POST_LOCKED => 'Another editor is working on this post.',
            self::FILE_MISSING => 'The suggestion\'s file is missing.',
            self::PLACEHOLDER_SET => 'The fallback placeholder was set.',
        ];
        return $map[$code] ?? $code;
    }
}
