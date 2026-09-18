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
        $completedCounts = $this->completedCountsByAssignee();

        foreach ($this->storage->assigneesWithOpenTasks() as $userId) {
            $tasks = $this->storage->tasksForAssignee($userId);
            $completedCount = $completedCounts[$userId] ?? 0;
            $channels = array_values(array_unique(array_map(
                static fn (array $task): string => $task['channelId'],
                $tasks
            )));

            $report[] = sprintf(
                'DM %s: %d open task(s) across %d channel(s) (%s)%s',
                $userId,
                count($tasks),
                count($channels),
                implode(', ', $channels),
                $completedCount > 0 ? "; {$completedCount} completed since last check" : ''
            );

            if ($dryRun) {
                continue;
            }

            $blocks = [
                ...$this->assigneeDigestHeaderBlocks($completedCount, count($tasks)),
                ...$this->listRenderer->renderMyTasks($tasks),
            ];
            $dmChannel = $this->slackApi->openDm($userId);
            $this->slackApi->postMessage($dmChannel, $blocks, self::DIGEST_FALLBACK_TEXT);
        }

        return $report;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function assigneeDigestHeaderBlocks(int $completedCount, int $openCount): array
    {
        $blocks = [];

        if ($completedCount > 0) {
            $blocks[] = $this->textBlock(sprintf(
                '✅ You completed %d task%s since the last check.',
                $completedCount,
                $completedCount === 1 ? '' : 's'
            ));
        }

        $blocks[] = $this->textBlock(sprintf(
            'You have %d open task%s.',
            $openCount,
            $openCount === 1 ? '' : 's'
        ));

        return $blocks;
    }

    /**
     * User id => number of currently-done tasks assigned to them, across
     * every channel. sweepAndRepublish() deletes every done row on every
     * run, so whatever's still done right now — before that pass runs —
     * is exactly what's been completed since the last run, no timestamp
     * comparison needed.
     *
     * @return array<string, int>
     */
    private function completedCountsByAssignee(): array
    {
        $counts = [];

        foreach ($this->storage->channelsWithTasks() as $channelId) {
            foreach ($this->storage->tasksForChannel($channelId) as $task) {
                if ($task['status'] !== 'done' || $task['assigneeUserId'] === null) {
                    continue;
                }

                $counts[$task['assigneeUserId']] = ($counts[$task['assigneeUserId']] ?? 0) + 1;
            }
        }

        return $counts;
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

            $openCount = count($this->storage->tasksForChannel($channelId, includeDone: false));

            $report[] = sprintf(
                'Post to %s: %d open task(s) total, %d unassigned',
                $channelId,
                $openCount,
                count($unassigned)
            );

            if ($dryRun) {
                continue;
            }

            $lines = array_map(static fn (array $task): string => '• ' . $task['title'], $unassigned);
            $text = sprintf('There are %d open task%s in this channel.', $openCount, $openCount === 1 ? '' : 's')
                . "\n\n*Unassigned tasks:*\n" . implode("\n", $lines);

            $this->slackApi->postMessage($channelId, [$this->textBlock($text)], self::UNASSIGNED_FALLBACK_TEXT);
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

    /**
     * @return array<string, mixed>
     */
    private function textBlock(string $text): array
    {
        return ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => $text]];
    }
}
