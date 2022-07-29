<?php

declare(strict_types=1);

namespace LaminasTest\Cache\Storage\Adapter;

use DateTimeImmutable;
use Laminas\Cache\Psr\CacheItemPool\CacheItemPoolDecorator;
use Laminas\Cache\Storage\FlushableInterface;
use Laminas\Cache\Storage\StorageInterface;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Cache\InvalidArgumentException;
use stdClass;
use Traversable;

use function chr;
use function count;
use function date_default_timezone_get;
use function date_default_timezone_set;
use function gc_collect_cycles;
use function get_class;
use function in_array;
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_object;
use function is_string;
use function iterator_to_array;
use function sleep;
use function str_repeat;
use function time;

abstract class AbstractCacheItemPoolIntegrationTest extends TestCase
{
    private ?string $tz = null;

    private ?StorageInterface $storage = null;

    /**
     * Map of test name and the reason why it is skipped.
     *
     * @var array<non-empty-string,non-empty-string>
     */
    protected array $skippedTests = [];

    protected ?CacheItemPoolInterface $cache;

    protected function setUp(): void
    {
        parent::setUp();
        // set non-UTC timezone
        $this->tz = date_default_timezone_get();
        date_default_timezone_set('America/Vancouver');
        $this->cache = $this->createCachePool();
    }

    protected function tearDown(): void
    {
        if ($this->tz) {
            date_default_timezone_set($this->tz);
        }

        if ($this->storage instanceof FlushableInterface) {
            $this->storage->flush();
            $this->cache->clear();
        }

        parent::tearDown();
    }

    /** @psalm-return class-string<StorageInterface> */
    protected function getStorageAdapterClassName(): string
    {
        return get_class($this->createStorage());
    }

    abstract protected function createStorage(): StorageInterface;

    public function createCachePool(): CacheItemPoolInterface
    {
        $this->storage = $this->createStorage();
        return new CacheItemPoolDecorator($this->storage);
    }

    /**
     * Data provider for invalid keys.
     *
     * @return list<array{0:mixed}>
     */
    public static function invalidKeys(): array
    {
        return [
            [true],
            [false],
            [null],
            [2],
            [2.5],
            ['{str'],
            ['rand{'],
            ['rand{str'],
            ['rand}str'],
            ['rand(str'],
            ['rand)str'],
            ['rand/str'],
            ['rand\\str'],
            ['rand@str'],
            ['rand:str'],
            [new stdClass()],
            [['array']],
        ];
    }

    public function testBasicUsage(): void
    {
        if (isset($this->skippedTests[__FUNCTION__])) {
            $this->markTestSkipped($this->skippedTests[__FUNCTION__]);
        }

        $item = $this->cache->getItem('key');
        $item->set('4711');
        $this->cache->save($item);

        $item = $this->cache->getItem('key2');
        $item->set('4712');
        $this->cache->save($item);

        $fooItem = $this->cache->getItem('key');
        self::assertTrue($fooItem->isHit());
        self::assertEquals('4711', $fooItem->get());

        $barItem = $this->cache->getItem('key2');
        self::assertTrue($barItem->isHit());
        self::assertEquals('4712', $barItem->get());

        // Remove 'key' and make sure 'key2' is still there
        $this->cache->deleteItem('key');
        self::assertFalse($this->cache->getItem('key')->isHit());
        self::assertTrue($this->cache->getItem('key2')->isHit());

        // Remove everything
        $this->cache->clear();
        self::assertFalse($this->cache->getItem('key')->isHit());
        self::assertFalse($this->cache->getItem('key2')->isHit());
    }

    public function testBasicUsageWithLongKey(): void
    {
        if (isset($this->skippedTests[__FUNCTION__])) {
            $this->markTestSkipped($this->skippedTests[__FUNCTION__]);
        }

        $pool = $this->createCachePool();

        $key = str_repeat('a', 300);

        $item = $pool->getItem($key);
        self::assertFalse($item->isHit());
        self::assertSame($key, $item->getKey());

        $item->set('value');
        self::assertTrue($pool->save($item));

        $item = $pool->getItem($key);
        self::assertTrue($item->isHit());
        self::assertSame($key, $item->getKey());
        self::assertSame('value', $item->get());

        self::assertTrue($pool->deleteItem($key));

        $item = $pool->getItem($key);
        self::assertFalse($item->isHit());
    }

