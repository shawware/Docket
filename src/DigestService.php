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
 *
 * Every pass reports what it did (or, with $dryRun, what it *would* do)
 * as plain-text lines, so bin/digest.php's --dry-run flag can preview a
 * run with no Slack calls and no storage mutations.
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

    /**
     * @return array<int, string>
     */
    public function run(bool $dryRun = false): array
    {
        return [
            ...$this->sendAssigneeDigests($dryRun),
            ...$this->sendUnassignedSummaries($dryRun),
            ...$this->sweepAndRepublish($dryRun),
        ];
    }

    /**
     * @return array<int, string>
     */
    private function sendAssigneeDigests(bool $dryRun): array
    {
        $report = [];

        foreach ($this->storage->assigneesWithOpenTasks() as $userId) {
            $tasks = $this->storage->tasksForAssignee($userId);
            $channels = array_values(array_unique(array_map(
                static fn (array $task): string => $task['channelId'],
                $tasks
            )));

            $report[] = sprintf(
                'DM %s: %d open task(s) across %d channel(s) (%s)',
                $userId,
                count($tasks),
                count($channels),
                implode(', ', $channels)
            );

            if ($dryRun) {
                continue;
            }

            $blocks = $this->listRenderer->renderMyTasks($tasks);
            $dmChannel = $this->slackApi->openDm($userId);
            $this->slackApi->postMessage($dmChannel, $blocks, self::DIGEST_FALLBACK_TEXT);
        }

        return $report;
    }

    /**
     * @return array<int, string>
     */
    private function sendUnassignedSummaries(bool $dryRun): array
    {
        $report = [];

        foreach ($this->storage->channelsWithOpenTasks() as $channelId) {
            $unassigned = $this->storage->unassignedTasksForChannel($channelId);

            if ($unassigned === []) {
                continue;
            }

            $report[] = sprintf(
                'Post to %s: unassigned summary (%d task(s))',
                $channelId,
                count($unassigned)
            );

            if ($dryRun) {
                continue;
            }

            $lines = array_map(static fn (array $task): string => '• ' . $task['title'], $unassigned);
            $blocks = [[
                'type' => 'section',
                'text' => ['type' => 'mrkdwn', 'text' => "*Unassigned tasks:*\n" . implode("\n", $lines)],
            ]];

            $this->slackApi->postMessage($channelId, $blocks, self::UNASSIGNED_FALLBACK_TEXT);
        }

        return $report;
    }

    /**
     * @return array<int, string>
     */
    private function sweepAndRepublish(bool $dryRun): array
    {
        $report = [];

        foreach ($this->storage->channelsWithTasks() as $channelId) {
            $doneCount = count(array_filter(
                $this->storage->tasksForChannel($channelId),
                static fn (array $task): bool => $task['status'] === 'done'
            ));

            $report[] = sprintf('Sweep %s: %d done task(s), then republish', $channelId, $doneCount);

            if ($dryRun) {
                continue;
            }

            $this->storage->sweepDoneTasks($channelId);
            $this->channelListService->publish($channelId);
        }

        return $report;
    }
}
