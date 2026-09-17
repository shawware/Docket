<?php

// Copyright © 2026 shawware.com.au

declare(strict_types=1);

namespace Shawware\Docket\Storage;

/**
 * Applies numbered, plain-SQL migration files in order, tracking which
 * ones have already run in a `schema_migrations` table. Safe to run
 * repeatedly — already-applied files are skipped.
 */
final class MigrationRunner
{
    public function __construct(
        private readonly \PDO $pdo,
        private readonly string $migrationsDir
    ) {
    }

    /**
     * @return array<int, string> filenames of the migrations applied by
     *         this call (empty if everything was already up to date)
     */
    public function run(): array
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations (
                filename VARCHAR(255) NOT NULL,
                applied_at DATETIME NOT NULL,
                PRIMARY KEY (filename)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );

        $alreadyApplied = $this->pdo
            ->query('SELECT filename FROM schema_migrations')
            ->fetchAll(\PDO::FETCH_COLUMN);

        $files = glob($this->migrationsDir . '/*.sql') ?: [];
        sort($files);

        $applied = [];

        foreach ($files as $file) {
            $filename = basename($file);

            if (in_array($filename, $alreadyApplied, true)) {
                continue;
            }

            $this->pdo->exec((string) file_get_contents($file));

            $statement = $this->pdo->prepare(
                'INSERT INTO schema_migrations (filename, applied_at) VALUES (?, ?)'
            );
            $statement->execute([$filename, (new \DateTimeImmutable())->format('Y-m-d H:i:s')]);

            $applied[] = $filename;
        }

        return $applied;
    }
}
