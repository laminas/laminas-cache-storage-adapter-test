<?php

declare(strict_types=1);

namespace LaminasTest\Cache\Storage\Adapter;

use Interop\Container\ContainerInterface;
use Laminas\Cache\Storage\AdapterPluginManager;
use PHPUnit\Framework\TestCase;

use function class_exists;
use function reset;
use function sprintf;

/**
 * @see                   TestCase
 *
 * @psalm-require-extends TestCase
 */
trait LaminasConfigAggregatorIntegrationTestTrait
{
    use LaminasConfigAggregatorConfigProviderProviderTrait;

    public function testConfigAggregatorConfigProviderIsCallable(): void
    {
        $providerClassName = $this->getConfigProviderClassName();
        $provider          = new $providerClassName();
        self::assertIsCallable($provider);
    }

    public function testConfigAggregatorConfigProviderProvidesDelegatorFactory(): void
    {
        $providerClassName = $this->getConfigProviderClassName();
        $provider          = new $providerClassName();
        self::assertIsCallable($provider);
        $config = $provider();
        self::assertIsArray($config);
        $dependencies = $config['dependencies'] ?? [];
        self::assertIsArray($dependencies);
        $delegators = $dependencies['delegators'] ?? [];
        self::assertIsArray($delegators);
        $delegatorsForAdapterPluginManager = $delegators[AdapterPluginManager::class] ?? [];
        self::assertIsArray($delegatorsForAdapterPluginManager);
        self::assertCount(
            1,
            $delegatorsForAdapterPluginManager,
            sprintf('There must be exactly one delegator factory for the %s', AdapterPluginManager::class)
        );
        $delegator = reset($delegatorsForAdapterPluginManager);
        self::assertString($delegator, 'The delegator should be a class-string pointing to the delegator class.');
        self::assertTrue(class_exists($delegator), sprintf(
            'The configured delegator "%s" is not a valid class name or it could not be autoloaded.',
            $delegator
        ));

        $instance = new $delegator();
        self::assertIsCallable($instance, 'The configured delegator must be callable.');

        $container     = $this->createMock(ContainerInterface::class);
        $pluginManager = new AdapterPluginManager($container);
        $callback      = static function () use ($pluginManager): AdapterPluginManager {
            return $pluginManager;
        };

        $delegatedPluginManager = $delegator($container, AdapterPluginManager::class, $callback);
        self::assertSame($pluginManager, $delegatedPluginManager);
    }
}
