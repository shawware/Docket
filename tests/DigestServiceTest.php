<?php

// Copyright © 2026 shawware.com.au

declare(strict_types=1);

namespace Shawware\Docket\Tests;

use PHPUnit\Framework\TestCase;
use Shawware\Docket\ChannelListService;
use Shawware\Docket\DigestService;
use Shawware\Docket\ListRenderer;
use Shawware\Docket\Storage\InMemoryStorage;
use Shawware\Docket\Tests\Fakes\RecordingSlackApi;

final class DigestServiceTest extends TestCase
{
    public function testDmsEachAssigneeGroupedByChannel(): void
    {
        [$digest, $storage, $slackApi] = $this->makeDigestService();

        $storage->createTask('C1', 'Mine in C1', 'U_ME', 1000, false, null, 'U1');
        $storage->createTask('C2', 'Mine in C2', 'U_ME', 1000, false, null, 'U1');
        $storage->createTask('C1', 'Not mine', 'U_OTHER', 2000, false, null, 'U1');

        $digest->run();

        $this->assertSame(['U_ME', 'U_OTHER'], $this->sortedUnique($slackApi->openedDms));

        $myDigest = $this->postedMessageTextFor($slackApi, 'D123');
        $this->assertStringContainsString('Mine in C1', $myDigest);
        $this->assertStringContainsString('Mine in C2', $myDigest);
        $this->assertStringNotContainsString('Not mine', $myDigest);
    }

    public function testAssigneeWithNoOpenTasksGetsNoDigestDm(): void
    {
        [$digest, $storage, $slackApi] = $this->makeDigestService();

        $done = $storage->createTask('C1', 'Done task', 'U_ME', 1000, false, null, 'U1');
        $storage->markDone($done['id']);

        $digest->run();

        $this->assertNotContains('U_ME', $slackApi->openedDms);
    }

    public function testPostsAnUnassignedSummaryOnlyToChannelsWithUnassignedOpenTasks(): void
    {
        [$digest, $storage, $slackApi] = $this->makeDigestService();

        $storage->createTask('C1', 'Needs an owner', null, 1000, false, null, 'U1');
        $storage->createTask('C2', 'Already assigned', 'U_ME', 1000, false, null, 'U1');

        $digest->run();

        $summaryPosts = array_filter(
            $slackApi->postedMessages,
            static fn (array $m): bool => $m['fallbackText'] === 'Unassigned tasks in this channel'
        );

        $this->assertCount(1, $summaryPosts, 'only one channel has an unassigned open task');
        $summary = reset($summaryPosts);
        $this->assertSame('C1', $summary['channel']);
        $this->assertStringContainsString('Needs an owner', json_encode($summary['blocks']));
    }

    public function testSweepsDoneTasksAndRepublishesEveryChannelWithTasks(): void
    {
        [$digest, $storage, $slackApi] = $this->makeDigestService();

        $open = $storage->createTask('C1', 'Still open', null, 1000, false, null, 'U1');
        $done = $storage->createTask('C1', 'Old done task', null, 2000, false, null, 'U1');
        $storage->markDone($done['id']);

        $digest->run();

        $this->assertNull($storage->getTask($done['id']), 'done task must be swept');
        $this->assertNotNull($storage->getTask($open['id']));

        // publish() posted (no list_state existed yet) rather than updated.
        $this->assertContains('postMessage', $slackApi->calls);
        $this->assertNotNull($storage->getListState('C1'));
    }

    public function testSweepsAndRepublishesAChannelWhoseLastOpenTaskWasJustCompleted(): void
    {
        [$digest, $storage, $slackApi] = $this->makeDigestService();

        $done = $storage->createTask('C1', 'The only task, now done', null, 1000, false, null, 'U1');
        $storage->markDone($done['id']);

        // C1 has zero open tasks, so it's absent from channelsWithOpenTasks()
        // — but it must still be swept and republished (empty list).
        $digest->run();

        $this->assertNull($storage->getTask($done['id']));
        $this->assertNotNull($storage->getListState('C1'), 'a channel with only a just-completed task must still be republished');
    }

    /**
     * @return array<int, string>
     */
    private function sortedUnique(array $values): array
    {
        $unique = array_values(array_unique($values));
        sort($unique);

        return $unique;
    }

    private function postedMessageTextFor(RecordingSlackApi $slackApi, string $channel): string
    {
        foreach ($slackApi->postedMessages as $message) {
            if ($message['channel'] === $channel) {
                return json_encode($message['blocks']);
            }
        }

        $this->fail("No message was posted to {$channel}");
    }

    /**
     * @return array{0: DigestService, 1: InMemoryStorage, 2: RecordingSlackApi}
     */
    private function makeDigestService(): array
    {
        $storage = new InMemoryStorage();
        $slackApi = new RecordingSlackApi();
        $listRenderer = new ListRenderer();
        $channelListService = new ChannelListService($storage, $slackApi, $listRenderer, 3, 90);
        $digestService = new DigestService($storage, $slackApi, $channelListService, $listRenderer);

        return [$digestService, $storage, $slackApi];
    }
}
