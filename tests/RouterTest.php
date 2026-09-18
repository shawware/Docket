<?php

// Copyright © 2026 shawware.com.au

declare(strict_types=1);

namespace Shawware\Docket\Tests;

use PHPUnit\Framework\TestCase;
use Shawware\Docket\AssignmentNotifier;
use Shawware\Docket\ChannelListService;
use Shawware\Docket\ListRenderer;
use Shawware\Docket\Router;
use Shawware\Docket\Tests\Fakes\RecordingSlackApi;
use Shawware\Docket\Storage\InMemoryStorage;

require_once __DIR__ . '/../docket.php';

final class RouterTest extends TestCase
{
    private const PRIORITY_GAP = 1000;

    public function testSlashCommandCreatesATaskAndPublishesTheList(): void
    {
        [$router, $storage, $slackApi] = $this->makeRouter();

        $response = $router->handleSlashCommand([
            'channel_id' => 'C1',
            'user_id' => 'U1',
            'text' => 'Fix the login bug',
        ]);

        $tasks = $storage->tasksForChannel('C1');
        $this->assertCount(1, $tasks);
        $this->assertSame('Fix the login bug', $tasks[0]['title']);
        $this->assertSame('U1', $tasks[0]['createdBy']);
        $this->assertSame(self::PRIORITY_GAP, $tasks[0]['priority']);

        $this->assertSame(['joinChannel', 'postMessage', 'pinMessage'], $slackApi->calls);
        $this->assertSame('ephemeral', $response['response_type']);
        $this->assertStringContainsString('Fix the login bug', $response['text']);
    }

    public function testSlashCommandAddsToTheBottomOfAnExistingList(): void
    {
        [$router, $storage] = $this->makeRouter();
        $storage->createTask('C1', 'Existing task', null, 3000, false, null, 'U1');

        $router->handleSlashCommand(['channel_id' => 'C1', 'user_id' => 'U1', 'text' => 'New task']);

        $tasks = $storage->tasksForChannel('C1');
        $this->assertSame(3000 + self::PRIORITY_GAP, $tasks[1]['priority']);
    }

    public function testSlashCommandWithNoTitleReturnsAUsageMessageAndCreatesNothing(): void
    {
        [$router, $storage, $slackApi] = $this->makeRouter();

        $response = $router->handleSlashCommand(['channel_id' => 'C1', 'user_id' => 'U1', 'text' => '']);

        $this->assertSame([], $storage->tasksForChannel('C1'));
        $this->assertSame([], $slackApi->calls);
        $this->assertStringContainsString('Usage', $response['text']);
    }

    public function testSlashCommandWithOnlyWhitespaceAlsoReturnsAUsageMessage(): void
    {
        [$router, $storage] = $this->makeRouter();

        $response = $router->handleSlashCommand(['channel_id' => 'C1', 'user_id' => 'U1', 'text' => '   ']);

        $this->assertSame([], $storage->tasksForChannel('C1'));
        $this->assertStringContainsString('Usage', $response['text']);
    }

    public function testSlashCommandIgnoresDoneTasksWhenComputingTheNextPriority(): void
    {
        [$router, $storage] = $this->makeRouter();
        $done = $storage->createTask('C1', 'Done task', null, 9000, false, null, 'U1');
        $storage->markDone($done['id']);
        $storage->createTask('C1', 'Open task', null, 2000, false, null, 'U1');

        $router->handleSlashCommand(['channel_id' => 'C1', 'user_id' => 'U1', 'text' => 'New task']);

        $newTask = $storage->tasksForChannel('C1', includeDone: false)[1];
        $this->assertSame('New task', $newTask['title']);
        $this->assertSame(2000 + self::PRIORITY_GAP, $newTask['priority']);
    }

    public function testSlashCommandRejectsAChannelIdThatLooksLikeAUserId(): void
    {
        [$router, $storage, $slackApi] = $this->makeRouter();

        // Regression: a malformed test payload once set channel_id to a
        // real Slack user id, silently creating a task whose "channel"
        // was actually someone's DM — see docket.php's looksLikeAChannelId().
        $response = $router->handleSlashCommand([
            'channel_id' => 'U06AGNZP4UB',
            'user_id' => 'U1',
            'text' => 'New task',
        ]);

        $this->assertSame([], $storage->tasksForChannel('U06AGNZP4UB'));
        $this->assertSame([], $slackApi->calls);
        $this->assertStringContainsString('channel', $response['text']);
    }