    public function testItemModifiersReturnsStatic(): void
    {
        if (isset($this->skippedTests[__FUNCTION__])) {
            $this->markTestSkipped($this->skippedTests[__FUNCTION__]);
        }

        $item = $this->cache->getItem('key');
        self::assertSame($item, $item->set('4711'));
        self::assertSame($item, $item->expiresAfter(2));
        self::assertSame($item, $item->expiresAt(new DateTimeImmutable('+2hours')));
    }

    public function testGetItem(): void
    {
        if (isset($this->skippedTests[__FUNCTION__])) {
            $this->markTestSkipped($this->skippedTests[__FUNCTION__]);
        }

        $item = $this->cache->getItem('key');
        $item->set('value');
        $this->cache->save($item);

        // get existing item
        $item = $this->cache->getItem('key');
        self::assertEquals('value', $item->get(), 'A stored item must be returned from cached.');
        self::assertEquals('key', $item->getKey(), 'Cache key can not change.');

        // get non-existent item
        $item = $this->cache->getItem('key2');
        self::assertFalse($item->isHit());
        self::assertNull($item->get(), "Item's value must be null when isHit is false.");
    }

    public function testGetItems(): void
    {
        if (isset($this->skippedTests[__FUNCTION__])) {
            $this->markTestSkipped($this->skippedTests[__FUNCTION__]);
        }

        $keys = ['foo', 'bar', 'baz'];
        /** @var array<non-empty-string,CacheItemInterface> $items */
        $items = $this->cache->getItems($keys);

        $count = 0;

        foreach ($items as $i => $item) {
            $item->set($i);
            $this->cache->save($item);

            $count++;
        }

        self::assertSame(3, $count);

        $keys[] = 'biz';
        /** @var array<non-empty-string,CacheItemInterface> $items */
        $items = $this->cache->getItems($keys);
        $count = 0;
        foreach ($items as $key => $item) {
            $itemKey = $item->getKey();
            self::assertEquals($itemKey, $key, 'Keys must be preserved when fetching multiple items');
            self::assertEquals($key !== 'biz', $item->isHit());
            self::assertTrue(in_array($key, $keys), 'Cache key can not change.');

            // Remove $key for $keys
            foreach ($keys as $k => $v) {
                if ($v === $key) {
                    unset($keys[$k]);
                }
            }

            $count++;
        }

        self::assertSame(4, $count);
    }

    public function testGetItemsEmpty(): void
    {
        if (isset($this->skippedTests[__FUNCTION__])) {
            $this->markTestSkipped($this->skippedTests[__FUNCTION__]);
        }

        $items = $this->cache->getItems([]);
        self::assertTrue(
            is_array($items) || $items instanceof Traversable,
            'A call to getItems with an empty array must always return an array or \Traversable.'
        );

        $count = count(is_array($items) ? $items : iterator_to_array($items));

        self::assertSame(0, $count);
    }

    public function testHasItem(): void
    {
        if (isset($this->skippedTests[__FUNCTION__])) {
            $this->markTestSkipped($this->skippedTests[__FUNCTION__]);
        }

        $item = $this->cache->getItem('key');
        $item->set('value');
        $this->cache->save($item);

        // has existing item
        self::assertTrue($this->cache->hasItem('key'));

        // has non-existent item
        self::assertFalse($this->cache->hasItem('key2'));
    }

    public function testClear(): void
    {
        if (isset($this->skippedTests[__FUNCTION__])) {
            $this->markTestSkipped($this->skippedTests[__FUNCTION__]);
        }

        $item = $this->cache->getItem('key');
        $item->set('value');
        $this->cache->save($item);

        $return = $this->cache->clear();

        self::assertTrue($return, 'clear() must return true if cache was cleared. ');
        self::assertFalse(
            $this->cache->getItem('key')->isHit(),
            'No item should be a hit after the cache is cleared. '
        );
        self::assertFalse(
            $this->cache->hasItem('key2'),
            'The cache pool should be empty after it is cleared.'
        );
    }

