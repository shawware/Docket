<?php

// Copyright © 2026 shawware.com.au

declare(strict_types=1);

namespace Shawware\Docket;

use Shawware\Docket\Storage\StorageInterface;

/**
 * Publishes a channel's pinned task list — the one call site every
 * mutation (add, reorder, done, edit, digest sweep) re-uses to keep the
 * pinned message in sync with storage.
 */
final class ChannelListService
{
    private const FALLBACK_TEXT = 'Docket task list';

    public function __construct(
        private readonly StorageInterface $storage,
        private readonly SlackApiInterface $slackApi,
        private readonly ListRenderer $listRenderer,
        private readonly int $dueSoonWindowDays
    ) {
    }

    /**
     * Renders the channel's current tasks and pushes them to Slack: an
     * update if the channel already has a pinned message, otherwise a
     * fresh post that is then pinned and recorded as the channel's
     * `list_state`.
     */
    public function publish(string $channelId): void
    {
        $tasks = $this->storage->tasksForChannel($channelId);
        $blocks = $this->listRenderer->render($tasks, new \DateTimeImmutable(), $this->dueSoonWindowDays);

        $listState = $this->storage->getListState($channelId);

        if ($listState !== null) {
            $this->slackApi->updateMessage($channelId, $listState['messageTs'], $blocks, self::FALLBACK_TEXT);

            return;
        }

        try {
            $this->slackApi->joinChannel($channelId);
        } catch (\RuntimeException) {
            // conversations.join only works for public channels. A private
            // channel's bot must already be a member via a manual /invite,
            // per CLAUDE.md — so a failure here is not fatal.
        }

        $ts = $this->slackApi->postMessage($channelId, $blocks, self::FALLBACK_TEXT);
        $this->slackApi->pinMessage($channelId, $ts);
        $this->storage->saveListState($channelId, $ts);
    }
}
