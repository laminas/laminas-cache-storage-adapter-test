<?php

declare(strict_types=1);

namespace LaminasTest\Cache\Storage\Adapter;

use PHPUnit\Framework\TestCase;
use ReflectionClass;

use function class_exists;
use function method_exists;
use function sprintf;

/**
 * @see                   TestCase
 *
 * @psalm-require-extends TestCase
 */
trait LaminasModuleManagerModuleIntegrationTestTrait
{
    use LaminasConfigAggregatorConfigProviderProviderTrait;

    public function testModuleProvidesTheSameConfigurationAsConfigAggregator(): void
    {
        $providerClassName = $this->getConfigProviderClassName();
        $reflection        = new ReflectionClass($providerClassName);
        $moduleClassName   = sprintf('%s\\Module', $reflection->getNamespaceName());
        self::assertTrue(
            class_exists($moduleClassName),
            sprintf(
                'Module class "%s" could not be found. Did you miss to create it?',
                $moduleClassName
            )
        );

        $configProvider           = new $providerClassName();
        $configFromConfigProvider = $configProvider();
        self::assertIsArray($configFromConfigProvider);
        $dependenciesFromConfigProvider = $configFromConfigProvider['dependencies'] ?? [];
        self::assertIsArray($dependenciesFromConfigProvider);

        $module = new $moduleClassName();
        self::assertTrue(
            method_exists($module, 'getConfig'),
            sprintf('Method `%s#%s` is missing.', $moduleClassName, 'getConfig')
        );
        $configFromModule = $module->getConfig();
        self::assertIsArray($configFromModule);
        $dependenciesFromModule = $configFromModule['service_manager'] ?? [];
        self::assertIsArray($dependenciesFromModule);

        self::assertSame($dependenciesFromConfigProvider, $dependenciesFromModule);
        self::assertArrayNotHasKey('dependencies', $configFromModule);
    }
}
