<?php

// Copyright © 2026 shawware.com.au

declare(strict_types=1);

namespace Shawware\Docket\Tests;

use PHPUnit\Framework\TestCase;
use Shawware\Docket\ListRenderer;

final class ListRendererTest extends TestCase
{
    private ListRenderer $renderer;
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->renderer = new ListRenderer();
        $this->now = new \DateTimeImmutable('2026-06-15');
    }

    public function testEmptyListRendersOnlyTheAddTaskButton(): void
    {
        $blocks = $this->renderer->render([], $this->now, 3, 90);

        $this->assertCount(1, $blocks);
        $this->assertSame('actions', $blocks[0]['type']);
        $this->assertSame('add_task', $blocks[0]['elements'][0]['action_id']);
    }

    public function testImportantBadgeAppearsWithoutAffectingOrder(): void
    {
        $tasks = [
            $this->task(id: 1, title: 'First', priority: 1000, important: false),
            $this->task(id: 2, title: 'Second', priority: 2000, important: true),
        ];

        $blocks = $this->renderer->render($tasks, $this->now, 3, 90);
        $rows = $this->taskRowBlocks($blocks);

        $this->assertStringContainsString('1. First', $rows[0]['text']['text']);
        $this->assertStringContainsString('2. ⭐ Second', $rows[1]['text']['text']);
    }

    public function testOverdueDueSoonAndNotDueTasksAreClassifiedCorrectly(): void
    {
        $tasks = [
            $this->task(id: 1, title: 'Overdue task', priority: 1000, dueDate: '2026-06-10'),
            $this->task(id: 2, title: 'Due soon task', priority: 2000, dueDate: '2026-06-17'),
            $this->task(id: 3, title: 'Not due yet', priority: 3000, dueDate: '2026-07-01'),
        ];

        $blocks = $this->renderer->render($tasks, $this->now, 3, 90);

        $this->assertStringContainsString('Overdue task', $blocks[0]['text']['text']);
        $this->assertStringNotContainsString('Due soon task', $blocks[0]['text']['text']);
        $this->assertStringNotContainsString('Not due yet', $blocks[0]['text']['text']);

        $this->assertStringContainsString('Due soon task', $blocks[1]['text']['text']);
        $this->assertStringNotContainsString('Overdue task', $blocks[1]['text']['text']);
        $this->assertStringNotContainsString('Not due yet', $blocks[1]['text']['text']);
    }

    public function testOverdueCalloutSortsMostRecentlyOverdueFirst(): void
    {
        $tasks = [
            $this->task(id: 1, title: 'Overdue five days', priority: 1000, dueDate: '2026-06-10'),
            $this->task(id: 2, title: 'Overdue one day', priority: 2000, dueDate: '2026-06-14'),
        ];

        $blocks = $this->renderer->render($tasks, $this->now, 3, 90);

        $text = $blocks[0]['text']['text'];
        $this->assertLessThan(
            strpos($text, 'Overdue five days'),
            strpos($text, 'Overdue one day')
        );
    }

    public function testDueSoonCalloutSortsNearestFirst(): void
    {
        $tasks = [
            $this->task(id: 1, title: 'Due in three days', priority: 1000, dueDate: '2026-06-18'),
            $this->task(id: 2, title: 'Due tomorrow', priority: 2000, dueDate: '2026-06-16'),
        ];

        $blocks = $this->renderer->render($tasks, $this->now, 3, 90);

        $text = $blocks[0]['text']['text'];
        $this->assertLessThan(
            strpos($text, 'Due in three days'),
            strpos($text, 'Due tomorrow')
        );
    }

    public function testBothCalloutsAreOmittedWhenEmpty(): void
    {
        $tasks = [$this->task(id: 1, title: 'No due date', priority: 1000)];

        $blocks = $this->renderer->render($tasks, $this->now, 3, 90);

        $this->assertSame('section', $blocks[0]['type']);
        $this->assertStringContainsString('1. No due date', $blocks[0]['text']['text']);
        foreach ($blocks as $block) {
            $text = $block['text']['text'] ?? '';
            $this->assertStringNotContainsString('Overdue', $text);
            $this->assertStringNotContainsString('Due soon', $text);
        }
    }

    public function testDoneTasksAreStruckThroughAtTheBottom(): void
    {
        $tasks = [
            $this->task(id: 1, title: 'Open task', priority: 1000),
            $this->task(id: 2, title: 'Finished task', priority: 2000, status: 'done'),
        ];

        $blocks = $this->renderer->render($tasks, $this->now, 3, 90);

        $doneBlock = $blocks[1];
        $this->assertSame('~Finished task~', $doneBlock['text']['text']);
        $this->assertSame([['text' => ['type' => 'plain_text', 'text' => '↩️ Reopen', 'emoji' => true], 'value' => '2:reopen']], $doneBlock['accessory']['options']);
    }

    public function testTopRowHasNoMoveUpAndBottomRowHasNoMoveDown(): void
    {
        $tasks = [
            $this->task(id: 1, title: 'First', priority: 1000),
            $this->task(id: 2, title: 'Second', priority: 2000),
            $this->task(id: 3, title: 'Third', priority: 3000),
        ];

        $blocks = $this->renderer->render($tasks, $this->now, 3, 90);
        $rows = $this->taskRowBlocks($blocks);

        $this->assertSame(['1:mark_done', '1:edit_task', '1:toggle_important', '1:move_down'], $this->optionValues($rows[0]));
        $this->assertSame(
            ['2:mark_done', '2:edit_task', '2:toggle_important', '2:move_up', '2:move_down'],
            $this->optionValues($rows[1])
        );
        $this->assertSame(
            ['3:mark_done', '3:edit_task', '3:toggle_important', '3:move_up'],
            $this->optionValues($rows[2])
        );
    }

    public function testDueDateBoundariesLandInTheCorrectCallout(): void
    {
        $tasks = [
            $this->task(id: 1, title: 'Due today', priority: 1000, dueDate: '2026-06-15'),
            $this->task(id: 2, title: 'Due on the last window day', priority: 2000, dueDate: '2026-06-18'),
            $this->task(id: 3, title: 'Due the day after the window', priority: 3000, dueDate: '2026-06-19'),
        ];

        $blocks = $this->renderer->render($tasks, $this->now, 3, 90);

        // No overdue callout: nothing is actually past due.
        $this->assertSame('section', $blocks[0]['type']);
        $dueSoonText = $blocks[0]['text']['text'];
        $this->assertStringContainsString('Due soon', $dueSoonText);
        $this->assertStringContainsString('Due today', $dueSoonText);
        $this->assertStringContainsString('Due on the last window day', $dueSoonText);
        $this->assertStringNotContainsString('Due the day after the window', $dueSoonText);
    }

    public function testSingleTaskListHasNeitherMoveUpNorMoveDown(): void
    {
        $tasks = [$this->task(id: 1, title: 'Only task', priority: 1000)];

        $blocks = $this->renderer->render($tasks, $this->now, 3, 90);
        $rows = $this->taskRowBlocks($blocks);

        $this->assertSame(['1:mark_done', '1:edit_task', '1:toggle_important'], $this->optionValues($rows[0]));
    }

    public function testRowLabelShowsAssigneeMentionOrUnassignedAndFlagsOverdueInline(): void
    {
        $tasks = [
            $this->task(id: 1, title: 'Assigned task', priority: 1000, assigneeUserId: 'U123'),
            $this->task(id: 2, title: 'Unassigned task', priority: 2000),
            $this->task(id: 3, title: 'Overdue task', priority: 3000, dueDate: '2026-06-10'),
        ];

        $blocks = $this->renderer->render($tasks, $this->now, 3, 90);
        $rows = $this->taskRowBlocks($blocks);

        $this->assertStringContainsString('<@U123>', $rows[0]['text']['text']);
        $this->assertStringContainsString('Unassigned', $rows[1]['text']['text']);
        $this->assertStringContainsString('⚠️ 2026-06-10', $rows[2]['text']['text']);
    }

    public function testRowShowsASourceLinkWhenRecentButNotWhenPastTheMaxAgeWindow(): void
    {
        $tasks = [
            $this->task(
                id: 1,
                title: 'Recent',
                priority: 1000,
                sourcePermalink: 'https://slack.example/archives/C1/p1',
                createdAt: '2026-06-01' // 14 days before $this->now
            ),
            $this->task(
                id: 2,
                title: 'Old',
                priority: 2000,
                sourcePermalink: 'https://slack.example/archives/C1/p2',
                createdAt: '2026-01-01' // well past a 90-day window
            ),
            $this->task(id: 3, title: 'No link', priority: 3000),
        ];

        $blocks = $this->renderer->render($tasks, $this->now, 3, 90);
        $rows = $this->taskRowBlocks($blocks);

        $this->assertStringContainsString('<https://slack.example/archives/C1/p1|🔗>', $rows[0]['text']['text']);
        $this->assertStringNotContainsString('🔗', $rows[1]['text']['text']);
        $this->assertStringNotContainsString('🔗', $rows[2]['text']['text']);
    }

    public function testSourceLinkAgeUsesTheMessagesOwnTimestampNotTheTasksCreatedAt(): void
    {
        // A recent message linked from a long-standing task (e.g. edited
        // in by hand, or the task itself is old) — still shown, because
        // the *message* is recent, regardless of when the task was made.
        $recentMessageTs = (new \DateTimeImmutable('2026-06-05'))->getTimestamp(); // 10 days before $this->now
        $recentLink = "https://my-workspace.slack.com/archives/C1/p{$recentMessageTs}";

        // An old message linked from a brand-new task — hidden, because
        // the *message* is stale, even though the task was just created.
        $oldMessageTs = (new \DateTimeImmutable('2020-01-01'))->getTimestamp();
        $oldLink = "https://my-workspace.slack.com/archives/C1/p{$oldMessageTs}";

        $tasks = [
            $this->task(
                id: 1,
                title: 'Old task, recent message',
                priority: 1000,
                sourcePermalink: $recentLink,
                createdAt: '2020-01-01'
            ),
            $this->task(
                id: 2,
                title: 'New task, old message',
                priority: 2000,
                sourcePermalink: $oldLink,
                createdAt: '2026-06-15'
            ),
        ];

        $blocks = $this->renderer->render($tasks, $this->now, 3, 90);
        $rows = $this->taskRowBlocks($blocks);

        $this->assertStringContainsString('🔗', $rows[0]['text']['text']);
        $this->assertStringNotContainsString('🔗', $rows[1]['text']['text']);
    }

    public function testAllDoneListHasNoCalloutsOrNumberedRowsJustStrikethroughAndAddTask(): void
    {
        $tasks = [
            $this->task(id: 1, title: 'Finished one', priority: 1000, status: 'done'),
            $this->task(id: 2, title: 'Finished two', priority: 2000, status: 'done'),
        ];

        $blocks = $this->renderer->render($tasks, $this->now, 3, 90);

        $this->assertCount(3, $blocks);
        $this->assertSame('~Finished one~', $blocks[0]['text']['text']);
        $this->assertSame('~Finished two~', $blocks[1]['text']['text']);
        $this->assertSame('actions', $blocks[2]['type']);
        $this->assertSame([], $this->taskRowBlocks($blocks));
    }

    public function testRenderMyTasksPreservesEachChannelsPriorityOrder(): void
    {
        // StorageInterface::tasksForAssignee() already returns each channel's
        // tasks ordered by priority ascending; renderMyTasks must not
        // scramble that when it groups by channel.
        $tasks = [
            $this->task(id: 1, title: 'Earlier in C1', priority: 1000, channelId: 'C1'),
            $this->task(id: 2, title: 'Later in C1', priority: 2000, channelId: 'C1'),
        ];

        $blocks = $this->renderer->renderMyTasks($tasks, $this->now);

        $listText = $blocks[1]['text']['text'];
        $this->assertLessThan(
            strpos($listText, 'Later in C1'),
            strpos($listText, 'Earlier in C1')
        );
    }

    public function testRenderMyTasksGroupsAcrossChannels(): void
    {
        $tasks = [
            $this->task(id: 1, title: 'Task A', priority: 1000, channelId: 'C1'),
            $this->task(id: 2, title: 'Task B', priority: 1000, channelId: 'C2'),
            $this->task(id: 3, title: 'Task C', priority: 2000, channelId: 'C1'),
        ];

        $blocks = $this->renderer->renderMyTasks($tasks, $this->now);

        $this->assertStringContainsString('C1', $blocks[0]['text']['text']);
        $this->assertStringContainsString('Task A', $blocks[1]['text']['text']);
        $this->assertStringContainsString('Task C', $blocks[1]['text']['text']);
        $this->assertStringContainsString('C2', $blocks[2]['text']['text']);
        $this->assertStringContainsString('Task B', $blocks[3]['text']['text']);
    }

    public function testRenderMyTasksWithNoTasksShowsAnEmptyState(): void
    {
        $blocks = $this->renderer->renderMyTasks([], $this->now);

        $this->assertCount(1, $blocks);
        $this->assertStringContainsString('No open tasks', $blocks[0]['text']['text']);
    }

    public function testRenderMyTasksFlagsAnOverdueTaskWithTheWarningEmoji(): void
    {
        // Regression: renderMyTasks() used to call rowLabel() without
        // $today, so the ⚠️ flag never appeared here even though the
        // pinned list's own render() showed it correctly.
        $tasks = [
            $this->task(id: 1, title: 'Overdue task', priority: 1000, channelId: 'C1', dueDate: $this->now->modify('-2 days')->format('Y-m-d')),
            $this->task(id: 2, title: 'Not due yet', priority: 2000, channelId: 'C1', dueDate: $this->now->modify('+2 days')->format('Y-m-d')),
        ];

        $blocks = $this->renderer->renderMyTasks($tasks, $this->now);
        $listText = $blocks[1]['text']['text'];

        $this->assertStringContainsString('⚠️', $listText);
        $this->assertStringNotContainsString('Not due yet (⚠️', $listText);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function taskRowBlocks(array $blocks): array
    {
        // Open-row menus always include mark_done; done rows only ever
        // carry the single reopen option — this distinguishes the two.
        return array_values(array_filter(
            $blocks,
            static fn(array $block): bool => $block['type'] === 'section'
                && isset($block['accessory'])
                && str_ends_with((string) $block['accessory']['options'][0]['value'], ':mark_done')
        ));
    }

    /**
     * @param array<string, mixed> $row
     * @return array<int, string>
     */
    private function optionValues(array $row): array
    {
        return array_map(
            static fn(array $option): string => $option['value'],
            $row['accessory']['options']
        );
    }

    /**
     * @return array{
     *     id: int,
     *     channelId: string,
     *     title: string,
     *     assigneeUserId: ?string,
     *     important: bool,
     *     priority: int,
     *     dueDate: ?\DateTimeImmutable,
     *     status: string,
     *     createdBy: string,
     *     createdAt: \DateTimeImmutable,
     *     completedAt: ?\DateTimeImmutable,
     *     sourcePermalink: ?string
     * }
     */
    private function task(
        int $id,
        string $title,
        int $priority,
        string $channelId = 'C123',
        ?string $assigneeUserId = null,
        bool $important = false,
        ?string $dueDate = null,
        string $status = 'open',
        ?string $sourcePermalink = null,
        string $createdAt = '2026-01-01'
    ): array {
        return [
            'id' => $id,
            'channelId' => $channelId,
            'title' => $title,
            'assigneeUserId' => $assigneeUserId,
            'important' => $important,
            'priority' => $priority,
            'dueDate' => $dueDate === null ? null : new \DateTimeImmutable($dueDate),
            'status' => $status,
            'createdBy' => 'U000',
            'createdAt' => new \DateTimeImmutable($createdAt),
            'completedAt' => null,
            'sourcePermalink' => $sourcePermalink,
        ];
    }
}
