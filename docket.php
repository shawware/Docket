<?php

// Copyright © 2026 shawware.com.au

declare(strict_types=1);

namespace Shawware\Docket;

use Shawware\Docket\Modals\TaskModal;
use Shawware\Docket\Storage\StorageInterface;

/**
 * Shared routing for Slack's slash command, block actions, view
 * submissions, and events. Included directly by public/index.php — not
 * autoloaded via Composer, since it sits outside src/.
 */
final class Router
{
    /** @var array<int, string> task_menu actions that mutate storage directly, with no modal. */
    private const HANDLED_TASK_MENU_ACTIONS = ['mark_done', 'toggle_important', 'move_up', 'move_down', 'reopen'];

    public function __construct(
        private readonly StorageInterface $storage,
        private readonly SlackApiInterface $slackApi,
        private readonly ChannelListService $channelListService,
        private readonly AssignmentNotifier $assignmentNotifier,
        private readonly ListRenderer $listRenderer,
        private readonly int $priorityGap
    ) {
    }

    /**
     * Handles `/docket <title>` — the simplest add path, appending to the
     * bottom of the requesting channel's list.
     *
     * @param array<string, mixed> $payload
     * @return array{response_type: string, text: string}
     */
    public function handleSlashCommand(array $payload): array
    {
        $channelId = (string) ($payload['channel_id'] ?? '');
        $userId = (string) ($payload['user_id'] ?? '');
        $title = trim((string) ($payload['text'] ?? ''));

        if ($title === '') {
            return $this->textResponse('Usage: /docket <task title>');
        }

        if (!self::looksLikeAChannelId($channelId)) {
            return $this->textResponse('Docket only works in a channel — try this again there.');
        }

        $this->storage->createTask(
            $channelId,
            $title,
            null,
            $this->nextPriority($channelId),
            false,
            null,
            $userId
        );

        $this->channelListService->publish($channelId);

        return $this->textResponse("Added: {$title}");
    }

    /**
     * Handles a `block_actions` payload: the "➕ Add task" button, or one
     * of the pinned list's row overflow menu options (Done, Edit, Move
     * up, Move down, Reopen).
     *
     * @param array<string, mixed> $payload
     */
    public function handleBlockAction(array $payload): void
    {
        $action = $payload['actions'][0] ?? [];
        $channelId = (string) ($payload['channel']['id'] ?? '');
        $triggerId = (string) ($payload['trigger_id'] ?? '');

        if (($action['action_id'] ?? null) === 'add_task') {
            $this->slackApi->openView($triggerId, TaskModal::build($channelId));

            return;
        }

        if (($action['action_id'] ?? null) !== 'task_menu') {
            return;
        }

        $value = (string) ($action['selected_option']['value'] ?? '');
        $parts = explode(':', $value, 2);
        $taskId = (int) ($parts[0] ?? 0);
        $taskAction = $parts[1] ?? '';

        if ($taskAction === 'edit_task') {
            $task = $this->storage->getTask($taskId);

            if ($task !== null) {
                $this->slackApi->openView($triggerId, TaskModal::build($channelId, $task['id'], $task));
            }

            return;
        }

        if (!in_array($taskAction, self::HANDLED_TASK_MENU_ACTIONS, true)) {
            return;
        }

        match ($taskAction) {
            'mark_done' => $this->storage->markDone($taskId),
            'toggle_important' => $this->toggleImportant($taskId),
            'move_up' => $this->storage->swapPriority($taskId, 'up'),
            'move_down' => $this->storage->swapPriority($taskId, 'down'),
            'reopen' => $this->storage->reopenTask($taskId),
        };

        $this->channelListService->publish($channelId);
    }

