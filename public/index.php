<?php

// Copyright © 2026 shawware.com.au

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../docket.php';
require __DIR__ . '/../env.php';

use Shawware\Docket\ChannelListService;
use Shawware\Docket\ListRenderer;
use Shawware\Docket\Router;
use Shawware\Docket\SlackApi;
use Shawware\Docket\Storage\MySqlStorage;

$root = dirname(__DIR__);

if (is_file($root . '/.env')) {
    Dotenv\Dotenv::createImmutable($root)->load();
}

$path = (string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');

$slackRoutes = ['/slack/commands', '/slack/interactions', '/slack/events'];

// Anything else — the bare domain, robots.txt, any guessed/scanned path —
// gets the static placeholder and never touches Slack or the database, per
// CLAUDE.md's "Hosting & deployment" section: no redirect, no diagnostic
// information, no DB connection triggered by bots probing random paths.
if (!in_array($path, $slackRoutes, true)) {
    http_response_code(200);
    header('Content-Type: text/html; charset=utf-8');
    readfile($root . '/resources/placeholder.html');
    return;
}

$rawBody = (string) file_get_contents('php://input');
$timestamp = $_SERVER['HTTP_X_SLACK_REQUEST_TIMESTAMP'] ?? '';
$signature = $_SERVER['HTTP_X_SLACK_SIGNATURE'] ?? '';
$signingSecret = (string) envValue('SLACK_SIGNING_SECRET');

$slackApi = new SlackApi(new GuzzleHttp\Client(), (string) envValue('SLACK_BOT_TOKEN'));

if (!$slackApi->verifySignature($signingSecret, $timestamp, $rawBody, $signature)) {
    error_log("[Docket] signature verification FAILED for {$path} (timestamp={$timestamp})");
    http_response_code(401);
    header('Content-Type: text/plain');
    echo 'Invalid signature';
    return;
}

if ($path !== '/slack/commands') {
    // Block actions (/slack/interactions) and events (/slack/events) are
    // not wired up yet — acknowledge with an empty 200, now that the
    // signature is verified, so Slack does not retry.
    http_response_code(200);
    return;
}

$pdo = new PDO(
    (string) envValue('DB_DSN'),
    envValue('DB_USER'),
    envValue('DB_PASS'),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$config = require $root . '/config/task.php';

$storage = new MySqlStorage($pdo);
$listRenderer = new ListRenderer();
$channelListService = new ChannelListService($storage, $slackApi, $listRenderer, $config['dueSoonWindowDays']);
$router = new Router($storage, $channelListService, $config['priorityGap']);

parse_str($rawBody, $payload);
header('Content-Type: application/json');
echo json_encode($router->handleSlashCommand($payload));
