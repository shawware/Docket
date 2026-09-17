<?php

// Copyright © 2026 shawware.com.au

declare(strict_types=1);

namespace Shawware\Docket\Tests\Storage;

use Shawware\Docket\Storage\InMemoryStorage;
use Shawware\Docket\Storage\StorageInterface;

final class InMemoryStorageTest extends StorageContractTestCase
{
    protected function createStorage(): StorageInterface
    {
        return new InMemoryStorage();
    }
}
