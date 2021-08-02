<?php

declare(strict_types=1);

namespace LaminasTestTest\Cache\Storage\Adapter\TestAsset;

use LaminasTest\Cache\Storage\Adapter\LaminasComponentInstallerIntegrationTestTrait;
use PHPUnit\Framework\TestCase;

final class LaminasComponentInstallerIntegrationTestTraitImplementation extends TestCase
{
    use LaminasComponentInstallerIntegrationTestTrait;

    protected function getConfigProviderClassName(): string
    {
        return ConfigProvider::class;
    }

    protected function getComposerJsonPath(): string
    {
        return __DIR__ . '/composer.json';
    }

    public function getParsedComposerJsonExtraForLaminasComponentInstallerInformations(): array
    {
        return $this->parseComposerJsonExtraForLaminasComponentInstallerInformations();
    }
}
