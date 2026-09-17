<?php

// Copyright © 2026 shawware.com.au

declare(strict_types=1);

namespace Shawware\Docket\Modals;

/**
 * Pure builder for the add/edit-task modal's `views.open` "view" payload.
 * Add vs. edit is decided by $taskId (null means add); pre-fill values
 * are independent of that — an add can still be pre-filled, e.g. by the
 * "Add as task" message shortcut. Both converge on the same modal, per
 * CLAUDE.md — only the title/submit text and whether $taskId is present
 * (which decides create vs. update on submission) differ.
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
     *     title?: string,
     *     assigneeUserId?: ?string,
     *     important?: bool,
     *     dueDate?: ?\DateTimeImmutable,
     *     sourcePermalink?: ?string
     * } $prefill Values to pre-fill; any key may be omitted.
     * @return array<string, mixed>
     */
    public static function build(string $channelId, ?int $taskId = null, array $prefill = []): array
    {
        return [
            'type' => 'modal',
            'callback_id' => self::CALLBACK_ID,
            'private_metadata' => json_encode(['channelId' => $channelId, 'taskId' => $taskId]),
            'title' => ['type' => 'plain_text', 'text' => $taskId === null ? 'Add task' : 'Edit task'],
            'submit' => ['type' => 'plain_text', 'text' => $taskId === null ? 'Add' : 'Save'],
            'close' => ['type' => 'plain_text', 'text' => 'Cancel'],
            'blocks' => [
                [
                    'type' => 'input',
                    'block_id' => 'title_block',
                    'label' => ['type' => 'plain_text', 'text' => 'Title'],
                    'element' => array_filter([
                        'type' => 'plain_text_input',
                        'action_id' => 'title_input',
                        'initial_value' => $prefill['title'] ?? null,
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
                        'initial_user' => $prefill['assigneeUserId'] ?? null,
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
                        'initial_date' => ($prefill['dueDate'] ?? null)?->format('Y-m-d'),
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
                        'initial_options' => ($prefill['important'] ?? false) ? [self::IMPORTANT_OPTION] : null,
                    ], static fn (mixed $value): bool => $value !== null),
                ],
                [
                    'type' => 'input',
                    'block_id' => 'link_block',
                    'label' => ['type' => 'plain_text', 'text' => 'Link'],
                    'optional' => true,
                    'element' => array_filter([
                        'type' => 'plain_text_input',
                        'action_id' => 'link_input',
                        'placeholder' => ['type' => 'plain_text', 'text' => 'Paste a Slack link to a message (optional)'],
                        'initial_value' => $prefill['sourcePermalink'] ?? null,
                    ], static fn (mixed $value): bool => $value !== null),
                ],
            ],
        ];
    }
}
