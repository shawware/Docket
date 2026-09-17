<?php

// Copyright © 2026 shawware.com.au

declare(strict_types=1);

namespace Shawware\Docket;

use GuzzleHttp\ClientInterface;

/**
 * Verifies incoming Slack requests and makes the outbound Slack Web API
 * calls Docket needs. No Slack SDK dependency — the call surface is small
 * enough for this thin, hand-rolled client over Guzzle.
 */
final class SlackApi implements SlackApiInterface
{
    private const BASE_URL = 'https://slack.com/api/';

    /** Slack requires requests within this many seconds of "now" — replay protection. */
    private const MAX_REQUEST_AGE_SECONDS = 300;

    public function __construct(
        private readonly ClientInterface $httpClient,
        private readonly string $botToken
    ) {
    }

    /**
     * Verifies that a request actually came from Slack: the signature
     * matches, computed the same way Slack computes it, and the request
     * isn't a stale replay of an old one.
     */
    public function verifySignature(
        string $signingSecret,
        string $timestamp,
        string $rawBody,
        string $signatureHeader
    ): bool {
        if (abs(time() - (int) $timestamp) > self::MAX_REQUEST_AGE_SECONDS) {
            return false;
        }

        $baseString = "v0:{$timestamp}:{$rawBody}";
        $expected = 'v0=' . hash_hmac('sha256', $baseString, $signingSecret);

        return hash_equals($expected, $signatureHeader);
    }

    public function postMessage(string $channel, array $blocks, string $fallbackText): string
    {
        $body = $this->call('chat.postMessage', [
            'channel' => $channel,
            'blocks' => $blocks,
            'text' => $fallbackText,
            'unfurl_links' => false,
            'unfurl_media' => false,
        ]);

        return (string) $body['ts'];
    }

    public function updateMessage(string $channel, string $ts, array $blocks, string $fallbackText): void
    {
        $this->call('chat.update', [
            'channel' => $channel,
            'ts' => $ts,
            'blocks' => $blocks,
            'text' => $fallbackText,
            'unfurl_links' => false,
            'unfurl_media' => false,
        ]);
    }

    public function pinMessage(string $channel, string $ts): void
    {
        $this->call('pins.add', [
            'channel' => $channel,
            'timestamp' => $ts,
        ]);
    }

    public function joinChannel(string $channel): void
    {
        $this->call('conversations.join', ['channel' => $channel]);
    }

    public function openView(string $triggerId, array $view): void
    {
        $this->call('views.open', [
            'trigger_id' => $triggerId,
            'view' => $view,
        ]);
    }

    public function openDm(string $userId): string
    {
        $body = $this->call('conversations.open', ['users' => $userId]);

        return (string) $body['channel']['id'];
    }

    public function publishView(string $userId, array $view): void
    {
        $this->call('views.publish', [
            'user_id' => $userId,
            'view' => $view,
        ]);
    }

    public function addReminder(string $userId, string $text, int $timeUnixTs): void
    {
        $this->call('reminders.add', [
            'text' => $text,
            'time' => $timeUnixTs,
            'user' => $userId,
        ]);
    }

    /**
     * @param array<string, mixed> $json
     * @return array<string, mixed>
     */
    private function call(string $method, array $json): array
    {
        $response = $this->httpClient->request('POST', self::BASE_URL . $method, [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->botToken,
            ],
            'json' => $json,
        ]);

        $body = json_decode((string) $response->getBody(), true);

        if (!is_array($body) || ($body['ok'] ?? false) !== true) {
            $error = is_array($body) ? ($body['error'] ?? 'unknown_error') : 'invalid_response';
            throw new \RuntimeException("Slack API call to {$method} failed: {$error}");
        }

        return $body;
    }
}
