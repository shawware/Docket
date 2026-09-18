<?php

// Copyright © 2026 shawware.com.au

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../env.php';

use Shawware\Docket\ChannelListService;
use Shawware\Docket\DigestService;
use Shawware\Docket\ListRenderer;
use Shawware\Docket\SlackApi;
use Shawware\Docket\Storage\MySqlStorage;

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

$config = require $dotenvPath . '/config/task.php';

$storage = new MySqlStorage($pdo);
$slackApi = new SlackApi(new GuzzleHttp\Client(), (string) envValue('SLACK_BOT_TOKEN'));
$listRenderer = new ListRenderer();
$channelListService = new ChannelListService(
    $storage,
    $slackApi,
    $listRenderer,
    $config['dueSoonWindowDays'],
    $config['sourceLinkMaxAgeDays']
);

$digestService = new DigestService($storage, $slackApi, $channelListService, $listRenderer);
$digestService->run();

echo "Weekly digest sent.\n";