    public function testHandleBlockActionMarksTheTaskDoneAndPublishes(): void
    {
        [$router, $storage, $slackApi] = $this->makeRouter();
        $task = $storage->createTask('C1', 'Task', null, 1000, false, null, 'U1');
        $storage->saveListState('C1', '1699999999.000100');
        $slackApi->calls = [];

        $router->handleBlockAction($this->blockActionPayload('C1', $task['id'] . ':mark_done'));

        $this->assertSame('done', $storage->getTask($task['id'])['status']);
        $this->assertSame(['updateMessage'], $slackApi->calls);
    }

    public function testHandleBlockActionSwapsPriorityWithTheAdjacentRow(): void
    {
        [$router, $storage] = $this->makeRouter();
        $first = $storage->createTask('C1', 'First', null, 1000, false, null, 'U1');
        $second = $storage->createTask('C1', 'Second', null, 2000, false, null, 'U1');

        $router->handleBlockAction($this->blockActionPayload('C1', $second['id'] . ':move_up'));

        $ordered = array_map(static fn (array $t): string => $t['title'], $storage->tasksForChannel('C1'));
        $this->assertSame(['Second', 'First'], $ordered);
    }

    public function testHandleBlockActionReopensADoneTask(): void
    {
        [$router, $storage] = $this->makeRouter();
        $task = $storage->createTask('C1', 'Task', null, 1000, false, null, 'U1');
        $storage->markDone($task['id']);

        $router->handleBlockAction($this->blockActionPayload('C1', $task['id'] . ':reopen'));

        $reopened = $storage->getTask($task['id']);
        $this->assertSame('open', $reopened['status']);
        $this->assertNull($reopened['completedAt']);
    }

    public function testHandleBlockActionTogglesImportantOn(): void
    {
        [$router, $storage] = $this->makeRouter();
        $task = $storage->createTask('C1', 'Task', 'U9', 1000, false, new \DateTimeImmutable('2026-07-01'), 'U1', 'https://example.com');

        $router->handleBlockAction($this->blockActionPayload('C1', $task['id'] . ':toggle_important'));

        $updated = $storage->getTask($task['id']);
        $this->assertTrue($updated['important']);
        // Every other field must survive the round-trip untouched.
        $this->assertSame('Task', $updated['title']);
        $this->assertSame('U9', $updated['assigneeUserId']);
        $this->assertSame('2026-07-01', $updated['dueDate']->format('Y-m-d'));
        $this->assertSame('https://example.com', $updated['sourcePermalink']);
        $this->assertSame(1000, $updated['priority']);
    }

    public function testHandleBlockActionTogglesImportantOff(): void
    {
        [$router, $storage] = $this->makeRouter();
        $task = $storage->createTask('C1', 'Task', null, 1000, true, null, 'U1');

        $router->handleBlockAction($this->blockActionPayload('C1', $task['id'] . ':toggle_important'));

        $this->assertFalse($storage->getTask($task['id'])['important']);
    }

    public function testHandleBlockActionToggleImportantIsANoOpForAnUnknownTaskId(): void
    {
        // Matches mark_done/move_up/reopen: the write is a safe no-op, but
        // the list still republishes afterward regardless (unlike
        // edit_task/remind_me-style actions, which return before that).
        [$router, $storage] = $this->makeRouter();

        $router->handleBlockAction($this->blockActionPayload('C1', '999999:toggle_important'));

        $this->assertNull($storage->getTask(999999));
    }

    public function testHandleBlockActionSwapsPriorityDownwardsToo(): void
    {
        [$router, $storage] = $this->makeRouter();
        $first = $storage->createTask('C1', 'First', null, 1000, false, null, 'U1');
        $second = $storage->createTask('C1', 'Second', null, 2000, false, null, 'U1');

        $router->handleBlockAction($this->blockActionPayload('C1', $first['id'] . ':move_down'));

        $ordered = array_map(static fn (array $t): string => $t['title'], $storage->tasksForChannel('C1'));
        $this->assertSame(['Second', 'First'], $ordered);
    }

