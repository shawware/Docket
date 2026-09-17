<?php

// Copyright © 2026 shawware.com.au

declare(strict_types=1);

namespace Shawware\Docket\Tests;

use PHPUnit\Framework\TestCase;
use Shawware\Docket\SlackPermalink;

final class SlackPermalinkTest extends TestCase
{
    public function testExtractsTheMessageTimestampFromAWellFormedPermalink(): void
    {
        $timestamp = SlackPermalink::messageTimestamp('https://my-workspace.slack.com/archives/C1/p1699999999000100');

        $this->assertNotNull($timestamp);
        $this->assertSame(1699999999, $timestamp->getTimestamp());
    }

    public function testExtractsTheTimestampEvenWithATrailingThreadQueryString(): void
    {
        $timestamp = SlackPermalink::messageTimestamp(
            'https://my-workspace.slack.com/archives/C1/p1699999999000100?thread_ts=1699999998.000100&cid=C1'
        );

        $this->assertNotNull($timestamp);
        $this->assertSame(1699999999, $timestamp->getTimestamp());
    }

    public function testReturnsNullWhenTheDigitsAfterPAreTooFewToBeATimestamp(): void
    {
        $this->assertNull(SlackPermalink::messageTimestamp('https://my-workspace.slack.com/archives/C1/p12345'));
    }

    public function testRoundTripsWithHowRouterConstructsAShortcutPermalink(): void
    {
        // Guards against the construction format (docket.php's
        // handleMessageShortcut) and the parsing format here silently
        // drifting apart — they're independently written and only
        // agree by convention.
        $messageTs = '1699999999.000100';
        $permalink = sprintf(
            'https://%s.slack.com/archives/%s/p%s',
            'my-workspace',
            'C1',
            str_replace('.', '', $messageTs)
        );

        $parsed = SlackPermalink::messageTimestamp($permalink);

        $this->assertNotNull($parsed);
        $this->assertSame(1699999999, $parsed->getTimestamp());
    }

    public function testReturnsNullForANonSlackUrl(): void
    {
        $this->assertNull(SlackPermalink::messageTimestamp('https://example.com/docs/readme'));
    }

    public function testReturnsNullForFreeTextThatIsNotAUrlAtAll(): void
    {
        $this->assertNull(SlackPermalink::messageTimestamp('see the bug report'));
    }
}
