<?php

declare(strict_types=1);

namespace LaminasTest\Cache\Storage\Adapter;

use Laminas\Cache\Storage\AdapterPluginManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

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
    abstract public static function getCommonAdapterNamesProvider(): iterable;

    /**
     * Should provide the provisioned plugin manager.
     * Starting with laminas-cache v3.0.0, all cache adapters have to provide themselves to the plugin manager.
     *
     * @psalm-return callable(ContainerInterface,string,callable):AdapterPluginManager
     */
    abstract public function getDelegatorFactory(): callable;

    /**
     * @psalm-param non-empty-string $commonAdapterName
     */
    #[DataProvider('getCommonAdapterNamesProvider')]
    public function testAdapterPluginManagerWithCommonNames(string $commonAdapterName): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $container
            ->expects(self::never())
            ->method(self::anything());

        $pluginManager = $this->getDelegatorFactory()(
            $container,
            AdapterPluginManager::class,
            static function () use ($container): AdapterPluginManager {
                return new AdapterPluginManager($container);
            }
        );
        self::assertTrue(
            $pluginManager->has($commonAdapterName),
            "Storage adapter name '{$commonAdapterName}' not found in storage adapter plugin manager"
        );
    }
}