    public function testHandleBlockActionIgnoresPayloadsFromAnUnrelatedActionId(): void
    {
        [$router, $storage, $slackApi] = $this->makeRouter();
        $task = $storage->createTask('C1', 'Task', null, 1000, false, null, 'U1');

        $router->handleBlockAction([
            'type' => 'block_actions',
            'channel' => ['id' => 'C1'],
            'actions' => [['action_id' => 'some_other_button', 'value' => (string) $task['id']]],
        ]);

        $this->assertSame('open', $storage->getTask($task['id'])['status']);
        $this->assertSame([], $slackApi->calls);
    }

    public function testHandleBlockActionIgnoresActionsNotYetImplemented(): void
    {
        [$router, $storage, $slackApi] = $this->makeRouter();
        $task = $storage->createTask('C1', 'Task', null, 1000, false, null, 'U1');

        $router->handleBlockAction($this->blockActionPayload('C1', $task['id'] . ':some_future_action'));

        $this->assertSame('open', $storage->getTask($task['id'])['status']);
        $this->assertSame([], $slackApi->calls);
    }

    public function testHandleBlockActionOpensABlankModalForAddTask(): void
    {
        [$router, , $slackApi] = $this->makeRouter();

        $router->handleBlockAction([
            'type' => 'block_actions',
            'trigger_id' => 'trigger-1',
            'channel' => ['id' => 'C1'],
            'actions' => [['action_id' => 'add_task']],
        ]);

        $this->assertSame(['openView'], $slackApi->calls);
        $this->assertSame('trigger-1', $slackApi->openedViews[0]['triggerId']);
        $this->assertNull(json_decode($slackApi->openedViews[0]['view']['private_metadata'], true)['taskId']);
    }

    public function testHandleBlockActionPropagatesExceptionsFromSlackApi(): void
    {
        // public/index.php relies on Router NOT swallowing this itself —
        // its own try/catch is what turns this into a clean 200 for Slack.
        [$router, , $slackApi] = $this->makeRouter();
        $slackApi->openViewFails = true;

        $this->expectException(\RuntimeException::class);

        $router->handleBlockAction([
            'type' => 'block_actions',
            'trigger_id' => 'trigger-1',
            'channel' => ['id' => 'C1'],
            'actions' => [['action_id' => 'add_task']],
        ]);
    }

    public function testHandleBlockActionOpensAPreFilledModalForEditTask(): void
    {
        [$router, $storage, $slackApi] = $this->makeRouter();
        $task = $storage->createTask('C1', 'Task', 'U9', 1000, true, null, 'U1');

        $router->handleBlockAction(array_merge(
            $this->blockActionPayload('C1', $task['id'] . ':edit_task'),
            ['trigger_id' => 'trigger-2']
        ));

        $this->assertSame(['openView'], $slackApi->calls);
        $view = $slackApi->openedViews[0]['view'];
        $this->assertSame($task['id'], json_decode($view['private_metadata'], true)['taskId']);
        $this->assertSame('Edit task', $view['title']['text']);
    }

    public function testHandleBlockActionEditTaskIgnoresAnUnknownTaskId(): void
    {
        [$router, , $slackApi] = $this->makeRouter();

        $router->handleBlockAction($this->blockActionPayload('C1', '999999:edit_task'));

        $this->assertSame([], $slackApi->calls);
    }

    public function testViewSubmissionWithNoTaskIdCreatesANewTaskAtTheBottom(): void
    {
        [$router, $storage] = $this->makeRouter();
        $storage->createTask('C1', 'Existing', null, 3000, false, null, 'U1');

        $result = $router->handleViewSubmission($this->viewSubmissionPayload('C1', null, [
            'title_block' => ['title_input' => ['value' => 'New task']],
            'assignee_block' => ['assignee_input' => ['selected_user' => 'U9']],
            'due_date_block' => ['due_date_input' => ['selected_date' => '2026-07-01']],
            'important_block' => ['important_input' => ['selected_options' => [['value' => 'important']]]],
        ]));

        $this->assertNull($result);
        $tasks = $storage->tasksForChannel('C1');
        $this->assertCount(2, $tasks);
        $this->assertSame('New task', $tasks[1]['title']);
        $this->assertSame('U9', $tasks[1]['assigneeUserId']);
        $this->assertSame('2026-07-01', $tasks[1]['dueDate']->format('Y-m-d'));
        $this->assertTrue($tasks[1]['important']);
        $this->assertSame(3000 + self::PRIORITY_GAP, $tasks[1]['priority']);
    }