    public function testClearWithDeferredItems(): void
    {
        if (isset($this->skippedTests[__FUNCTION__])) {
            $this->markTestSkipped($this->skippedTests[__FUNCTION__]);
        }

        $item = $this->cache->getItem('key');
        $item->set('value');
        $this->cache->saveDeferred($item);

        $this->cache->clear();
        $this->cache->commit();

        self::assertFalse(
            $this->cache->getItem('key')->isHit(),
            'Deferred items must be cleared on clear().'
        );
    }

    public function testDeleteItem(): void
    {
        if (isset($this->skippedTests[__FUNCTION__])) {
            $this->markTestSkipped($this->skippedTests[__FUNCTION__]);
        }

        $item = $this->cache->getItem('key');
        $item->set('value');
        $this->cache->save($item);

        self::assertTrue($this->cache->deleteItem('key'));
        self::assertFalse(
            $this->cache->getItem('key')->isHit(),
            'A deleted item should not be a hit.'
        );
        self::assertFalse(
            $this->cache->hasItem('key'),
            'A deleted item should not be a in cache.'
        );

        self::assertTrue(
            $this->cache->deleteItem('key2'),
            'Deleting an item that does not exist should return true.'
        );
    }

    public function testDeleteItems(): void
    {
        if (isset($this->skippedTests[__FUNCTION__])) {
            $this->markTestSkipped($this->skippedTests[__FUNCTION__]);
        }

        /** @var array<non-empty-string,CacheItemInterface> $items */
        $items = $this->cache->getItems(['foo', 'bar', 'baz']);

        foreach ($items as $idx => $item) {
            $item->set($idx);
            $this->cache->save($item);
        }

        // All should be a hit but 'biz'
        self::assertTrue($this->cache->getItem('foo')->isHit());
        self::assertTrue($this->cache->getItem('bar')->isHit());
        self::assertTrue($this->cache->getItem('baz')->isHit());
        self::assertFalse($this->cache->getItem('biz')->isHit());

        $return = $this->cache->deleteItems(['foo', 'bar', 'biz']);
        self::assertTrue($return);

        self::assertFalse($this->cache->getItem('foo')->isHit());
        self::assertFalse($this->cache->getItem('bar')->isHit());
        self::assertTrue($this->cache->getItem('baz')->isHit());
        self::assertFalse($this->cache->getItem('biz')->isHit());
    }

    public function testSave(): void
    {
        if (isset($this->skippedTests[__FUNCTION__])) {
            $this->markTestSkipped($this->skippedTests[__FUNCTION__]);
        }

        $item = $this->cache->getItem('key');
        $item->set('value');
        $return = $this->cache->save($item);

        self::assertTrue($return, 'save() should return true when items are saved.');
        self::assertEquals('value', $this->cache->getItem('key')->get());
    }

    public function testSaveExpired(): void
    {
        if (isset($this->skippedTests[__FUNCTION__])) {
            $this->markTestSkipped($this->skippedTests[__FUNCTION__]);
        }

        $item = $this->cache->getItem('key');
        $item->set('value');
        $item->expiresAt(DateTimeImmutable::createFromFormat('U', (string) (time() + 10)));
        $this->cache->save($item);
        $item->expiresAt(DateTimeImmutable::createFromFormat('U', (string) (time() - 1)));
        $this->cache->save($item);
        $item = $this->cache->getItem('key');
        self::assertFalse($item->isHit(), 'Cache should not save expired items');
    }

    public function testSaveWithoutExpire(): void
    {
        if (isset($this->skippedTests[__FUNCTION__])) {
            $this->markTestSkipped($this->skippedTests[__FUNCTION__]);
        }

        $item = $this->cache->getItem('test_ttl_null');
        $item->set('data');
        $this->cache->save($item);

        // Use a new pool instance to ensure that we don't hit any caches
        $pool = $this->createCachePool();
        $item = $pool->getItem('test_ttl_null');

        self::assertTrue($item->isHit(), 'Cache should have retrieved the items');
        self::assertEquals('data', $item->get());
    }

