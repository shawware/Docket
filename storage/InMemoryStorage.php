<?php

// Copyright © 2026 shawware.com.au

declare(strict_types=1);

namespace Shawware\Docket\Storage;

/**
 * A plain-array-backed StorageInterface implementation.
 *
 * Used in tests only, so that anything depending on storage (routing,
 * slash commands) can be tested without a real database. MySqlStorage
 * implements the same interface against MySQL.
 */
final class InMemoryStorage implements StorageInterface
{
    /**
     * @var array<int, array{
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
     * }>
     */
    private array $tasks = [];

    private int $nextId = 1;

    /** @var array<string, array{channelId: string, messageTs: string}> */
    private array $listState = [];

    public function createTask(
        string $channelId,
        string $title,
        ?string $assigneeUserId,
        int $priority,
        bool $important,
        ?\DateTimeImmutable $dueDate,
        string $createdBy,
        ?string $sourcePermalink = null
    ): array {
        $task = [
            'id' => $this->nextId++,
            'channelId' => $channelId,
            'title' => $title,
            'assigneeUserId' => $assigneeUserId,
            'important' => $important,
            'priority' => $priority,
            'dueDate' => $dueDate,
            'status' => 'open',
            'createdBy' => $createdBy,
            'createdAt' => new \DateTimeImmutable(),
            'completedAt' => null,
            'sourcePermalink' => $sourcePermalink,
        ];

        $this->tasks[$task['id']] = $task;

        return $task;
    }

    public function getTask(int $taskId): ?array
    {
        return $this->tasks[$taskId] ?? null;
    }

    public function tasksForChannel(string $channelId, bool $includeDone = true): array
    {
        $inChannel = array_values(array_filter(
            $this->tasks,
            static fn (array $task): bool => $task['channelId'] === $channelId
                && ($includeDone || $task['status'] === 'open')
        ));

        $open = array_values(array_filter($inChannel, static fn (array $task): bool => $task['status'] === 'open'));
        $done = array_values(array_filter($inChannel, static fn (array $task): bool => $task['status'] === 'done'));

        usort($open, static fn (array $a, array $b): int => $a['priority'] <=> $b['priority']);

        return [...$open, ...$done];
    }

    public function tasksForAssignee(string $userId): array
    {
        $mine = array_values(array_filter(
            $this->tasks,
            static fn (array $task): bool => $task['assigneeUserId'] === $userId && $task['status'] === 'open'
        ));

        usort($mine, static fn (array $a, array $b): int => $a['priority'] <=> $b['priority']);

        return $mine;
    }

    public function updateTaskDetails(
        int $taskId,
        string $title,
        ?string $assigneeUserId,
        ?\DateTimeImmutable $dueDate,
        bool $important
    ): void {
        $this->tasks[$taskId]['title'] = $title;
        $this->tasks[$taskId]['assigneeUserId'] = $assigneeUserId;
        $this->tasks[$taskId]['dueDate'] = $dueDate;
        $this->tasks[$taskId]['important'] = $important;
    }

    public function swapPriority(int $taskId, string $direction): void
    {
        $task = $this->tasks[$taskId] ?? null;

        if ($task === null) {
            return;
        }

        $siblings = $this->tasksForChannel($task['channelId'], includeDone: false);

        $index = array_search($taskId, array_map(static fn (array $t): int => $t['id'], $siblings), true);
        $neighbourIndex = $direction === 'up' ? $index - 1 : $index + 1;

        if ($neighbourIndex < 0 || $neighbourIndex >= count($siblings)) {
            return;
        }

        $neighbour = $siblings[$neighbourIndex];

        [$this->tasks[$taskId]['priority'], $this->tasks[$neighbour['id']]['priority']] =
            [$neighbour['priority'], $task['priority']];
    }

    public function markDone(int $taskId): void
    {
        $this->tasks[$taskId]['status'] = 'done';
        $this->tasks[$taskId]['completedAt'] = new \DateTimeImmutable();
    }

    public function reopenTask(int $taskId): void
    {
        $this->tasks[$taskId]['status'] = 'open';
        $this->tasks[$taskId]['completedAt'] = null;
    }

    public function channelsWithOpenTasks(): array
    {
        $channels = array_unique(array_map(
            static fn (array $task): string => $task['channelId'],
            array_filter($this->tasks, static fn (array $task): bool => $task['status'] === 'open')
        ));

        return array_values($channels);
    }

    public function unassignedTasksForChannel(string $channelId): array
    {
        $unassigned = array_values(array_filter(
            $this->tasks,
            static fn (array $task): bool => $task['channelId'] === $channelId
                && $task['status'] === 'open'
                && $task['assigneeUserId'] === null
        ));

        usort($unassigned, static fn (array $a, array $b): int => $a['priority'] <=> $b['priority']);

        return $unassigned;
    }

    public function sweepDoneTasks(string $channelId): void
    {
        $this->tasks = array_filter(
            $this->tasks,
            static fn (array $task): bool => !($task['channelId'] === $channelId && $task['status'] === 'done')
        );
    }

    public function getListState(string $channelId): ?array
    {
        return $this->listState[$channelId] ?? null;
    }

    public function saveListState(string $channelId, string $messageTs): void
    {
        $this->listState[$channelId] = ['channelId' => $channelId, 'messageTs' => $messageTs];
    }
}
