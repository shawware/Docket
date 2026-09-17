<?php

// Copyright © 2026 shawware.com.au

declare(strict_types=1);

namespace Shawware\Docket\Tests\Modals;

use PHPUnit\Framework\TestCase;
use Shawware\Docket\Modals\ReminderModal;

final class ReminderModalTest extends TestCase
{
    public function testBuildProducesADatetimepickerModalCarryingTheTaskInMetadata(): void
    {
        $view = ReminderModal::build('C1', 42, 'Fix the login bug');

        $this->assertSame('reminder_modal', $view['callback_id']);
        $this->assertSame(['channelId' => 'C1', 'taskId' => 42], json_decode($view['private_metadata'], true));
        $this->assertStringContainsString('Fix the login bug', $view['blocks'][0]['text']['text']);

        $whenBlock = $view['blocks'][1];
        $this->assertSame('when_block', $whenBlock['block_id']);
        $this->assertSame('datetimepicker', $whenBlock['element']['type']);
        $this->assertSame('when_input', $whenBlock['element']['action_id']);
    }
}
