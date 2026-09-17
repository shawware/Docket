<?php

// Copyright © 2026 shawware.com.au

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../docket.php';
require __DIR__ . '/../env.php';

use Shawware\Docket\AssignmentNotifier;
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

$debugLogging = envValue('DEBUG_LOGGING') === '1';

$slackApi = new SlackApi(new GuzzleHttp\Client(), (string) envValue('SLACK_BOT_TOKEN'));

if (!$slackApi->verifySignature($signingSecret, $timestamp, $rawBody, $signature)) {
    error_log("[Docket] signature verification FAILED for {$path} (timestamp={$timestamp})");
    http_response_code(401);
    header('Content-Type: text/plain');
    echo 'Invalid signature';
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
$channelListService = new ChannelListService(
    $storage,
    $slackApi,
    $listRenderer,
    $config['dueSoonWindowDays'],
    $config['sourceLinkMaxAgeDays']
);
$assignmentNotifier = new AssignmentNotifier($slackApi);
$router = new Router(
    $storage,
    $slackApi,
    $channelListService,
    $assignmentNotifier,
    $listRenderer,
    $config['priorityGap']
);

if ($path === '/slack/events') {
    $eventPayload = json_decode($rawBody, true) ?: [];

    if ($debugLogging) {
        error_log(sprintf(
            '[Docket] events payload type=%s event_type=%s tab=%s',
            $eventPayload['type'] ?? 'n/a',
            $eventPayload['event']['type'] ?? 'n/a',
            $eventPayload['event']['tab'] ?? 'n/a'
        ));
    }

    try {
        $challenge = $router->handleEvent($eventPayload);
    } catch (\Throwable $e) {
        error_log('[Docket] handleEvent threw: ' . $e->getMessage());
        http_response_code(200);
        return;
    }

    if ($challenge !== null) {
        header('Content-Type: text/plain');
        echo $challenge;
    }

    return;
}

if ($path === '/slack/interactions') {
    parse_str($rawBody, $formFields);
    $payload = json_decode((string) ($formFields['payload'] ?? '{}'), true);
    $payload = is_array($payload) ? $payload : [];

    if ($debugLogging) {
        error_log(sprintf(
            '[Docket] interactions payload type=%s callback_id=%s',
            $payload['type'] ?? 'n/a',
            $payload['callback_id'] ?? ($payload['view']['callback_id'] ?? 'n/a')
        ));
    }

    if (($payload['type'] ?? null) === 'view_submission') {
        $result = $router->handleViewSubmission($payload);
        header('Content-Type: application/json');
        echo json_encode($result ?? new stdClass());
        return;
    }

    if (($payload['type'] ?? null) === 'message_action') {
        $router->handleMessageShortcut($payload);
        http_response_code(200);
        return;
    }

    $router->handleBlockAction($payload);
    http_response_code(200);
    return;
}

if ($debugLogging) {
    error_log("[Docket] commands payload body={$rawBody}");
}

parse_str($rawBody, $payload);
header('Content-Type: application/json');
echo json_encode($router->handleSlashCommand($payload));
