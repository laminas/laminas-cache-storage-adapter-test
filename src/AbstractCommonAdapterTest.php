<?php

declare(strict_types=1);

namespace LaminasTest\Cache\Storage\Adapter;

use Laminas\Cache\Exception\InvalidArgumentException;
use Laminas\Cache\Storage\Adapter\AdapterOptions;
use Laminas\Cache\Storage\AvailableSpaceCapableInterface;
use Laminas\Cache\Storage\Capabilities;
use Laminas\Cache\Storage\ClearByNamespaceInterface;
use Laminas\Cache\Storage\ClearByPrefixInterface;
use Laminas\Cache\Storage\ClearExpiredInterface;
use Laminas\Cache\Storage\FlushableInterface;
use Laminas\Cache\Storage\IterableInterface;
use Laminas\Cache\Storage\IteratorInterface;
use Laminas\Cache\Storage\OptimizableInterface;
use Laminas\Cache\Storage\StorageInterface;
use Laminas\Cache\Storage\TaggableInterface;
use Laminas\Cache\Storage\TotalSpaceCapableInterface;
use Laminas\Stdlib\ErrorHandler;
use PHPUnit\Framework\TestCase;
use stdClass;

use function array_keys;
use function array_merge;
use function assert;
use function bin2hex;
use function count;
use function fopen;
use function is_string;
use function iterator_to_array;
use function ksort;
use function method_exists;
use function microtime;
use function random_bytes;
use function sort;
use function str_replace;
use function time;
use function ucwords;
use function usleep;

/**
 * @template TStorage of StorageInterface
 * @template TOptions of AdapterOptions
 */
abstract class AbstractCommonAdapterTest extends TestCase
{
    /**
     * @var StorageInterface
     * @psalm-var TStorage
     */
    protected $storage;

    /**
     * @var AdapterOptions
     * @psalm-var TOptions
     */
    protected $options;

    /**
     * All datatypes of PHP
     *
     * @var list<non-empty-string>
     */
    protected $phpDatatypes = [
        'NULL',
        'boolean',
        'integer',
        'double',
        'string',
        'array',
        'object',
        'resource',
    ];

    protected function setUp(): void
    {
        self::assertInstanceOf(
            StorageInterface::class,
            $this->storage,
            'Storage adapter instance is needed for tests'
        );
        self::assertInstanceOf(
            AdapterOptions::class,
            $this->options,
            'Options instance is needed for tests'
        );
    }

    protected function tearDown(): void
    {
        // be sure the error handler has been stopped
        if (ErrorHandler::started()) {
            ErrorHandler::stop();
            self::fail('ErrorHandler not stopped');
        }

        if ($this->storage instanceof FlushableInterface) {
            $this->storage->flush();
        }
    }

    public function testOptionNamesValid(): void
    {
        $options = $this->storage->getOptions()->toArray();

        foreach (array_keys($options) as $name) {
            self::assertIsString($name);
            self::assertMatchesRegularExpression(
                '/^[a-z]+[a-z0-9_]*[a-z0-9]+$/',
                $name,
                "Invalid option name '{$name}'"
            );
        }
    }

    public function testGettersAndSettersOfOptionsExists(): void
    {
        $options = $this->storage->getOptions();
        foreach (array_keys($options->toArray()) as $option) {
            self::assertIsString($option);
            if ($option === 'adapter') {
                // Skip this, as it's a "special" value
                continue;
            }

            $method = ucwords(str_replace('_', ' ', $option));
            $method = str_replace(' ', '', $method);

            self::assertTrue(
                method_exists($options, 'set' . $method),
                "Missing method 'set'{$method}"
            );

            self::assertTrue(
                method_exists($options, 'get' . $method),
                "Missing method 'get'{$method}"
            );
        }
    }

    public function testOptionsGetAndSetDefault(): void
    {
        $options = $this->storage->getOptions();
        $this->storage->setOptions($options);
        self::assertSame($options, $this->storage->getOptions());
    }

    public function testOptionsFluentInterface(): void
    {
        $options = $this->storage->getOptions();
        /** @psalm-suppress MixedAssignment */
        foreach ($options->toArray() as $option => $value) {
            self::assertIsString($option);
            $method = ucwords(str_replace('_', ' ', $option));
            $method = 'set' . str_replace(' ', '', $method);
            self::assertSame(
                $options,
                $options->{$method}($value),
                "Method '{$method}' doesn't implement the fluent interface"
            );
        }

        self::assertSame(
            $this->storage,
            $this->storage->setOptions($options),
            "Method 'setOptions' doesn't implement the fluent interface"
        );
    }

    public function testGetCapabilities(): void
    {
        $capabilities = $this->storage->getCapabilities();
        self::assertInstanceOf(Capabilities::class, $capabilities);
    }

    public function testDatatypesCapability(): void
    {
        $capabilities = $this->storage->getCapabilities();
        $datatypes    = $capabilities->getSupportedDatatypes();

        foreach ($datatypes as $sourceType => $targetType) {
            self::assertContains(
                $sourceType,
                $this->phpDatatypes,
                "Unknown source type '{$sourceType}'"
            );
            if (is_string($targetType)) {
                self::assertContains(
                    $targetType,
                    $this->phpDatatypes,
                    "Unknown target type '{$targetType}'"
                );
            } else {
                self::assertIsBool($targetType);
            }
        }
    }

