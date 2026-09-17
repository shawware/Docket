<?php

// Copyright © 2026 shawware.com.au

declare(strict_types=1);

namespace Shawware\Docket;

use Shawware\Docket\Storage\StorageInterface;

/**
 * Shared routing for Slack's slash command, block actions, view
 * submissions, and events. Included directly by public/index.php — not
 * autoloaded via Composer, since it sits outside src/.
 */
final class Router
{
    public function __construct(
        private readonly StorageInterface $storage,
        private readonly ChannelListService $channelListService,
        private readonly int $priorityGap
    ) {
    }

    /**
     * Handles `/docket <title>` — the simplest add path, appending to the
     * bottom of the requesting channel's list.
     *
     * @param array<string, mixed> $payload
     * @return array{response_type: string, text: string}
     */
    public function handleSlashCommand(array $payload): array
    {
        $channelId = (string) ($payload['channel_id'] ?? '');
        $userId = (string) ($payload['user_id'] ?? '');
        $title = trim((string) ($payload['text'] ?? ''));

        if ($title === '') {
            return $this->textResponse('Usage: /docket <task title>');
        }

        $openTasks = $this->storage->tasksForChannel($channelId, includeDone: false);
        $maxPriority = $openTasks === [] ? 0 : max(array_column($openTasks, 'priority'));

        $this->storage->createTask(
            $channelId,
            $title,
            null,
            $maxPriority + $this->priorityGap,
            false,
            null,
            $userId
        );

        $this->channelListService->publish($channelId);

        return $this->textResponse("Added: {$title}");
    }

    /** @return array{response_type: string, text: string} */
    private function textResponse(string $text): array
    {
        return ['response_type' => 'ephemeral', 'text' => $text];
    }
}
