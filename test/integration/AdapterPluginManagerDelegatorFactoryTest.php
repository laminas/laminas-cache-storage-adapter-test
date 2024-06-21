<?php

declare(strict_types=1);

namespace LaminasTestTest\Cache\Storage\Adapter;

use Laminas\Cache\Storage\AdapterPluginManager;
use Laminas\Cache\Storage\StorageInterface;
use LaminasTest\Cache\Storage\Adapter\PluginManagerDelegatorFactoryTestTrait;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

final class AdapterPluginManagerDelegatorFactoryTest extends TestCase
{
    use PluginManagerDelegatorFactoryTestTrait;

    public static function getCommonAdapterNamesProvider(): iterable
    {
        yield 'foo' => ['foo'];
    }

    public function getDelegatorFactory(): callable
    {
        return fn (ContainerInterface $container) => new AdapterPluginManager($container, [
            'services' => [
                'foo' => $this->createMock(StorageInterface::class),
            ],
        ]);
    }
}