    public function testViewSubmissionCreateRejectsAChannelIdThatLooksLikeAUserId(): void
    {
        [$router, $storage, $slackApi] = $this->makeRouter();

        $result = $router->handleViewSubmission($this->viewSubmissionPayload('U06AGNZP4UB', null, [
            'title_block' => ['title_input' => ['value' => 'New task']],
        ]));

        $this->assertSame(['response_action', 'errors'], array_keys($result));
        $this->assertSame([], $storage->tasksForChannel('U06AGNZP4UB'));
        $this->assertSame([], $slackApi->calls);
    }

    public function testViewSubmissionWithATaskIdUpdatesDetailsWithoutTouchingPriority(): void
    {
        [$router, $storage] = $this->makeRouter();
        $task = $storage->createTask('C1', 'Old title', null, 5000, false, null, 'U1');

        $result = $router->handleViewSubmission($this->viewSubmissionPayload('C1', $task['id'], [
            'title_block' => ['title_input' => ['value' => 'Updated title']],
            'assignee_block' => ['assignee_input' => ['selected_user' => 'U9']],
            'due_date_block' => ['due_date_input' => ['selected_date' => null]],
            'important_block' => ['important_input' => ['selected_options' => []]],
        ]));

        $this->assertNull($result);
        $updated = $storage->getTask($task['id']);
        $this->assertSame('Updated title', $updated['title']);
        $this->assertSame('U9', $updated['assigneeUserId']);
        $this->assertFalse($updated['important']);
        $this->assertSame(5000, $updated['priority']);
    }

    public function testViewSubmissionCreateWithAnAssigneeNotifiesThem(): void
    {
        [$router, , $slackApi] = $this->makeRouter();

        $router->handleViewSubmission($this->viewSubmissionPayload('C1', null, [
            'title_block' => ['title_input' => ['value' => 'New task']],
            'assignee_block' => ['assignee_input' => ['selected_user' => 'U9']],
        ]));

        $this->assertSame(['U9'], $slackApi->openedDms);
    }

    public function testViewSubmissionCreateFirstTaskWithAssigneeStillIncludesTheListLinkInTheDm(): void
    {
        // Guards the ordering in handleViewSubmission: publish() must run
        // before maybeNotifyAssignee(), or the very first task in a
        // channel would DM its assignee before list_state exists to link to.
        [$router, , $slackApi] = $this->makeRouter();

        $router->handleViewSubmission($this->viewSubmissionPayload('C1', null, [
            'title_block' => ['title_input' => ['value' => 'New task']],
            'assignee_block' => ['assignee_input' => ['selected_user' => 'U9']],
        ]));

        $dmMessage = end($slackApi->postedMessages);
        $this->assertStringContainsString('View the list', $dmMessage['blocks'][0]['text']['text']);
    }

    public function testViewSubmissionCreateAssignedToYourselfDoesNotNotify(): void
    {
        // viewSubmissionPayload() always acts as U1.
        [$router, , $slackApi] = $this->makeRouter();

        $router->handleViewSubmission($this->viewSubmissionPayload('C1', null, [
            'title_block' => ['title_input' => ['value' => 'New task']],
            'assignee_block' => ['assignee_input' => ['selected_user' => 'U1']],
        ]));

        $this->assertSame([], $slackApi->openedDms);
    }

    public function testViewSubmissionCreateWithNoAssigneeDoesNotNotify(): void
    {
        [$router, , $slackApi] = $this->makeRouter();

        $router->handleViewSubmission($this->viewSubmissionPayload('C1', null, [
            'title_block' => ['title_input' => ['value' => 'New task']],
        ]));

        $this->assertSame([], $slackApi->openedDms);
    }

    public function testViewSubmissionEditChangingTheAssigneeNotifiesTheNewOne(): void
    {
        [$router, $storage, $slackApi] = $this->makeRouter();
        $task = $storage->createTask('C1', 'Task', 'U8', 1000, false, null, 'U1');

        $router->handleViewSubmission($this->viewSubmissionPayload('C1', $task['id'], [
            'title_block' => ['title_input' => ['value' => 'Task']],
            'assignee_block' => ['assignee_input' => ['selected_user' => 'U9']],
        ]));

        $this->assertSame(['U9'], $slackApi->openedDms);
    }

