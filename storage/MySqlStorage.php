<?php

// Copyright © 2026 shawware.com.au

declare(strict_types=1);

namespace Shawware\Docket\Storage;

/**
 * StorageInterface implementation backed by MySQL, via PDO.
 */
final class MySqlStorage implements StorageInterface
{
    public function __construct(private readonly \PDO $pdo)
    {
    }

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
        $createdAt = new \DateTimeImmutable();

        $statement = $this->pdo->prepare(
            'INSERT INTO tasks
                (channel_id, title, assignee_user_id, important, priority, due_date,
                 status, created_by, created_at, completed_at, source_permalink)
             VALUES (?, ?, ?, ?, ?, ?, \'open\', ?, ?, NULL, ?)'
        );
        $statement->execute([
            $channelId,
            $title,
            $assigneeUserId,
            $important ? 1 : 0,
            $priority,
            $dueDate?->format('Y-m-d'),
            $createdBy,
            $createdAt->format('Y-m-d H:i:s'),
            $sourcePermalink,
        ]);

        return $this->getTask((int) $this->pdo->lastInsertId());
    }

    public function getTask(int $taskId): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM tasks WHERE id = ?');
        $statement->execute([$taskId]);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : $this->hydrate($row);
    }

    public function tasksForChannel(string $channelId, bool $includeDone = true): array
    {
        $sql = 'SELECT * FROM tasks WHERE channel_id = ?';
        if (!$includeDone) {
            $sql .= " AND status = 'open'";
        }
        $sql .= " ORDER BY (status = 'done') ASC, priority ASC";

        $statement = $this->pdo->prepare($sql);
        $statement->execute([$channelId]);

        return array_map($this->hydrate(...), $statement->fetchAll(\PDO::FETCH_ASSOC));
    }

    public function tasksForAssignee(string $userId): array
    {
        $statement = $this->pdo->prepare(
            "SELECT * FROM tasks WHERE assignee_user_id = ? AND status = 'open' ORDER BY priority ASC"
        );
        $statement->execute([$userId]);

        return array_map($this->hydrate(...), $statement->fetchAll(\PDO::FETCH_ASSOC));
    }

    public function updateTaskDetails(
        int $taskId,
        string $title,
        ?string $assigneeUserId,
        ?\DateTimeImmutable $dueDate,
        bool $important
    ): void {
        $statement = $this->pdo->prepare(
            'UPDATE tasks SET title = ?, assignee_user_id = ?, due_date = ?, important = ? WHERE id = ?'
        );
        $statement->execute([
            $title,
            $assigneeUserId,
            $dueDate?->format('Y-m-d'),
            $important ? 1 : 0,
            $taskId,
        ]);
    }

    public function swapPriority(int $taskId, string $direction): void
    {
        $task = $this->getTask($taskId);

        if ($task === null) {
            return;
        }

        $comparator = $direction === 'up' ? '<' : '>';
        $order = $direction === 'up' ? 'DESC' : 'ASC';

        $statement = $this->pdo->prepare(
            "SELECT id, priority FROM tasks
             WHERE channel_id = ? AND status = 'open' AND priority {$comparator} ?
             ORDER BY priority {$order}
             LIMIT 1"
        );
        $statement->execute([$task['channelId'], $task['priority']]);
        $neighbour = $statement->fetch(\PDO::FETCH_ASSOC);

        if ($neighbour === false) {
            return;
        }

        $this->pdo->beginTransaction();

        try {
            $update = $this->pdo->prepare('UPDATE tasks SET priority = ? WHERE id = ?');
            $update->execute([$neighbour['priority'], $taskId]);
            $update->execute([$task['priority'], $neighbour['id']]);

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function markDone(int $taskId): void
    {
        $statement = $this->pdo->prepare(
            "UPDATE tasks SET status = 'done', completed_at = ? WHERE id = ?"
        );
        $statement->execute([(new \DateTimeImmutable())->format('Y-m-d H:i:s'), $taskId]);
    }

    public function channelsWithOpenTasks(): array
    {
        return $this->pdo
            ->query("SELECT DISTINCT channel_id FROM tasks WHERE status = 'open'")
            ->fetchAll(\PDO::FETCH_COLUMN);
    }

    public function unassignedTasksForChannel(string $channelId): array
    {
        $statement = $this->pdo->prepare(
            "SELECT * FROM tasks
             WHERE channel_id = ? AND status = 'open' AND assignee_user_id IS NULL
             ORDER BY priority ASC"
        );
        $statement->execute([$channelId]);

        return array_map($this->hydrate(...), $statement->fetchAll(\PDO::FETCH_ASSOC));
    }

    public function sweepDoneTasks(string $channelId): void
    {
        $statement = $this->pdo->prepare("DELETE FROM tasks WHERE channel_id = ? AND status = 'done'");
        $statement->execute([$channelId]);
    }

    public function getListState(string $channelId): ?array
    {
        $statement = $this->pdo->prepare('SELECT channel_id, message_ts FROM list_state WHERE channel_id = ?');
        $statement->execute([$channelId]);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        return ['channelId' => $row['channel_id'], 'messageTs' => $row['message_ts']];
    }

    public function saveListState(string $channelId, string $messageTs): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO list_state (channel_id, message_ts) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE message_ts = VALUES(message_ts)'
        );
        $statement->execute([$channelId, $messageTs]);
    }

    /**
     * @param array<string, mixed> $row
     * @return array{
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
     * }
     */
    private function hydrate(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'channelId' => $row['channel_id'],
            'title' => $row['title'],
            'assigneeUserId' => $row['assignee_user_id'],
            'important' => (bool) $row['important'],
            'priority' => (int) $row['priority'],
            'dueDate' => $row['due_date'] === null ? null : new \DateTimeImmutable($row['due_date']),
            'status' => $row['status'],
            'createdBy' => $row['created_by'],
            'createdAt' => new \DateTimeImmutable($row['created_at']),
            'completedAt' => $row['completed_at'] === null ? null : new \DateTimeImmutable($row['completed_at']),
            'sourcePermalink' => $row['source_permalink'],
        ];
    }
}
