<?php

declare(strict_types=1);

namespace LaminasTestTest\Cache\Storage\Adapter;

use Composer\InstalledVersions;
use Laminas\Cache\Exception\RuntimeException;
use Laminas\Cache\Storage\Adapter\Apcu;
use Laminas\Cache\Storage\Adapter\Memory;
use Laminas\Cache\Storage\FlushableInterface;
use Laminas\Cache\Storage\StorageInterface;
use Webmozart\Assert\Assert;

use function assert;
use function class_exists;

final class AdapterForIntegrationTestDetector
{
    /**
     * @psalm-suppress MixedInferredReturnType Due to recursive dependencies, we can not have APCu installed as during
     *                                         development. Will be installed during CI via `.laminas-ci.json`.
     */
    public static function detect(): StorageInterface
    {
        if (InstalledVersions::isInstalled('laminas/laminas-cache-storage-adapter-apcu')) {
            assert(class_exists(Apcu::class));
            Assert::implementsInterface(Apcu::class, StorageInterface::class);
            Assert::implementsInterface(Apcu::class, FlushableInterface::class);
            /**
             * @psalm-suppress MixedReturnStatement Due to recursive dependencies, we can not have APCu installed
             *                                        during development. Will be installed during CI via
             *                                        `.laminas-ci.json`.
             */
            return new Apcu();
        }

        if (InstalledVersions::isInstalled('laminas/laminas-cache-storage-adapter-memory')) {
            assert(class_exists(Memory::class));
            Assert::implementsInterface(Memory::class, StorageInterface::class);
            Assert::implementsInterface(Memory::class, FlushableInterface::class);
            /**
             * @psalm-suppress MixedReturnStatement Due to recursive dependencies, we can not have Memory installed
             *                                        during development. Will be installed during CI via
             *                                        `.laminas-ci.json`.
             */
            return new Memory();
        }

        throw new RuntimeException('Unable to detect storage adapter for integration tests.');
    }
}
