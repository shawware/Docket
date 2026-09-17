<?php

// Copyright © 2026 shawware.com.au

declare(strict_types=1);

namespace Shawware\Docket\Modals;

/**
 * Pure builder for the add/edit-task modal's `views.open` "view" payload.
 * Blank when $task is null (add); pre-filled with the task's current
 * values otherwise (edit). Both converge on the same modal, per
 * CLAUDE.md — only the title/submit text and pre-fill differ.
 */
final class TaskModal
{
    public const CALLBACK_ID = 'task_modal';

    private const IMPORTANT_OPTION = [
        'text' => ['type' => 'plain_text', 'text' => 'Important'],
        'value' => 'important',
    ];

    /**
     * @param array{
     *     id: int,
     *     title: string,
     *     assigneeUserId: ?string,
     *     important: bool,
     *     dueDate: ?\DateTimeImmutable
     * }|null $task null to build a blank "Add task" modal; the task's
     *        current values to build a pre-filled "Edit task" modal.
     * @return array<string, mixed>
     */
    public static function build(string $channelId, ?array $task = null): array
    {
        return [
            'type' => 'modal',
            'callback_id' => self::CALLBACK_ID,
            'private_metadata' => json_encode(['channelId' => $channelId, 'taskId' => $task['id'] ?? null]),
            'title' => ['type' => 'plain_text', 'text' => $task === null ? 'Add task' : 'Edit task'],
            'submit' => ['type' => 'plain_text', 'text' => $task === null ? 'Add' : 'Save'],
            'close' => ['type' => 'plain_text', 'text' => 'Cancel'],
            'blocks' => [
                [
                    'type' => 'input',
                    'block_id' => 'title_block',
                    'label' => ['type' => 'plain_text', 'text' => 'Title'],
                    'element' => array_filter([
                        'type' => 'plain_text_input',
                        'action_id' => 'title_input',
                        'initial_value' => $task['title'] ?? null,
                    ], static fn (mixed $value): bool => $value !== null),
                ],
                [
                    'type' => 'input',
                    'block_id' => 'assignee_block',
                    'label' => ['type' => 'plain_text', 'text' => 'Assignee'],
                    'optional' => true,
                    'element' => array_filter([
                        'type' => 'users_select',
                        'action_id' => 'assignee_input',
                        'initial_user' => $task['assigneeUserId'] ?? null,
                    ], static fn (mixed $value): bool => $value !== null),
                ],
                [
                    'type' => 'input',
                    'block_id' => 'due_date_block',
                    'label' => ['type' => 'plain_text', 'text' => 'Due date'],
                    'optional' => true,
                    'element' => array_filter([
                        'type' => 'datepicker',
                        'action_id' => 'due_date_input',
                        'initial_date' => ($task['dueDate'] ?? null)?->format('Y-m-d'),
                    ], static fn (mixed $value): bool => $value !== null),
                ],
                [
                    'type' => 'input',
                    'block_id' => 'important_block',
                    'label' => ['type' => 'plain_text', 'text' => 'Important'],
                    'optional' => true,
                    'element' => array_filter([
                        'type' => 'checkboxes',
                        'action_id' => 'important_input',
                        'options' => [self::IMPORTANT_OPTION],
                        'initial_options' => ($task['important'] ?? false) ? [self::IMPORTANT_OPTION] : null,
                    ], static fn (mixed $value): bool => $value !== null),
                ],
            ],
        ];
    }
}
