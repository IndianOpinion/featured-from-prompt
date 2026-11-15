<?php

declare(strict_types=1);

namespace IndianOpinion\FeaturedFromPrompt\Tests\Integration;

use IndianOpinion\FeaturedFromPrompt\Tests\Support\MysqliWpdb;
use IndianOpinion\FeaturedFromPrompt\WpdbStorage;
use PHPUnit\Framework\TestCase;

/**
 * WpdbStorage against a real MySQL or MariaDB. Skipped unless FFP_TEST_DB
 * is set, for example "127.0.0.1:3306:root::ffp_test" (host, port, user,
 * password, database) or with FFP_TEST_DB_SOCKET for a socket.
 */
final class WpdbStorageTest extends TestCase
{
    private MysqliWpdb $db;
    private WpdbStorage $storage;

    /** @return array{0: string, 1: int, 2: string, 3: string, 4: string, 5: string} */
    private static function config(): array
    {
        $env = (string) getenv('FFP_TEST_DB');
        $parts = explode(':', $env);
        return [$parts[0] ?? '127.0.0.1', (int) ($parts[1] ?? 3306), (string) getenv('FFP_TEST_DB_SOCKET'), $parts[2] ?? 'root', $parts[3] ?? '', $parts[4] ?? 'ffp_test'];
    }

    private static function connect(): MysqliWpdb
    {
        [$host, $port, $socket, $user, $pass, $db] = self::config();
        return new MysqliWpdb($host, $port, $socket, $user, $pass, $db);
    }

    protected function setUp(): void
    {
        if (getenv('FFP_TEST_DB') === false || getenv('FFP_TEST_DB') === '') {
            $this->markTestSkipped('Set FFP_TEST_DB to run the database tests.');
        }
        $this->db = self::connect();
        $this->db->query('DROP TABLE IF EXISTS wp_ffp_requests, wp_ffp_counters, wp_options');
        $this->db->query("CREATE TABLE wp_options (option_id bigint unsigned NOT NULL AUTO_INCREMENT, option_name varchar(191) NOT NULL DEFAULT '', option_value longtext NOT NULL, autoload varchar(20) NOT NULL DEFAULT 'yes', PRIMARY KEY (option_id), UNIQUE KEY option_name (option_name))");
        $this->storage = new WpdbStorage($this->db);
        $GLOBALS['ffp_test_dbdelta_target'] = $this->db;
        $this->storage->install();
    }

    public function test_schema_is_verified_with_show_columns(): void
    {
        $this->assertSame([], $this->storage->missing_columns());
        $this->db->query('ALTER TABLE wp_ffp_requests DROP COLUMN runner');
        $this->assertSame(['wp_ffp_requests.runner'], $this->storage->missing_columns());
        $this->db->query('DROP TABLE wp_ffp_counters');
        $missing = $this->storage->missing_columns();
        $this->assertContains('wp_ffp_counters.scope', $missing);
        $this->assertCount(4, $missing);
    }

    public function test_request_rows_round_trip_and_claim(): void
    {
        $row = [
            'id' => '0b6f3c1e-7f43-4a51-9d55-0d2d2b1f0a11', 'post_id' => 12, 'state' => 'queued', 'trigger_type' => 'manual',
            'user_id' => 3, 'prompt' => "Prompt with 'quotes' and \u{0928}\u{092E}", 'negative_prompt' => '', 'provider_id' => 'fake',
            'priority' => 10, 'attempts' => 0, 'next_run' => 100, 'heartbeat' => 0, 'reason' => 'queued', 'attachment_id' => 0,
            'created_at' => 100, 'updated_at' => 100, 'runner' => 'own', 'record' => ['a' => 1],
        ];
        $this->assertTrue($this->storage->insert_request($row));
        $got = $this->storage->get_request($row['id']);
        $this->assertSame($row['prompt'], $got['prompt']);
        $this->assertSame(['a' => 1], $got['record']);
        $this->assertSame(12, $got['post_id']);
        $this->assertSame($row['id'], $this->storage->active_request_for_post(12)['id']);
        $this->assertCount(1, $this->storage->due_requests(100, 10));
        $this->assertCount(0, $this->storage->due_requests(99, 10));

        $this->assertTrue($this->storage->update_request($row['id'], ['state' => 'running'], 'queued'));
        $this->assertFalse($this->storage->update_request($row['id'], ['state' => 'running'], 'queued'), 'second claim loses');
        $this->assertSame(1, $this->storage->count_requests(['running']));
        $this->storage->update_request($row['id'], ['state' => 'done', 'updated_at' => 50]);
        $this->assertSame(1, $this->storage->anonymise_user(3));
        $this->assertSame(1, $this->storage->delete_final_before(60, 10));
        $this->assertNull($this->storage->get_request($row['id']));
    }

