<?php

// Copyright © 2026 shawware.com.au

declare(strict_types=1);

namespace Shawware\Docket\Tests\Storage;

use PHPUnit\Framework\TestCase;
use Shawware\Docket\Storage\StorageInterface;

/**
 * Shared behaviour every StorageInterface implementation must satisfy.
 *
 * Run against InMemoryStorage (InMemoryStorageTest) and MySqlStorage
 * (MySqlStorageTest), proving both backends behave identically rather
 * than duplicating these assertions per backend.
 */
abstract class StorageContractTestCase extends TestCase
{
    abstract protected function createStorage(): StorageInterface;

    public function testGetTaskIsNullForAnUnknownId(): void
    {
        $storage = $this->createStorage();

        $this->assertNull($storage->getTask(999999));
    }

    public function testCreateTaskThenGetTaskRoundTrips(): void
    {
        $storage = $this->createStorage();
        $dueDate = new \DateTimeImmutable('2026-10-01');

        $created = $storage->createTask(
            'C1',
            'Fix the login bug',
            'U_ASSIGNEE',
            1000,
            true,
            $dueDate,
            'U_CREATOR',
            'https://example.slack.com/archives/C1/p123'
        );

        $this->assertSame('C1', $created['channelId']);
        $this->assertSame('Fix the login bug', $created['title']);
        $this->assertSame('U_ASSIGNEE', $created['assigneeUserId']);
        $this->assertTrue($created['important']);
        $this->assertSame(1000, $created['priority']);
        $this->assertEquals($dueDate, $created['dueDate']);
        $this->assertSame('open', $created['status']);
        $this->assertSame('U_CREATOR', $created['createdBy']);
        $this->assertSame('https://example.slack.com/archives/C1/p123', $created['sourcePermalink']);
        $this->assertNull($created['completedAt']);

        $fetched = $storage->getTask($created['id']);

        $this->assertEquals($created, $fetched);
    }

    public function testCreateTaskDefaultsAreNullable(): void
    {
        $storage = $this->createStorage();

        $created = $storage->createTask('C1', 'Untitled work', null, 1000, false, null, 'U_CREATOR');

        $this->assertNull($created['assigneeUserId']);
        $this->assertNull($created['dueDate']);
        $this->assertNull($created['sourcePermalink']);
        $this->assertFalse($created['important']);
    }

    public function testTasksForChannelOrdersOpenTasksByPriorityAscending(): void
    {
        $storage = $this->createStorage();

        $storage->createTask('C1', 'Third', null, 3000, false, null, 'U1');
        $storage->createTask('C1', 'First', null, 1000, false, null, 'U1');
        $storage->createTask('C1', 'Second', null, 2000, false, null, 'U1');
        // A different channel — must never appear.
        $storage->createTask('C2', 'Other channel', null, 1000, false, null, 'U1');

        $titles = array_map(
            static fn (array $task): string => $task['title'],
            $storage->tasksForChannel('C1')
        );

        $this->assertSame(['First', 'Second', 'Third'], $titles);
    }

    public function testTasksForChannelCanExcludeDoneTasks(): void
    {
        $storage = $this->createStorage();

        $open = $storage->createTask('C1', 'Open task', null, 1000, false, null, 'U1');
        $done = $storage->createTask('C1', 'Done task', null, 2000, false, null, 'U1');
        $storage->markDone($done['id']);

        $withDone = $storage->tasksForChannel('C1', includeDone: true);
        $withoutDone = $storage->tasksForChannel('C1', includeDone: false);

        $this->assertCount(2, $withDone);
        $this->assertCount(1, $withoutDone);
        $this->assertSame($open['id'], $withoutDone[0]['id']);
    }

    public function testTasksForAssigneeSpansChannels(): void
    {
        $storage = $this->createStorage();

        $storage->createTask('C1', 'Mine in C1', 'U_ME', 1000, false, null, 'U1');
        $storage->createTask('C2', 'Mine in C2', 'U_ME', 1000, false, null, 'U1');
        $storage->createTask('C1', 'Not mine', 'U_OTHER', 1000, false, null, 'U1');

        $titles = array_map(
            static fn (array $task): string => $task['title'],
            $storage->tasksForAssignee('U_ME')
        );

        sort($titles);
        $this->assertSame(['Mine in C1', 'Mine in C2'], $titles);
    }

    public function testUpdateTaskDetailsChangesFieldsButNeverPriority(): void
    {
        $storage = $this->createStorage();
        $created = $storage->createTask('C1', 'Original title', null, 1000, false, null, 'U1');
        $newDueDate = new \DateTimeImmutable('2026-12-25');

        $storage->updateTaskDetails($created['id'], 'New title', 'U_NEW_ASSIGNEE', $newDueDate, true);

        $updated = $storage->getTask($created['id']);

        $this->assertSame('New title', $updated['title']);
        $this->assertSame('U_NEW_ASSIGNEE', $updated['assigneeUserId']);
        $this->assertEquals($newDueDate, $updated['dueDate']);
        $this->assertTrue($updated['important']);
        $this->assertSame(1000, $updated['priority'], 'editing must never change priority');
    }

