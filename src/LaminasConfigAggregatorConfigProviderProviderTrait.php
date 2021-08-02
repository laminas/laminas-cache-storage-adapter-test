<?php

declare(strict_types=1);

namespace LaminasTest\Cache\Storage\Adapter;

trait LaminasConfigAggregatorConfigProviderProviderTrait
{
    /**
     * Returns the config provider class-name of the cache component.
     *
     * @psalm-return class-string
     */
    abstract protected function getConfigProviderClassName(): string;
}
