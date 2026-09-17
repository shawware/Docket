<?php

// Copyright © 2026 shawware.com.au

declare(strict_types=1);

namespace Shawware\Docket\Storage;

/**
 * Persists tasks and each channel's pinned-list state.
 *
 * Implementations store the `tasks` table (one row per task, scoped to a
 * channel via `channelId`) and the `list_state` table (one row per
 * channel, recording which pinned message to `chat.update`) — see
 * CLAUDE.md's "Data model" section.
 *
 * `priority` is a manual, gapped rank, scoped per channel. Nothing in
 * this interface ever changes it except swapPriority() — not
 * updateTaskDetails(), not markDone(). A task's position in the list
 * only ever changes via an explicit reorder.
 */
interface StorageInterface
{
    /**
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
    public function createTask(
        string $channelId,
        string $title,
        ?string $assigneeUserId,
        int $priority,
        bool $important,
        ?\DateTimeImmutable $dueDate,
        string $createdBy,
        ?string $sourcePermalink = null
    ): array;

    /**
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
     * }|null null if no task with that id exists.
     */
    public function getTask(int $taskId): ?array;

    /**
     * Every task in a channel. Open tasks are ordered by `priority`
     * ascending (the rendered rank); done tasks, when included, follow
     * them (order among done tasks is unspecified).
     *
     * @return array<int, array{
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
    public function tasksForChannel(string $channelId, bool $includeDone = true): array;

    /**
     * A user's tasks across every channel — the "My Tasks" query. Open
     * tasks only, ordered by `priority` ascending within each channel's
     * group (grouping/order beyond that is the caller's concern).
     *
     * @return array<int, array{
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
    public function tasksForAssignee(string $userId): array;

    /**
     * Updates a task's editable fields in one place — title, assignee,
     * due date, the Important flag, and the source link. Never touches
     * `priority`; a task's position in the list only ever changes via
     * swapPriority().
     */
    public function updateTaskDetails(
        int $taskId,
        string $title,
        ?string $assigneeUserId,
        ?\DateTimeImmutable $dueDate,
        bool $important,
        ?string $sourcePermalink
    ): void;

    /**
     * Swaps a task's `priority` with its immediate neighbour in the same
     * channel's ranked list. A no-op at the top of the list ('up') or
     * the bottom ('down').
     *
     * @param 'up'|'down' $direction
     */
    public function swapPriority(int $taskId, string $direction): void;

    /**
     * Marks a task done, recording `completedAt`. The row stays visible
     * (struck through, per the renderer) until sweepDoneTasks() removes
     * it — this method never deletes anything.
     */
    public function markDone(int $taskId): void;

    /**
     * Undoes markDone(): sets a task back to 'open' and clears
     * `completedAt`. `priority` is untouched, same as every other
     * status-only mutation. Only useful before sweepDoneTasks() removes
     * the row — after that, there's nothing left to reopen.
     */
    public function reopenTask(int $taskId): void;

    /**
     * Channel ids with at least one open task — for the weekly digest's
     * per-channel unassigned summary and the pinned-list sweep.
     *
     * @return array<int, string>
     */
    public function channelsWithOpenTasks(): array;

    /**
     * A channel's open tasks with no assignee, ordered by `priority`
     * ascending — for the weekly digest's channel-level summary.
     *
     * @return array<int, array{
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
    public function unassignedTasksForChannel(string $channelId): array;

    /**
     * Permanently removes every done task in a channel — the weekly
     * cron's cleanup of the pinned list. Open tasks are untouched.
     */
    public function sweepDoneTasks(string $channelId): void;

    /**
     * @return array{channelId: string, messageTs: string}|null null if
     *         the channel hasn't opted in yet (no pinned message exists).
     */
    public function getListState(string $channelId): ?array;

    /**
     * Records which message is the channel's pinned list, so later
     * mutations know what to `chat.update`. Upserts — a channel has at
     * most one row.
     */
    public function saveListState(string $channelId, string $messageTs): void;
}