    public function testSwapPriorityExchangesRankWithTheAdjacentRowOnly(): void
    {
        $storage = $this->createStorage();

        $first = $storage->createTask('C1', 'First', null, 1000, false, null, 'U1');
        $second = $storage->createTask('C1', 'Second', null, 2000, false, null, 'U1');
        $third = $storage->createTask('C1', 'Third', null, 3000, false, null, 'U1');

        $storage->swapPriority($second['id'], 'up');

        $ordered = array_map(
            static fn (array $task): string => $task['title'],
            $storage->tasksForChannel('C1')
        );

        $this->assertSame(['Second', 'First', 'Third'], $ordered);

        // The row that wasn't involved in the swap must be untouched.
        $this->assertSame(3000, $storage->getTask($third['id'])['priority']);
    }

    public function testSwapPriorityIsANoOpAtListBoundaries(): void
    {
        $storage = $this->createStorage();

        $first = $storage->createTask('C1', 'First', null, 1000, false, null, 'U1');
        $second = $storage->createTask('C1', 'Second', null, 2000, false, null, 'U1');

        $storage->swapPriority($first['id'], 'up');
        $storage->swapPriority($second['id'], 'down');

        $ordered = array_map(
            static fn (array $task): string => $task['title'],
            $storage->tasksForChannel('C1')
        );

        $this->assertSame(['First', 'Second'], $ordered);
    }

    public function testSwapPriorityIsANoOpForAnUnknownTaskId(): void
    {
        $storage = $this->createStorage();

        $storage->swapPriority(999999, 'up');

        $this->assertNull($storage->getTask(999999));
    }

    public function testMarkDoneSetsStatusAndCompletedAtButKeepsTheRow(): void
    {
        $storage = $this->createStorage();
        $created = $storage->createTask('C1', 'Task', null, 1000, false, null, 'U1');

        $storage->markDone($created['id']);

        $updated = $storage->getTask($created['id']);

        $this->assertSame('done', $updated['status']);
        $this->assertNotNull($updated['completedAt']);
        $this->assertCount(1, $storage->tasksForChannel('C1'), 'the row must still be present until swept');
    }

    public function testChannelsWithOpenTasksListsOnlyChannelsWithAnOpenTask(): void
    {
        $storage = $this->createStorage();

        $storage->createTask('C1', 'Open', null, 1000, false, null, 'U1');
        $done = $storage->createTask('C2', 'Will be done', null, 1000, false, null, 'U1');
        $storage->markDone($done['id']);

        $channels = $storage->channelsWithOpenTasks();

        $this->assertContains('C1', $channels);
        $this->assertNotContains('C2', $channels);
    }

    public function testUnassignedTasksForChannelExcludesAssignedRows(): void
    {
        $storage = $this->createStorage();

        $storage->createTask('C1', 'Unassigned', null, 1000, false, null, 'U1');
        $storage->createTask('C1', 'Assigned', 'U_SOMEONE', 2000, false, null, 'U1');

        $titles = array_map(
            static fn (array $task): string => $task['title'],
            $storage->unassignedTasksForChannel('C1')
        );

        $this->assertSame(['Unassigned'], $titles);
    }

    public function testSweepDoneTasksOnlyRemovesDoneRowsForThatChannel(): void
    {
        $storage = $this->createStorage();

        $done = $storage->createTask('C1', 'Done here', null, 1000, false, null, 'U1');
        $storage->markDone($done['id']);
        $openHere = $storage->createTask('C1', 'Still open here', null, 2000, false, null, 'U1');
        $doneElsewhere = $storage->createTask('C2', 'Done elsewhere', null, 1000, false, null, 'U1');
        $storage->markDone($doneElsewhere['id']);

        $storage->sweepDoneTasks('C1');

        $this->assertNull($storage->getTask($done['id']));
        $this->assertNotNull($storage->getTask($openHere['id']));
        $this->assertNotNull($storage->getTask($doneElsewhere['id']), 'sweep must be scoped to the given channel');
    }

    public function testListStateIsNullUntilSaved(): void
    {
        $storage = $this->createStorage();

        $this->assertNull($storage->getListState('C1'));
    }

    public function testSaveListStateThenGetListStateRoundTrips(): void
    {
        $storage = $this->createStorage();

        $storage->saveListState('C1', '1700000000.123456');

        $this->assertSame(
            ['channelId' => 'C1', 'messageTs' => '1700000000.123456'],
            $storage->getListState('C1')
        );
    }

    public function testSaveListStateUpsertsRatherThanDuplicating(): void
    {
        $storage = $this->createStorage();

        $storage->saveListState('C1', '1700000000.111111');
        $storage->saveListState('C1', '1700000000.222222');

        $this->assertSame(
            ['channelId' => 'C1', 'messageTs' => '1700000000.222222'],
            $storage->getListState('C1')
        );
    }
}
