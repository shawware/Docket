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

    public function verifySignature(string $signingSecret, string $timestamp, string $rawBody, string $signatureHeader): bool
    {
        return true;
    }

    public function postMessage(string $channel, array $blocks, string $fallbackText): string
    {
        $this->calls[] = 'postMessage';

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
}