    /**
     * Flips a task's Important flag — the only field `updateTaskDetails()`
     * doesn't let you change alone, so this reads the task's other fields
     * back out unchanged and writes them straight through.
     */
    private function toggleImportant(int $taskId): void
    {
        $task = $this->storage->getTask($taskId);

        if ($task === null) {
            return;
        }

        $this->storage->updateTaskDetails(
            $taskId,
            $task['title'],
            $task['assigneeUserId'],
            $task['dueDate'],
            !$task['important'],
            $task['sourcePermalink']
        );
    }

    /**
     * Handles the "Add as task" message shortcut (`callback_id`
     * `add_as_task`): opens the add-task modal pre-filled with the
     * message's text, author (as suggested assignee), and a permalink
     * constructed from `team.domain` + `channel.id` + `message.ts` — the
     * payload doesn't include a permalink field directly.
     *
     * @param array<string, mixed> $payload
     */
    public function handleMessageShortcut(array $payload): void
    {
        if (($payload['callback_id'] ?? null) !== 'add_as_task') {
            return;
        }

        $channelId = (string) ($payload['channel']['id'] ?? '');
        $triggerId = (string) ($payload['trigger_id'] ?? '');
        $teamDomain = (string) ($payload['team']['domain'] ?? '');
        $message = $payload['message'] ?? [];
        $messageTs = (string) ($message['ts'] ?? '');

        $permalink = sprintf(
            'https://%s.slack.com/archives/%s/p%s',
            $teamDomain,
            $channelId,
            str_replace('.', '', $messageTs)
        );

        $this->slackApi->openView($triggerId, TaskModal::build($channelId, null, [
            'title' => (string) ($message['text'] ?? ''),
            'assigneeUserId' => $message['user'] ?? null,
            'sourcePermalink' => $permalink,
        ]));
    }

    /**
     * Handles a decoded Slack Events API payload: the one-time
     * `url_verification` handshake, and `app_home_opened` (which
     * publishes the read-only "My Tasks" App Home tab).
     *
     * @param array<string, mixed> $payload
     * @return string|null The challenge string to echo back for
     *         `url_verification`, or null otherwise (the caller should
     *         just respond HTTP 200 with an empty body).
     */
    public function handleEvent(array $payload): ?string
    {
        if (($payload['type'] ?? null) === 'url_verification') {
            return is_string($payload['challenge'] ?? null) ? $payload['challenge'] : null;
        }

        if (($payload['type'] ?? null) !== 'event_callback') {
            return null;
        }

        $event = $payload['event'] ?? [];

        // app_home_opened also fires for the Messages tab, not just Home —
        // republishing there would be wasted work for a tab we don't use.
        if (!is_array($event) || ($event['type'] ?? null) !== 'app_home_opened' || ($event['tab'] ?? 'home') !== 'home') {
            return null;
        }

        $userId = (string) ($event['user'] ?? '');
        $tasks = $this->storage->tasksForAssignee($userId);
        $blocks = $this->listRenderer->renderMyTasks($tasks, new \DateTimeImmutable());

        $this->slackApi->publishView($userId, [
            'type' => 'home',
            'blocks' => $blocks,
        ]);

        return null;
    }

