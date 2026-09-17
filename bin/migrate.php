<?php

// Copyright © 2026 shawware.com.au

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../env.php';

use Shawware\Docket\Storage\MigrationRunner;

$dotenvPath = __DIR__ . '/..';
if (is_file($dotenvPath . '/.env')) {
    Dotenv\Dotenv::createImmutable($dotenvPath)->load();
}

$dsn = envValue('DB_DSN');
$user = envValue('DB_USER');
$pass = envValue('DB_PASS');

if ($dsn === null || $dsn === '') {
    fwrite(STDERR, "DB_DSN is not set (check your .env or environment).\n");
    exit(1);
}

$pdo = new PDO($dsn, $user ?: null, $pass ?: null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

$runner = new MigrationRunner($pdo, __DIR__ . '/../storage/migrations');
$applied = $runner->run();

if ($applied === []) {
    echo "Already up to date.\n";
} else {
    foreach ($applied as $filename) {
        echo "Applied {$filename}\n";
    }
}
