<?php

declare(strict_types=1);

namespace IndianOpinion\FeaturedFromPrompt;

/**
 * Everything that must be atomic or fresh: the request queue, the cap
 * counters, locks and uncached option reads.
 *
 * A request row is an array with the keys listed in WpdbStorage::COLUMNS.
 * The record column is decoded to an array.
 *
 * @phpstan-type Row array{id: string, post_id: int, state: string, trigger_type: string, user_id: int, prompt: string, negative_prompt: string, provider_id: string, priority: int, attempts: int, next_run: int, heartbeat: int, reason: string, attachment_id: int, created_at: int, updated_at: int, runner: string, record: array<string, mixed>}
 */
interface Storage
{
    /** Create or upgrade the tables. */
    public function install(): void;

    /**
     * Columns that should exist and do not, read from the live table.
     *
     * @return list<string>
     */
    public function missing_columns(): array;

    /** Remove the tables (uninstall). */
    public function drop(): void;

    /** @param array<string, mixed> $row */
    public function insert_request(array $row): bool;

    /** @return array<string, mixed>|null */
    public function get_request(string $id): ?array;

    /**
     * Update fields. With $expected_state set, the update applies only while
     * the row is still in that state (an atomic claim).
     *
     * @param array<string, mixed> $fields
     */
    public function update_request(string $id, array $fields, ?string $expected_state = null): bool;

    /** @return array<string, mixed>|null The queued or running request of a post. */
    public function active_request_for_post(int $post_id): ?array;

    /**
     * Queued rows due at $now, highest priority first, then oldest.
     *
     * @return list<array<string, mixed>>
     */
    public function due_requests(int $now, int $limit): array;

    /**
     * @param list<string> $states
     * @return list<array<string, mixed>>
     */
    public function list_requests(array $states, int $limit, int $post_id = 0): array;

    /** @param list<string> $states */
    public function count_requests(array $states): int;

    /** Delete final rows last updated before $time. Returns rows removed. */
    public function delete_final_before(int $time, int $limit): int;

    /**
     * Rows of a user, for the personal data tools.
     *
     * @return list<array<string, mixed>>
     */
    public function requests_by_user(int $user_id, int $limit): array;

    /** Set user_id to 0 on a user's rows. Returns rows changed. */
    public function anonymise_user(int $user_id): int;

    /**
     * Increment the counter only while it is below the cap, in one atomic
     * statement. Returns true when the slot was taken.
     */
    public function reserve(string $scope, string $day, int $cap): bool;

    /** Give back one slot. Never goes below 0. */
    public function refund(string $scope, string $day): void;

    public function count(string $scope, string $day): int;

    /** Remove counters for days before $day. */
    public function purge_counters_before(string $day): int;

    /** An option read straight from the database, past any object cache. */
    public function fresh_option(string $name, mixed $default = null): mixed;

    /** Atomic add with expiry. True when the lock was taken. */
    public function acquire_lock(string $name, int $ttl, int $now): bool;

    public function release_lock(string $name): void;
}
