<?php

// Copyright © 2026 shawware.com.au

declare(strict_types=1);

namespace Shawware\Docket;

/**
 * DMs a task's assignee right after the storage write that assigned it —
 * the primary "push" visibility mechanism from CLAUDE.md. The caller
 * (Router) decides *whether* to notify (a new assignee, not a
 * self-assignment); this class only handles *how*.
 */
final class AssignmentNotifier
{
    public function __construct(private readonly SlackApiInterface $slackApi)
    {
    }

    /**
     * @param string|null $listMessageTs the channel's pinned list message
     *        ts, if one exists yet, to link back to it.
     */
    public function notify(string $userId, string $taskTitle, string $channelId, ?string $listMessageTs): void
    {
        $dmChannel = $this->slackApi->openDm($userId);

        $text = "You were assigned a task in <#{$channelId}>:\n*{$taskTitle}*";

        if ($listMessageTs !== null) {
            $link = 'https://slack.com/archives/' . $channelId . '/p' . str_replace('.', '', $listMessageTs);
            $text .= "\n<{$link}|View the list>";
        }

        $this->slackApi->postMessage(
            $dmChannel,
            [['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => $text]]],
            "You were assigned: {$taskTitle}"
        );
    }
}
