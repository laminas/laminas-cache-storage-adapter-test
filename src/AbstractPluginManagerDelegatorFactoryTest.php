<?php

declare(strict_types=1);

namespace LaminasTest\Cache\Storage\Adapter;

use Laminas\Cache\Storage\AdapterPluginManager;
use PHPUnit\Framework\TestCase;

abstract class AbstractPluginManagerDelegatorFactoryTest extends TestCase
{
    /**
     * A data provider for common storage adapter names
     *
     * @psalm-return iterable<non-empty-string,array{0:non-empty-string}>
     */
    abstract public function getCommonAdapterNamesProvider(): iterable;

    /**
     * Should provide the provisioned plugin manager.
     * Starting with laminas-cache v3.0.0, all cache adapters have to provide themselves to the plugin manager.
     */
    abstract public function getProvisionedPluginManager(): AdapterPluginManager;

    /**
     * @psalm-param non-empty-string $commonAdapterName
     * @dataProvider getCommonAdapterNamesProvider
     */
    public function testAdapterPluginManagerWithCommonNames(string $commonAdapterName): void
    {
        $pluginManager = $this->getProvisionedPluginManager();
        $this->assertTrue(
            $pluginManager->has($commonAdapterName),
            "Storage adapter name '{$commonAdapterName}' not found in storage adapter plugin manager"
        );
    }
}
