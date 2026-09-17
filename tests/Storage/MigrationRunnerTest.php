<?php

// Copyright © 2026 shawware.com.au

declare(strict_types=1);

namespace Shawware\Docket\Tests\Storage;

use PHPUnit\Framework\TestCase;
use Shawware\Docket\Storage\MigrationRunner;

final class MigrationRunnerTest extends TestCase
{
    private \PDO $pdo;

    protected function setUp(): void
    {
        $dsn = getenv('TEST_DB_DSN') ?: 'mysql:host=127.0.0.1;dbname=docket_test;charset=utf8mb4';
        $user = getenv('TEST_DB_USER') ?: 'root';
        $pass = getenv('TEST_DB_PASS') ?: '';

        try {
            $this->pdo = new \PDO($dsn, $user, $pass, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            ]);
        } catch (\PDOException $e) {
            self::markTestSkipped('No test MySQL connection available: ' . $e->getMessage());
        }

        // Start from a clean slate so this test proves a fresh apply, not
        // just a no-op against tables another test already created.
        $this->pdo->exec('DROP TABLE IF EXISTS list_state');
        $this->pdo->exec('DROP TABLE IF EXISTS tasks');
        $this->pdo->exec('DROP TABLE IF EXISTS schema_migrations');
    }

    public function testRunCreatesBothTablesWithTheExpectedIndexes(): void
    {
        $runner = new MigrationRunner($this->pdo, __DIR__ . '/../../storage/migrations');
        $applied = $runner->run();

        $this->assertSame(['001_create_tasks.sql', '002_create_list_state.sql'], $applied);

        $tables = $this->pdo->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertContains('tasks', $tables);
        $this->assertContains('list_state', $tables);

        $indexes = $this->pdo->query(
            "SHOW INDEX FROM tasks WHERE Key_name = 'idx_tasks_channel_priority'"
        )->fetchAll(\PDO::FETCH_ASSOC);
        $this->assertNotEmpty($indexes, 'tasks.(channel_id, priority) must have an index');
    }

    public function testRunTwiceIsIdempotent(): void
    {
        $runner = new MigrationRunner($this->pdo, __DIR__ . '/../../storage/migrations');

        $firstRun = $runner->run();
        $secondRun = $runner->run();

        $this->assertSame(['001_create_tasks.sql', '002_create_list_state.sql'], $firstRun);
        $this->assertSame([], $secondRun, 'a second run should apply nothing new');

        $count = (int) $this->pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn();
        $this->assertSame(2, $count);
    }
}
