<?php

// Copyright © 2026 shawware.com.au

declare(strict_types=1);

namespace Shawware\Docket\Modals;

/**
 * Pure builder for the one-off reminder modal's `views.open` "view"
 * payload — a single Slack built-in `datetimepicker` block. Submitting it
 * calls `reminders.add` for whoever clicked ⏰, not necessarily the
 * task's assignee — a shared pinned message can't show a different menu
 * per viewer, so "remind me" always means the clicking user.
 */
final class ReminderModal
{
    public const CALLBACK_ID = 'reminder_modal';

    public static function build(string $channelId, int $taskId, string $taskTitle, \DateTimeImmutable $now): array
    {
        return [
            'type' => 'modal',
            'callback_id' => self::CALLBACK_ID,
            'private_metadata' => json_encode(['channelId' => $channelId, 'taskId' => $taskId]),
            'title' => ['type' => 'plain_text', 'text' => 'Set a reminder'],
            'submit' => ['type' => 'plain_text', 'text' => 'Set reminder'],
            'close' => ['type' => 'plain_text', 'text' => 'Cancel'],
            'blocks' => [
                [
                    'type' => 'section',
                    'text' => ['type' => 'mrkdwn', 'text' => "Remind me about:\n*{$taskTitle}*"],
                ],
                [
                    'type' => 'input',
                    'block_id' => 'when_block',
                    'label' => ['type' => 'plain_text', 'text' => 'When'],
                    'element' => [
                        'type' => 'datetimepicker',
                        'action_id' => 'when_input',
                        'initial_date_time' => $now->modify('+1 hour')->getTimestamp(),
                    ],
                ],
            ],
        ];
    }
}
