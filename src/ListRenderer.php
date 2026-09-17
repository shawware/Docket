<?php

// Copyright © 2026 shawware.com.au

declare(strict_types=1);

namespace Shawware\Docket;

/**
 * Builds the Block Kit payload for a channel's pinned task list, and for
 * the read-only "My Tasks" App Home view — pure functions, no Slack or
 * storage calls.
 *
 * Row actions use the overflow-menu layout: each open task is a single
 * `section` block with an `overflow` accessory, so the whole row (text
 * plus actions) renders on one line. See PLAN.md Phase 2 for the
 * alternative (icon-buttons) layout that was considered and not used.
 */
final class ListRenderer
{
    private const ACTION_LABELS = [
        'mark_done' => '✅ Done',
        'edit_task' => '✏️ Edit',
        'toggle_important' => '⭐ Toggle Important',
        'move_up' => '▲ Move up',
        'move_down' => '▼ Move down',
        'reopen' => '↩️ Reopen',
    ];

    /**
     * @param array<int, array{
     *     id: int,
     *     channelId: string,
     *     title: string,
     *     assigneeUserId: ?string,
     *     important: bool,
     *     priority: int,
     *     dueDate: ?\DateTimeImmutable,
     *     status: string,
     *     createdBy: string,
     *     createdAt: \DateTimeImmutable,
     *     completedAt: ?\DateTimeImmutable,
     *     sourcePermalink: ?string
     * }> $tasks open tasks ordered by priority ascending, followed by any
     *    done tasks — as returned by StorageInterface::tasksForChannel().
     * @return array<int, array<string, mixed>> Block Kit blocks.
     */
    public function render(array $tasks, \DateTimeImmutable $now, int $dueSoonWindowDays, int $sourceLinkMaxAgeDays): array
    {
        $today = new \DateTimeImmutable($now->format('Y-m-d'));
        $openTasks = array_values(array_filter($tasks, static fn (array $task): bool => $task['status'] === 'open'));
        $doneTasks = array_values(array_filter($tasks, static fn (array $task): bool => $task['status'] !== 'open'));

        $blocks = [];

        foreach ($this->buildCallout('⚠️ Overdue', $this->overdueTasks($openTasks, $today)) as $block) {
            $blocks[] = $block;
        }
        foreach ($this->buildCallout('⏰ Due soon', $this->dueSoonTasks($openTasks, $today, $dueSoonWindowDays)) as $block) {
            $blocks[] = $block;
        }

        if ($blocks !== []) {
            $blocks[] = ['type' => 'divider'];
        }

        $lastOpenIndex = count($openTasks) - 1;
        foreach ($openTasks as $index => $task) {
            $blocks[] = $this->buildTaskRow(
                $task,
                $index + 1,
                $today,
                $now,
                $sourceLinkMaxAgeDays,
                $index === 0,
                $index === $lastOpenIndex
            );
        }

        foreach ($doneTasks as $task) {
            $blocks[] = [
                'type' => 'section',
                'text' => ['type' => 'mrkdwn', 'text' => '~' . $task['title'] . '~'],
                'accessory' => [
                    'type' => 'overflow',
                    'action_id' => 'task_menu',
                    'options' => [[
                        'text' => ['type' => 'plain_text', 'text' => self::ACTION_LABELS['reopen'], 'emoji' => true],
                        'value' => $task['id'] . ':reopen',
                    ]],
                ],
            ];
        }

        $blocks[] = [
            'type' => 'actions',
            'elements' => [[
                'type' => 'button',
                'text' => ['type' => 'plain_text', 'text' => '➕ Add task', 'emoji' => true],
                'action_id' => 'add_task',
            ]],
        ];

        return $blocks;
    }

    /**
     * The read-only App Home "My Tasks" view — a user's open tasks across
     * every channel, grouped by channel. No row actions.
     *
     * @param array<int, array{
     *     id: int,
     *     channelId: string,
     *     title: string,
     *     assigneeUserId: ?string,
     *     important: bool,
     *     priority: int,
     *     dueDate: ?\DateTimeImmutable,
     *     status: string,
     *     createdBy: string,
     *     createdAt: \DateTimeImmutable,
     *     completedAt: ?\DateTimeImmutable,
     *     sourcePermalink: ?string
     * }> $tasks open tasks, as returned by StorageInterface::tasksForAssignee().
     * @return array<int, array<string, mixed>> Block Kit blocks.
     */
    public function renderMyTasks(array $tasks): array
    {
        if ($tasks === []) {
            return [[
                'type' => 'section',
                'text' => ['type' => 'mrkdwn', 'text' => '_No open tasks._'],
            ]];
        }

        $byChannel = [];
        foreach ($tasks as $task) {
            $byChannel[$task['channelId']][] = $task;
        }

        $blocks = [];
        foreach ($byChannel as $channelId => $channelTasks) {
            $blocks[] = [
                'type' => 'section',
                'text' => ['type' => 'mrkdwn', 'text' => '*<#' . $channelId . '>*'],
            ];

            $lines = array_map(
                fn (array $task): string => '• ' . $this->rowLabel($task),
                $channelTasks
            );
            $blocks[] = [
                'type' => 'section',
                'text' => ['type' => 'mrkdwn', 'text' => implode("\n", $lines)],
            ];
        }

        return $blocks;
    }