    public function testViewSubmissionEditAssigningAPreviouslyUnassignedTaskNotifies(): void
    {
        [$router, $storage, $slackApi] = $this->makeRouter();
        $task = $storage->createTask('C1', 'Task', null, 1000, false, null, 'U1');

        $router->handleViewSubmission($this->viewSubmissionPayload('C1', $task['id'], [
            'title_block' => ['title_input' => ['value' => 'Task']],
            'assignee_block' => ['assignee_input' => ['selected_user' => 'U9']],
        ]));

        $this->assertSame(['U9'], $slackApi->openedDms);
    }

    public function testViewSubmissionEditLeavingTheAssigneeUnchangedDoesNotNotify(): void
    {
        [$router, $storage, $slackApi] = $this->makeRouter();
        $task = $storage->createTask('C1', 'Task', 'U9', 1000, false, null, 'U1');

        $router->handleViewSubmission($this->viewSubmissionPayload('C1', $task['id'], [
            'title_block' => ['title_input' => ['value' => 'Task, retitled']],
            'assignee_block' => ['assignee_input' => ['selected_user' => 'U9']],
        ]));

        $this->assertSame([], $slackApi->openedDms);
    }

    public function testViewSubmissionEditReassigningToYourselfDoesNotNotify(): void
    {
        // viewSubmissionPayload() always acts as U1.
        [$router, $storage, $slackApi] = $this->makeRouter();
        $task = $storage->createTask('C1', 'Task', 'U9', 1000, false, null, 'U1');

        $router->handleViewSubmission($this->viewSubmissionPayload('C1', $task['id'], [
            'title_block' => ['title_input' => ['value' => 'Task']],
            'assignee_block' => ['assignee_input' => ['selected_user' => 'U1']],
        ]));

        $this->assertSame([], $slackApi->openedDms);
    }

    public function testViewSubmissionWithOptionalBlocksOmittedLeavesThemUnset(): void
    {
        // Slack omits an untouched optional block's key entirely, rather
        // than sending it with an empty value.
        [$router, $storage] = $this->makeRouter();

        $router->handleViewSubmission($this->viewSubmissionPayload('C1', null, [
            'title_block' => ['title_input' => ['value' => 'Bare task']],
        ]));

        $tasks = $storage->tasksForChannel('C1');
        $this->assertNull($tasks[0]['assigneeUserId']);
        $this->assertNull($tasks[0]['dueDate']);
        $this->assertFalse($tasks[0]['important']);
        $this->assertNull($tasks[0]['sourcePermalink']);
    }

    public function testViewSubmissionStoresAManuallyTypedLink(): void
    {
        [$router, $storage] = $this->makeRouter();

        $router->handleViewSubmission($this->viewSubmissionPayload('C1', null, [
            'title_block' => ['title_input' => ['value' => 'Task with a link']],
            'link_block' => ['link_input' => ['value' => 'https://slack.example/archives/C1/p999']],
        ]));

        $tasks = $storage->tasksForChannel('C1');
        $this->assertSame('https://slack.example/archives/C1/p999', $tasks[0]['sourcePermalink']);
    }

    public function testViewSubmissionClearsAnExistingLinkWhenTheFieldIsEmptied(): void
    {
        [$router, $storage] = $this->makeRouter();
        $task = $storage->createTask(
            'C1',
            'Task',
            null,
            1000,
            false,
            null,
            'U1',
            'https://slack.example/archives/C1/p123'
        );

        $router->handleViewSubmission($this->viewSubmissionPayload('C1', $task['id'], [
            'title_block' => ['title_input' => ['value' => 'Task']],
            'link_block' => ['link_input' => ['value' => '  ']],
        ]));

        $this->assertNull($storage->getTask($task['id'])['sourcePermalink']);
    }

    public function testViewSubmissionWithAMalformedLinkReturnsErrorsAndWritesNothing(): void
    {
        [$router, $storage] = $this->makeRouter();

        $result = $router->handleViewSubmission($this->viewSubmissionPayload('C1', null, [
            'title_block' => ['title_input' => ['value' => 'Task']],
            'link_block' => ['link_input' => ['value' => 'not a url']],
        ]));

        $this->assertSame(['response_action' => 'errors', 'errors' => ['link_block' => 'Enter a valid URL.']], $result);
        $this->assertSame([], $storage->tasksForChannel('C1'));
    }