    public function testSupportedMetadataCapability(): void
    {
        $capabilities = $this->storage->getCapabilities();
        $metadata     = $capabilities->getSupportedMetadata();
        if ($metadata === []) {
            self::markTestSkipped('Adapter does not support any metadata.');
        }

        foreach ($metadata as $property) {
            self::assertIsString($property);
        }
    }

    public function testTtlCapabilities(): void
    {
        $capabilities = $this->storage->getCapabilities();

        self::assertIsInt($capabilities->getMaxTtl());
        self::assertGreaterThanOrEqual(0, $capabilities->getMaxTtl());

        self::assertIsBool($capabilities->getStaticTtl());

        self::assertIsNumeric($capabilities->getTtlPrecision());
        self::assertGreaterThan(0, $capabilities->getTtlPrecision());

        self::assertIsInt($capabilities->getLockOnExpire());
    }

    public function testKeyCapabilities(): void
    {
        $capabilities = $this->storage->getCapabilities();

        self::assertIsInt($capabilities->getMaxKeyLength());
        self::assertGreaterThanOrEqual(-1, $capabilities->getMaxKeyLength());

        self::assertIsBool($capabilities->getNamespaceIsPrefix());

        self::assertIsString($capabilities->getNamespaceSeparator());
    }

    public function testHasItemReturnsTrueOnValidItem(): void
    {
        self::assertTrue($this->storage->setItem('key', 'value'));
        self::assertTrue($this->storage->hasItem('key'));
    }

    public function testHasItemReturnsFalseOnMissingItem(): void
    {
        self::assertFalse($this->storage->hasItem('key'));
    }

    public function testHasItemReturnsFalseOnExpiredItem(): void
    {
        $capabilities = $this->storage->getCapabilities();

        if ($capabilities->getMinTtl() === 0) {
            $this->markTestSkipped("Adapter doesn't support item expiration");
        }

        $ttl = $capabilities->getTtlPrecision();
        $this->options->setTtl($ttl);

        $this->waitForFullSecond();

        self::assertTrue($this->storage->setItem('key', 'value'));

        // wait until the item expired
        $wait = (int) ($ttl + $capabilities->getTtlPrecision() * 2000000);
        self::assertGreaterThanOrEqual(0, $wait);
        assert($wait >= 0);
        usleep($wait);

        if (! $capabilities->getUseRequestTime()) {
            self::assertFalse($this->storage->hasItem('key'));
        } else {
            self::assertTrue($this->storage->hasItem('key'));
        }
    }

    public function testHasItemNonReadable(): void
    {
        self::assertTrue($this->storage->setItem('key', 'value'));

        $this->options->setReadable(false);
        self::assertFalse($this->storage->hasItem('key'));
    }

    public function testHasItemsReturnsKeysOfFoundItems(): void
    {
        self::assertTrue($this->storage->setItem('key1', 'value1'));
        self::assertTrue($this->storage->setItem('key2', 'value2'));

        $result = $this->storage->hasItems(['missing', 'key1', 'key2']);
        sort($result);

        $exprectedResult = ['key1', 'key2'];
        self::assertEquals($exprectedResult, $result);
    }

    public function testHasItemsReturnsEmptyArrayIfNonReadable(): void
    {
        self::assertTrue($this->storage->setItem('key', 'value'));

        $this->options->setReadable(false);
        self::assertEquals([], $this->storage->hasItems(['key']));
    }

    public function testGetItemReturnsNullOnMissingItem(): void
    {
        self::assertNull($this->storage->getItem('unknwon'));
    }

    public function testGetItemSetsSuccessFlag(): void
    {
        $success = null;

        // $success = false on get missing item
        $this->storage->getItem('unknown', $success);
        self::assertFalse($success);

        // $success = true on get valid item
        $this->storage->setItem('test', 'test');
        $this->storage->getItem('test', $success);
        self::assertTrue($success);
    }

    public function testGetItemReturnsNullOnExpiredItem(): void
    {
        $capabilities = $this->storage->getCapabilities();

        if ($capabilities->getMinTtl() === 0) {
            $this->markTestSkipped("Adapter doesn't support item expiration");
        }

        if ($capabilities->getUseRequestTime()) {
            $this->markTestSkipped("Can't test get expired item if request time will be used");
        }

        $ttl = $capabilities->getTtlPrecision();
        $this->options->setTtl($ttl);

        $this->waitForFullSecond();

        $this->storage->setItem('key', 'value');

        // wait until expired
        $wait = (int) ($ttl + $capabilities->getTtlPrecision() * 2000000);
        self::assertGreaterThanOrEqual(0, $wait);
        assert($wait >= 0);
        usleep($wait);

        self::assertNull($this->storage->getItem('key'));
    }

    public function testGetItemReturnsNullIfNonReadable(): void
    {
        $this->options->setReadable(false);

        self::assertTrue($this->storage->setItem('key', 'value'));
        self::assertNull($this->storage->getItem('key'));
    }

    public function testGetItemsReturnsKeyValuePairsOfFoundItems(): void
    {
        self::assertTrue($this->storage->setItem('key1', 'value1'));
        self::assertTrue($this->storage->setItem('key2', 'value2'));

        $result = $this->storage->getItems(['missing', 'key1', 'key2']);
        ksort($result);

        $exprectedResult = [
            'key1' => 'value1',
            'key2' => 'value2',
        ];
        self::assertEquals($exprectedResult, $result);
    }

