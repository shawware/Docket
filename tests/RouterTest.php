<?php

// Copyright © 2026 shawware.com.au

declare(strict_types=1);

namespace Shawware\Docket\Tests;

use PHPUnit\Framework\TestCase;
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

        $router->handleBlockAction($this->blockActionPayload('C1', $task['id'] . ':edit_task'));

        $this->assertSame('open', $storage->getTask($task['id'])['status']);
        $this->assertSame([], $slackApi->calls);
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
        $channelListService = new ChannelListService($storage, $slackApi, new ListRenderer(), 3);
        $router = new Router($storage, $channelListService, self::PRIORITY_GAP);

        return [$router, $storage, $slackApi];
    }
}
