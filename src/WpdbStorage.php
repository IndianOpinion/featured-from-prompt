<?php

declare(strict_types=1);

namespace IndianOpinion\FeaturedFromPrompt;

/**
 * Storage on the WordPress database (MySQL or MariaDB).
 *
 * Tables are per site ($wpdb->prefix), so on multisite every site has its
 * own queue and counters. dbDelta is not trusted: after every install the
 * live columns are read back with SHOW COLUMNS and the plugin refuses to
 * run while any is missing.
 */
final class WpdbStorage implements Storage
{
    public const SCHEMA_VERSION = 1;
    public const SCHEMA_OPTION = 'ffp_schema_version';

    /** Request table columns, in order. */
    public const COLUMNS = [
        'id', 'post_id', 'state', 'trigger_type', 'user_id', 'prompt', 'negative_prompt', 'provider_id',
        'priority', 'attempts', 'next_run', 'heartbeat', 'reason', 'attachment_id', 'created_at',
        'updated_at', 'runner', 'record',
    ];

    public const COUNTER_COLUMNS = ['scope', 'day', 'count'];

    private const INT_COLUMNS = [
        'post_id', 'user_id', 'priority', 'attempts', 'next_run', 'heartbeat', 'attachment_id',
        'created_at', 'updated_at',
    ];

    /** @var \wpdb */
    private $db;

    /** @param \wpdb|null $db */
    public function __construct($db = null)
    {
        if ($db === null) {
            global $wpdb;
            $db = $wpdb;
        }
        $this->db = $db;
    }

    public function requests_table(): string
    {
        return $this->db->prefix . 'ffp_requests';
    }

    public function counters_table(): string
    {
        return $this->db->prefix . 'ffp_counters';
    }

