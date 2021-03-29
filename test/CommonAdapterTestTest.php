<?php

/**
 * @see       https://github.com/laminas/laminas-cache-storage-adapter-test for the canonical source repository
 * @copyright https://github.com/laminas/laminas-cache-storage-adapter-test/blob/master/COPYRIGHT.md
 * @license   https://github.com/laminas/laminas-cache-storage-adapter-test/blob/master/LICENSE.md New BSD License
 */

namespace LaminasTestTest\Cache\Storage\Adapter;

use Laminas\Cache\Storage\Adapter\AdapterOptions;
use LaminasTest\Cache\Storage\Adapter\CommonAdapterTest;

/**
 * @group      Laminas_Cache
 */
final class CommonAdapterTestTest extends CommonAdapterTest
{
    public function setUp()
    {
        $this->_options = new AdapterOptions();
        $this->_storage = new TestAsset\MockAdapter($this->_options);

        parent::setUp();
    }

    /**
     * @return array
     */
    public function getCommonAdapterNamesProvider()
    {
        return [];
    }
}