    public function testGetItemsReturnsEmptyArrayIfNonReadable(): void
    {
        $this->options->setReadable(false);

        self::assertTrue($this->storage->setItem('key', 'value'));
        self::assertEquals([], $this->storage->getItems(['key']));
    }

    public function testGetMetadata(): void
    {
        $capabilities       = $this->storage->getCapabilities();
        $supportedMetadatas = $capabilities->getSupportedMetadata();

        self::assertTrue($this->storage->setItem('key', 'value'));
        $metadata = $this->storage->getMetadata('key');

        self::assertIsArray($metadata);
        /** @psalm-suppress MixedAssignment */
        foreach ($supportedMetadatas as $supportedMetadata) {
            self::assertIsString($supportedMetadata);
            self::assertArrayHasKey($supportedMetadata, $metadata);
        }
    }

    public function testGetMetadataReturnsFalseOnMissingItem(): void
    {
        self::assertFalse($this->storage->getMetadata('unknown'));
    }

    public function testGetMetadataReturnsFalseIfNonReadable(): void
    {
        $this->options->setReadable(false);

        self::assertTrue($this->storage->setItem('key', 'value'));
        self::assertFalse($this->storage->getMetadata('key'));
    }

    public function testGetMetadatas(): void
    {
        $capabilities       = $this->storage->getCapabilities();
        $supportedMetadatas = $capabilities->getSupportedMetadata();

        $items = [
            'key1' => 'value1',
            'key2' => 'value2',
        ];
        self::assertSame([], $this->storage->setItems($items));

        $metadatas = $this->storage->getMetadatas(array_keys($items));
        self::assertSame(count($items), count($metadatas));
        foreach ($metadatas as $metadata) {
            self::assertIsArray($metadata);
            /** @psalm-suppress MixedAssignment */
            foreach ($supportedMetadatas as $supportedMetadata) {
                self::assertIsString($supportedMetadata);
                self::assertArrayHasKey($supportedMetadata, $metadata);
            }
        }
    }

    /**
     * @group 7031
     * @group 7032
     */
    public function testGetMetadatasWithEmptyNamespace(): void
    {
        $this->options->setNamespace('');
        $this->testGetMetadatas();
    }

    public function testGetMetadatasReturnsEmptyArrayIfNonReadable(): void
    {
        $this->options->setReadable(false);

        self::assertTrue($this->storage->setItem('key', 'value'));
        self::assertEquals([], $this->storage->getMetadatas(['key']));
    }

    public function testSetGetHasAndRemoveItemWithoutNamespace(): void
    {
        $this->storage->getOptions()->setNamespace('');

        self::assertTrue($this->storage->setItem('key', 'value'));
        self::assertEquals('value', $this->storage->getItem('key'));
        self::assertTrue($this->storage->hasItem('key'));

        self::assertTrue($this->storage->removeItem('key'));
        self::assertFalse($this->storage->hasItem('key'));
        self::assertNull($this->storage->getItem('key'));
    }

    public function testSetGetHasAndRemoveItemsWithoutNamespace(): void
    {
        $this->storage->getOptions()->setNamespace('');

        $items = [
            'key1' => 'value1',
            'key2' => 'value2',
            'key3' => 'value3',
        ];

        self::assertSame([], $this->storage->setItems($items));

        $rs = $this->storage->getItems(array_keys($items));

        foreach ($items as $key => $value) {
            self::assertArrayHasKey($key, $rs);
            self::assertEquals($value, $rs[$key]);
        }

        $rs = $this->storage->hasItems(array_keys($items));
        self::assertEquals(count($items), count($rs));
        foreach (array_keys($items) as $key) {
            self::assertContains($key, $rs);
        }

        self::assertSame(['missing'], $this->storage->removeItems(['missing', 'key1', 'key3']));
        unset($items['key1'], $items['key3']);

        $rs = $this->storage->getItems(array_keys($items));
        foreach ($items as $key => $value) {
            self::assertArrayHasKey($key, $rs);
            self::assertEquals($value, $rs[$key]);
        }

        $rs = $this->storage->hasItems(array_keys($items));
        self::assertEquals(count($items), count($rs));
        foreach (array_keys($items) as $key) {
            self::assertContains($key, $rs);
        }
    }

    public function testSetGetHasAndRemoveItemWithNamespace(): void
    {
        // write "key" to default namespace
        $this->options->setNamespace('defaultns1');
        self::assertTrue($this->storage->setItem('key', 'defaultns1'));

        // write "key" to an other default namespace
        $this->options->setNamespace('defaultns2');
        self::assertTrue($this->storage->setItem('key', 'defaultns2'));

        // test value of defaultns2
        self::assertTrue($this->storage->hasItem('key'));
        self::assertEquals('defaultns2', $this->storage->getItem('key'));

        // test value of defaultns1
        $this->options->setNamespace('defaultns1');
        self::assertTrue($this->storage->hasItem('key'));
        self::assertEquals('defaultns1', $this->storage->getItem('key'));

        // remove item of defaultns1
        $this->options->setNamespace('defaultns1');
        self::assertTrue($this->storage->removeItem('key'));
        self::assertFalse($this->storage->hasItem('key'));

        // remove item of defaultns2
        $this->options->setNamespace('defaultns2');
        self::assertTrue($this->storage->removeItem('key'));
        self::assertFalse($this->storage->hasItem('key'));
    }

