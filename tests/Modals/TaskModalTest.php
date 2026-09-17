<?php

// Copyright © 2026 shawware.com.au

declare(strict_types=1);

namespace Shawware\Docket\Tests\Modals;

use PHPUnit\Framework\TestCase;
use Shawware\Docket\Modals\TaskModal;

final class TaskModalTest extends TestCase
{
    public function testBlankModalHasNoInitialValuesAndIsTitledAddTask(): void
    {
        $view = TaskModal::build('C1');

        $this->assertSame('Add task', $view['title']['text']);
        $this->assertSame('Add', $view['submit']['text']);
        $this->assertSame(['channelId' => 'C1', 'taskId' => null], json_decode($view['private_metadata'], true));

        [$titleBlock, $assigneeBlock, $dueDateBlock, $importantBlock, $linkBlock] = $view['blocks'];
        $this->assertArrayNotHasKey('initial_value', $titleBlock['element']);
        $this->assertArrayNotHasKey('initial_user', $assigneeBlock['element']);
        $this->assertArrayNotHasKey('initial_date', $dueDateBlock['element']);
        $this->assertArrayNotHasKey('initial_options', $importantBlock['element']);
        $this->assertArrayNotHasKey('initial_value', $linkBlock['element']);
    }

    public function testEditModalCarriesTheTasksCurrentValuesAndIsTitledEditTask(): void
    {
        $prefill = [
            'title' => 'Fix the login bug',
            'assigneeUserId' => 'U123',
            'important' => true,
            'dueDate' => new \DateTimeImmutable('2026-07-01'),
            'sourcePermalink' => 'https://slack.example/archives/C1/p123',
        ];

        $view = TaskModal::build('C1', 42, $prefill);

        $this->assertSame('Edit task', $view['title']['text']);
        $this->assertSame('Save', $view['submit']['text']);
        $this->assertSame(['channelId' => 'C1', 'taskId' => 42], json_decode($view['private_metadata'], true));

        [$titleBlock, $assigneeBlock, $dueDateBlock, $importantBlock, $linkBlock] = $view['blocks'];
        $this->assertSame('Fix the login bug', $titleBlock['element']['initial_value']);
        $this->assertSame('U123', $assigneeBlock['element']['initial_user']);
        $this->assertSame('2026-07-01', $dueDateBlock['element']['initial_date']);
        $this->assertSame(['important'], array_column($importantBlock['element']['initial_options'], 'value'));
        $this->assertSame('https://slack.example/archives/C1/p123', $linkBlock['element']['initial_value']);
    }

    public function testEditModalWithNoAssigneeDueDateImportantOrLinkOmitsThoseInitialValues(): void
    {
        $view = TaskModal::build('C1', 42, ['title' => 'Bare task']);

        [, $assigneeBlock, $dueDateBlock, $importantBlock, $linkBlock] = $view['blocks'];
        $this->assertArrayNotHasKey('initial_user', $assigneeBlock['element']);
        $this->assertArrayNotHasKey('initial_date', $dueDateBlock['element']);
        $this->assertArrayNotHasKey('initial_options', $importantBlock['element']);
        $this->assertArrayNotHasKey('initial_value', $linkBlock['element']);
    }

    public function testAPreFilledAddModalIsStillTitledAddTaskWithNoTaskIdInMetadata(): void
    {
        // The "Add as task" message shortcut: pre-filled, but still a
        // fresh create on submission, not an edit of an existing task.
        $view = TaskModal::build('C1', null, [
            'title' => 'Investigate the reported bug',
            'assigneeUserId' => 'U456',
            'sourcePermalink' => 'https://slack.example/archives/C1/p999',
        ]);

        $this->assertSame('Add task', $view['title']['text']);
        $this->assertSame('Add', $view['submit']['text']);
        $this->assertSame(['channelId' => 'C1', 'taskId' => null], json_decode($view['private_metadata'], true));

        [$titleBlock, $assigneeBlock, , , $linkBlock] = $view['blocks'];
        $this->assertSame('Investigate the reported bug', $titleBlock['element']['initial_value']);
        $this->assertSame('U456', $assigneeBlock['element']['initial_user']);
        $this->assertSame('https://slack.example/archives/C1/p999', $linkBlock['element']['initial_value']);
    }
}
