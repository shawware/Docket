<?php

// Copyright © 2026 shawware.com.au

declare(strict_types=1);

namespace Shawware\Docket\Tests;

use PHPUnit\Framework\TestCase;
use Shawware\Docket\ChannelListService;
use Shawware\Docket\ListRenderer;
use Shawware\Docket\Storage\InMemoryStorage;
use Shawware\Docket\Tests\Fakes\RecordingSlackApi;

final class ChannelListServiceTest extends TestCase
{
    public function testFirstPublishJoinsPostsPinsAndSavesListState(): void
    {
        $storage = new InMemoryStorage();
        $storage->createTask('C1', 'First task', null, 1000, false, null, 'U1');
        $slackApi = new RecordingSlackApi();

        $service = new ChannelListService($storage, $slackApi, new ListRenderer(), 3, 90);
        $service->publish('C1');

        $this->assertSame(['joinChannel', 'postMessage', 'pinMessage'], $slackApi->calls);
        $this->assertSame(
            ['channelId' => 'C1', 'messageTs' => '1699999999.000100'],
            $storage->getListState('C1')
        );
    }

    public function testFirstPublishStillSucceedsWhenJoinChannelFailsForAPrivateChannel(): void
    {
        $storage = new InMemoryStorage();
        $storage->createTask('C1', 'First task', null, 1000, false, null, 'U1');
        $slackApi = new RecordingSlackApi();
        $slackApi->joinChannelFails = true;

        $service = new ChannelListService($storage, $slackApi, new ListRenderer(), 3, 90);
        $service->publish('C1');

        $this->assertSame(['joinChannel', 'postMessage', 'pinMessage'], $slackApi->calls);
        $this->assertNotNull($storage->getListState('C1'));
    }

    public function testSubsequentPublishOnlyUpdates(): void
    {
        $storage = new InMemoryStorage();
        $storage->createTask('C1', 'First task', null, 1000, false, null, 'U1');
        $storage->saveListState('C1', '1699999999.000100');
        $slackApi = new RecordingSlackApi();

        $service = new ChannelListService($storage, $slackApi, new ListRenderer(), 3, 90);
        $service->publish('C1');

        $this->assertSame(['updateMessage'], $slackApi->calls);
    }
}