    public function testViewSubmissionWithASchemelessLinkReturnsErrorsAndWritesNothing(): void
    {
        [$router, $storage] = $this->makeRouter();

        $result = $router->handleViewSubmission($this->viewSubmissionPayload('C1', null, [
            'title_block' => ['title_input' => ['value' => 'Task']],
            'link_block' => ['link_input' => ['value' => 'slack.com/archives/C1/p123']],
        ]));

        $this->assertSame(['response_action' => 'errors', 'errors' => ['link_block' => 'Enter a valid URL.']], $result);
        $this->assertSame([], $storage->tasksForChannel('C1'));
    }

    public function testViewSubmissionWithAnEmptyTitleReturnsErrorsAndWritesNothing(): void
    {
        [$router, $storage] = $this->makeRouter();

        $result = $router->handleViewSubmission($this->viewSubmissionPayload('C1', null, [
            'title_block' => ['title_input' => ['value' => '  ']],
        ]));

        $this->assertSame(['response_action' => 'errors', 'errors' => ['title_block' => 'Title is required.']], $result);
        $this->assertSame([], $storage->tasksForChannel('C1'));
    }

    public function testViewSubmissionEditForAnUnknownTaskIdDoesNotFabricateARow(): void
    {
        [$router, $storage] = $this->makeRouter();

        $result = $router->handleViewSubmission($this->viewSubmissionPayload('C1', 999999, [
            'title_block' => ['title_input' => ['value' => 'Stale edit']],
        ]));

        $this->assertNull($result);
        $this->assertNull($storage->getTask(999999));
    }

    public function testViewSubmissionForAnUnrelatedCallbackIdIsIgnored(): void
    {
        [$router] = $this->makeRouter();

        $result = $router->handleViewSubmission(['view' => ['callback_id' => 'some_other_modal']]);

        $this->assertNull($result);
    }

    public function testHandleMessageShortcutOpensAPreFilledAddModalWithAConstructedPermalink(): void
    {
        [$router, , $slackApi] = $this->makeRouter();

        $router->handleMessageShortcut([
            'type' => 'message_action',
            'callback_id' => 'add_as_task',
            'trigger_id' => 'trigger-3',
            'channel' => ['id' => 'C1'],
            'team' => ['domain' => 'my-workspace'],
            'message' => ['text' => 'Something is broken', 'user' => 'U9', 'ts' => '1699999999.000100'],
        ]);

        $this->assertSame(['openView'], $slackApi->calls);
        $opened = $slackApi->openedViews[0];
        $this->assertSame('trigger-3', $opened['triggerId']);

        $view = $opened['view'];
        $this->assertSame('Add task', $view['title']['text']);
        $this->assertNull(json_decode($view['private_metadata'], true)['taskId']);

        [$titleBlock, $assigneeBlock, , , $linkBlock] = $view['blocks'];
        $this->assertSame('Something is broken', $titleBlock['element']['initial_value']);
        $this->assertSame('U9', $assigneeBlock['element']['initial_user']);
        $this->assertSame(
            'https://my-workspace.slack.com/archives/C1/p1699999999000100',
            $linkBlock['element']['initial_value']
        );
    }

    public function testHandleMessageShortcutPropagatesExceptionsFromSlackApi(): void
    {
        // Same contract as handleBlockAction: public/index.php's own
        // try/catch is what protects this, not Router swallowing it.
        [$router, , $slackApi] = $this->makeRouter();
        $slackApi->openViewFails = true;

        $this->expectException(\RuntimeException::class);

        $router->handleMessageShortcut([
            'type' => 'message_action',
            'callback_id' => 'add_as_task',
            'trigger_id' => 'trigger-3',
            'channel' => ['id' => 'C1'],
            'team' => ['domain' => 'my-workspace'],
            'message' => ['text' => 'Something is broken', 'user' => 'U9', 'ts' => '1699999999.000100'],
        ]);
    }

    public function testHandleMessageShortcutIgnoresUnrelatedCallbackIds(): void
    {
        [$router, , $slackApi] = $this->makeRouter();

        $router->handleMessageShortcut(['type' => 'message_action', 'callback_id' => 'some_other_shortcut']);

        $this->assertSame([], $slackApi->calls);
    }

