<?php

// Copyright © 2026 shawware.com.au

declare(strict_types=1);

namespace Shawware\Docket\Tests;

use PHPUnit\Framework\TestCase;
use Shawware\Docket\AssignmentNotifier;
use Shawware\Docket\ChannelListService;
use Shawware\Docket\ListRenderer;
use Shawware\Docket\RequestDispatcher;
use Shawware\Docket\Router;
use Shawware\Docket\Storage\InMemoryStorage;
use Shawware\Docket\Tests\Fakes\RecordingSlackApi;

require_once __DIR__ . '/../docket.php';

final class RequestDispatcherTest extends TestCase
{
    private const PRIORITY_GAP = 1000;

    // --- dispatchEvent ---------------------------------------------------

    public function testDispatchEventReturnsTheChallengeAsPlainText(): void
    {
        [$dispatcher] = $this->makeDispatcher();

        $result = $dispatcher->dispatchEvent(['type' => 'url_verification', 'challenge' => 'abc123']);

        $this->assertSame(200, $result->status);
        $this->assertSame('abc123', $result->body);
        $this->assertSame('text/plain', $result->contentType);
    }

    public function testDispatchEventWithNoChallengeReturns200WithAnEmptyBody(): void
    {
        [$dispatcher] = $this->makeDispatcher();

        $result = $dispatcher->dispatchEvent(['type' => 'event_callback', 'event' => ['type' => 'message']]);

        $this->assertSame(200, $result->status);
        $this->assertSame('', $result->body);
    }

    public function testDispatchEventStillReturns200WhenHandlerThrows(): void
    {
        [$dispatcher, , $slackApi] = $this->makeDispatcher();
        $slackApi->publishViewFails = true;

        $result = $dispatcher->dispatchEvent([
            'type' => 'event_callback',
            'event' => ['type' => 'app_home_opened', 'user' => 'U9', 'tab' => 'home'],
        ]);

        $this->assertSame(200, $result->status);
        $this->assertSame('', $result->body);
    }

    // --- dispatchInteraction: view_submission -----------------------------

    public function testDispatchInteractionViewSubmissionReturnsTheRouterResultAsJson(): void
    {
        [$dispatcher] = $this->makeDispatcher();

        $result = $dispatcher->dispatchInteraction([
            'type' => 'view_submission',
            'user' => ['id' => 'U1'],
            'view' => [
                'callback_id' => 'task_modal',
                'private_metadata' => json_encode(['channelId' => 'C1', 'taskId' => null]),
                'state' => ['values' => ['title_block' => ['title_input' => ['value' => 'New task']]]],
            ],
        ]);

        $this->assertSame(200, $result->status);
        $this->assertSame('application/json', $result->contentType);
        // json_decode("[]", true) and json_decode("{}", true) are both []
        // in PHP — a string check is the only way to actually prove this
        // is a JSON *object* (what Slack expects), not an array.
        $this->assertSame('{}', $result->body);
    }

    public function testDispatchInteractionViewSubmissionPassesThroughAnErrorsResponseAction(): void
    {
        [$dispatcher] = $this->makeDispatcher();

        $result = $dispatcher->dispatchInteraction([
            'type' => 'view_submission',
            'user' => ['id' => 'U1'],
            'view' => [
                'callback_id' => 'task_modal',
                'private_metadata' => json_encode(['channelId' => 'C1', 'taskId' => null]),
                'state' => ['values' => ['title_block' => ['title_input' => ['value' => '']]]],
            ],
        ]);

        $this->assertSame(200, $result->status);
        $this->assertSame('application/json', $result->contentType);
        $this->assertSame(
            ['response_action' => 'errors', 'errors' => ['title_block' => 'Title is required.']],
            json_decode($result->body, true)
        );
    }

    public function testDispatchInteractionViewSubmissionReturns500WhenHandlerThrows(): void
    {
        [$dispatcher, $storage, $slackApi] = $this->makeDispatcher();
        $slackApi->postMessageFails = true;

        $result = $dispatcher->dispatchInteraction([
            'type' => 'view_submission',
            'user' => ['id' => 'U1'],
            'view' => [
                'callback_id' => 'task_modal',
                'private_metadata' => json_encode(['channelId' => 'C1', 'taskId' => null]),
                'state' => ['values' => ['title_block' => ['title_input' => ['value' => 'New task']]]],
            ],
        ]);

        $this->assertSame(500, $result->status);
        $this->assertSame('', $result->body);
        // The write still happened — only the outbound Slack call failed.
        $this->assertCount(1, $storage->tasksForChannel('C1'));
    }

