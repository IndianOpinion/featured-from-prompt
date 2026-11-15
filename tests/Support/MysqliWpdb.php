<?php

declare(strict_types=1);

namespace IndianOpinion\FeaturedFromPrompt\Tests\Support;

/**
 * The slice of wpdb that WpdbStorage uses, on mysqli, for the integration
 * suite. Placeholders follow wpdb::prepare (%s, %d, %f).
 */
final class MysqliWpdb
{
    public string $prefix = 'wp_';
    public string $options = 'wp_options';
    public \mysqli $link;
    private bool $suppress = false;

    public function __construct(string $host, int $port, string $socket, string $user, string $password, string $db)
    {
        mysqli_report(MYSQLI_REPORT_OFF);
        $link = mysqli_init();
        if ($link === false || !$link->real_connect($host, $user, $password, $db, $port, $socket !== '' ? $socket : null)) {
            throw new \RuntimeException('Cannot connect to the test database.');
        }
        $this->link = $link;
        $this->link->set_charset('utf8mb4');
    }

    public function get_charset_collate(): string
    {
        return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
    }

    public function suppress_errors(bool $suppress = true): bool
    {
        $old = $this->suppress;
        $this->suppress = $suppress;
        return $old;
    }

    public function esc_like(string $text): string
    {
        return addcslashes($text, '_%\\');
    }

    public function prepare(string $query, mixed ...$args): string
    {
        $i = 0;
        return (string) preg_replace_callback('/%[sdfi]/', function (array $m) use (&$i, $args): string {
            $value = $args[$i++] ?? '';
            return match ($m[0]) {
                '%i' => '`' . str_replace('`', '``', (string) $value) . '`',
                '%d' => (string) (int) $value,
                '%f' => (string) (float) $value,
                default => "'" . $this->link->real_escape_string((string) $value) . "'",
            };
        }, $query);
    }

    public function query(string $sql): int|bool
    {
        $result = $this->link->query($sql);
        if ($result === false) {
            if (!$this->suppress) {
                throw new \RuntimeException($this->link->error . ' in ' . $sql);
            }
            return false;
        }
        if ($result instanceof \mysqli_result) {
            $result->free();
            return true;
        }
        return (int) $this->link->affected_rows;
    }

    public function get_var(string $sql): ?string
    {
        $row = $this->first($sql, MYSQLI_NUM);
        return $row === null || $row[0] === null ? null : (string) $row[0];
    }

    /** @return array<string, mixed>|null */
    public function get_row(string $sql, string $output = 'ARRAY_A'): ?array
    {
        return $this->first($sql, MYSQLI_ASSOC);
    }

    /** @return list<array<string, mixed>> */
    public function get_results(string $sql, string $output = 'ARRAY_A'): array
    {
        $result = $this->select($sql);
        if ($result === null) {
            return [];
        }
        $rows = $result->fetch_all(MYSQLI_ASSOC);
        $result->free();
        return $rows;
    }

    /** @return list<mixed> */
    public function get_col(string $sql, int $x = 0): array
    {
        $result = $this->select($sql);
        if ($result === null) {
            return [];
        }
        $out = [];
        while (($row = $result->fetch_row()) !== null) {
            $out[] = $row[$x];
        }
        $result->free();
        return $out;
    }

    /** @param array<string, mixed> $data */
    public function insert(string $table, array $data): int|false
    {
        $cols = implode(',', array_map(static fn ($c) => '`' . $c . '`', array_keys($data)));
        $vals = implode(',', array_map(fn ($v) => is_int($v) ? (string) $v : "'" . $this->link->real_escape_string((string) $v) . "'", array_values($data)));
        $result = $this->query("INSERT INTO `{$table}` ({$cols}) VALUES ({$vals})");
        return is_int($result) ? $result : false;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $where
     */
    public function update(string $table, array $data, array $where): int|false
    {
        $set = [];
        foreach ($data as $c => $v) {
            $set[] = '`' . $c . '` = ' . (is_int($v) ? (string) $v : "'" . $this->link->real_escape_string((string) $v) . "'");
        }
        $cond = [];
        foreach ($where as $c => $v) {
            $cond[] = '`' . $c . '` = ' . (is_int($v) ? (string) $v : "'" . $this->link->real_escape_string((string) $v) . "'");
        }
        $result = $this->query("UPDATE `{$table}` SET " . implode(', ', $set) . ' WHERE ' . implode(' AND ', $cond));
        return is_int($result) ? $result : false;
    }

    private function select(string $sql): ?\mysqli_result
    {
        $result = $this->link->query($sql);
        if ($result === false) {
            if (!$this->suppress) {
                throw new \RuntimeException($this->link->error . ' in ' . $sql);
            }
            return null;
        }
        return $result instanceof \mysqli_result ? $result : null;
    }

    /** @return array<mixed>|null */
    private function first(string $sql, int $mode): ?array
    {
        $result = $this->select($sql);
        if ($result === null) {
            return null;
        }
        $row = $result->fetch_array($mode);
        $result->free();
        return is_array($row) ? $row : null;
    }
}