    public function testDeferredSave(): void
    {
        if (isset($this->skippedTests[__FUNCTION__])) {
            $this->markTestSkipped($this->skippedTests[__FUNCTION__]);
        }

        $item = $this->cache->getItem('key');
        $item->set('4711');
        $return = $this->cache->saveDeferred($item);
        self::assertTrue($return, 'save() should return true when items are saved.');

        $item = $this->cache->getItem('key2');
        $item->set('4712');
        $this->cache->saveDeferred($item);

        // They are not saved yet but should be a hit
        self::assertTrue(
            $this->cache->hasItem('key'),
            'Deferred items should be considered as a part of the cache even before they are committed'
        );
        self::assertTrue(
            $this->cache->getItem('key')->isHit(),
            'Deferred items should be a hit even before they are committed'
        );
        self::assertTrue($this->cache->getItem('key2')->isHit());

        $this->cache->commit();

        // They should be a hit after the commit as well
        self::assertTrue($this->cache->getItem('key')->isHit());
        self::assertTrue($this->cache->getItem('key2')->isHit());
    }

    public function testDeferredExpired(): void
    {
        if (isset($this->skippedTests[__FUNCTION__])) {
            $this->markTestSkipped($this->skippedTests[__FUNCTION__]);
        }

        $item = $this->cache->getItem('key');
        $item->set('4711');
        $item->expiresAt(DateTimeImmutable::createFromFormat('U', time() - 1));
        $this->cache->saveDeferred($item);

        self::assertFalse(
            $this->cache->hasItem('key'),
            'Cache should not have expired deferred item'
        );
        $this->cache->commit();
        $item = $this->cache->getItem('key');
        self::assertFalse($item->isHit(), 'Cache should not save expired items');
    }

    public function testDeleteDeferredItem(): void
    {
        if (isset($this->skippedTests[__FUNCTION__])) {
            $this->markTestSkipped($this->skippedTests[__FUNCTION__]);
        }

        $item = $this->cache->getItem('key');
        $item->set('4711');
        $this->cache->saveDeferred($item);
        self::assertTrue($this->cache->getItem('key')->isHit());

        $this->cache->deleteItem('key');
        self::assertFalse(
            $this->cache->hasItem('key'),
            'You must be able to delete a deferred item before committed.'
        );
        self::assertFalse(
            $this->cache->getItem('key')->isHit(),
            'You must be able to delete a deferred item before committed.'
        );

        $this->cache->commit();
        self::assertFalse(
            $this->cache->hasItem('key'),
            'A deleted item should not reappear after commit.'
        );
        self::assertFalse(
            $this->cache->getItem('key')->isHit(),
            'A deleted item should not reappear after commit.'
        );
    }

    public function testDeferredSaveWithoutCommit(): void
    {
        if (isset($this->skippedTests[__FUNCTION__])) {
            $this->markTestSkipped($this->skippedTests[__FUNCTION__]);
        }

        $this->prepareDeferredSaveWithoutCommit();
        gc_collect_cycles();

        $cache = $this->createCachePool();
        self::assertTrue(
            $cache->getItem('key')->isHit(),
            'A deferred item should automatically be committed on CachePool::__destruct().'
        );
    }

    private function prepareDeferredSaveWithoutCommit(): void
    {
        $cache       = $this->cache;
        $this->cache = null;
        $item        = $cache->getItem('key');
        $item->set('4711');
        $cache->saveDeferred($item);
    }

    public function testCommit(): void
    {
        if (isset($this->skippedTests[__FUNCTION__])) {
            $this->markTestSkipped($this->skippedTests[__FUNCTION__]);
        }

        $item = $this->cache->getItem('key');
        $item->set('value');
        $this->cache->saveDeferred($item);
        $return = $this->cache->commit();

        self::assertTrue($return, 'commit() should return true on successful commit. ');
        self::assertEquals('value', $this->cache->getItem('key')->get());

        $return = $this->cache->commit();
        self::assertTrue(
            $return,
            'commit() should return true even if no items were deferred.'
        );
    }

