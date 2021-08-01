<?php

declare(strict_types=1);

namespace LaminasTest\Cache\Storage\Adapter;

use Cache\IntegrationTests\SimpleCacheTest;
use Laminas\Cache\Psr\SimpleCache\SimpleCacheDecorator;
use Laminas\Cache\Storage\StorageInterface;
use Psr\SimpleCache\CacheInterface;

use function date_default_timezone_get;
use function date_default_timezone_set;
use function get_class;

abstract class AbstractSimpleCacheIntegrationTest extends SimpleCacheTest
{
    /** @var string */
    private $tz;

    protected function setUp(): void
    {
        parent::setUp();
        // set non-UTC timezone
        $this->tz = date_default_timezone_get();
        date_default_timezone_set('America/Vancouver');
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        date_default_timezone_set($this->tz);
    }

    /** @psalm-return class-string<StorageInterface> */
    protected function getStorageAdapterClassName(): string
    {
        return get_class($this->createStorage());
    }

    abstract protected function createStorage(): StorageInterface;

    public function createSimpleCache(): CacheInterface
    {
        return new SimpleCacheDecorator($this->createStorage());
    }
}