    public function testHandleEventEchoesTheUrlVerificationChallenge(): void
    {
        [$router] = $this->makeRouter();

        $challenge = $router->handleEvent(['type' => 'url_verification', 'challenge' => 'abc123']);

        $this->assertSame('abc123', $challenge);
    }

    public function testHandleEventUrlVerificationWithAMissingChallengeReturnsNull(): void
    {
        [$router] = $this->makeRouter();

        $this->assertNull($router->handleEvent(['type' => 'url_verification']));
    }

    public function testHandleEventOnAppHomeOpenedPublishesMyTasksForThatUser(): void
    {
        [$router, $storage, $slackApi] = $this->makeRouter();
        $storage->createTask('C1', 'My task', 'U9', 1000, false, null, 'U1');
        $storage->createTask('C1', 'Someone else\'s task', 'U1', 2000, false, null, 'U1');

        $result = $router->handleEvent([
            'type' => 'event_callback',
            'event' => ['type' => 'app_home_opened', 'user' => 'U9', 'tab' => 'home'],
        ]);

        $this->assertNull($result);
        $this->assertSame(['publishView'], $slackApi->calls);
        $published = $slackApi->publishedViews[0];
        $this->assertSame('U9', $published['userId']);
        $this->assertSame('home', $published['view']['type']);
        $blocksText = json_encode($published['view']['blocks']);
        $this->assertStringContainsString('My task', $blocksText);
        $this->assertStringNotContainsString('Someone else', $blocksText);
    }

    public function testHandleEventIgnoresAppHomeOpenedForTheMessagesTab(): void
    {
        [$router, , $slackApi] = $this->makeRouter();

        $router->handleEvent([
            'type' => 'event_callback',
            'event' => ['type' => 'app_home_opened', 'user' => 'U9', 'tab' => 'messages'],
        ]);

        $this->assertSame([], $slackApi->calls);
    }

    public function testHandleEventTreatsAMissingTabAsHome(): void
    {
        // Slack docs: the "tab" field defaults to "home" when absent.
        [$router, , $slackApi] = $this->makeRouter();

        $router->handleEvent([
            'type' => 'event_callback',
            'event' => ['type' => 'app_home_opened', 'user' => 'U9'],
        ]);

        $this->assertSame(['publishView'], $slackApi->calls);
    }

    public function testHandleEventIgnoresUnrelatedEventTypes(): void
    {
        [$router, , $slackApi] = $this->makeRouter();

        $router->handleEvent(['type' => 'event_callback', 'event' => ['type' => 'message']]);

        $this->assertSame([], $slackApi->calls);
    }

    public function testHandleEventIgnoresPayloadsThatAreNeitherVerificationNorEventCallback(): void
    {
        [$router, , $slackApi] = $this->makeRouter();

        $result = $router->handleEvent(['type' => 'something_else']);

        $this->assertNull($result);
        $this->assertSame([], $slackApi->calls);
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private function viewSubmissionPayload(string $channelId, ?int $taskId, array $values): array
    {
        return [
            'type' => 'view_submission',
            'user' => ['id' => 'U1'],
            'view' => [
                'callback_id' => 'task_modal',
                'private_metadata' => json_encode(['channelId' => $channelId, 'taskId' => $taskId]),
                'state' => ['values' => $values],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function blockActionPayload(string $channelId, string $optionValue): array
    {
        return [
            'type' => 'block_actions',
            'channel' => ['id' => $channelId],
            'actions' => [
                ['action_id' => 'task_menu', 'selected_option' => ['value' => $optionValue]],
            ],
        ];
    }

    /**
     * @return array{0: Router, 1: InMemoryStorage, 2: RecordingSlackApi}
     */
    private function makeRouter(): array
    {
        $storage = new InMemoryStorage();
        $slackApi = new RecordingSlackApi();
        $channelListService = new ChannelListService($storage, $slackApi, new ListRenderer(), 3, 90);
        $assignmentNotifier = new AssignmentNotifier($slackApi);
        $router = new Router(
            $storage,
            $slackApi,
            $channelListService,
            $assignmentNotifier,
            new ListRenderer(),
            self::PRIORITY_GAP
        );

        return [$router, $storage, $slackApi];
    }
}