    public function testSetGetHasAndRemoveItemsWithNamespace(): void
    {
        $items = [
            'key1' => 'value1',
            'key2' => 'value2',
            'key3' => 'value3',
        ];

        $this->options->setNamespace('defaultns1');
        self::assertSame([], $this->storage->setItems($items));

        $this->options->setNamespace('defaultns2');
        self::assertSame([], $this->storage->hasItems(array_keys($items)));

        $this->options->setNamespace('defaultns1');
        $rs = $this->storage->getItems(array_keys($items));
        foreach ($items as $key => $value) {
            self::assertArrayHasKey($key, $rs);
            self::assertEquals($value, $rs[$key]);
        }

        $rs = $this->storage->hasItems(array_keys($items));
        self::assertEquals(count($items), count($rs));
        foreach (array_keys($items) as $key) {
            self::assertContains($key, $rs);
        }

        // remove the first and the last item
        self::assertSame(['missing'], $this->storage->removeItems(['missing', 'key1', 'key3']));
        unset($items['key1'], $items['key3']);

        $rs = $this->storage->getItems(array_keys($items));
        foreach ($items as $key => $value) {
            self::assertArrayHasKey($key, $rs);
            self::assertEquals($value, $rs[$key]);
        }

        $rs = $this->storage->hasItems(array_keys($items));
        self::assertEquals(count($items), count($rs));
        foreach (array_keys($items) as $key) {
            self::assertContains($key, $rs);
        }
    }

    public function testSetAndGetExpiredItem(): void
    {
        $capabilities = $this->storage->getCapabilities();

        if ($capabilities->getMinTtl() === 0) {
            $this->markTestSkipped("Adapter doesn't support item expiration");
        }

        $ttl = $capabilities->getTtlPrecision();
        $this->options->setTtl($ttl);

        $this->waitForFullSecond();

        $this->storage->setItem('key', 'value');

        // wait until expired
        $wait = (int) ($ttl + $capabilities->getTtlPrecision() * 2000000);
        self::assertGreaterThanOrEqual(0, $wait);
        assert($wait >= 0);
        usleep($wait);

        if ($capabilities->getUseRequestTime()) {
            // Can't test much more if the request time will be used
            self::assertEquals('value', $this->storage->getItem('key'));
            return;
        }

        self::assertNull($this->storage->getItem('key'));

        if ($capabilities->getLockOnExpire()) {
            self::assertEquals('value', $this->storage->getItem('key'));
        } else {
            self::assertNull($this->storage->getItem('key'));
        }
    }

    public function testSetAndGetExpiredItems(): void
    {
        $capabilities = $this->storage->getCapabilities();

        if ($capabilities->getMinTtl() === 0) {
            $this->markTestSkipped("Adapter doesn't support item expiration");
        }

        // item definition
        $itemsHigh = [
            'keyHigh1' => 'valueHigh1',
            'keyHigh2' => 'valueHigh2',
            'keyHigh3' => 'valueHigh3',
        ];
        $itemsLow  = [
            'keyLow1' => 'valueLow1',
            'keyLow2' => 'valueLow2',
            'keyLow3' => 'valueLow3',
        ];
        $items     = $itemsHigh + $itemsLow;

        // set items with high TTL
        $this->options->setTtl(123456);
        self::assertSame([], $this->storage->setItems($itemsHigh));

        // set items with low TTL
        $ttl = $capabilities->getTtlPrecision();
        $this->options->setTtl($ttl);
        $this->waitForFullSecond();
        self::assertSame([], $this->storage->setItems($itemsLow));

        // wait until expired
        $wait = (int) ($ttl + $capabilities->getTtlPrecision() * 2000000);
        self::assertGreaterThanOrEqual(0, $wait);
        assert($wait >= 0);
        usleep($wait);

        $rs = $this->storage->getItems(array_keys($items));
        ksort($rs); // make comparable

        if (! $capabilities->getStaticTtl()) {
            // if item expiration will be done on read there is no difference
            // between the previos set items in TTL.
            // -> all items will be expired
            self::assertEquals([], $rs);

            // after disabling TTL all items will be available
            $this->options->setTtl(0);
            $rs = $this->storage->getItems(array_keys($items));
            ksort($rs); // make comparable
            self::assertEquals($items, $rs);
        } elseif ($capabilities->getUseRequestTime()) {
            // if the request time will be used as current time all items will
            // be available as expiration doesn't work within the same process
            self::assertEquals($items, $rs);
        } else {
            self::assertEquals($itemsHigh, $rs);

            // if 'lock-on-expire' is not supported the low items will be still missing
            // if 'lock-on-expire' is supported the low items could be retrieved
            $rs = $this->storage->getItems(array_keys($items));
            ksort($rs); // make comparable
            if (! $capabilities->getLockOnExpire()) {
                self::assertEquals($itemsHigh, $rs);
            } else {
                $itemsExpected = array_merge($itemsLow, $itemsHigh);
                /** @psalm-suppress RedundantFunctionCall Suppressing here to avoid issues between different PHP versions */
                ksort($itemsExpected); // make comparable
                self::assertEquals($itemsExpected, $rs);
            }
        }
    }