    // --- dispatchInteraction: message_action ------------------------------

    public function testDispatchInteractionMessageActionReturns200WithAnEmptyBody(): void
    {
        [$dispatcher] = $this->makeDispatcher();

        $result = $dispatcher->dispatchInteraction([
            'type' => 'message_action',
            'callback_id' => 'add_as_task',
            'trigger_id' => 'trigger-1',
            'channel' => ['id' => 'C1'],
            'team' => ['domain' => 'my-workspace'],
            'message' => ['text' => 'Something is broken', 'user' => 'U9', 'ts' => '1699999999.000100'],
        ]);

        $this->assertSame(200, $result->status);
        $this->assertSame('', $result->body);
    }

    public function testDispatchInteractionMessageActionStillReturns200WhenHandlerThrows(): void
    {
        [$dispatcher, , $slackApi] = $this->makeDispatcher();
        $slackApi->openViewFails = true;

        $result = $dispatcher->dispatchInteraction([
            'type' => 'message_action',
            'callback_id' => 'add_as_task',
            'trigger_id' => 'trigger-1',
            'channel' => ['id' => 'C1'],
            'team' => ['domain' => 'my-workspace'],
            'message' => ['text' => 'Something is broken', 'user' => 'U9', 'ts' => '1699999999.000100'],
        ]);

        $this->assertSame(200, $result->status);
        $this->assertSame('', $result->body);
    }

    // --- dispatchInteraction: block_actions --------------------------------

    public function testDispatchInteractionBlockActionReturns200WithAnEmptyBody(): void
    {
        [$dispatcher, $storage] = $this->makeDispatcher();
        $task = $storage->createTask('C1', 'Task', null, 1000, false, null, 'U1');

        $result = $dispatcher->dispatchInteraction($this->blockActionPayload('C1', $task['id'] . ':mark_done'));

        $this->assertSame(200, $result->status);
        $this->assertSame('', $result->body);
        $this->assertSame('done', $storage->getTask($task['id'])['status']);
    }

    public function testDispatchInteractionBlockActionStillReturns200WhenHandlerThrows(): void
    {
        [$dispatcher, , $slackApi] = $this->makeDispatcher();
        $slackApi->openViewFails = true;

        $result = $dispatcher->dispatchInteraction([
            'type' => 'block_actions',
            'trigger_id' => 'trigger-1',
            'channel' => ['id' => 'C1'],
            'actions' => [['action_id' => 'add_task']],
        ]);

        $this->assertSame(200, $result->status);
        $this->assertSame('', $result->body);
    }

    // --- dispatchCommand ---------------------------------------------------

    public function testDispatchCommandReturnsTheRouterResultAsJson(): void
    {
        [$dispatcher] = $this->makeDispatcher();

        $result = $dispatcher->dispatchCommand(['channel_id' => 'C1', 'user_id' => 'U1', 'text' => 'Fix the bug']);

        $this->assertSame(200, $result->status);
        $this->assertSame('application/json', $result->contentType);
        $decoded = json_decode($result->body, true);
        $this->assertSame('ephemeral', $decoded['response_type']);
        $this->assertStringContainsString('Fix the bug', $decoded['text']);
    }

    public function testDispatchCommandReturnsAFriendlyEphemeralMessageWhenHandlerThrows(): void
    {
        [$dispatcher, $storage, $slackApi] = $this->makeDispatcher();
        $slackApi->postMessageFails = true;

        $result = $dispatcher->dispatchCommand(['channel_id' => 'C1', 'user_id' => 'U1', 'text' => 'Fix the bug']);

        $this->assertSame(200, $result->status);
        $this->assertSame('application/json', $result->contentType);
        $decoded = json_decode($result->body, true);
        $this->assertSame('ephemeral', $decoded['response_type']);
        $this->assertStringContainsString('Something went wrong', $decoded['text']);
        // The task was still created — only the outbound Slack call failed.
        $this->assertCount(1, $storage->tasksForChannel('C1'));
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
     * @return array{0: RequestDispatcher, 1: InMemoryStorage, 2: RecordingSlackApi}
     */
    private function makeDispatcher(): array
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
        $dispatcher = new RequestDispatcher($router);

        return [$dispatcher, $storage, $slackApi];
    }
}