    /**
     * @medium
     */
    public function testExpiration(): void
    {
        if (isset($this->skippedTests[__FUNCTION__])) {
            $this->markTestSkipped($this->skippedTests[__FUNCTION__]);
        }

        $item = $this->cache->getItem('key');
        $item->set('value');
        $item->expiresAfter(2);
        $this->cache->save($item);

        sleep(3);
        $item = $this->cache->getItem('key');
        self::assertFalse($item->isHit());
        self::assertNull($item->get(), "Item's value must be null when isHit() is false.");
    }

    public function testExpiresAt(): void
    {
        if (isset($this->skippedTests[__FUNCTION__])) {
            $this->markTestSkipped($this->skippedTests[__FUNCTION__]);
        }

        $item = $this->cache->getItem('key');
        $item->set('value');
        $item->expiresAt(new DateTimeImmutable('+2hours'));
        $this->cache->save($item);

        $item = $this->cache->getItem('key');
        self::assertTrue($item->isHit());
    }

    public function testExpiresAtWithNull(): void
    {
        if (isset($this->skippedTests[__FUNCTION__])) {
            $this->markTestSkipped($this->skippedTests[__FUNCTION__]);
        }

        $item = $this->cache->getItem('key');
        $item->set('value');
        $item->expiresAt(null);
        $this->cache->save($item);

        $item = $this->cache->getItem('key');
        self::assertTrue($item->isHit());
    }

    public function testExpiresAfterWithNull(): void
    {
        if (isset($this->skippedTests[__FUNCTION__])) {
            $this->markTestSkipped($this->skippedTests[__FUNCTION__]);
        }

        $item = $this->cache->getItem('key');
        $item->set('value');
        $item->expiresAfter(null);
        $this->cache->save($item);

        $item = $this->cache->getItem('key');
        self::assertTrue($item->isHit());
    }

    public function testKeyLength(): void
    {
        if (isset($this->skippedTests[__FUNCTION__])) {
            $this->markTestSkipped($this->skippedTests[__FUNCTION__]);
        }

        $key  = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_.';
        $item = $this->cache->getItem($key);
        $item->set('value');
        self::assertTrue(
            $this->cache->save($item),
            'The implementation does not support a valid cache key'
        );

        self::assertTrue($this->cache->hasItem($key));
    }

    /**
     * @param mixed $key
     * @dataProvider invalidKeys
     */
    public function testGetItemInvalidKeys($key): void
    {
        if (isset($this->skippedTests[__FUNCTION__])) {
            $this->markTestSkipped($this->skippedTests[__FUNCTION__]);
        }

        $this->expectException(InvalidArgumentException::class);
        $this->cache->getItem($key);
    }

    /**
     * @param mixed $key
     * @dataProvider invalidKeys
     */
    public function testGetItemsInvalidKeys($key): void
    {
        if (isset($this->skippedTests[__FUNCTION__])) {
            $this->markTestSkipped($this->skippedTests[__FUNCTION__]);
        }

        $this->expectException(InvalidArgumentException::class);
        $this->cache->getItems(['key1', $key, 'key2']);
    }

    /**
     * @param mixed $key
     * @dataProvider invalidKeys
     */
    public function testHasItemInvalidKeys($key): void
    {
        if (isset($this->skippedTests[__FUNCTION__])) {
            $this->markTestSkipped($this->skippedTests[__FUNCTION__]);
        }

        $this->expectException(InvalidArgumentException::class);
        $this->cache->hasItem($key);
    }

    /**
     * @param mixed $key
     * @dataProvider invalidKeys
     */
    public function testDeleteItemInvalidKeys($key): void
    {
        if (isset($this->skippedTests[__FUNCTION__])) {
            $this->markTestSkipped($this->skippedTests[__FUNCTION__]);
        }

        $this->expectException(InvalidArgumentException::class);
        $this->cache->deleteItem($key);
    }

    /**
     * @param mixed $key
     * @dataProvider invalidKeys
     */
    public function testDeleteItemsInvalidKeys($key): void
    {
        if (isset($this->skippedTests[__FUNCTION__])) {
            $this->markTestSkipped($this->skippedTests[__FUNCTION__]);
        }

        $this->expectException(InvalidArgumentException::class);
        $this->cache->deleteItems(['key1', $key, 'key2']);
    }