    public function testSetAndGetItemOfDifferentTypes(): void
    {
        $capabilities = $this->storage->getCapabilities();

        $object             = new stdClass();
        $object->one        = 'one';
        $object->two        = new stdClass();
        $object->two->three = 'three';

        $types = [
            'NULL'     => null,
            'boolean'  => true,
            'integer'  => 12345,
            'double'   => 123.45,
            'string'   => 'string', // already tested
            'array'    => ['one', 'tow' => 'two', 'three' => ['four' => 'four']],
            'object'   => $object,
            'resource' => fopen(__FILE__, 'r'),
        ];

        /**
         * @var string $sourceType
         * @var mixed $targetType
         */
        foreach ($capabilities->getSupportedDatatypes() as $sourceType => $targetType) {
            if ($targetType === false) {
                continue;
            }

            $value = $types[$sourceType];
            self::assertTrue($this->storage->setItem('key', $value), "Failed to set type '$sourceType'");

            if ($targetType === true) {
                self::assertSame($value, $this->storage->getItem('key'));
            } elseif (is_string($targetType)) {
//                $typeval = $targetType . 'val';
//                $typeval($value);
                self::assertEquals($value, $this->storage->getItem('key'));
            }
        }
    }

    public function testSetItemReturnsFalseIfNonWritable(): void
    {
        $this->options->setWritable(false);

        self::assertFalse($this->storage->setItem('key', 'value'));
        self::assertFalse($this->storage->hasItem('key'));
    }

    public function testAddNewItem(): void
    {
        self::assertTrue($this->storage->addItem('key', 'value'));
        self::assertTrue($this->storage->hasItem('key'));
    }

    public function testAddItemReturnsFalseIfItemAlreadyExists(): void
    {
        self::assertTrue($this->storage->setItem('key', 'value'));
        self::assertFalse($this->storage->addItem('key', 'newValue'));
    }

    public function testAddItemReturnsFalseIfNonWritable(): void
    {
        $this->options->setWritable(false);

        self::assertFalse($this->storage->addItem('key', 'value'));
        self::assertFalse($this->storage->hasItem('key'));
    }

    public function testAddItemsReturnsFailedKeys(): void
    {
        self::assertTrue($this->storage->setItem('key1', 'value1'));

        $failedKeys = $this->storage->addItems([
            'key1' => 'XYZ',
            'key2' => 'value2',
        ]);

        self::assertSame(['key1'], $failedKeys);
        self::assertSame('value1', $this->storage->getItem('key1'));
        self::assertTrue($this->storage->hasItem('key2'));
    }

    public function testAddItemSetsTTL(): void
    {
        $capabilities = $this->storage->getCapabilities();

        if ($capabilities->getMinTtl() === 0) {
            $this->markTestSkipped("Adapter doesn't support item expiration");
        }

        $ttl = $capabilities->getTtlPrecision();
        $this->options->setTtl($ttl);

        $this->waitForFullSecond();

        self::assertTrue($this->storage->addItem('key', 'value'));

        // wait until the item expired
        $wait = (int) ($ttl + $capabilities->getTtlPrecision() * 2000000);
        self::assertGreaterThanOrEqual(0, $wait);
        assert($wait >= 0);
        usleep($wait);

        if (! $capabilities->getUseRequestTime()) {
            self::assertFalse($this->storage->hasItem('key'));
        } else {
            self::assertTrue($this->storage->hasItem('key'));
        }
    }

    public function testReplaceExistingItem(): void
    {
        self::assertTrue($this->storage->setItem('key', 'value'));
        self::assertTrue($this->storage->replaceItem('key', 'anOtherValue'));
        self::assertEquals('anOtherValue', $this->storage->getItem('key'));
    }

    public function testReplaceItemReturnsFalseOnMissingItem(): void
    {
        self::assertFalse($this->storage->replaceItem('missingKey', 'value'));
    }

    public function testReplaceItemReturnsFalseIfNonWritable(): void
    {
        $this->storage->setItem('key', 'value');
        $this->options->setWritable(false);

        self::assertFalse($this->storage->replaceItem('key', 'newvalue'));
        self::assertEquals('value', $this->storage->getItem('key'));
    }

    public function testReplaceItemsReturnsFailedKeys(): void
    {
        self::assertTrue($this->storage->setItem('key1', 'value1'));

        $failedKeys = $this->storage->replaceItems([
            'key1' => 'XYZ',
            'key2' => 'value2',
        ]);

        self::assertSame(['key2'], $failedKeys);
        self::assertSame('XYZ', $this->storage->getItem('key1'));
        self::assertFalse($this->storage->hasItem('key2'));
    }

    public function testRemoveItemReturnsFalseOnMissingItem(): void
    {
        self::assertFalse($this->storage->removeItem('missing'));
    }

    public function testRemoveItemsReturnsMissingKeys(): void
    {
        $this->storage->setItem('key', 'value');
        self::assertSame(['missing'], $this->storage->removeItems(['key', 'missing']));
    }

    public function testCheckAndSetItem(): void
    {
        self::assertTrue($this->storage->setItem('key', 'value'));

        $success  = null;
        $casToken = null;
        self::assertEquals('value', $this->storage->getItem('key', $success, $casToken));
        self::assertNotNull($casToken);

        self::assertTrue($this->storage->checkAndSetItem($casToken, 'key', 'newValue'));
        self::assertFalse($this->storage->checkAndSetItem($casToken, 'key', 'failedValue'));
        self::assertEquals('newValue', $this->storage->getItem('key'));
    }