    public function install(): void
    {
        $charset = $this->db->get_charset_collate();
        $requests = $this->requests_table();
        $counters = $this->counters_table();
        $sql = "CREATE TABLE {$requests} (
  id char(36) NOT NULL,
  post_id bigint(20) unsigned NOT NULL DEFAULT 0,
  state varchar(20) NOT NULL DEFAULT 'queued',
  trigger_type varchar(20) NOT NULL DEFAULT 'manual',
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  prompt text NOT NULL,
  negative_prompt text NOT NULL,
  provider_id varchar(40) NOT NULL DEFAULT '',
  priority int(11) NOT NULL DEFAULT 0,
  attempts int(11) NOT NULL DEFAULT 0,
  next_run bigint(20) NOT NULL DEFAULT 0,
  heartbeat bigint(20) NOT NULL DEFAULT 0,
  reason varchar(40) NOT NULL DEFAULT '',
  attachment_id bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at bigint(20) NOT NULL DEFAULT 0,
  updated_at bigint(20) NOT NULL DEFAULT 0,
  runner varchar(20) NOT NULL DEFAULT 'own',
  record longtext NOT NULL,
  PRIMARY KEY  (id),
  KEY state_next (state,next_run),
  KEY post_id (post_id)
) {$charset};
CREATE TABLE {$counters} (
  scope varchar(64) NOT NULL,
  day char(10) NOT NULL,
  count int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY  (scope,day)
) {$charset};";
        if (!function_exists('dbDelta')) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }
        dbDelta($sql);
        update_option(self::SCHEMA_OPTION, self::SCHEMA_VERSION, true);
    }

    public function missing_columns(): array
    {
        $missing = [];
        foreach ([[$this->requests_table(), self::COLUMNS], [$this->counters_table(), self::COUNTER_COLUMNS]] as [$table, $want]) {
            $suppress = $this->db->suppress_errors(true);
            $rows = $this->db->get_col((string) $this->db->prepare('SHOW COLUMNS FROM %i', $table), 0);
            $this->db->suppress_errors($suppress);
            $have = is_array($rows) ? array_map('strval', $rows) : [];
            foreach ($want as $column) {
                if (!in_array($column, $have, true)) {
                    $missing[] = $table . '.' . $column;
                }
            }
        }
        return $missing;
    }

    public function drop(): void
    {
        $this->exec($this->db->prepare('DROP TABLE IF EXISTS %i', $this->requests_table()));
        $this->exec($this->db->prepare('DROP TABLE IF EXISTS %i', $this->counters_table()));
    }

    public function insert_request(array $row): bool
    {
        $data = $this->encode($row);
        return $this->db->insert($this->requests_table(), $data) === 1;
    }

    public function get_request(string $id): ?array
    {
        $row = $this->db->get_row(
            $this->db->prepare('SELECT * FROM %i WHERE id = %s', $this->requests_table(), $id),
            ARRAY_A
        );
        return is_array($row) ? $this->decode($row) : null;
    }

    public function update_request(string $id, array $fields, ?string $expected_state = null): bool
    {
        $where = ['id' => $id];
        if ($expected_state !== null) {
            $where['state'] = $expected_state;
        }
        $result = $this->db->update($this->requests_table(), $this->encode($fields), $where);
        return is_int($result) && $result > 0;
    }

    public function active_request_for_post(int $post_id): ?array
    {
        $row = $this->db->get_row(
            $this->db->prepare(
                "SELECT * FROM %i WHERE post_id = %d AND state IN ('queued','running') ORDER BY created_at ASC LIMIT 1",
                $this->requests_table(),
                $post_id
            ),
            ARRAY_A
        );
        return is_array($row) ? $this->decode($row) : null;
    }

    public function due_requests(int $now, int $limit): array
    {
        $rows = $this->db->get_results(
            $this->db->prepare(
                "SELECT * FROM %i WHERE state = 'queued' AND next_run <= %d ORDER BY priority DESC, created_at ASC LIMIT %d",
                $this->requests_table(),
                $now,
                $limit
            ),
            ARRAY_A
        );
        return $this->decode_all($rows);
    }

    public function list_requests(array $states, int $limit, int $post_id = 0): array
    {
        $rows = $this->db->get_results(
            $this->db->prepare(
                "SELECT * FROM %i WHERE (%s = '' OR FIND_IN_SET(state, %s) > 0) AND (%d = 0 OR post_id = %d) ORDER BY created_at DESC LIMIT %d",
                $this->requests_table(),
                implode(',', $states),
                implode(',', $states),
                $post_id,
                $post_id,
                $limit
            ),
            ARRAY_A
        );
        return $this->decode_all($rows);
    }

    public function count_requests(array $states): int
    {
        return (int) $this->db->get_var($this->db->prepare(
            "SELECT COUNT(*) FROM %i WHERE (%s = '' OR FIND_IN_SET(state, %s) > 0)",
            $this->requests_table(),
            implode(',', $states),
            implode(',', $states)
        ));
    }

    public function delete_final_before(int $time, int $limit): int
    {
        $result = $this->exec($this->db->prepare(
            "DELETE FROM %i WHERE state IN ('done','failed','skipped','cancelled') AND updated_at < %d LIMIT %d",
            $this->requests_table(),
            $time,
            $limit
        ));
        return is_int($result) ? $result : 0;
    }

    /** @return list<array<string, mixed>> */
    public function requests_by_user(int $user_id, int $limit): array
    {
        $rows = $this->db->get_results(
            $this->db->prepare(
                'SELECT * FROM %i WHERE user_id = %d ORDER BY created_at DESC LIMIT %d',
                $this->requests_table(),
                $user_id,
                $limit
            ),
            ARRAY_A
        );
        return $this->decode_all($rows);
    }

    public function anonymise_user(int $user_id): int
    {
        $result = $this->db->update($this->requests_table(), ['user_id' => 0], ['user_id' => $user_id]);
        return is_int($result) ? $result : 0;
    }

    public function reserve(string $scope, string $day, int $cap): bool
    {
        if ($cap <= 0) {
            return false;
        }
        $table = $this->counters_table();
        $this->exec($this->db->prepare(
            'INSERT IGNORE INTO %i (scope, day, count) VALUES (%s, %s, 0)',
            $table,
            $scope,
            $day
        ));
        // One statement: increments only while below the cap.
        $result = $this->exec($this->db->prepare(
            'UPDATE %i SET count = count + 1 WHERE scope = %s AND day = %s AND count < %d',
            $table,
            $scope,
            $day,
            $cap
        ));
        return $result === 1;
    }

    public function refund(string $scope, string $day): void
    {
        $this->exec($this->db->prepare(
            'UPDATE %i SET count = count - 1 WHERE scope = %s AND day = %s AND count > 0',
            $this->counters_table(),
            $scope,
            $day
        ));
    }

    public function count(string $scope, string $day): int
    {
        return (int) $this->db->get_var($this->db->prepare(
            'SELECT count FROM %i WHERE scope = %s AND day = %s',
            $this->counters_table(),
            $scope,
            $day
        ));
    }

    public function purge_counters_before(string $day): int
    {
        $result = $this->exec($this->db->prepare(
            'DELETE FROM %i WHERE day < %s',
            $this->counters_table(),
            $day
        ));
        return is_int($result) ? $result : 0;
    }

    public function fresh_option(string $name, mixed $default = null): mixed
    {
        $value = $this->db->get_var($this->db->prepare(
            'SELECT option_value FROM %i WHERE option_name = %s LIMIT 1',
            $this->db->options,
            $name
        ));
        if ($value === null) {
            return $default;
        }
        return maybe_unserialize($value);
    }

    public function acquire_lock(string $name, int $ttl, int $now): bool
    {
        $option = '_ffp_lock_' . $name;
        $expires = (string) ($now + $ttl);
        $inserted = $this->exec($this->db->prepare(
            "INSERT IGNORE INTO %i (option_name, option_value, autoload) VALUES (%s, %s, 'off')",
            $this->db->options,
            $option,
            $expires
        ));
        if ($inserted === 1) {
            return true;
        }
        // Take over an expired lock, atomically.
        $taken = $this->exec($this->db->prepare(
            'UPDATE %i SET option_value = %s WHERE option_name = %s AND CAST(option_value AS UNSIGNED) < %d',
            $this->db->options,
            $expires,
            $option,
            $now
        ));
        return $taken === 1;
    }

    public function release_lock(string $name): void
    {
        $this->exec($this->db->prepare(
            'DELETE FROM %i WHERE option_name = %s',
            $this->db->options,
            '_ffp_lock_' . $name
        ));
    }

    /** Run a prepared statement; a failed prepare runs nothing. */
    private function exec(?string $sql): int|bool
    {
        if ($sql === null || $sql === '') {
            return false;
        }
        return $this->db->query($sql);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function encode(array $row): array
    {
        $out = [];
        foreach ($row as $key => $value) {
            if (!in_array($key, self::COLUMNS, true)) {
                continue;
            }
            if ($key === 'record') {
                $out[$key] = (string) wp_json_encode(is_array($value) ? $value : []);
                continue;
            }
            $out[$key] = in_array($key, self::INT_COLUMNS, true) ? (int) $value : (string) $value;
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function decode(array $row): array
    {
        foreach (self::INT_COLUMNS as $key) {
            $row[$key] = (int) ($row[$key] ?? 0);
        }
        $record = json_decode((string) ($row['record'] ?? ''), true);
        $row['record'] = is_array($record) ? $record : [];
        return $row;
    }

    /**
     * @param mixed $rows
     * @return list<array<string, mixed>>
     */
    private function decode_all($rows): array
    {
        if (!is_array($rows)) {
            return [];
        }
        $out = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $out[] = $this->decode($row);
            }
        }
        return $out;
    }
}
