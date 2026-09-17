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

        [$titleBlock, $assigneeBlock, $dueDateBlock, $importantBlock] = $view['blocks'];
        $this->assertArrayNotHasKey('initial_value', $titleBlock['element']);
        $this->assertArrayNotHasKey('initial_user', $assigneeBlock['element']);
        $this->assertArrayNotHasKey('initial_date', $dueDateBlock['element']);
        $this->assertArrayNotHasKey('initial_options', $importantBlock['element']);
    }

    public function testPreFilledModalCarriesTheTasksCurrentValuesAndIsTitledEditTask(): void
    {
        $task = [
            'id' => 42,
            'title' => 'Fix the login bug',
            'assigneeUserId' => 'U123',
            'important' => true,
            'dueDate' => new \DateTimeImmutable('2026-07-01'),
        ];

        $view = TaskModal::build('C1', $task);

        $this->assertSame('Edit task', $view['title']['text']);
        $this->assertSame('Save', $view['submit']['text']);
        $this->assertSame(['channelId' => 'C1', 'taskId' => 42], json_decode($view['private_metadata'], true));

        [$titleBlock, $assigneeBlock, $dueDateBlock, $importantBlock] = $view['blocks'];
        $this->assertSame('Fix the login bug', $titleBlock['element']['initial_value']);
        $this->assertSame('U123', $assigneeBlock['element']['initial_user']);
        $this->assertSame('2026-07-01', $dueDateBlock['element']['initial_date']);
        $this->assertSame(['important'], array_column($importantBlock['element']['initial_options'], 'value'));
    }

    public function testPreFilledModalWithNoAssigneeDueDateOrImportantOmitsThoseInitialValues(): void
    {
        $task = [
            'id' => 42,
            'title' => 'Bare task',
            'assigneeUserId' => null,
            'important' => false,
            'dueDate' => null,
        ];

        $view = TaskModal::build('C1', $task);

        [, $assigneeBlock, $dueDateBlock, $importantBlock] = $view['blocks'];
        $this->assertArrayNotHasKey('initial_user', $assigneeBlock['element']);
        $this->assertArrayNotHasKey('initial_date', $dueDateBlock['element']);
        $this->assertArrayNotHasKey('initial_options', $importantBlock['element']);
    }
}
