<?php

/**
 * @see       https://github.com/laminas/laminas-cache-storage-adapter-test for the canonical source repository
 * @copyright https://github.com/laminas/laminas-cache-storage-adapter-test/blob/master/COPYRIGHT.md
 * @license   https://github.com/laminas/laminas-cache-storage-adapter-test/blob/master/LICENSE.md New BSD License
 */

namespace LaminasTest\Cache\Storage\Adapter;

use Laminas\Cache\Storage\Adapter\AdapterOptions;
use Laminas\Cache\Storage\StorageInterface;

/**
 * PHPUnit test case
 */

/**
 * @deprecated Please extend {@see AbstractCommonAdapterTest} instead.
 * @codingStandardsIgnoreFile
 */
abstract class CommonAdapterTest extends AbstractCommonAdapterTest
{
    /**
     * @var StorageInterface
     */
    protected $_storage;

    /** @var AdapterOptions */
    protected $_options;

    /**
     * All datatypes of PHP
     *
     * @var string[]|null
     */
    protected $_phpDatatypes;

    protected function setUp(): void
    {
        $this->storage = $this->_storage;
        $this->options = $this->_options;
        $this->phpDatatypes = $this->_phpDatatypes ?? $this->phpDatatypes;

        parent::setUp();
    }
}