    public function testIncrementItem(): void
    {
        self::assertTrue($this->storage->setItem('counter', 10));
        self::assertEquals(15, $this->storage->incrementItem('counter', 5));
        self::assertEquals(15, $this->storage->getItem('counter'));
    }

    public function testIncrementItemInitialValue(): void
    {
        self::assertEquals(5, $this->storage->incrementItem('counter', 5));
        self::assertEquals(5, $this->storage->getItem('counter'));
    }

    public function testIncrementItemReturnsFalseIfNonWritable(): void
    {
        $this->storage->setItem('key', 10);
        $this->options->setWritable(false);

        self::assertFalse($this->storage->incrementItem('key', 5));
        self::assertEquals(10, $this->storage->getItem('key'));
    }

    /**
     * @link https://github.com/zendframework/zend-cache/issues/66
     */
    public function testSetAndIncrementItems(): void
    {
        $this->storage->setItems([
            'key1' => 10,
            'key2' => 11,
        ]);

        $result = $this->storage->incrementItems([
            'key1' => 10,
            'key2' => 20,
        ]);
        ksort($result);

        self::assertSame([
            'key1' => 20,
            'key2' => 31,
        ], $result);
    }

    public function testIncrementItemsReturnsKeyValuePairsOfWrittenItems(): void
    {
        self::assertTrue($this->storage->setItem('key1', 10));

        $result = $this->storage->incrementItems([
            'key1' => 10,
            'key2' => 10,
        ]);
        ksort($result);

        self::assertSame([
            'key1' => 20,
            'key2' => 10,
        ], $result);
    }

    public function testIncrementItemsReturnsEmptyArrayIfNonWritable(): void
    {
        $this->storage->setItem('key', 10);
        $this->options->setWritable(false);

        self::assertSame([], $this->storage->incrementItems(['key' => 5]));
        self::assertEquals(10, $this->storage->getItem('key'));
    }

    public function testDecrementItem(): void
    {
        self::assertTrue($this->storage->setItem('counter', 30));
        self::assertEquals(25, $this->storage->decrementItem('counter', 5));
        self::assertEquals(25, $this->storage->getItem('counter'));
    }

    public function testDecrementItemInitialValue(): void
    {
        self::assertEquals(-5, $this->storage->decrementItem('counter', 5));
        self::assertEquals(-5, $this->storage->getItem('counter'));
    }

    public function testDecrementItemReturnsFalseIfNonWritable(): void
    {
        $this->storage->setItem('key', 10);
        $this->options->setWritable(false);

        self::assertFalse($this->storage->decrementItem('key', 5));
        self::assertEquals(10, $this->storage->getItem('key'));
    }

    /**
     * @link https://github.com/zendframework/zend-cache/issues/66
     */
    public function testSetAndDecrementItems(): void
    {
        $this->storage->setItems([
            'key1' => 10,
            'key2' => 11,
        ]);

        $result = $this->storage->decrementItems([
            'key1' => 10,
            'key2' => 5,
        ]);
        ksort($result);

        self::assertSame([
            'key1' => 0,
            'key2' => 6,
        ], $result);
    }

    public function testDecrementItemsReturnsEmptyArrayIfNonWritable(): void
    {
        $this->storage->setItem('key', 10);
        $this->options->setWritable(false);

        self::assertSame([], $this->storage->decrementItems(['key' => 5]));
        self::assertEquals(10, $this->storage->getItem('key'));
    }

    public function testTouchItem(): void
    {
        $capabilities = $this->storage->getCapabilities();

        if ($capabilities->getMinTtl() === 0) {
            $this->markTestSkipped("Adapter doesn't support item expiration");
        }

        $this->options->setTtl(2 * $capabilities->getTtlPrecision());

        $this->waitForFullSecond();
        $waitInitial = (int) ($capabilities->getTtlPrecision() * 1000000);
        self::assertGreaterThanOrEqual(0, $waitInitial);
        assert($waitInitial >= 0);

        self::assertTrue($this->storage->setItem('key', 'value'));

        // sleep 1 times before expire to touch the item
        usleep($waitInitial);
        self::assertTrue($this->storage->touchItem('key'));

        usleep($waitInitial);
        self::assertTrue($this->storage->hasItem('key'));

        if (! $capabilities->getUseRequestTime()) {
            $waitExtended = (int) ($capabilities->getTtlPrecision() * 2000000);
            self::assertGreaterThanOrEqual(0, $waitExtended);
            assert($waitExtended >= 0);
            usleep($waitExtended);
            self::assertFalse($this->storage->hasItem('key'));
        }
    }

    public function testTouchItemReturnsFalseOnMissingItem(): void
    {
        self::assertFalse($this->storage->touchItem('missing'));
    }

    public function testTouchItemReturnsFalseIfNonWritable(): void
    {
        $this->options->setWritable(false);

        self::assertFalse($this->storage->touchItem('key'));
    }

    public function testTouchItemsReturnsGivenKeysIfNonWritable(): void
    {
        $this->options->setWritable(false);
        self::assertSame(['key'], $this->storage->touchItems(['key']));
    }