    public function testDataTypeString(): void
    {
        if (isset($this->skippedTests[__FUNCTION__])) {
            $this->markTestSkipped($this->skippedTests[__FUNCTION__]);
        }

        $item = $this->cache->getItem('key');
        $item->set('5');
        $this->cache->save($item);

        $item = $this->cache->getItem('key');
        self::assertTrue(
            '5' === $item->get(),
            'Wrong data type. If we store a string we must get an string back.'
        );
        self::assertTrue(
            is_string($item->get()),
            'Wrong data type. If we store a string we must get an string back.'
        );
    }

    public function testDataTypeInteger(): void
    {
        if (isset($this->skippedTests[__FUNCTION__])) {
            $this->markTestSkipped($this->skippedTests[__FUNCTION__]);
        }

        $item = $this->cache->getItem('key');
        $item->set(5);
        $this->cache->save($item);

        $item = $this->cache->getItem('key');
        self::assertTrue(
            5 === $item->get(),
            'Wrong data type. If we store an int we must get an int back.'
        );
        self::assertTrue(
            is_int($item->get()),
            'Wrong data type. If we store an int we must get an int back.'
        );
    }

    public function testDataTypeNull(): void
    {
        if (isset($this->skippedTests[__FUNCTION__])) {
            $this->markTestSkipped($this->skippedTests[__FUNCTION__]);
        }

        $item = $this->cache->getItem('key');
        $item->set(null);
        $this->cache->save($item);

        self::assertTrue(
            $this->cache->hasItem('key'),
            'Null is a perfectly fine cache value. hasItem() should return true when null are stored.'
        );
        $item = $this->cache->getItem('key');
        self::assertTrue(
            null === $item->get(),
            'Wrong data type. If we store null we must get an null back.'
        );
        self::assertTrue(
            $item->isHit(),
            'isHit() should return true when null are stored.'
        );
    }

    public function testDataTypeFloat(): void
    {
        if (isset($this->skippedTests[__FUNCTION__])) {
            $this->markTestSkipped($this->skippedTests[__FUNCTION__]);
        }

        $float = 1.23456789;
        $item  = $this->cache->getItem('key');
        $item->set($float);
        $this->cache->save($item);

        $item = $this->cache->getItem('key');
        self::assertTrue(
            is_float($item->get()),
            'Wrong data type. If we store float we must get an float back.'
        );
        self::assertEquals($float, $item->get());
        self::assertTrue(
            $item->isHit(),
            'isHit() should return true when float are stored.'
        );
    }

    public function testDataTypeBoolean(): void
    {
        if (isset($this->skippedTests[__FUNCTION__])) {
            $this->markTestSkipped($this->skippedTests[__FUNCTION__]);
        }

        $item = $this->cache->getItem('key');
        $item->set(true);
        $this->cache->save($item);

        $item = $this->cache->getItem('key');
        self::assertTrue(
            is_bool($item->get()),
            'Wrong data type. If we store boolean we must get an boolean back.'
        );
        self::assertTrue($item->get());
        self::assertTrue(
            $item->isHit(),
            'isHit() should return true when true are stored.'
        );
    }

    public function testDataTypeArray(): void
    {
        if (isset($this->skippedTests[__FUNCTION__])) {
            $this->markTestSkipped($this->skippedTests[__FUNCTION__]);
        }

        $array = ['a' => 'foo', 2 => 'bar'];
        $item  = $this->cache->getItem('key');
        $item->set($array);
        $this->cache->save($item);

        $item = $this->cache->getItem('key');
        self::assertTrue(
            is_array($item->get()),
            'Wrong data type. If we store array we must get an array back.'
        );
        self::assertEquals($array, $item->get());
        self::assertTrue(
            $item->isHit(),
            'isHit() should return true when array are stored.'
        );
    }

    public function testDataTypeObject(): void
    {
        if (isset($this->skippedTests[__FUNCTION__])) {
            $this->markTestSkipped($this->skippedTests[__FUNCTION__]);
        }

        $object    = new stdClass();
        $object->a = 'foo';
        $item      = $this->cache->getItem('key');
        $item->set($object);
        $this->cache->save($item);

        $item = $this->cache->getItem('key');
        self::assertTrue(
            is_object($item->get()),
            'Wrong data type. If we store object we must get an object back.'
        );
        self::assertEquals($object, $item->get());
        self::assertTrue(
            $item->isHit(),
            'isHit() should return true when object are stored.'
        );
    }