    /**
     * @param array<int, array<string, mixed>> $tasks
     * @return array<int, array<string, mixed>>
     */
    private function overdueTasks(array $tasks, \DateTimeImmutable $today): array
    {
        $overdue = array_values(array_filter(
            $tasks,
            static fn (array $task): bool => $task['dueDate'] !== null && $task['dueDate'] < $today
        ));

        usort($overdue, static fn (array $a, array $b): int => $b['dueDate'] <=> $a['dueDate']);

        return $overdue;
    }

    /**
     * @param array<int, array<string, mixed>> $tasks
     * @return array<int, array<string, mixed>>
     */
    private function dueSoonTasks(array $tasks, \DateTimeImmutable $today, int $windowDays): array
    {
        $windowEnd = $today->modify("+{$windowDays} days");

        $dueSoon = array_values(array_filter(
            $tasks,
            static fn (array $task): bool => $task['dueDate'] !== null
                && $task['dueDate'] >= $today
                && $task['dueDate'] <= $windowEnd
        ));

        usort($dueSoon, static fn (array $a, array $b): int => $a['dueDate'] <=> $b['dueDate']);

        return $dueSoon;
    }

    /**
     * @param array<int, array<string, mixed>> $tasks
     * @return array<int, array<string, mixed>>
     */
    private function buildCallout(string $heading, array $tasks): array
    {
        if ($tasks === []) {
            return [];
        }

        $lines = array_map(
            fn (array $task): string => '• ' . $task['title'] . ' — ' . $task['dueDate']->format('Y-m-d'),
            $tasks
        );

        return [[
            'type' => 'section',
            'text' => ['type' => 'mrkdwn', 'text' => "*{$heading}*\n" . implode("\n", $lines)],
        ]];
    }

    /**
     * @param array<string, mixed> $task
     * @return array<string, mixed>
     */
    private function buildTaskRow(
        array $task,
        int $rank,
        \DateTimeImmutable $today,
        \DateTimeImmutable $now,
        int $sourceLinkMaxAgeDays,
        bool $isFirst,
        bool $isLast
    ): array {
        $actions = ['mark_done', 'edit_task', 'toggle_important'];
        if (!$isFirst) {
            $actions[] = 'move_up';
        }
        if (!$isLast) {
            $actions[] = 'move_down';
        }

        $options = array_map(
            static fn (string $action): array => [
                'text' => ['type' => 'plain_text', 'text' => self::ACTION_LABELS[$action], 'emoji' => true],
                'value' => $task['id'] . ':' . $action,
            ],
            $actions
        );

        $text = $rank . '. ' . $this->rowLabel($task, $today) . $this->sourceLink($task, $now, $sourceLinkMaxAgeDays);

        return [
            'type' => 'section',
            'text' => ['type' => 'mrkdwn', 'text' => $text],
            'accessory' => [
                'type' => 'overflow',
                'action_id' => 'task_menu',
                'options' => $options,
            ],
        ];
    }

    /**
     * A trailing 🔗 link to the task's source message, only while that
     * message is likely to still exist — see CLAUDE.md's Decisions
     * section. A heuristic based on the message's own timestamp (parsed
     * straight out of the permalink, so this is accurate whether the
     * link came from the message shortcut or was pasted in by hand); a
     * link that isn't a recognizable Slack permalink falls back to the
     * task's `createdAt`. Not a live Slack check either way.
     *
     * @param array<string, mixed> $task
     */
    private function sourceLink(array $task, \DateTimeImmutable $now, int $maxAgeDays): string
    {
        if ($task['sourcePermalink'] === null) {
            return '';
        }

        $linkCreatedAt = SlackPermalink::messageTimestamp($task['sourcePermalink']) ?? $task['createdAt'];
        $expiresAt = $linkCreatedAt->modify("+{$maxAgeDays} days");

        if ($now > $expiresAt) {
            return '';
        }

        return ' <' . $task['sourcePermalink'] . '|🔗>';
    }

    /**
     * @param array<string, mixed> $task
     */
    private function rowLabel(array $task, ?\DateTimeImmutable $today = null): string
    {
        $label = $task['important'] ? '⭐ ' : '';
        $label .= $task['title'];
        $label .= ' — ' . ($task['assigneeUserId'] !== null ? '<@' . $task['assigneeUserId'] . '>' : 'Unassigned');

        if ($task['dueDate'] !== null) {
            $isOverdue = $today !== null && $task['dueDate'] < $today;
            $label .= ' (' . ($isOverdue ? '⚠️ ' : '') . $task['dueDate']->format('Y-m-d') . ')';
        }

        return $label;
    }
}