    public function testOptimize(): void
    {
        if (! $this->storage instanceof OptimizableInterface) {
            $this->markTestSkipped("Storage doesn't implement OptimizableInterface");
        }

        self::assertTrue($this->storage->optimize());
    }

    public function testIterator(): void
    {
        if (! $this->storage instanceof IterableInterface) {
            $this->markTestSkipped("Storage doesn't implement IterableInterface");
        }

        $items = [
            'key1' => 'value1',
            'key2' => 'value2',
        ];
        self::assertSame([], $this->storage->setItems($items));

        // check iterator aggregate
        $iterator = $this->storage->getIterator();
        self::assertInstanceOf(IteratorInterface::class, $iterator);
        self::assertSame(IteratorInterface::CURRENT_AS_KEY, $iterator->getMode());

        // check mode CURRENT_AS_KEY
        $iterator = $this->storage->getIterator();
        self::assertInstanceOf(IteratorInterface::class, $iterator);
        $iterator->setMode(IteratorInterface::CURRENT_AS_KEY);
        $keys = iterator_to_array($iterator, false);
        sort($keys);
        self::assertSame(array_keys($items), $keys);

        // check mode CURRENT_AS_VALUE
        $iterator = $this->storage->getIterator();
        self::assertInstanceOf(IteratorInterface::class, $iterator);
        $iterator->setMode(IteratorInterface::CURRENT_AS_VALUE);
        $result = iterator_to_array($iterator, true);
        ksort($result);
        self::assertSame($items, $result);
    }

    public function testFlush(): void
    {
        if (! $this->storage instanceof FlushableInterface) {
            $this->markTestSkipped("Storage doesn't implement OptimizableInterface");
        }

        self::assertSame([], $this->storage->setItems([
            'key1' => 'value1',
            'key2' => 'value2',
        ]));

        self::assertTrue($this->storage->flush());
        self::assertFalse($this->storage->hasItem('key1'));
        self::assertFalse($this->storage->hasItem('key2'));
    }

    public function testClearByPrefix(): void
    {
        if (! $this->storage instanceof ClearByPrefixInterface) {
            $this->markTestSkipped("Storage doesn't implement ClearByPrefixInterface");
        }

        self::assertSame([], $this->storage->setItems([
            'key1' => 'value1',
            'key2' => 'value2',
            'test' => 'value',
        ]));

        self::assertTrue($this->storage->clearByPrefix('key'));
        self::assertFalse($this->storage->hasItem('key1'));
        self::assertFalse($this->storage->hasItem('key2'));
        self::assertTrue($this->storage->hasItem('test'));
    }

    public function testClearByPrefixThrowsInvalidArgumentExceptionOnEmptyPrefix(): void
    {
        if (! $this->storage instanceof ClearByPrefixInterface) {
            $this->markTestSkipped("Storage doesn't implement ClearByPrefixInterface");
        }

        $this->expectException(InvalidArgumentException::class);
        $this->storage->clearByPrefix('');
    }

    public function testClearByNamespace(): void
    {
        if (! $this->storage instanceof ClearByNamespaceInterface) {
            $this->markTestSkipped("Storage doesn't implement ClearByNamespaceInterface");
        }

        // write 2 items of 2 different namespaces
        $this->options->setNamespace('ns1');
        self::assertTrue($this->storage->setItem('key1', 'value1'));
        $this->options->setNamespace('ns2');
        self::assertTrue($this->storage->setItem('key2', 'value2'));

        // clear unknown namespace should return true but clear nothing
        self::assertTrue($this->storage->clearByNamespace('unknown'));
        $this->options->setNamespace('ns1');
        self::assertTrue($this->storage->hasItem('key1'));
        $this->options->setNamespace('ns2');
        self::assertTrue($this->storage->hasItem('key2'));

        // clear "ns1"
        self::assertTrue($this->storage->clearByNamespace('ns1'));
        $this->options->setNamespace('ns1');
        self::assertFalse($this->storage->hasItem('key1'));
        $this->options->setNamespace('ns2');
        self::assertTrue($this->storage->hasItem('key2'));

        // clear "ns2"
        self::assertTrue($this->storage->clearByNamespace('ns2'));
        $this->options->setNamespace('ns1');
        self::assertFalse($this->storage->hasItem('key1'));
        $this->options->setNamespace('ns2');
        self::assertFalse($this->storage->hasItem('key2'));
    }

    public function testClearByNamespaceThrowsInvalidArgumentExceptionOnEmptyNamespace(): void
    {
        if (! $this->storage instanceof ClearByNamespaceInterface) {
            $this->markTestSkipped("Storage doesn't implement ClearByNamespaceInterface");
        }

        $this->expectException(InvalidArgumentException::class);
        $this->storage->clearByNamespace('');
    }

    public function testClearExpired(): void
    {
        if (! $this->storage instanceof ClearExpiredInterface) {
            $this->markTestSkipped("Storage doesn't implement ClearExpiredInterface");
        }

        $capabilities = $this->storage->getCapabilities();
        $ttl          = $capabilities->getTtlPrecision();
        $this->options->setTtl($ttl);

        $this->waitForFullSecond();

        self::assertTrue($this->storage->setItem('key1', 'value1'));

        // wait until the first item expired
        $wait = (int) ($ttl + $capabilities->getTtlPrecision() * 2000);
        self::assertGreaterThanOrEqual(0, $wait);
        assert($wait >= 0);
        usleep($wait);

        self::assertTrue($this->storage->setItem('key2', 'value2'));

        self::assertTrue($this->storage->clearExpired());

        if ($capabilities->getUseRequestTime()) {
            self::assertTrue($this->storage->hasItem('key1'));
        } else {
            self::assertFalse($this->storage->hasItem('key1'));
        }

        self::assertTrue($this->storage->hasItem('key2'));
    }

