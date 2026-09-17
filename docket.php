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
    /** @var array<int, string> action_ids handled from the row overflow menu. */
    private const HANDLED_TASK_MENU_ACTIONS = ['mark_done', 'move_up', 'move_down', 'reopen'];

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

    /**
     * Handles a `block_actions` payload from the pinned list's row
     * overflow menu: Done, Move up, Move down, or Reopen. Edit and
     * Remind-me options also exist on the menu but aren't handled yet
     * (Phase 5) — anything not in HANDLED_TASK_MENU_ACTIONS is ignored.
     *
     * @param array<string, mixed> $payload
     */
    public function handleBlockAction(array $payload): void
    {
        $action = $payload['actions'][0] ?? [];

        if (($action['action_id'] ?? null) !== 'task_menu') {
            return;
        }

        $value = (string) ($action['selected_option']['value'] ?? '');
        $parts = explode(':', $value, 2);
        $taskId = (int) ($parts[0] ?? 0);
        $taskAction = $parts[1] ?? '';

        if (!in_array($taskAction, self::HANDLED_TASK_MENU_ACTIONS, true)) {
            return;
        }

        match ($taskAction) {
            'mark_done' => $this->storage->markDone($taskId),
            'move_up' => $this->storage->swapPriority($taskId, 'up'),
            'move_down' => $this->storage->swapPriority($taskId, 'down'),
            'reopen' => $this->storage->reopenTask($taskId),
        };

        $channelId = (string) ($payload['channel']['id'] ?? '');
        $this->channelListService->publish($channelId);
    }

    /** @return array{response_type: string, text: string} */
    private function textResponse(string $text): array
    {
        return ['response_type' => 'ephemeral', 'text' => $text];
    }
}