    public function testBinaryData(): void
    {
        if (isset($this->skippedTests[__FUNCTION__])) {
            $this->markTestSkipped($this->skippedTests[__FUNCTION__]);
        }

        $data = '';
        for ($i = 0; $i < 256; $i++) {
            $data .= chr($i);
        }

        $item = $this->cache->getItem('key');
        $item->set($data);
        $this->cache->save($item);

        $item = $this->cache->getItem('key');
        self::assertTrue($data === $item->get(), 'Binary data must survive a round trip.');
    }

    public function testIsHit(): void
    {
        if (isset($this->skippedTests[__FUNCTION__])) {
            $this->markTestSkipped($this->skippedTests[__FUNCTION__]);
        }

        $item = $this->cache->getItem('key');
        $item->set('value');
        $this->cache->save($item);

        $item = $this->cache->getItem('key');
        self::assertTrue($item->isHit());
    }

    public function testIsHitDeferred(): void
    {
        if (isset($this->skippedTests[__FUNCTION__])) {
            $this->markTestSkipped($this->skippedTests[__FUNCTION__]);
        }

        $item = $this->cache->getItem('key');
        $item->set('value');
        $this->cache->saveDeferred($item);

        // Test accessing the value before it is committed
        $item = $this->cache->getItem('key');
        self::assertTrue($item->isHit());

        $this->cache->commit();
        $item = $this->cache->getItem('key');
        self::assertTrue($item->isHit());
    }

    public function testSaveDeferredWhenChangingValues(): void
    {
        if (isset($this->skippedTests[__FUNCTION__])) {
            $this->markTestSkipped($this->skippedTests[__FUNCTION__]);
        }

        $item = $this->cache->getItem('key');
        $item->set('value');
        $this->cache->saveDeferred($item);

        $item = $this->cache->getItem('key');
        $item->set('new value');

        $item = $this->cache->getItem('key');
        self::assertEquals(
            'value',
            $item->get(),
            'Items that is put in the deferred queue should not get their values changed'
        );

        $this->cache->commit();
        $item = $this->cache->getItem('key');
        self::assertEquals(
            'value',
            $item->get(),
            'Items that is put in the deferred queue should not get their values changed'
        );
    }

    public function testSaveDeferredOverwrite(): void
    {
        if (isset($this->skippedTests[__FUNCTION__])) {
            $this->markTestSkipped($this->skippedTests[__FUNCTION__]);
        }

        $item = $this->cache->getItem('key');
        $item->set('value');
        $this->cache->saveDeferred($item);

        $item = $this->cache->getItem('key');
        $item->set('new value');
        $this->cache->saveDeferred($item);

        $item = $this->cache->getItem('key');
        self::assertEquals('new value', $item->get());

        $this->cache->commit();
        $item = $this->cache->getItem('key');
        self::assertEquals('new value', $item->get());
    }

    public function testSavingObject(): void
    {
        if (isset($this->skippedTests[__FUNCTION__])) {
            $this->markTestSkipped($this->skippedTests[__FUNCTION__]);
        }

        $item = $this->cache->getItem('key');
        $item->set(new DateTimeImmutable());
        $this->cache->save($item);

        $item  = $this->cache->getItem('key');
        $value = $item->get();
        self::assertInstanceOf(DateTimeImmutable::class, $value, 'You must be able to store objects in cache.');
    }

    /**
     * @medium
     */
    public function testHasItemReturnsFalseWhenDeferredItemIsExpired(): void
    {
        if (isset($this->skippedTests[__FUNCTION__])) {
            $this->markTestSkipped($this->skippedTests[__FUNCTION__]);
        }

        $item = $this->cache->getItem('key');
        $item->set('value');
        $item->expiresAfter(2);
        $this->cache->saveDeferred($item);

        sleep(3);
        self::assertFalse($this->cache->hasItem('key'));
    }
}
