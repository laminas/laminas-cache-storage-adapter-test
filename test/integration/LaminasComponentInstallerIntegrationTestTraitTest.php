<?php

declare(strict_types=1);

namespace LaminasTestTest\Cache\Storage\Adapter;

use LaminasTestTest\Cache\Storage\Adapter\TestAsset\ConfigProvider;
use LaminasTestTest\Cache\Storage\Adapter\TestAsset\LaminasComponentInstallerIntegrationTestTraitImplementation;
use PHPUnit\Framework\TestCase;

final class LaminasComponentInstallerIntegrationTestTraitTest extends TestCase
{
    /** @var LaminasComponentInstallerIntegrationTestTraitImplementation */
    private $implementation;

    protected function setUp(): void
    {
        parent::setUp();
        /** @psalm-suppress InternalMethod */
        $this->implementation = new LaminasComponentInstallerIntegrationTestTraitImplementation();
    }

    public function testCanParseComposerJson(): void
    {
        $parsed = $this->implementation->getParsedComposerJsonExtraForLaminasComponentInstallerInformations();
        self::assertEquals(ConfigProvider::class, $parsed['config-provider']);
        self::assertEquals(__NAMESPACE__ . '\\TestAsset', $parsed['module']);
    }
}
