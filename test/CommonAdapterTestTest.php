<?php

declare(strict_types=1);

namespace LaminasTestTest\Cache\Storage\Adapter;

use Laminas\Cache\Storage\Adapter\AdapterOptions;
use LaminasTest\Cache\Storage\Adapter\AbstractCommonAdapterTest;

/**
 * @group      Laminas_Cache
 */
final class CommonAdapterTestTest extends AbstractCommonAdapterTest
{
    protected function setUp(): void
    {
        $this->options = new AdapterOptions();
        $this->storage = new TestAsset\MockAdapter($this->options);

        parent::setUp();
    }

    /**
     * @return array
     */
    public function getCommonAdapterNamesProvider()
    {
        return [];
    }

    public function testCanStoreValuesWithCacheKeysUpToTheMaximumKeyLengthLimit(): void
    {
        self::markTestSkipped();
    }
}
