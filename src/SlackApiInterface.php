<?php

// Copyright © 2026 shawware.com.au

declare(strict_types=1);

namespace Shawware\Docket;

/**
 * Verifying incoming Slack requests and making the outbound Slack Web API
 * calls Docket needs. See SlackApi for the real implementation.
 */
interface SlackApiInterface
{
    public function verifySignature(
        string $signingSecret,
        string $timestamp,
        string $rawBody,
        string $signatureHeader
    ): bool;

    /**
     * Posts a new message and returns its `ts` (timestamp), which is the
     * id later used to `chat.update` and `pins.add` it.
     *
     * @param array<int, array<string, mixed>> $blocks
     */
    public function postMessage(string $channel, array $blocks, string $fallbackText): string;

    /**
     * @param array<int, array<string, mixed>> $blocks
     */
    public function updateMessage(string $channel, string $ts, array $blocks, string $fallbackText): void;

    /**
     * Pins a message to the channel — called once, right after the
     * channel's first `postMessage`, so the list stays visible at the top.
     */
    public function pinMessage(string $channel, string $ts): void;

    /**
     * Joins a public channel so the bot can pin a message there —
     * `pins.add` requires actual membership, not just posting rights.
     * Not possible for private channels; those need a manual `/invite`.
     */
    public function joinChannel(string $channel): void;

    /**
     * Opens a modal in response to a block action — the `trigger_id`
     * comes from that action's payload and expires after 3 seconds, so
     * this must be called synchronously, not deferred.
     *
     * @param array<string, mixed> $view
     */
    public function openView(string $triggerId, array $view): void;
}
