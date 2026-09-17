<?php

// Copyright © 2026 shawware.com.au

declare(strict_types=1);

namespace Shawware\Docket\Tests\Fakes;

use Shawware\Docket\SlackApiInterface;

/**
 * A test double that records which SlackApiInterface methods were called,
 * in order, instead of making any real HTTP calls. Used wherever a test
 * only cares that the right Slack calls happened, not their exact
 * payloads (SlackApiTest already covers those).
 */
final class RecordingSlackApi implements SlackApiInterface
{
    /** @var array<int, string> */
    public array $calls = [];

    public string $nextMessageTs = '1699999999.000100';

    public bool $joinChannelFails = false;

    /** @var array<int, array{triggerId: string, view: array<string, mixed>}> */
    public array $openedViews = [];

    /** @var array<int, array{channel: string, blocks: array<int, array<string, mixed>>, fallbackText: string}> */
    public array $postedMessages = [];

    /** @var array<int, string> */
    public array $openedDms = [];

    public string $nextDmChannel = 'D123';

    public function verifySignature(string $signingSecret, string $timestamp, string $rawBody, string $signatureHeader): bool
    {
        return true;
    }

    public function postMessage(string $channel, array $blocks, string $fallbackText): string
    {
        $this->calls[] = 'postMessage';
        $this->postedMessages[] = ['channel' => $channel, 'blocks' => $blocks, 'fallbackText' => $fallbackText];

        return $this->nextMessageTs;
    }

    public function updateMessage(string $channel, string $ts, array $blocks, string $fallbackText): void
    {
        $this->calls[] = 'updateMessage';
    }

    public function pinMessage(string $channel, string $ts): void
    {
        $this->calls[] = 'pinMessage';
    }

    public function joinChannel(string $channel): void
    {
        $this->calls[] = 'joinChannel';

        if ($this->joinChannelFails) {
            throw new \RuntimeException('method_not_supported_for_channel_type');
        }
    }

    public function openView(string $triggerId, array $view): void
    {
        $this->calls[] = 'openView';
        $this->openedViews[] = ['triggerId' => $triggerId, 'view' => $view];
    }

    public function openDm(string $userId): string
    {
        $this->calls[] = 'openDm';
        $this->openedDms[] = $userId;

        return $this->nextDmChannel;
    }
}
