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
