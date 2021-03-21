<?php

/**
 * @see       https://github.com/laminas/laminas-cache for the canonical source repository
 * @copyright https://github.com/laminas/laminas-cache/blob/master/COPYRIGHT.md
 * @license   https://github.com/laminas/laminas-cache/blob/master/LICENSE.md New BSD License
 */

namespace LaminasTestTest\Cache\Storage\Adapter\TestAsset;

use Laminas\Cache\Storage\Adapter\AbstractAdapter;
use Laminas\Cache\Storage\Adapter\AdapterOptions;

/**
 * @property AdapterOptions $options
 */
final class MockAdapter extends AbstractAdapter
{
    /** @var array<mixed, mixed> */
    private $data = [];

    /**
     * @phpcsSuppress SlevomatCodingStandard.TypeHints.ReturnTypeHint.MissingAnyTypeHint
     * @phpcsSuppress SlevomatCodingStandard.TypeHints.ParameterTypeHint.MissingAnyTypeHint
     */
    protected function internalGetItem(&$normalizedKey, &$success = null, &$casToken = null)
    {
        $ns = $this->options->getNamespace();
        $success = isset($this->data[$ns][$normalizedKey]) && $this->options->getReadable();

        if (! $success) {
            return null;
        }

        return $casToken = $this->data[$ns][$normalizedKey];
    }

    /**
     * @phpcsSuppress SlevomatCodingStandard.TypeHints.ReturnTypeHint.MissingAnyTypeHint
     * @phpcsSuppress SlevomatCodingStandard.TypeHints.ParameterTypeHint.MissingAnyTypeHint
     */
    protected function internalSetItem(&$normalizedKey, &$value)
    {
        $ns = $this->options->getNamespace();
        $this->data[$ns][$normalizedKey] = $value;

        return true;
    }

    /**
     * @phpcsSuppress SlevomatCodingStandard.TypeHints.ReturnTypeHint.MissingAnyTypeHint
     * @phpcsSuppress SlevomatCodingStandard.TypeHints.ParameterTypeHint.MissingAnyTypeHint
     */
    protected function internalRemoveItem(&$normalizedKey)
    {
        $ns = $this->options->getNamespace();
        if (! isset($this->data[$ns][$normalizedKey])) {
            return false;
        }

        unset($this->data[$ns][$normalizedKey]);

        return true;
    }
}
