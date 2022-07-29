<?php

declare(strict_types=1);

namespace LaminasTestTest\Cache\Storage\Adapter;

use Laminas\Cache\Storage\Adapter\Apcu;
use Laminas\Cache\Storage\StorageInterface;
use LaminasTest\Cache\Storage\Adapter\AbstractCacheItemPoolIntegrationTest;

final class CacheItemPoolIntegrationTestTest extends AbstractCacheItemPoolIntegrationTest
{
    protected function setUp(): void
    {
        $this->skippedTests['testHasItemReturnsFalseWhenDeferredItemIsExpired']
            = 'Skipping since laminas-cache does not yet handle `expiresAt` properly.'
            . ' See https://github.com/laminas/laminas-cache/pull/199';

        parent::setUp();
    }

    protected function createStorage(): StorageInterface
    {
        return new Apcu();
    }
}
