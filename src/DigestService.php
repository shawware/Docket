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
    private const CHANNEL_SUMMARY_FALLBACK_TEXT = 'Channel task summary';

    private const DIGEST_FALLBACK_TEXT = 'Your weekly Docket digest';

    public function __construct(
        private readonly StorageInterface $storage,
        private readonly SlackApiInterface $slackApi,
        private readonly ChannelListService $channelListService,
        private readonly ListRenderer $listRenderer
    ) {}

    /**
     * @return array<int, string>
     */
    public function run(bool $dryRun = false): array
    {
        return [
            ...$this->sendAssigneeDigests($dryRun),
            ...$this->sendChannelSummaries($dryRun),
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
                static fn(array $task): string => $task['channelId'],
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
                ...$this->listRenderer->renderMyTasks($tasks, new \DateTimeImmutable()),
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
     * Posts a channel-level heads-up when there's something worth a nudge:
     * a task completed since the last check, an open task with no owner,
     * or an open task that's overdue (regardless of who it's assigned to
     * — everyone in the channel benefits from knowing). Silent otherwise.
     * Scoped to channelsWithTasks(), not channelsWithOpenTasks(), so a
     * channel whose only task was just completed still gets its "N
     * completed" heads-up even though it now has zero open tasks.
     *
     * @return array<int, string>
     */
    private function sendChannelSummaries(bool $dryRun): array
    {
        $report = [];

        foreach ($this->storage->channelsWithTasks() as $channelId) {
            $allTasks = $this->storage->tasksForChannel($channelId);
            $openTasks = array_values(array_filter($allTasks, static fn(array $task): bool => $task['status'] === 'open'));
            $doneCount = count($allTasks) - count($openTasks);
            $unassigned = array_values(array_filter($openTasks, static fn(array $task): bool => $task['assigneeUserId'] === null));
            $overdue = $this->listRenderer->overdueTasks($openTasks, new \DateTimeImmutable());

            if ($unassigned === [] && $overdue === [] && $doneCount === 0) {
                continue;
            }

            $report[] = sprintf(
                'Post to %s: %d completed, %d open task(s) total, %d unassigned, %d overdue',
                $channelId,
                $doneCount,
                count($openTasks),
                count($unassigned),
                count($overdue)
            );

            if ($dryRun) {
                continue;
            }

            $text = '';

            if ($doneCount > 0) {
                $text .= sprintf(
                    '✅ %d task%s completed since the last check.',
                    $doneCount,
                    $doneCount === 1 ? '' : 's'
                ) . "\n\n";
            }

            $text .= sprintf(
                'There are %d open task%s in this channel.',
                count($openTasks),
                count($openTasks) === 1 ? '' : 's'
            );

            if ($overdue !== []) {
                $lines = array_map(
                    static fn(array $task): string => '• ' . $task['title'] . ' — ' . $task['dueDate']->format('Y-m-d'),
                    $overdue
                );
                $text .= "\n\n*⚠️ Overdue (" . count($overdue) . "):*\n" . implode("\n", $lines);
            }

            if ($unassigned !== []) {
                $lines = array_map(static fn(array $task): string => '• ' . $task['title'], $unassigned);
                $text .= "\n\n*Unassigned (" . count($unassigned) . "):*\n" . implode("\n", $lines);
            }

            $this->slackApi->postMessage($channelId, [$this->textBlock($text)], self::CHANNEL_SUMMARY_FALLBACK_TEXT);
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
                static fn(array $task): bool => $task['status'] === 'done'
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
