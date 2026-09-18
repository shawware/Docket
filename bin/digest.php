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

$dryRun = array_key_exists('dry-run', getopt('', ['dry-run']));

$digestService = new DigestService($storage, $slackApi, $channelListService, $listRenderer);
$report = $digestService->run($dryRun);

// The real (cron) run prints nothing on success — cron mails whatever a
// job writes to stdout to the account owner, and a weekly action log is
// noise nobody reads. --dry-run is the one case there's someone at a
// terminal actually wanting to see this.
if ($dryRun) {
    foreach ($report as $line) {
        echo '[DRY RUN] ' . $line . "\n";
    }
    echo "Dry run complete — no messages were sent and no data was changed.\n";
}