    public function test_reserve_refund_count_and_locks(): void
    {
        $this->assertTrue($this->storage->reserve('day', '2026-11-15', 2));
        $this->assertTrue($this->storage->reserve('day', '2026-11-15', 2));
        $this->assertFalse($this->storage->reserve('day', '2026-11-15', 2));
        $this->assertFalse($this->storage->reserve('day', '2026-11-15', 0));
        $this->storage->refund('day', '2026-11-15');
        $this->assertSame(1, $this->storage->count('day', '2026-11-15'));
        $this->storage->refund('day', '2026-11-15');
        $this->storage->refund('day', '2026-11-15');
        $this->assertSame(0, $this->storage->count('day', '2026-11-15'));
        $this->storage->reserve('day', '2026-11-01', 5);
        $this->assertSame(1, $this->storage->purge_counters_before('2026-11-08'));

        $this->assertTrue($this->storage->acquire_lock('batch', 60, 1000));
        $this->assertFalse($this->storage->acquire_lock('batch', 60, 1030));
        $this->assertTrue($this->storage->acquire_lock('batch', 60, 1061), 'an expired lock is taken over');
        $this->storage->release_lock('batch');
        $this->assertTrue($this->storage->acquire_lock('batch', 60, 1062));

        $this->db->query("INSERT INTO wp_options (option_name, option_value) VALUES ('ffp_settings', '" . $this->db->link->real_escape_string(serialize(['enabled' => false])) . "')");
        $this->assertSame(['enabled' => false], $this->storage->fresh_option('ffp_settings'));
        $this->assertSame('x', $this->storage->fresh_option('missing', 'x'));
    }

    /**
     * Case 31: two workers at cap minus one, concurrently: exactly one wins.
     */
    public function test_case_31_concurrent_reservations_give_exactly_one_success(): void
    {
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl is needed for the concurrency test.');
        }
        $rounds = 20;
        $workers = 8;
        for ($round = 0; $round < $rounds; $round++) {
            $day = sprintf('2026-12-%02d', $round + 1);
            for ($i = 0; $i < 4; $i++) {
                $this->storage->reserve('day', $day, 5);
            }
            $pids = [];
            for ($w = 0; $w < $workers; $w++) {
                $pid = pcntl_fork();
                if ($pid === 0) {
                    // Report by signal so the child never runs destructors on
                    // the parent's inherited connection.
                    $storage = new WpdbStorage(self::connect());
                    posix_kill(posix_getpid(), $storage->reserve('day', $day, 5) ? SIGUSR1 : SIGUSR2);
                    exit(2);
                }
                $pids[] = $pid;
            }
            $wins = 0;
            foreach ($pids as $pid) {
                pcntl_waitpid($pid, $status);
                if (pcntl_wifsignaled($status) && pcntl_wtermsig($status) === SIGUSR1) {
                    $wins++;
                }
            }
            $this->assertSame(1, $wins, 'round ' . $round);
            $this->assertSame(5, $this->storage->count('day', $day));
        }
    }
}
