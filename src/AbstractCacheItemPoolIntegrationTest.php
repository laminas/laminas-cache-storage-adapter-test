<?php

declare(strict_types=1);

namespace LaminasTest\Cache\Storage\Adapter;

use Cache\IntegrationTests\CachePoolTest;
use Laminas\Cache\Psr\CacheItemPool\CacheItemPoolDecorator;
use Laminas\Cache\Storage\StorageInterface;
use Psr\Cache\CacheItemPoolInterface;

use function date_default_timezone_get;
use function date_default_timezone_set;
use function get_class;
use function sprintf;

abstract class AbstractCacheItemPoolIntegrationTest extends CachePoolTest
{
    /** @var string */
    private $tz;

    protected function setUp(): void
    {
        $deferredSkippedMessage = sprintf(
            '%s storage doesn\'t support driver deferred',
            $this->getStorageAdapterClassName(),
        );

        /**
         * @link           https://github.com/php-cache/integration-tests/issues/115
         *
         * @psalm-suppress MixedArrayAssignment
         */
        $this->skippedTests['testHasItemReturnsFalseWhenDeferredItemIsExpired'] = $deferredSkippedMessage;

        parent::setUp();
        // set non-UTC timezone
        $this->tz = date_default_timezone_get();
        date_default_timezone_set('America/Vancouver');
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->tz);
        parent::tearDown();
    }

    /** @psalm-return class-string<StorageInterface> */
    protected function getStorageAdapterClassName(): string
    {
        return get_class($this->createStorage());
    }

    abstract protected function createStorage(): StorageInterface;

    public function createCachePool(): CacheItemPoolInterface
    {
        return new CacheItemPoolDecorator($this->createStorage());
    }
}
