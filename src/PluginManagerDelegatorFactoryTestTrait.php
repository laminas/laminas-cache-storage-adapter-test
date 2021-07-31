<?php

declare(strict_types=1);

namespace LaminasTest\Cache\Storage\Adapter;

use Interop\Container\ContainerInterface;
use Laminas\Cache\Storage\AdapterPluginManager;
use PHPUnit\Framework\TestCase;

/**
 * @see TestCase
 *
 * @psalm-require-extends TestCase
 */
trait PluginManagerDelegatorFactoryTestTrait
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
     *
     * @psalm-return callable(ContainerInterface,string,callable):AdapterPluginManager
     */
    abstract public function getDelegatorFactory(): callable;

    /**
     * @psalm-param non-empty-string $commonAdapterName
     * @dataProvider getCommonAdapterNamesProvider
     */
    public function testAdapterPluginManagerWithCommonNames(string $commonAdapterName): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $container
            ->expects(self::never())
            ->method(self::anything());

        $pluginManager = $this->getDelegatorFactory()(
            $container,
            AdapterPluginManager::class,
            static function (): AdapterPluginManager {
                return new AdapterPluginManager();
            }
        );
        $this->assertTrue(
            $pluginManager->has($commonAdapterName),
            "Storage adapter name '{$commonAdapterName}' not found in storage adapter plugin manager"
        );
    }
}
