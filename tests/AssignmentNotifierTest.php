<?php

// Copyright © 2026 shawware.com.au

declare(strict_types=1);

namespace Shawware\Docket\Tests;

use PHPUnit\Framework\TestCase;
use Shawware\Docket\AssignmentNotifier;
use Shawware\Docket\Tests\Fakes\RecordingSlackApi;

final class AssignmentNotifierTest extends TestCase
{
    public function testNotifyOpensADmThenPostsTheTaskAndAListLink(): void
    {
        $slackApi = new RecordingSlackApi();
        $notifier = new AssignmentNotifier($slackApi);

        $notifier->notify('U9', 'Fix the login bug', 'C1', '1699999999.000100');

        $this->assertSame(['openDm', 'postMessage'], $slackApi->calls);
        $this->assertSame(['U9'], $slackApi->openedDms);

        $posted = $slackApi->postedMessages[0];
        $this->assertSame('D123', $posted['channel']);
        $this->assertStringContainsString('Fix the login bug', $posted['blocks'][0]['text']['text']);
        $this->assertStringContainsString('<#C1>', $posted['blocks'][0]['text']['text']);
        $this->assertStringContainsString(
            '<https://slack.com/archives/C1/p1699999999000100|View the list>',
            $posted['blocks'][0]['text']['text']
        );
        $this->assertStringContainsString('Fix the login bug', $posted['fallbackText']);
    }

    public function testNotifyOmitsTheListLinkWhenNoListMessageExistsYet(): void
    {
        $slackApi = new RecordingSlackApi();
        $notifier = new AssignmentNotifier($slackApi);

        $notifier->notify('U9', 'Fix the login bug', 'C1', null);

        $posted = $slackApi->postedMessages[0];
        $this->assertStringNotContainsString('View the list', $posted['blocks'][0]['text']['text']);
    }
}
