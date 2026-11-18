<?php

declare(strict_types=1);

namespace IndianOpinion\FeaturedFromPrompt\Tests\Support;

use IndianOpinion\FeaturedFromPrompt\Storage;
use WP;

/**
 * In-memory storage, per site, for unit tests. The atomic contract of
 * reserve() is tested against MySQL in the integration suite.
 */
final class MemoryStorage implements Storage
{
    /** @var array<int, array<string, array<string, mixed>>> */
    public array $requests = [];
    /** @var array<int, array<string, int>> */
    public array $counters = [];
    /** @var array<int, array<string, int>> */
    public array $locks = [];
    /** @var list<string> */
    public array $missing = [];
    public bool $fail_insert = false;

    public function install(): void
    {
    }

    public function missing_columns(): array
    {
        return $this->missing;
    }

    public function drop(): void
    {
        unset($this->requests[WP::$blog], $this->counters[WP::$blog]);
    }

    public function insert_request(array $row): bool
    {
        if ($this->fail_insert) {
            return false;
        }
        $this->requests[WP::$blog][(string) $row['id']] = $row;
        return true;
    }

    public function get_request(string $id): ?array
    {
        return $this->requests[WP::$blog][$id] ?? null;
    }

    public function update_request(string $id, array $fields, ?string $expected_state = null): bool
    {
        $row = $this->requests[WP::$blog][$id] ?? null;
        if ($row === null || ($expected_state !== null && $row['state'] !== $expected_state)) {
            return false;
        }
        $this->requests[WP::$blog][$id] = array_merge($row, $fields);
        return true;
    }

    public function active_request_for_post(int $post_id): ?array
    {
        foreach ($this->rows() as $row) {
            if ((int) $row['post_id'] === $post_id && in_array($row['state'], ['queued', 'running'], true)) {
                return $row;
            }
        }
        return null;
    }

    public function due_requests(int $now, int $limit): array
    {
        $rows = array_values(array_filter($this->rows(), static fn (array $r): bool => $r['state'] === 'queued' && (int) $r['next_run'] <= $now));
        usort($rows, static fn (array $a, array $b): int => [$b['priority'], $a['created_at']] <=> [$a['priority'], $b['created_at']]);
        return array_slice($rows, 0, $limit);
    }

    public function list_requests(array $states, int $limit, int $post_id = 0): array
    {
        $rows = array_values(array_filter($this->rows(), static fn (array $r): bool => ($states === [] || in_array($r['state'], $states, true)) && ($post_id === 0 || (int) $r['post_id'] === $post_id)));
        usort($rows, static fn (array $a, array $b): int => $b['created_at'] <=> $a['created_at']);
        return array_slice($rows, 0, $limit);
    }

    public function count_requests(array $states): int
    {
        return count($this->list_requests($states, PHP_INT_MAX));
    }

    public function delete_final_before(int $time, int $limit): int
    {
        $n = 0;
        foreach ($this->rows() as $id => $row) {
            if ($n < $limit && in_array($row['state'], ['done', 'failed', 'skipped', 'cancelled'], true) && (int) $row['updated_at'] < $time) {
                unset($this->requests[WP::$blog][$id]);
                $n++;
            }
        }
        return $n;
    }

    public function requests_by_user(int $user_id, int $limit): array
    {
        return array_slice(array_values(array_filter($this->rows(), static fn (array $r): bool => (int) $r['user_id'] === $user_id)), 0, $limit);
    }

    public function anonymise_user(int $user_id): int
    {
        $n = 0;
        foreach ($this->rows() as $id => $row) {
            if ((int) $row['user_id'] === $user_id) {
                $this->requests[WP::$blog][$id]['user_id'] = 0;
                $n++;
            }
        }
        return $n;
    }

    public function reserve(string $scope, string $day, int $cap): bool
    {
        $key = $scope . '|' . $day;
        $count = $this->counters[WP::$blog][$key] ?? 0;
        if ($count >= $cap) {
            return false;
        }
        $this->counters[WP::$blog][$key] = $count + 1;
        return true;
    }

    public function refund(string $scope, string $day): void
    {
        $key = $scope . '|' . $day;
        $this->counters[WP::$blog][$key] = max(0, ($this->counters[WP::$blog][$key] ?? 0) - 1);
    }

    public function count(string $scope, string $day): int
    {
        return $this->counters[WP::$blog][$scope . '|' . $day] ?? 0;
    }

    public function purge_counters_before(string $day): int
    {
        $n = 0;
        foreach (array_keys($this->counters[WP::$blog] ?? []) as $key) {
            if (explode('|', $key)[1] < $day) {
                unset($this->counters[WP::$blog][$key]);
                $n++;
            }
        }
        return $n;
    }

    public function fresh_option(string $name, mixed $default = null): mixed
    {
        // Straight to the "database", past the simulated object cache.
        return array_key_exists($name, WP::$options[WP::$blog] ?? []) ? WP::$options[WP::$blog][$name] : $default;
    }

    public function acquire_lock(string $name, int $ttl, int $now): bool
    {
        $expires = $this->locks[WP::$blog][$name] ?? null;
        if ($expires !== null && $expires >= $now) {
            return false;
        }
        $this->locks[WP::$blog][$name] = $now + $ttl;
        return true;
    }

    public function release_lock(string $name): void
    {
        unset($this->locks[WP::$blog][$name]);
    }

    /** @return array<string, array<string, mixed>> */
    private function rows(): array
    {
        return $this->requests[WP::$blog] ?? [];
    }
}
