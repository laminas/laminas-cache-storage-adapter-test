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
use function count;
use function fopen;
use function is_string;
use function iterator_to_array;
use function ksort;
use function method_exists;
use function microtime;
use function sort;
use function str_replace;
use function time;
use function ucwords;
use function usleep;

/**
 * @group      Laminas_Cache
 */
abstract class AbstractCommonAdapterTest extends TestCase
{
    /** @var StorageInterface */
    protected $storage;

    /** @var AdapterOptions */
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
        $this->assertInstanceOf(
            StorageInterface::class,
            $this->storage,
            'Storage adapter instance is needed for tests'
        );
        $this->assertInstanceOf(
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
    }

    public function testOptionNamesValid(): void
    {
        $options = $this->storage->getOptions()->toArray();

        foreach (array_keys($options) as $name) {
            $this->assertMatchesRegularExpression(
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
            if ($option === 'adapter') {
                // Skip this, as it's a "special" value
                continue;
            }
            $method = ucwords(str_replace('_', ' ', $option));
            $method = str_replace(' ', '', $method);

            $this->assertTrue(
                method_exists($options, 'set' . $method),
                "Missing method 'set'{$method}"
            );

            $this->assertTrue(
                method_exists($options, 'get' . $method),
                "Missing method 'get'{$method}"
            );
        }
    }

    public function testOptionsGetAndSetDefault(): void
    {
        $options = $this->storage->getOptions();
        $this->storage->setOptions($options);
        $this->assertSame($options, $this->storage->getOptions());
    }

    public function testOptionsFluentInterface(): void
    {
        $options = $this->storage->getOptions();
        /** @psalm-suppress MixedAssignment */
        foreach ($options->toArray() as $option => $value) {
            $method = ucwords(str_replace('_', ' ', $option));
            $method = 'set' . str_replace(' ', '', $method);
            $this->assertSame(
                $options,
                $options->{$method}($value),
                "Method '{$method}' doesn't implement the fluent interface"
            );
        }

        $this->assertSame(
            $this->storage,
            $this->storage->setOptions($options),
            "Method 'setOptions' doesn't implement the fluent interface"
        );
    }

    public function testGetCapabilities(): void
    {
        $capabilities = $this->storage->getCapabilities();
        $this->assertInstanceOf(Capabilities::class, $capabilities);
    }

    public function testDatatypesCapability(): void
    {
        $capabilities = $this->storage->getCapabilities();
        $datatypes    = $capabilities->getSupportedDatatypes();
        $this->assertIsArray($datatypes);

        foreach ($datatypes as $sourceType => $targetType) {
            $this->assertContains(
                $sourceType,
                $this->phpDatatypes,
                "Unknown source type '{$sourceType}'"
            );
            if (is_string($targetType)) {
                $this->assertContains(
                    $targetType,
                    $this->phpDatatypes,
                    "Unknown target type '{$targetType}'"
                );
            } else {
                $this->assertIsBool($targetType);
            }
        }
    }

    public function testSupportedMetadataCapability(): void
    {
        $capabilities = $this->storage->getCapabilities();
        $metadata     = $capabilities->getSupportedMetadata();
        $this->assertIsArray($metadata);

        foreach ($metadata as $property) {
            $this->assertIsString($property);
        }
    }

    public function testTtlCapabilities(): void
    {
        $capabilities = $this->storage->getCapabilities();

        $this->assertIsInt($capabilities->getMaxTtl());
        $this->assertGreaterThanOrEqual(0, $capabilities->getMaxTtl());

        $this->assertIsBool($capabilities->getStaticTtl());

        $this->assertIsNumeric($capabilities->getTtlPrecision());
        $this->assertGreaterThan(0, $capabilities->getTtlPrecision());

        $this->assertIsInt($capabilities->getLockOnExpire());
    }

    public function testKeyCapabilities(): void
    {
        $capabilities = $this->storage->getCapabilities();

        $this->assertIsInt($capabilities->getMaxKeyLength());
        $this->assertGreaterThanOrEqual(-1, $capabilities->getMaxKeyLength());

        $this->assertIsBool($capabilities->getNamespaceIsPrefix());

        $this->assertIsString($capabilities->getNamespaceSeparator());
    }

    public function testHasItemReturnsTrueOnValidItem(): void
    {
        $this->assertTrue($this->storage->setItem('key', 'value'));
        $this->assertTrue($this->storage->hasItem('key'));
    }

    public function testHasItemReturnsFalseOnMissingItem(): void
    {
        $this->assertFalse($this->storage->hasItem('key'));
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

        $this->assertTrue($this->storage->setItem('key', 'value'));

        // wait until the item expired
        $wait = $ttl + $capabilities->getTtlPrecision();
        usleep((int) $wait * 2000000);

        if (! $capabilities->getUseRequestTime()) {
            $this->assertFalse($this->storage->hasItem('key'));
        } else {
            $this->assertTrue($this->storage->hasItem('key'));
        }
    }

    public function testHasItemNonReadable(): void
    {
        $this->assertTrue($this->storage->setItem('key', 'value'));

        $this->options->setReadable(false);
        $this->assertFalse($this->storage->hasItem('key'));
    }

    public function testHasItemsReturnsKeysOfFoundItems(): void
    {
        $this->assertTrue($this->storage->setItem('key1', 'value1'));
        $this->assertTrue($this->storage->setItem('key2', 'value2'));

        $result = $this->storage->hasItems(['missing', 'key1', 'key2']);
        sort($result);

        $exprectedResult = ['key1', 'key2'];
        $this->assertEquals($exprectedResult, $result);
    }

    public function testHasItemsReturnsEmptyArrayIfNonReadable(): void
    {
        $this->assertTrue($this->storage->setItem('key', 'value'));

        $this->options->setReadable(false);
        $this->assertEquals([], $this->storage->hasItems(['key']));
    }

    public function testGetItemReturnsNullOnMissingItem(): void
    {
        $this->assertNull($this->storage->getItem('unknwon'));
    }

    public function testGetItemSetsSuccessFlag(): void
    {
        $success = null;

        // $success = false on get missing item
        $this->storage->getItem('unknown', $success);
        $this->assertFalse($success);

        // $success = true on get valid item
        $this->storage->setItem('test', 'test');
        $this->storage->getItem('test', $success);
        $this->assertTrue($success);
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
        $wait = $ttl + $capabilities->getTtlPrecision();
        usleep((int) $wait * 2000000);

        $this->assertNull($this->storage->getItem('key'));
    }

    public function testGetItemReturnsNullIfNonReadable(): void
    {
        $this->options->setReadable(false);

        $this->assertTrue($this->storage->setItem('key', 'value'));
        $this->assertNull($this->storage->getItem('key'));
    }

    public function testGetItemsReturnsKeyValuePairsOfFoundItems(): void
    {
        $this->assertTrue($this->storage->setItem('key1', 'value1'));
        $this->assertTrue($this->storage->setItem('key2', 'value2'));

        $result = $this->storage->getItems(['missing', 'key1', 'key2']);
        ksort($result);

        $exprectedResult = [
            'key1' => 'value1',
            'key2' => 'value2',
        ];
        $this->assertEquals($exprectedResult, $result);
    }

    public function testGetItemsReturnsEmptyArrayIfNonReadable(): void
    {
        $this->options->setReadable(false);

        $this->assertTrue($this->storage->setItem('key', 'value'));
        $this->assertEquals([], $this->storage->getItems(['key']));
    }

    public function testGetMetadata(): void
    {
        $capabilities       = $this->storage->getCapabilities();
        $supportedMetadatas = $capabilities->getSupportedMetadata();

        $this->assertTrue($this->storage->setItem('key', 'value'));
        $metadata = $this->storage->getMetadata('key');

        $this->assertIsArray($metadata);
        foreach ($supportedMetadatas as $supportedMetadata) {
            $this->assertArrayHasKey($supportedMetadata, $metadata);
        }
    }

    public function testGetMetadataReturnsFalseOnMissingItem(): void
    {
        $this->assertFalse($this->storage->getMetadata('unknown'));
    }

    public function testGetMetadataReturnsFalseIfNonReadable(): void
    {
        $this->options->setReadable(false);

        $this->assertTrue($this->storage->setItem('key', 'value'));
        $this->assertFalse($this->storage->getMetadata('key'));
    }

    public function testGetMetadatas(): void
    {
        $capabilities       = $this->storage->getCapabilities();
        $supportedMetadatas = $capabilities->getSupportedMetadata();

        $items = [
            'key1' => 'value1',
            'key2' => 'value2',
        ];
        $this->assertSame([], $this->storage->setItems($items));

        $metadatas = $this->storage->getMetadatas(array_keys($items));
        $this->assertIsArray($metadatas);
        $this->assertSame(count($items), count($metadatas));
        foreach ($metadatas as $k => $metadata) {
            $this->assertIsArray($metadata);
            foreach ($supportedMetadatas as $supportedMetadata) {
                $this->assertArrayHasKey($supportedMetadata, $metadata);
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

        $this->assertTrue($this->storage->setItem('key', 'value'));
        $this->assertEquals([], $this->storage->getMetadatas(['key']));
    }

    public function testSetGetHasAndRemoveItemWithoutNamespace(): void
    {
        $this->storage->getOptions()->setNamespace('');

        $this->assertTrue($this->storage->setItem('key', 'value'));
        $this->assertEquals('value', $this->storage->getItem('key'));
        $this->assertTrue($this->storage->hasItem('key'));

        $this->assertTrue($this->storage->removeItem('key'));
        $this->assertFalse($this->storage->hasItem('key'));
        $this->assertNull($this->storage->getItem('key'));
    }

    public function testSetGetHasAndRemoveItemsWithoutNamespace(): void
    {
        $this->storage->getOptions()->setNamespace('');

        $items = [
            'key1' => 'value1',
            'key2' => 'value2',
            'key3' => 'value3',
        ];

        $this->assertSame([], $this->storage->setItems($items));

        $rs = $this->storage->getItems(array_keys($items));

        $this->assertIsArray($rs);
        foreach ($items as $key => $value) {
            $this->assertArrayHasKey($key, $rs);
            $this->assertEquals($value, $rs[$key]);
        }

        $rs = $this->storage->hasItems(array_keys($items));
        $this->assertIsArray($rs);
        $this->assertEquals(count($items), count($rs));
        foreach ($items as $key => $value) {
            $this->assertContains($key, $rs);
        }

        $this->assertSame(['missing'], $this->storage->removeItems(['missing', 'key1', 'key3']));
        unset($items['key1'], $items['key3']);

        $rs = $this->storage->getItems(array_keys($items));
        $this->assertIsArray($rs);
        foreach ($items as $key => $value) {
            $this->assertArrayHasKey($key, $rs);
            $this->assertEquals($value, $rs[$key]);
        }

        $rs = $this->storage->hasItems(array_keys($items));
        $this->assertIsArray($rs);
        $this->assertEquals(count($items), count($rs));
        foreach ($items as $key => $value) {
            $this->assertContains($key, $rs);
        }
    }

    public function testSetGetHasAndRemoveItemWithNamespace(): void
    {
        // write "key" to default namespace
        $this->options->setNamespace('defaultns1');
        $this->assertTrue($this->storage->setItem('key', 'defaultns1'));

        // write "key" to an other default namespace
        $this->options->setNamespace('defaultns2');
        $this->assertTrue($this->storage->setItem('key', 'defaultns2'));

        // test value of defaultns2
        $this->assertTrue($this->storage->hasItem('key'));
        $this->assertEquals('defaultns2', $this->storage->getItem('key'));

        // test value of defaultns1
        $this->options->setNamespace('defaultns1');
        $this->assertTrue($this->storage->hasItem('key'));
        $this->assertEquals('defaultns1', $this->storage->getItem('key'));

        // remove item of defaultns1
        $this->options->setNamespace('defaultns1');
        $this->assertTrue($this->storage->removeItem('key'));
        $this->assertFalse($this->storage->hasItem('key'));

        // remove item of defaultns2
        $this->options->setNamespace('defaultns2');
        $this->assertTrue($this->storage->removeItem('key'));
        $this->assertFalse($this->storage->hasItem('key'));
    }

    public function testSetGetHasAndRemoveItemsWithNamespace(): void
    {
        $items = [
            'key1' => 'value1',
            'key2' => 'value2',
            'key3' => 'value3',
        ];

        $this->options->setNamespace('defaultns1');
        $this->assertSame([], $this->storage->setItems($items));

        $this->options->setNamespace('defaultns2');
        $this->assertSame([], $this->storage->hasItems(array_keys($items)));

        $this->options->setNamespace('defaultns1');
        $rs = $this->storage->getItems(array_keys($items));
        $this->assertIsArray($rs);
        foreach ($items as $key => $value) {
            $this->assertArrayHasKey($key, $rs);
            $this->assertEquals($value, $rs[$key]);
        }

        $rs = $this->storage->hasItems(array_keys($items));
        $this->assertIsArray($rs);
        $this->assertEquals(count($items), count($rs));
        foreach ($items as $key => $value) {
            $this->assertContains($key, $rs);
        }

        // remove the first and the last item
        $this->assertSame(['missing'], $this->storage->removeItems(['missing', 'key1', 'key3']));
        unset($items['key1'], $items['key3']);

        $rs = $this->storage->getItems(array_keys($items));
        $this->assertIsArray($rs);
        foreach ($items as $key => $value) {
            $this->assertArrayHasKey($key, $rs);
            $this->assertEquals($value, $rs[$key]);
        }

        $rs = $this->storage->hasItems(array_keys($items));
        $this->assertIsArray($rs);
        $this->assertEquals(count($items), count($rs));
        foreach ($items as $key => $value) {
            $this->assertContains($key, $rs);
        }
    }

    /**
     * @return void
     */
    public function testSetAndGetExpiredItem()
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
        $wait = $ttl + $capabilities->getTtlPrecision();
        usleep((int) $wait * 2000000);

        if ($capabilities->getUseRequestTime()) {
            // Can't test much more if the request time will be used
            $this->assertEquals('value', $this->storage->getItem('key'));
            return;
        }

        $this->assertNull($this->storage->getItem('key'));

        if ($capabilities->getLockOnExpire()) {
            $this->assertEquals('value', $this->storage->getItem('key'));
        } else {
            $this->assertNull($this->storage->getItem('key'));
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
        $this->assertSame([], $this->storage->setItems($itemsHigh));

        // set items with low TTL
        $ttl = $capabilities->getTtlPrecision();
        $this->options->setTtl($ttl);
        $this->waitForFullSecond();
        $this->assertSame([], $this->storage->setItems($itemsLow));

        // wait until expired
        $wait = $ttl + $capabilities->getTtlPrecision();
        usleep((int) $wait * 2000000);

        $rs = $this->storage->getItems(array_keys($items));
        ksort($rs); // make comparable

        if (! $capabilities->getStaticTtl()) {
            // if item expiration will be done on read there is no difference
            // between the previos set items in TTL.
            // -> all items will be expired
            $this->assertEquals([], $rs);

            // after disabling TTL all items will be available
            $this->options->setTtl(0);
            $rs = $this->storage->getItems(array_keys($items));
            ksort($rs); // make comparable
            $this->assertEquals($items, $rs);
        } elseif ($capabilities->getUseRequestTime()) {
            // if the request time will be used as current time all items will
            // be available as expiration doesn't work within the same process
            $this->assertEquals($items, $rs);
        } else {
            $this->assertEquals($itemsHigh, $rs);

            // if 'lock-on-expire' is not supported the low items will be still missing
            // if 'lock-on-expire' is supported the low items could be retrieved
            $rs = $this->storage->getItems(array_keys($items));
            ksort($rs); // make comparable
            if (! $capabilities->getLockOnExpire()) {
                $this->assertEquals($itemsHigh, $rs);
            } else {
                $itemsExpected = array_merge($itemsLow, $itemsHigh);
                ksort($itemsExpected); // make comparable
                $this->assertEquals($itemsExpected, $rs);
            }
        }
    }

    public function testSetAndGetItemOfDifferentTypes(): void
    {
        $capabilities = $this->storage->getCapabilities();

        $types                       = [
            'NULL'     => null,
            'boolean'  => true,
            'integer'  => 12345,
            'double'   => 123.45,
            'string'   => 'string', // already tested
            'array'    => ['one', 'tow' => 'two', 'three' => ['four' => 'four']],
            'object'   => new stdClass(),
            'resource' => fopen(__FILE__, 'r'),
        ];
        $types['object']->one        = 'one';
        $types['object']->two        = new stdClass();
        $types['object']->two->three = 'three';

        /**
         * @var string $sourceType
         * @var mixed $targetType
         */
        foreach ($capabilities->getSupportedDatatypes() as $sourceType => $targetType) {
            if ($targetType === false) {
                continue;
            }

            $value = $types[$sourceType];
            $this->assertTrue($this->storage->setItem('key', $value), "Failed to set type '$sourceType'");

            if ($targetType === true) {
                $this->assertSame($value, $this->storage->getItem('key'));
            } elseif (is_string($targetType)) {
//                $typeval = $targetType . 'val';
//                $typeval($value);
                $this->assertEquals($value, $this->storage->getItem('key'));
            }
        }
    }

    public function testSetItemReturnsFalseIfNonWritable(): void
    {
        $this->options->setWritable(false);

        $this->assertFalse($this->storage->setItem('key', 'value'));
        $this->assertFalse($this->storage->hasItem('key'));
    }

    public function testAddNewItem(): void
    {
        $this->assertTrue($this->storage->addItem('key', 'value'));
        $this->assertTrue($this->storage->hasItem('key'));
    }

    public function testAddItemReturnsFalseIfItemAlreadyExists(): void
    {
        $this->assertTrue($this->storage->setItem('key', 'value'));
        $this->assertFalse($this->storage->addItem('key', 'newValue'));
    }

    public function testAddItemReturnsFalseIfNonWritable(): void
    {
        $this->options->setWritable(false);

        $this->assertFalse($this->storage->addItem('key', 'value'));
        $this->assertFalse($this->storage->hasItem('key'));
    }

    public function testAddItemsReturnsFailedKeys(): void
    {
        $this->assertTrue($this->storage->setItem('key1', 'value1'));

        $failedKeys = $this->storage->addItems([
            'key1' => 'XYZ',
            'key2' => 'value2',
        ]);

        $this->assertSame(['key1'], $failedKeys);
        $this->assertSame('value1', $this->storage->getItem('key1'));
        $this->assertTrue($this->storage->hasItem('key2'));
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

        $this->assertTrue($this->storage->addItem('key', 'value'));

        // wait until the item expired
        $wait = $ttl + $capabilities->getTtlPrecision();
        usleep((int) $wait * 2000000);

        if (! $capabilities->getUseRequestTime()) {
            $this->assertFalse($this->storage->hasItem('key'));
        } else {
            $this->assertTrue($this->storage->hasItem('key'));
        }
    }

    public function testReplaceExistingItem(): void
    {
        $this->assertTrue($this->storage->setItem('key', 'value'));
        $this->assertTrue($this->storage->replaceItem('key', 'anOtherValue'));
        $this->assertEquals('anOtherValue', $this->storage->getItem('key'));
    }

    public function testReplaceItemReturnsFalseOnMissingItem(): void
    {
        $this->assertFalse($this->storage->replaceItem('missingKey', 'value'));
    }

    public function testReplaceItemReturnsFalseIfNonWritable(): void
    {
        $this->storage->setItem('key', 'value');
        $this->options->setWritable(false);

        $this->assertFalse($this->storage->replaceItem('key', 'newvalue'));
        $this->assertEquals('value', $this->storage->getItem('key'));
    }

    public function testReplaceItemsReturnsFailedKeys(): void
    {
        $this->assertTrue($this->storage->setItem('key1', 'value1'));

        $failedKeys = $this->storage->replaceItems([
            'key1' => 'XYZ',
            'key2' => 'value2',
        ]);

        $this->assertSame(['key2'], $failedKeys);
        $this->assertSame('XYZ', $this->storage->getItem('key1'));
        $this->assertFalse($this->storage->hasItem('key2'));
    }

    public function testRemoveItemReturnsFalseOnMissingItem(): void
    {
        $this->assertFalse($this->storage->removeItem('missing'));
    }

    public function testRemoveItemsReturnsMissingKeys(): void
    {
        $this->storage->setItem('key', 'value');
        $this->assertSame(['missing'], $this->storage->removeItems(['key', 'missing']));
    }

    public function testCheckAndSetItem(): void
    {
        $this->assertTrue($this->storage->setItem('key', 'value'));

        $success  = null;
        $casToken = null;
        $this->assertEquals('value', $this->storage->getItem('key', $success, $casToken));
        $this->assertNotNull($casToken);

        $this->assertTrue($this->storage->checkAndSetItem($casToken, 'key', 'newValue'));
        $this->assertFalse($this->storage->checkAndSetItem($casToken, 'key', 'failedValue'));
        $this->assertEquals('newValue', $this->storage->getItem('key'));
    }

    public function testIncrementItem(): void
    {
        $this->assertTrue($this->storage->setItem('counter', 10));
        $this->assertEquals(15, $this->storage->incrementItem('counter', 5));
        $this->assertEquals(15, $this->storage->getItem('counter'));
    }

    public function testIncrementItemInitialValue(): void
    {
        $this->assertEquals(5, $this->storage->incrementItem('counter', 5));
        $this->assertEquals(5, $this->storage->getItem('counter'));
    }

    public function testIncrementItemReturnsFalseIfNonWritable(): void
    {
        $this->storage->setItem('key', 10);
        $this->options->setWritable(false);

        $this->assertFalse($this->storage->incrementItem('key', 5));
        $this->assertEquals(10, $this->storage->getItem('key'));
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

        $this->assertSame([
            'key1' => 20,
            'key2' => 31,
        ], $result);
    }

    public function testIncrementItemsResturnsKeyValuePairsOfWrittenItems(): void
    {
        $this->assertTrue($this->storage->setItem('key1', 10));

        $result = $this->storage->incrementItems([
            'key1' => 10,
            'key2' => 10,
        ]);
        ksort($result);

        $this->assertSame([
            'key1' => 20,
            'key2' => 10,
        ], $result);
    }

    public function testIncrementItemsReturnsEmptyArrayIfNonWritable(): void
    {
        $this->storage->setItem('key', 10);
        $this->options->setWritable(false);

        $this->assertSame([], $this->storage->incrementItems(['key' => 5]));
        $this->assertEquals(10, $this->storage->getItem('key'));
    }

    public function testDecrementItem(): void
    {
        $this->assertTrue($this->storage->setItem('counter', 30));
        $this->assertEquals(25, $this->storage->decrementItem('counter', 5));
        $this->assertEquals(25, $this->storage->getItem('counter'));
    }

    public function testDecrementItemInitialValue(): void
    {
        $this->assertEquals(-5, $this->storage->decrementItem('counter', 5));
        $this->assertEquals(-5, $this->storage->getItem('counter'));
    }

    public function testDecrementItemReturnsFalseIfNonWritable(): void
    {
        $this->storage->setItem('key', 10);
        $this->options->setWritable(false);

        $this->assertFalse($this->storage->decrementItem('key', 5));
        $this->assertEquals(10, $this->storage->getItem('key'));
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

        $this->assertSame([
            'key1' => 0,
            'key2' => 6,
        ], $result);
    }

    public function testDecrementItemsReturnsEmptyArrayIfNonWritable(): void
    {
        $this->storage->setItem('key', 10);
        $this->options->setWritable(false);

        $this->assertSame([], $this->storage->decrementItems(['key' => 5]));
        $this->assertEquals(10, $this->storage->getItem('key'));
    }

    public function testTouchItem(): void
    {
        $capabilities = $this->storage->getCapabilities();

        if ($capabilities->getMinTtl() === 0) {
            $this->markTestSkipped("Adapter doesn't support item expiration");
        }

        $this->options->setTtl(2 * $capabilities->getTtlPrecision());

        $this->waitForFullSecond();

        $this->assertTrue($this->storage->setItem('key', 'value'));

        // sleep 1 times before expire to touch the item
        usleep((int) $capabilities->getTtlPrecision() * 1000000);
        $this->assertTrue($this->storage->touchItem('key'));

        usleep((int) $capabilities->getTtlPrecision() * 1000000);
        $this->assertTrue($this->storage->hasItem('key'));

        if (! $capabilities->getUseRequestTime()) {
            usleep((int) $capabilities->getTtlPrecision() * 2000000);
            $this->assertFalse($this->storage->hasItem('key'));
        }
    }

    public function testTouchItemReturnsFalseOnMissingItem(): void
    {
        $this->assertFalse($this->storage->touchItem('missing'));
    }

    public function testTouchItemReturnsFalseIfNonWritable(): void
    {
        $this->options->setWritable(false);

        $this->assertFalse($this->storage->touchItem('key'));
    }

    public function testTouchItemsReturnsGivenKeysIfNonWritable(): void
    {
        $this->options->setWritable(false);
        $this->assertSame(['key'], $this->storage->touchItems(['key']));
    }

    public function testOptimize(): void
    {
        if (! $this->storage instanceof OptimizableInterface) {
            $this->markTestSkipped("Storage doesn't implement OptimizableInterface");
        }

        $this->assertTrue($this->storage->optimize());
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
        $this->assertSame([], $this->storage->setItems($items));

        // check iterator aggregate
        $iterator = $this->storage->getIterator();
        $this->assertInstanceOf(IteratorInterface::class, $iterator);
        $this->assertSame(IteratorInterface::CURRENT_AS_KEY, $iterator->getMode());

        // check mode CURRENT_AS_KEY
        $iterator = $this->storage->getIterator();
        $iterator->setMode(IteratorInterface::CURRENT_AS_KEY);
        $keys = iterator_to_array($iterator, false);
        sort($keys);
        $this->assertSame(array_keys($items), $keys);

        // check mode CURRENT_AS_VALUE
        $iterator = $this->storage->getIterator();
        $iterator->setMode(IteratorInterface::CURRENT_AS_VALUE);
        $result = iterator_to_array($iterator, true);
        ksort($result);
        $this->assertSame($items, $result);
    }

    public function testFlush(): void
    {
        if (! $this->storage instanceof FlushableInterface) {
            $this->markTestSkipped("Storage doesn't implement OptimizableInterface");
        }

        $this->assertSame([], $this->storage->setItems([
            'key1' => 'value1',
            'key2' => 'value2',
        ]));

        $this->assertTrue($this->storage->flush());
        $this->assertFalse($this->storage->hasItem('key1'));
        $this->assertFalse($this->storage->hasItem('key2'));
    }

    public function testClearByPrefix(): void
    {
        if (! $this->storage instanceof ClearByPrefixInterface) {
            $this->markTestSkipped("Storage doesn't implement ClearByPrefixInterface");
        }

        $this->assertSame([], $this->storage->setItems([
            'key1' => 'value1',
            'key2' => 'value2',
            'test' => 'value',
        ]));

        $this->assertTrue($this->storage->clearByPrefix('key'));
        $this->assertFalse($this->storage->hasItem('key1'));
        $this->assertFalse($this->storage->hasItem('key2'));
        $this->assertTrue($this->storage->hasItem('test'));
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
        $this->assertTrue($this->storage->setItem('key1', 'value1'));
        $this->options->setNamespace('ns2');
        $this->assertTrue($this->storage->setItem('key2', 'value2'));

        // clear unknown namespace should return true but clear nothing
        $this->assertTrue($this->storage->clearByNamespace('unknown'));
        $this->options->setNamespace('ns1');
        $this->assertTrue($this->storage->hasItem('key1'));
        $this->options->setNamespace('ns2');
        $this->assertTrue($this->storage->hasItem('key2'));

        // clear "ns1"
        $this->assertTrue($this->storage->clearByNamespace('ns1'));
        $this->options->setNamespace('ns1');
        $this->assertFalse($this->storage->hasItem('key1'));
        $this->options->setNamespace('ns2');
        $this->assertTrue($this->storage->hasItem('key2'));

        // clear "ns2"
        $this->assertTrue($this->storage->clearByNamespace('ns2'));
        $this->options->setNamespace('ns1');
        $this->assertFalse($this->storage->hasItem('key1'));
        $this->options->setNamespace('ns2');
        $this->assertFalse($this->storage->hasItem('key2'));
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

        $this->assertTrue($this->storage->setItem('key1', 'value1'));

        // wait until the first item expired
        $wait = $ttl + $capabilities->getTtlPrecision();
        usleep((int) $wait * 2000000);

        $this->assertTrue($this->storage->setItem('key2', 'value2'));

        $this->assertTrue($this->storage->clearExpired());

        if ($capabilities->getUseRequestTime()) {
            $this->assertTrue($this->storage->hasItem('key1'));
        } else {
            $this->assertFalse($this->storage->hasItem('key1', ['ttl' => 0]));
        }

        $this->assertTrue($this->storage->hasItem('key2'));
    }

    public function testTaggable(): void
    {
        if (! $this->storage instanceof TaggableInterface) {
            $this->markTestSkipped("Storage doesn't implement TaggableInterface");
        }

        // store 3 items and register the current default namespace
        $this->assertSame([], $this->storage->setItems([
            'key1' => 'value1',
            'key2' => 'value2',
            'key3' => 'value3',
        ]));

        $this->assertTrue($this->storage->setTags('key1', ['tag1a', 'tag1b']));
        $this->assertTrue($this->storage->setTags('key2', ['tag2a', 'tag2b']));
        $this->assertTrue($this->storage->setTags('key3', ['tag3a', 'tag3b']));
        $this->assertFalse($this->storage->setTags('missing', ['tag']));

        // return tags
        $tags = $this->storage->getTags('key1');
        $this->assertIsArray($tags);
        sort($tags);
        $this->assertSame(['tag1a', 'tag1b'], $tags);

        // this should remove nothing
        $this->assertTrue($this->storage->clearByTags(['tag1a', 'tag2a']));
        $this->assertTrue($this->storage->hasItem('key1'));
        $this->assertTrue($this->storage->hasItem('key2'));
        $this->assertTrue($this->storage->hasItem('key3'));

        // this should remove key1 and key2
        $this->assertTrue($this->storage->clearByTags(['tag1a', 'tag2b'], true));
        $this->assertFalse($this->storage->hasItem('key1'));
        $this->assertFalse($this->storage->hasItem('key2'));
        $this->assertTrue($this->storage->hasItem('key3'));

        // this should remove key3
        $this->assertTrue($this->storage->clearByTags(['tag3a', 'tag3b'], true));
        $this->assertFalse($this->storage->hasItem('key1'));
        $this->assertFalse($this->storage->hasItem('key2'));
        $this->assertFalse($this->storage->hasItem('key3'));
    }

    /**
     * @group 6878
     */
    public function testTaggableFunctionsOnEmptyStorage(): void
    {
        if (! $this->storage instanceof TaggableInterface) {
            $this->markTestSkipped("Storage doesn't implement TaggableInterface");
        }

        $this->assertFalse($this->storage->setTags('unknown', ['no']));
        $this->assertFalse($this->storage->getTags('unknown'));
        $this->assertTrue($this->storage->clearByTags(['unknown']));
    }

    public function testGetTotalSpace(): void
    {
        if (! $this->storage instanceof TotalSpaceCapableInterface) {
            $this->markTestSkipped("Storage doesn't implement TotalSpaceCapableInterface");
        }

        $totalSpace = $this->storage->getTotalSpace();
        $this->assertGreaterThanOrEqual(0, $totalSpace);

        if ($this->storage instanceof AvailableSpaceCapableInterface) {
            $availableSpace = $this->storage->getAvailableSpace();
            $this->assertGreaterThanOrEqual($availableSpace, $totalSpace);
        }
    }

    public function testGetAvailableSpace(): void
    {
        if (! $this->storage instanceof AvailableSpaceCapableInterface) {
            $this->markTestSkipped("Storage doesn't implement AvailableSpaceCapableInterface");
        }

        $availableSpace = $this->storage->getAvailableSpace();
        $this->assertGreaterThanOrEqual(0, $availableSpace);

        if ($this->storage instanceof TotalSpaceCapableInterface) {
            $totalSpace = $this->storage->getTotalSpace();
            $this->assertLessThanOrEqual($totalSpace, $availableSpace);
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
        $interval = (microtime(true) - time()) * 1000000;
        usleep((int) $interval);
    }
}
