<?php

// Copyright © 2026 shawware.com.au

declare(strict_types=1);

namespace Shawware\Docket;

use Shawware\Docket\Storage\StorageInterface;

/**
 * The weekly cron job's logic (see CLAUDE.md's "Weekly digest" feature and
 * bin/digest.php, which just bootstraps and calls run()). Three
 * independent passes: DM every assignee with open tasks, post an
 * unassigned-tasks summary to every channel that has any, then sweep done
 * tasks and republish every channel's pinned list.
 */
final class DigestService
{
    private const UNASSIGNED_FALLBACK_TEXT = 'Unassigned tasks in this channel';

    private const DIGEST_FALLBACK_TEXT = 'Your weekly Docket digest';

    public function __construct(
        private readonly StorageInterface $storage,
        private readonly SlackApiInterface $slackApi,
        private readonly ChannelListService $channelListService,
        private readonly ListRenderer $listRenderer
    ) {
    }

    public function run(): void
    {
        $this->sendAssigneeDigests();
        $this->sendUnassignedSummaries();
        $this->sweepAndRepublish();
    }

    private function sendAssigneeDigests(): void
    {
        foreach ($this->storage->assigneesWithOpenTasks() as $userId) {
            $tasks = $this->storage->tasksForAssignee($userId);
            $blocks = $this->listRenderer->renderMyTasks($tasks);
            $dmChannel = $this->slackApi->openDm($userId);
            $this->slackApi->postMessage($dmChannel, $blocks, self::DIGEST_FALLBACK_TEXT);
        }
    }

    private function sendUnassignedSummaries(): void
    {
        foreach ($this->storage->channelsWithOpenTasks() as $channelId) {
            $unassigned = $this->storage->unassignedTasksForChannel($channelId);

            if ($unassigned === []) {
                continue;
            }

            $lines = array_map(static fn (array $task): string => '• ' . $task['title'], $unassigned);
            $blocks = [[
                'type' => 'section',
                'text' => ['type' => 'mrkdwn', 'text' => "*Unassigned tasks:*\n" . implode("\n", $lines)],
            ]];

            $this->slackApi->postMessage($channelId, $blocks, self::UNASSIGNED_FALLBACK_TEXT);
        }
    }

    private function sweepAndRepublish(): void
    {
        foreach ($this->storage->channelsWithTasks() as $channelId) {
            $this->storage->sweepDoneTasks($channelId);
            $this->channelListService->publish($channelId);
        }
    }
}