    /**
     * Handles the add/edit-task modal's `view_submission`. Add vs. edit
     * is decided by whether `private_metadata` carries a task id — set
     * by handleBlockAction() when it opened the modal.
     *
     * @param array<string, mixed> $payload
     * @return array{response_action: string, errors: array<string, string>}|null
     *         An errors response to re-show the modal with a validation
     *         message, or null to close it (the write already happened).
     */
    public function handleViewSubmission(array $payload): ?array
    {
        $view = $payload['view'] ?? [];

        if (($view['callback_id'] ?? null) !== TaskModal::CALLBACK_ID) {
            return null;
        }

        $metadata = json_decode((string) ($view['private_metadata'] ?? '{}'), true);
        $channelId = (string) ($metadata['channelId'] ?? '');
        $taskId = $metadata['taskId'] ?? null;

        $values = $view['state']['values'] ?? [];
        $title = trim((string) ($values['title_block']['title_input']['value'] ?? ''));

        if ($title === '') {
            return ['response_action' => 'errors', 'errors' => ['title_block' => 'Title is required.']];
        }

        $assigneeUserId = $values['assignee_block']['assignee_input']['selected_user'] ?? null;
        $selectedDate = $values['due_date_block']['due_date_input']['selected_date'] ?? null;
        $dueDate = $selectedDate !== null ? new \DateTimeImmutable($selectedDate) : null;
        $important = ($values['important_block']['important_input']['selected_options'] ?? []) !== [];
        $link = trim((string) ($values['link_block']['link_input']['value'] ?? ''));

        if ($link !== '' && filter_var($link, FILTER_VALIDATE_URL) === false) {
            return ['response_action' => 'errors', 'errors' => ['link_block' => 'Enter a valid URL.']];
        }

        $sourcePermalink = $link === '' ? null : $link;

        $actorUserId = (string) ($payload['user']['id'] ?? '');
        $previousAssigneeUserId = null;

        if ($taskId === null) {
            if (!self::looksLikeAChannelId($channelId)) {
                return ['response_action' => 'errors', 'errors' => ['title_block' => 'Something went wrong — please reopen this from a channel.']];
            }

            $this->storage->createTask(
                $channelId,
                $title,
                $assigneeUserId,
                $this->nextPriority($channelId),
                $important,
                $dueDate,
                $actorUserId,
                $sourcePermalink
            );
        } else {
            $previousAssigneeUserId = $this->storage->getTask((int) $taskId)['assigneeUserId'] ?? null;
            $this->storage->updateTaskDetails(
                (int) $taskId,
                $title,
                $assigneeUserId,
                $dueDate,
                $important,
                $sourcePermalink
            );
        }

        $this->channelListService->publish($channelId);
        $this->maybeNotifyAssignee($assigneeUserId, $previousAssigneeUserId, $title, $channelId, $actorUserId);

        return null;
    }

    /**
     * DMs the new assignee, unless there's nothing to tell them: no
     * assignee, the assignee didn't actually change, or they assigned it
     * to themselves (they already know).
     */
    private function maybeNotifyAssignee(
        ?string $assigneeUserId,
        ?string $previousAssigneeUserId,
        string $taskTitle,
        string $channelId,
        string $actorUserId
    ): void {
        if ($assigneeUserId === null || $assigneeUserId === $previousAssigneeUserId || $assigneeUserId === $actorUserId) {
            return;
        }

        $listState = $this->storage->getListState($channelId);
        $this->assignmentNotifier->notify($assigneeUserId, $taskTitle, $channelId, $listState['messageTs'] ?? null);
    }

    /**
     * The priority for a new task added to the bottom of a channel's
     * list: one gap past the current highest-ranked open task (or the
     * first gap, if the channel has none). Done tasks never influence
     * this — they've left the ranked list in every sense but storage.
     */
    private function nextPriority(string $channelId): int
    {
        $openTasks = $this->storage->tasksForChannel($channelId, includeDone: false);
        $maxPriority = $openTasks === [] ? 0 : max(array_column($openTasks, 'priority'));

        return $maxPriority + $this->priorityGap;
    }

    /** @return array{response_type: string, text: string} */
    private function textResponse(string $text): array
    {
        return ['response_type' => 'ephemeral', 'text' => $text];
    }

    /**
     * A cheap sanity check on `channelId` before it's used to create a
     * task: Slack channel/group/DM ids always start with C, G, or D —
     * never U (a user id) or W (some bot/enterprise ids). This isn't a
     * security boundary (a forged-but-signed request could still lie),
     * just a guard against a malformed payload silently creating a task
     * whose "channel" is actually someone's user id — which
     * ChannelListService would then happily try to join/pin/publish to.
     */
    private static function looksLikeAChannelId(string $channelId): bool
    {
        return (bool) preg_match('/^[CGD][A-Z0-9]+$/', $channelId);
    }
}