    public function testTaggable(): void
    {
        if (! $this->storage instanceof TaggableInterface) {
            $this->markTestSkipped("Storage doesn't implement TaggableInterface");
        }

        // store 3 items and register the current default namespace
        self::assertSame([], $this->storage->setItems([
            'key1' => 'value1',
            'key2' => 'value2',
            'key3' => 'value3',
        ]));

        self::assertTrue($this->storage->setTags('key1', ['tag1a', 'tag1b']));
        self::assertTrue($this->storage->setTags('key2', ['tag2a', 'tag2b']));
        self::assertTrue($this->storage->setTags('key3', ['tag3a', 'tag3b']));
        self::assertFalse($this->storage->setTags('missing', ['tag']));

        // return tags
        $tags = $this->storage->getTags('key1');
        self::assertIsArray($tags);
        sort($tags);
        self::assertSame(['tag1a', 'tag1b'], $tags);

        // this should remove nothing
        self::assertTrue($this->storage->clearByTags(['tag1a', 'tag2a']));
        self::assertTrue($this->storage->hasItem('key1'));
        self::assertTrue($this->storage->hasItem('key2'));
        self::assertTrue($this->storage->hasItem('key3'));

        // this should remove key1 and key2
        self::assertTrue($this->storage->clearByTags(['tag1a', 'tag2b'], true));
        self::assertFalse($this->storage->hasItem('key1'));
        self::assertFalse($this->storage->hasItem('key2'));
        self::assertTrue($this->storage->hasItem('key3'));

        // this should remove key3
        self::assertTrue($this->storage->clearByTags(['tag3a', 'tag3b'], true));
        self::assertFalse($this->storage->hasItem('key1'));
        self::assertFalse($this->storage->hasItem('key2'));
        self::assertFalse($this->storage->hasItem('key3'));
    }

    /**
     * @group 6878
     */
    public function testTaggableFunctionsOnEmptyStorage(): void
    {
        if (! $this->storage instanceof TaggableInterface) {
            $this->markTestSkipped("Storage doesn't implement TaggableInterface");
        }

        self::assertFalse($this->storage->setTags('unknown', ['no']));
        self::assertFalse($this->storage->getTags('unknown'));
        self::assertTrue($this->storage->clearByTags(['unknown']));
    }

    public function testGetTotalSpace(): void
    {
        if (! $this->storage instanceof TotalSpaceCapableInterface) {
            $this->markTestSkipped("Storage doesn't implement TotalSpaceCapableInterface");
        }

        $totalSpace = $this->storage->getTotalSpace();
        self::assertGreaterThanOrEqual(0, $totalSpace);

        if ($this->storage instanceof AvailableSpaceCapableInterface) {
            $availableSpace = $this->storage->getAvailableSpace();
            self::assertGreaterThanOrEqual($availableSpace, $totalSpace);
        }
    }

    public function testGetAvailableSpace(): void
    {
        if (! $this->storage instanceof AvailableSpaceCapableInterface) {
            $this->markTestSkipped("Storage doesn't implement AvailableSpaceCapableInterface");
        }

        $availableSpace = $this->storage->getAvailableSpace();
        self::assertGreaterThanOrEqual(0, $availableSpace);

        if ($this->storage instanceof TotalSpaceCapableInterface) {
            $totalSpace = $this->storage->getTotalSpace();
            self::assertLessThanOrEqual($totalSpace, $availableSpace);
        }
    }

    /**
     * This will wait for a full second started
     * to reduce test failures on high load servers
     *
     * @see https://github.com/zendframework/zf2/issues/5144
     */
    protected function waitForFullSecond(): void
    {
        $interval = (int) (microtime(true) - time()) * 1000000;
        assert($interval >= 0);
        usleep($interval);
    }

    public function testCanStoreValuesWithCacheKeysUpToTheMaximumKeyLengthLimit(): void
    {
        $capabilities     = $this->storage->getCapabilities();
        $maximumKeyLength = $capabilities->getMaxKeyLength();
        if ($maximumKeyLength > 1024) {
            self::markTestSkipped('Maximum cache key length is bigger than 1M.');
        } elseif ($maximumKeyLength === Capabilities::UNKNOWN_KEY_LENGTH) {
            self::fail('Capabilities do not provide key length.');
        } elseif ($maximumKeyLength === Capabilities::UNLIMITED_KEY_LENGTH) {
            self::markTestSkipped('Maximum cache key length is unlimited.');
        } elseif ($maximumKeyLength === 1) {
            self::markTestSkipped('The maximum key length of the storage adapter is 1.');
        }

        $length = (int) ($maximumKeyLength / 2);
        assert($length > 0);
        $key   = bin2hex(random_bytes($length));
        $value = 'whatever';
        self::assertTrue($this->storage->setItem($key, $value));
        self::assertSame($value, $this->storage->getItem($key));
    }
}
