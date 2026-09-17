<?php

// Copyright © 2026 shawware.com.au

declare(strict_types=1);

namespace Shawware\Docket\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Shawware\Docket\SlackApi;

final class SlackApiTest extends TestCase
{
    private const SIGNING_SECRET = 'test-signing-secret';

    // --- verifySignature -----------------------------------------------

    public function testVerifySignatureAcceptsAValidSignature(): void
    {
        $slackApi = $this->makeSlackApi();
        $timestamp = (string) time();
        $body = '{"type":"block_actions"}';
        $signature = 'v0=' . hash_hmac('sha256', "v0:{$timestamp}:{$body}", self::SIGNING_SECRET);

        $this->assertTrue(
            $slackApi->verifySignature(self::SIGNING_SECRET, $timestamp, $body, $signature)
        );
    }

    public function testVerifySignatureRejectsATamperedBody(): void
    {
        $slackApi = $this->makeSlackApi();
        $timestamp = (string) time();
        $signature = 'v0=' . hash_hmac(
            'sha256',
            "v0:{$timestamp}:{\"type\":\"block_actions\"}",
            self::SIGNING_SECRET
        );

        $this->assertFalse(
            $slackApi->verifySignature(self::SIGNING_SECRET, $timestamp, '{"type":"tampered"}', $signature)
        );
    }

    public function testVerifySignatureRejectsAStaleTimestamp(): void
    {
        $slackApi = $this->makeSlackApi();
        $staleTimestamp = (string) (time() - 400); // older than the 300s window
        $body = '{"type":"block_actions"}';
        $signature = 'v0=' . hash_hmac('sha256', "v0:{$staleTimestamp}:{$body}", self::SIGNING_SECRET);

        $this->assertFalse(
            $slackApi->verifySignature(self::SIGNING_SECRET, $staleTimestamp, $body, $signature)
        );
    }

    // --- postMessage / updateMessage / pinMessage / joinChannel ---------

    public function testPostMessageCallsChatPostMessageAndReturnsTheTs(): void
    {
        $history = [];
        $slackApi = $this->makeSlackApiWithMockedHttp(
            new Response(200, [], json_encode(['ok' => true, 'ts' => '1699999999.000100'])),
            $history
        );

        $ts = $slackApi->postMessage('C123', [['type' => 'section']], 'fallback');

        $this->assertSame('1699999999.000100', $ts);
        $this->assertCount(1, $history);
        $request = $history[0]['request'];

        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('https://slack.com/api/chat.postMessage', (string) $request->getUri());
        $this->assertSame('Bearer test-bot-token', $request->getHeaderLine('Authorization'));
        $this->assertSame(
            ['channel' => 'C123', 'blocks' => [['type' => 'section']], 'text' => 'fallback'],
            json_decode((string) $request->getBody(), true)
        );
    }

    public function testUpdateMessageCallsChatUpdateWithTheRightPayload(): void
    {
        $history = [];
        $slackApi = $this->makeSlackApiWithMockedHttp(
            new Response(200, [], json_encode(['ok' => true])),
            $history
        );

        $slackApi->updateMessage('C123', '1699999999.000100', [['type' => 'section']], 'fallback');

        $this->assertCount(1, $history);
        $request = $history[0]['request'];

        $this->assertSame('https://slack.com/api/chat.update', (string) $request->getUri());
        $this->assertSame(
            ['channel' => 'C123', 'ts' => '1699999999.000100', 'blocks' => [['type' => 'section']], 'text' => 'fallback'],
            json_decode((string) $request->getBody(), true)
        );
    }

    public function testPinMessageCallsPinsAddWithTheRightPayload(): void
    {
        $history = [];
        $slackApi = $this->makeSlackApiWithMockedHttp(
            new Response(200, [], json_encode(['ok' => true])),
            $history
        );

        $slackApi->pinMessage('C123', '1699999999.000100');

        $this->assertCount(1, $history);
        $request = $history[0]['request'];

        $this->assertSame('https://slack.com/api/pins.add', (string) $request->getUri());
        $this->assertSame(
            ['channel' => 'C123', 'timestamp' => '1699999999.000100'],
            json_decode((string) $request->getBody(), true)
        );
    }

    public function testJoinChannelCallsConversationsJoinWithTheRightPayload(): void
    {
        $history = [];
        $slackApi = $this->makeSlackApiWithMockedHttp(
            new Response(200, [], json_encode(['ok' => true])),
            $history
        );

        $slackApi->joinChannel('C123');

        $this->assertCount(1, $history);
        $request = $history[0]['request'];

        $this->assertSame('https://slack.com/api/conversations.join', (string) $request->getUri());
        $this->assertSame(['channel' => 'C123'], json_decode((string) $request->getBody(), true));
    }

    public function testOpenViewCallsViewsOpenWithTheRightPayload(): void
    {
        $history = [];
        $slackApi = $this->makeSlackApiWithMockedHttp(
            new Response(200, [], json_encode(['ok' => true])),
            $history
        );

        $slackApi->openView('trigger-1', ['type' => 'modal']);

        $this->assertCount(1, $history);
        $request = $history[0]['request'];

        $this->assertSame('https://slack.com/api/views.open', (string) $request->getUri());
        $this->assertSame(
            ['trigger_id' => 'trigger-1', 'view' => ['type' => 'modal']],
            json_decode((string) $request->getBody(), true)
        );
    }

    public function testPostMessageThrowsWhenSlackReturnsOkFalse(): void
    {
        $history = [];
        $slackApi = $this->makeSlackApiWithMockedHttp(
            new Response(200, [], json_encode(['ok' => false, 'error' => 'channel_not_found'])),
            $history
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('channel_not_found');

        $slackApi->postMessage('C_BAD', [], 'fallback');
    }

    // --- helpers ---------------------------------------------------------

    private function makeSlackApi(): SlackApi
    {
        return new SlackApi(new Client(), 'test-bot-token');
    }

    /**
     * @param array<int, array{request: \Psr\Http\Message\RequestInterface}> $history
     *        Passed by reference — Guzzle's history middleware appends to
     *        it as requests are made, so the caller must pass a real
     *        variable (not an inline expression) to observe the requests.
     */
    private function makeSlackApiWithMockedHttp(Response $response, array &$history): SlackApi
    {
        $mock = new MockHandler([$response]);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($history));

        $client = new Client(['handler' => $stack]);

        return new SlackApi($client, 'test-bot-token');
    }
}
