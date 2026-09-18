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

    public function testAssigneeDigestLeadsWithHowManyOpenTasksTheyHave(): void
    {
        [$digest, $storage, $slackApi] = $this->makeDigestService();

        $storage->createTask('C1', 'Task A', 'U_ME', 1000, false, null, 'U1');
        $storage->createTask('C1', 'Task B', 'U_ME', 2000, false, null, 'U1');

        $digest->run();

        $this->assertStringContainsString('You have 2 open tasks.', $this->postedMessageTextFor($slackApi, 'D123'));
    }

    public function testAssigneeDigestUsesSingularWordingForOneOpenTask(): void
    {
        [$digest, $storage, $slackApi] = $this->makeDigestService();

        $storage->createTask('C1', 'Only task', 'U_ME', 1000, false, null, 'U1');

        $digest->run();

        $this->assertStringContainsString('You have 1 open task.', $this->postedMessageTextFor($slackApi, 'D123'));
        $this->assertStringNotContainsString('1 open tasks.', $this->postedMessageTextFor($slackApi, 'D123'));
    }

    public function testAssigneeDigestLeadsWithACompletedCountWhenSomethingWasCompletedSinceLastCheck(): void
    {
        [$digest, $storage, $slackApi] = $this->makeDigestService();

        $storage->createTask('C1', 'Still open', 'U_ME', 1000, false, null, 'U1');
        $done = $storage->createTask('C1', 'Just finished', 'U_ME', 2000, false, null, 'U1');
        $storage->markDone($done['id']);

        $digest->run();

        $text = $this->postedMessageTextFor($slackApi, 'D123');
        // The completed line must come before the open-count line ("It can go first").
        $this->assertMatchesRegularExpression(
            '/You completed 1 task since the last check.*You have 1 open task\./s',
            $text
        );
    }

    public function testAssigneeDigestOmitsTheCompletedLineWhenNothingWasCompleted(): void
    {
        [$digest, $storage, $slackApi] = $this->makeDigestService();

        $storage->createTask('C1', 'Still open', 'U_ME', 1000, false, null, 'U1');

        $digest->run();

        $this->assertStringNotContainsString('completed', $this->postedMessageTextFor($slackApi, 'D123'));
    }

    public function testAssigneeDigestDoesNotCountAnUnassignedDoneTaskAsAnyonesCompletion(): void
    {
        [$digest, $storage, $slackApi] = $this->makeDigestService();

        $storage->createTask('C1', 'Still open', 'U_ME', 1000, false, null, 'U1');
        $done = $storage->createTask('C1', 'Done, never assigned', null, 2000, false, null, 'U1');
        $storage->markDone($done['id']);

        $digest->run();

        $this->assertStringNotContainsString('completed', $this->postedMessageTextFor($slackApi, 'D123'));
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

    public function testChannelSummaryLeadsWithTheTotalOpenTaskCountNotJustUnassigned(): void
    {
        [$digest, $storage, $slackApi] = $this->makeDigestService();

        $storage->createTask('C1', 'Needs an owner', null, 1000, false, null, 'U1');
        $storage->createTask('C1', 'Already assigned', 'U_ME', 2000, false, null, 'U1');

        $digest->run();

        $summaryPosts = array_filter(
            $slackApi->postedMessages,
            static fn (array $m): bool => $m['fallbackText'] === 'Unassigned tasks in this channel'
        );
        $summary = reset($summaryPosts);

        // 2 open tasks total in C1, only 1 of them unassigned.
        $this->assertStringContainsString('There are 2 open tasks in this channel.', json_encode($summary['blocks']));
    }

    public function testChannelSummaryUsesSingularWordingForOneOpenTask(): void
    {
        [$digest, $storage, $slackApi] = $this->makeDigestService();

        $storage->createTask('C1', 'Needs an owner', null, 1000, false, null, 'U1');

        $digest->run();

        $summaryPosts = array_filter(
            $slackApi->postedMessages,
            static fn (array $m): bool => $m['fallbackText'] === 'Unassigned tasks in this channel'
        );
        $text = json_encode(reset($summaryPosts)['blocks']);

        $this->assertStringContainsString('There are 1 open task in this channel.', $text);
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

    public function testDryRunMakesNoSlackCallsAndMutatesNoStorage(): void
    {
        [$digest, $storage, $slackApi] = $this->makeDigestService();

        $storage->createTask('C1', 'Needs an owner', null, 1000, false, null, 'U1');
        $done = $storage->createTask('C1', 'Assigned, now done', 'U_ME', 2000, false, null, 'U1');
        $storage->markDone($done['id']);

        $digest->run(dryRun: true);

        $this->assertSame([], $slackApi->calls, 'dry run must make no Slack calls at all');
        $this->assertNotNull($storage->getTask($done['id']), 'dry run must not sweep the done task');
        $this->assertNull($storage->getListState('C1'), 'dry run must not publish/save list state');
    }

    public function testDryRunReportDescribesEveryActionThatWouldHappen(): void
    {
        [$digest, $storage] = $this->makeDigestService();

        $storage->createTask('C1', 'Needs an owner', null, 1000, false, null, 'U1');
        $storage->createTask('C1', 'Mine', 'U_ME', 2000, false, null, 'U1');
        $done = $storage->createTask('C1', 'Old done task', null, 3000, false, null, 'U1');
        $storage->markDone($done['id']);

        $report = $digest->run(dryRun: true);
        $text = implode("\n", $report);

        $this->assertStringContainsString('DM U_ME', $text);
        $this->assertStringContainsString('2 open task(s) total, 1 unassigned', $text);
        $this->assertStringContainsString('Sweep C1: 1 done task(s)', $text);
    }

    public function testRealRunAlsoReturnsAReportOfWhatItDid(): void
    {
        [$digest, $storage] = $this->makeDigestService();

        $storage->createTask('C1', 'Mine', 'U_ME', 1000, false, null, 'U1');

        $report = $digest->run();

        $this->assertStringContainsString('DM U_ME', implode("\n", $report));
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
