<?php

declare(strict_types=1);

namespace LaminasTestTest\Cache\Storage\Adapter;

use Laminas\Cache\Storage\Adapter\Memory;
use Laminas\Cache\Storage\StorageInterface;
use LaminasTest\Cache\Storage\Adapter\AbstractSimpleCacheIntegrationTest;

final class SimpleCacheIntegrationTestTest extends AbstractSimpleCacheIntegrationTest
{
    protected function setUp(): void
    {
        parent::setUp();
        /** @psalm-suppress UndefinedClass Memory adapter is not loaded during development. */
        if ($this->createStorage() instanceof Memory) {
            $this->skippedTests['testObjectDoesNotChangeInCache'] =
                'Memory adapter stores objects in memory; so change in references is possible';
        }
    }

    protected function createStorage(): StorageInterface
    {
        return AdapterForIntegrationTestDetector::detect();
    }
}
