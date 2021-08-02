<?php

declare(strict_types=1);

namespace LaminasTest\Cache\Storage\Adapter;

use JsonException;
use ReflectionClass;

use function class_exists;
use function file_get_contents;
use function is_readable;
use function json_decode;
use function sprintf;

use const JSON_THROW_ON_ERROR;

/**
 * @see                   TestCase
 *
 * @psalm-require-extends TestCase
 */
trait LaminasComponentInstallerIntegrationTestTrait
{
    /**
     * Returns the config provider class-name of the cache component.
     *
     * @psalm-return class-string
     */
    abstract protected function getConfigProviderClassName(): string;

    /**
     * @psalm-return non-empty-string
     */
    abstract protected function getComposerJsonPath(): string;

    /**
     * @psalm-return array{config-provider:non-empty-string,module:non-empty-string}
     */
    private function parseComposerJsonExtraForLaminasComponentInstallerInformations(): array
    {
        $composerJsonPath = $this->getComposerJsonPath();
        if (! is_readable($composerJsonPath)) {
            self::fail(sprintf('`composer.json` located at "%s" is not readable.', $composerJsonPath));
        }

        try {
            $composerJson = json_decode(file_get_contents($composerJsonPath), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            self::fail(sprintf(
                '`composer.json` located at "%s" is invalid: %s',
                $composerJsonPath,
                $exception->getMessage()
            ));
        }

        self::assertIsArray($composerJson);
        $composerJsonExtra = $composerJson['extra'] ?? [];
        self::assertIsArray($composerJsonExtra);
        $composerJsonExtraLaminas = $composerJsonExtra['laminas'] ?? [];
        self::assertIsArray($composerJsonExtraLaminas);

        $configProviderClassName = $composerJsonExtraLaminas['config-provider'] ?? null;
        $namespaceOfModule       = $composerJsonExtraLaminas['module'] ?? null;

        $errorTemplate = 'The `composer.json` is missing "extra.laminas.%s" information.';

        self::assertIsString($configProviderClassName, sprintf($errorTemplate, 'config-provider'));
        self::assertNotEmpty($configProviderClassName, sprintf($errorTemplate, 'config-provider'));
        self::assertIsString($namespaceOfModule, sprintf($errorTemplate, 'module'));
        self::assertNotEmpty($namespaceOfModule, sprintf($errorTemplate, 'module'));

        return ['config-provider' => $configProviderClassName, 'module' => $namespaceOfModule];
    }

    public function testWillProvideConfigProviderInformationsToComponentInstaller(): void
    {
        $componentInstallerInformations = $this->parseComposerJsonExtraForLaminasComponentInstallerInformations();
        $configProviderClassName        = $this->getConfigProviderClassName();
        self::assertSame($configProviderClassName, $componentInstallerInformations['config-provider']);
    }

    public function testWillProvideModuleInformationsToComponentInstaller(): void
    {
        $componentInstallerInformations = $this->parseComposerJsonExtraForLaminasComponentInstallerInformations();

        $configProviderClassName = $this->getConfigProviderClassName();
        $reflectionClass         = new ReflectionClass($configProviderClassName);
        $namespaceOfModule       = $reflectionClass->getNamespaceName();
        self::assertSame($namespaceOfModule, $componentInstallerInformations['module']);
        $moduleClassName = sprintf('%s\\Module', $componentInstallerInformations['module']);
        self::assertTrue(
            class_exists($moduleClassName),
            sprintf(
                'Module class "%s" could not be found. Did you miss to create it?',
                $moduleClassName
            )
        );
    }
}
