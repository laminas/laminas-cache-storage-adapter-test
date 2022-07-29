<?php

declare(strict_types=1);

namespace LaminasTest\Cache\Storage\Adapter;

use DateInterval;
use Generator;
use Laminas\Cache\Psr\SimpleCache\SimpleCacheDecorator;
use Laminas\Cache\Storage\FlushableInterface;
use Laminas\Cache\Storage\StorageInterface;
use PHPUnit\Framework\TestCase;
use Psr\SimpleCache\CacheInterface;
use Psr\SimpleCache\InvalidArgumentException;
use stdClass;

use function array_merge;
use function chr;
use function date_default_timezone_get;
use function date_default_timezone_set;
use function get_class;
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_object;
use function is_string;
use function sleep;
use function sort;
use function str_repeat;

abstract class AbstractSimpleCacheIntegrationTest extends TestCase
{
    private ?string $tz = null;

    private ?StorageInterface $storage = null;

    /**
     * Map of test name and the reason why it is skipped.
     *
     * @var array<non-empty-string,non-empty-string>
     */
    protected array $skippedTests = [];

    protected CacheInterface $cache;

    protected function setUp(): void
    {
        parent::setUp();
        // set non-UTC timezone
        $this->tz = date_default_timezone_get();
        date_default_timezone_set('America/Vancouver');
        $this->cache = $this->createSimpleCache();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        if ($this->tz) {
            date_default_timezone_set($this->tz);
        }

        if ($this->storage instanceof FlushableInterface) {
            $this->storage->flush();
            $this->cache->clear();
        }
    }

    /** @psalm-return class-string<StorageInterface> */
    protected function getStorageAdapterClassName(): string
    {
        return get_class($this->createStorage());
    }

    abstract protected function createStorage(): StorageInterface;

    public function createSimpleCache(): CacheInterface
    {
        $this->storage = $this->createStorage();
        return new SimpleCacheDecorator($this->storage);
    }

    /**
     * Advance time perceived by the cache for the purposes of testing TTL.
     *
     * The default implementation sleeps for the specified duration,
     * but subclasses are encouraged to override this,
     * adjusting a mocked time possibly set up in {@link createSimpleCache()},
     * to speed up the tests.
     *
     * @param 0|positive-int $seconds
     */
    protected function advanceTime(int $seconds): void
    {
        sleep($seconds);
    }

    /**
     * Data provider for invalid cache keys.
     *
     * @return list<array{0:mixed}>
     */
    public static function invalidKeys(): array
    {
        return array_merge(
            self::invalidArrayKeys(),
            [
                [2],
            ]
        );
    }

    /**
     * Data provider for invalid array keys.
     *
     * @return list<array{0:mixed}>
     */
    public static function invalidArrayKeys(): array
    {
        return [
            [''],
            [true],
            [false],
            [null],
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

    /**
     * @return list<array{0:mixed}>
     */
    public static function invalidTtl(): array
    {
        return [
            [''],
            [true],
            [false],
            ['abc'],
            [2.5],
            [' 1'], // can be casted to a int
            ['12foo'], // can be casted to a int
            ['025'], // can be interpreted as hex
            [new stdClass()],
            [['array']],
        ];
    }

    /**
     * Data provider for valid keys.
     *
     * @return list<array{0:non-empty-string}>
     */
    public static function validKeys(): array
    {
        return [
            ['AbC19_.'],
            ['1234567890123456789012345678901234567890123456789012345678901234'],
        ];
    }

    /**
     * Data provider for valid data to store.
     *
     * @return list<array{0:mixed}>
     */
    public static function validData(): array
    {
        return [
            ['AbC19_.'],
            [4711],
            [47.11],
            [true],
            [null],
            [['key' => 'value']],
            [new stdClass()],
        ];
    }

    public function testSet(): void
    {
        if (isset($this->skippedTests[__FUNCTION__])) {
            $this->markTestSkipped($this->skippedTests[__FUNCTION__]);
        }

        $result = $this->cache->set('key', 'value');
        self::assertTrue($result, 'set() must return true if success');
        self::assertEquals('value', $this->cache->get('key'));
    }

    /**
     * @medium
     */
    public function testSetTtl(): void
    {
        if (isset($this->skippedTests[__FUNCTION__])) {
            $this->markTestSkipped($this->skippedTests[__FUNCTION__]);
        }

        $result = $this->cache->set('key1', 'value', 2);
        self::assertTrue($result, 'set() must return true if success');
        self::assertEquals('value', $this->cache->get('key1'));

        $this->cache->set('key2', 'value', new DateInterval('PT2S'));
        self::assertEquals('value', $this->cache->get('key2'));

        $this->advanceTime(3);

        self::assertNull($this->cache->get('key1'), 'Value must expire after ttl.');
        self::assertNull($this->cache->get('key2'), 'Value must expire after ttl.');
    }

    public function testSetExpiredTtl(): void
    {
        if (isset($this->skippedTests[__FUNCTION__])) {
            $this->markTestSkipped($this->skippedTests[__FUNCTION__]);
        }

        $this->cache->set('key0', 'value');
        $this->cache->set('key0', 'value', 0);
        self::assertNull($this->cache->get('key0'));
        self::assertFalse($this->cache->has('key0'));

        $this->cache->set('key1', 'value', -1);
        self::assertNull($this->cache->get('key1'));
        self::assertFalse($this->cache->has('key1'));
    }

    public function testGet(): void
    {
        if (isset($this->skippedTests[__FUNCTION__])) {
            $this->markTestSkipped($this->skippedTests[__FUNCTION__]);
        }

        self::assertNull($this->cache->get('key'));
        self::assertEquals('foo', $this->cache->get('key', 'foo'));

        $this->cache->set('key', 'value');
        self::assertEquals('value', $this->cache->get('key', 'foo'));
    }

    public function testDelete(): void
    {
        if (isset($this->skippedTests[__FUNCTION__])) {
            $this->markTestSkipped($this->skippedTests[__FUNCTION__]);
        }

        self::assertTrue($this->cache->delete('key'), 'Deleting a value that does not exist should return true');
        $this->cache->set('key', 'value');
        self::assertTrue($this->cache->delete('key'), 'Delete must return true on success');
        self::assertNull($this->cache->get('key'), 'Values must be deleted on delete()');
    }

    public function testClear(): void
    {
        if (isset($this->skippedTests[__FUNCTION__])) {
            $this->markTestSkipped($this->skippedTests[__FUNCTION__]);
        }

        self::assertTrue($this->cache->clear(), 'Clearing an empty cache should return true');
        $this->cache->set('key', 'value');
        self::assertTrue($this->cache->clear(), 'Delete must return true on success');
        self::assertNull($this->cache->get('key'), 'Values must be deleted on clear()');
    }

    public function testSetMultiple(): void
    {
        if (isset($this->skippedTests[__FUNCTION__])) {
            $this->markTestSkipped($this->skippedTests[__FUNCTION__]);
        }

        $result = $this->cache->setMultiple(['key0' => 'value0', 'key1' => 'value1']);
        self::assertTrue($result, 'setMultiple() must return true if success');
        self::assertEquals('value0', $this->cache->get('key0'));
        self::assertEquals('value1', $this->cache->get('key1'));
    }

    public function testSetMultipleWithIntegerArrayKey(): void
    {
        if (isset($this->skippedTests[__FUNCTION__])) {
            $this->markTestSkipped($this->skippedTests[__FUNCTION__]);
        }

        $result = $this->cache->setMultiple(['0' => 'value0']);
        self::assertTrue($result, 'setMultiple() must return true if success');
        self::assertEquals('value0', $this->cache->get('0'));
    }

    /**
     * @medium
     */
    public function testSetMultipleTtl(): void
    {
        if (isset($this->skippedTests[__FUNCTION__])) {
            $this->markTestSkipped($this->skippedTests[__FUNCTION__]);
        }

        $this->cache->setMultiple(['key2' => 'value2', 'key3' => 'value3'], 2);
        self::assertEquals('value2', $this->cache->get('key2'));
        self::assertEquals('value3', $this->cache->get('key3'));

        $this->cache->setMultiple(['key4' => 'value4'], new DateInterval('PT2S'));
        self::assertEquals('value4', $this->cache->get('key4'));

        $this->advanceTime(3);
        self::assertNull($this->cache->get('key2'), 'Value must expire after ttl.');
        self::assertNull($this->cache->get('key3'), 'Value must expire after ttl.');
        self::assertNull($this->cache->get('key4'), 'Value must expire after ttl.');
    }

    public function testSetMultipleExpiredTtl(): void
    {
        if (isset($this->skippedTests[__FUNCTION__])) {
            $this->markTestSkipped($this->skippedTests[__FUNCTION__]);
        }

        $this->cache->setMultiple(['key0' => 'value0', 'key1' => 'value1'], 0);
        self::assertNull($this->cache->get('key0'));
        self::assertNull($this->cache->get('key1'));
    }

    public function testSetMultipleWithGenerator(): void
    {
        if (isset($this->skippedTests[__FUNCTION__])) {
            $this->markTestSkipped($this->skippedTests[__FUNCTION__]);
        }

        $gen = function (): Generator {
            yield 'key0' => 'value0';
            yield 'key1' => 'value1';
        };

        $this->cache->setMultiple($gen());
        self::assertEquals('value0', $this->cache->get('key0'));
        self::assertEquals('value1', $this->cache->get('key1'));
    }

    public function testGetMultiple(): void
    {
        if (isset($this->skippedTests[__FUNCTION__])) {
            $this->markTestSkipped($this->skippedTests[__FUNCTION__]);
        }

        $result = $this->cache->getMultiple(['key0', 'key1']);
        $keys   = [];
        foreach ($result as $i => $r) {
            $keys[] = $i;
            self::assertNull($r);
        }
        sort($keys);
        self::assertSame(['key0', 'key1'], $keys);

        $this->cache->set('key3', 'value');
        $result = $this->cache->getMultiple(['key2', 'key3', 'key4'], 'foo');
        $keys   = [];
        foreach ($result as $key => $r) {
            $keys[] = $key;
            if ($key === 'key3') {
                self::assertEquals('value', $r);
            } else {
                self::assertEquals('foo', $r);
            }
        }
        sort($keys);
        self::assertSame(['key2', 'key3', 'key4'], $keys);
    }

    public function testGetMultipleWithGenerator(): void
    {
        if (isset($this->skippedTests[__FUNCTION__])) {
            $this->markTestSkipped($this->skippedTests[__FUNCTION__]);
        }

        $gen = function (): Generator {
            yield 1 => 'key0';
            yield 1 => 'key1';
        };

        $this->cache->set('key0', 'value0');
        $result = $this->cache->getMultiple($gen());
        $keys   = [];
        foreach ($result as $key => $r) {
            $keys[] = $key;
            if ($key === 'key0') {
                self::assertEquals('value0', $r);
            } elseif ($key === 'key1') {
                self::assertNull($r);
            } else {
                self::assertFalse(true, 'This should not happend');
            }
        }
        sort($keys);
        self::assertSame(['key0', 'key1'], $keys);
        self::assertEquals('value0', $this->cache->get('key0'));
        self::assertNull($this->cache->get('key1'));
    }

    public function testDeleteMultiple(): void
    {
        if (isset($this->skippedTests[__FUNCTION__])) {
            $this->markTestSkipped($this->skippedTests[__FUNCTION__]);
        }

        self::assertTrue($this->cache->deleteMultiple([]), 'Deleting a empty array should return true');
        self::assertTrue(
            $this->cache->deleteMultiple(['key']),
            'Deleting a value that does not exist should return true'
        );

        $this->cache->set('key0', 'value0');
        $this->cache->set('key1', 'value1');
        self::assertTrue($this->cache->deleteMultiple(['key0', 'key1']), 'Delete must return true on success');
        self::assertNull($this->cache->get('key0'), 'Values must be deleted on deleteMultiple()');
        self::assertNull($this->cache->get('key1'), 'Values must be deleted on deleteMultiple()');
    }

    public function testDeleteMultipleGenerator(): void
    {
        if (isset($this->skippedTests[__FUNCTION__])) {
            $this->markTestSkipped($this->skippedTests[__FUNCTION__]);
        }

        $gen = function (): Generator {
            yield 1 => 'key0';
            yield 1 => 'key1';
        };
        $this->cache->set('key0', 'value0');
        self::assertTrue($this->cache->deleteMultiple($gen()), 'Deleting a generator should return true');

        self::assertNull($this->cache->get('key0'), 'Values must be deleted on deleteMultiple()');
        self::assertNull($this->cache->get('key1'), 'Values must be deleted on deleteMultiple()');
    }

    public function testHas(): void
    {
        if (isset($this->skippedTests[__FUNCTION__])) {
            $this->markTestSkipped($this->skippedTests[__FUNCTION__]);
        }

        self::assertFalse($this->cache->has('key0'));
        $this->cache->set('key0', 'value0');
        self::assertTrue($this->cache->has('key0'));
    }

    public function testBasicUsageWithLongKey(): void
    {
        if (isset($this->skippedTests[__FUNCTION__])) {
            $this->markTestSkipped($this->skippedTests[__FUNCTION__]);
        }

        $key = str_repeat('a', 300);

        self::assertFalse($this->cache->has($key));
        self::assertTrue($this->cache->set($key, 'value'));

        self::assertTrue($this->cache->has($key));
        self::assertSame('value', $this->cache->get($key));

        self::assertTrue($this->cache->delete($key));

        self::assertFalse($this->cache->has($key));
    }

    /**
     * @param mixed $key
     * @dataProvider invalidKeys
     */
    public function testGetInvalidKeys($key): void
    {
        if (isset($this->skippedTests[__FUNCTION__])) {
            $this->markTestSkipped($this->skippedTests[__FUNCTION__]);
        }

        $this->expectException(InvalidArgumentException::class);
        $this->cache->get($key);
    }

    /**
     * @param mixed $key
     * @dataProvider invalidKeys
     */
    public function testGetMultipleInvalidKeys($key): void
    {
        if (isset($this->skippedTests[__FUNCTION__])) {
            $this->markTestSkipped($this->skippedTests[__FUNCTION__]);
        }

        $this->expectException(InvalidArgumentException::class);
        $this->cache->getMultiple(['key1', $key, 'key2']);
    }

    public function testGetMultipleNoIterable(): void
    {
        if (isset($this->skippedTests[__FUNCTION__])) {
            $this->markTestSkipped($this->skippedTests[__FUNCTION__]);
        }

        $this->expectException(InvalidArgumentException::class);
        $this->cache->getMultiple('key');
    }

    /**
     * @param mixed $key
     * @dataProvider invalidKeys
     */
    public function testSetInvalidKeys($key): void
    {
        if (isset($this->skippedTests[__FUNCTION__])) {
            $this->markTestSkipped($this->skippedTests[__FUNCTION__]);
        }

        $this->expectException(InvalidArgumentException::class);
        $this->cache->set($key, 'foobar');
    }

    /**
     * @param mixed $key
     * @dataProvider invalidArrayKeys
     */
    public function testSetMultipleInvalidKeys($key): void
    {
        if (isset($this->skippedTests[__FUNCTION__])) {
            $this->markTestSkipped($this->skippedTests[__FUNCTION__]);
        }

        $values = function () use ($key): Generator {
            yield 'key1' => 'foo';
            yield $key => 'bar';
            yield 'key2' => 'baz';
        };
        $this->expectException(InvalidArgumentException::class);
        $this->cache->setMultiple($values());
    }

    public function testSetMultipleNoIterable(): void
    {
        if (isset($this->skippedTests[__FUNCTION__])) {
            $this->markTestSkipped($this->skippedTests[__FUNCTION__]);
        }

        $this->expectException(InvalidArgumentException::class);
        $this->cache->setMultiple('key');
    }

    /**
     * @param mixed $key
     * @dataProvider invalidKeys
     */
    public function testHasInvalidKeys($key): void
    {
        if (isset($this->skippedTests[__FUNCTION__])) {
            $this->markTestSkipped($this->skippedTests[__FUNCTION__]);
        }

        $this->expectException(InvalidArgumentException::class);
        $this->cache->has($key);
    }

    /**
     * @param mixed $key
     * @dataProvider invalidKeys
     */
    public function testDeleteInvalidKeys($key): void
    {
        if (isset($this->skippedTests[__FUNCTION__])) {
            $this->markTestSkipped($this->skippedTests[__FUNCTION__]);
        }

        $this->expectException(InvalidArgumentException::class);
        $this->cache->delete($key);
    }

    /**
     * @param mixed $key
     * @dataProvider invalidKeys
     */
    public function testDeleteMultipleInvalidKeys($key): void
    {
        if (isset($this->skippedTests[__FUNCTION__])) {
            $this->markTestSkipped($this->skippedTests[__FUNCTION__]);
        }

        $this->expectException(InvalidArgumentException::class);
        $this->cache->deleteMultiple(['key1', $key, 'key2']);
    }

    public function testDeleteMultipleNoIterable(): void
    {
        if (isset($this->skippedTests[__FUNCTION__])) {
            $this->markTestSkipped($this->skippedTests[__FUNCTION__]);
        }

        $this->expectException(InvalidArgumentException::class);
        $this->cache->deleteMultiple('key');
    }

    /**
     * @param mixed $ttl
     * @dataProvider invalidTtl
     */
    public function testSetInvalidTtl($ttl): void
    {
        if (isset($this->skippedTests[__FUNCTION__])) {
            $this->markTestSkipped($this->skippedTests[__FUNCTION__]);
        }

        $this->expectException(InvalidArgumentException::class);
        $this->cache->set('key', 'value', $ttl);
    }

    /**
     * @param mixed $ttl
     * @dataProvider invalidTtl
     */
    public function testSetMultipleInvalidTtl($ttl): void
    {
        if (isset($this->skippedTests[__FUNCTION__])) {
            $this->markTestSkipped($this->skippedTests[__FUNCTION__]);
        }

        $this->expectException(InvalidArgumentException::class);
        $this->cache->setMultiple(['key' => 'value'], $ttl);
    }

    public function testNullOverwrite(): void
    {
        if (isset($this->skippedTests[__FUNCTION__])) {
            $this->markTestSkipped($this->skippedTests[__FUNCTION__]);
        }

        $this->cache->set('key', 5);
        $this->cache->set('key', null);

        self::assertNull($this->cache->get('key'), 'Setting null to a key must overwrite previous value');
    }

    public function testDataTypeString(): void
    {
        if (isset($this->skippedTests[__FUNCTION__])) {
            $this->markTestSkipped($this->skippedTests[__FUNCTION__]);
        }

        $this->cache->set('key', '5');
        $result = $this->cache->get('key');
        self::assertTrue('5' === $result, 'Wrong data type. If we store a string we must get an string back.');
        self::assertTrue(is_string($result), 'Wrong data type. If we store a string we must get an string back.');
    }

    public function testDataTypeInteger(): void
    {
        if (isset($this->skippedTests[__FUNCTION__])) {
            $this->markTestSkipped($this->skippedTests[__FUNCTION__]);
        }

        $this->cache->set('key', 5);
        $result = $this->cache->get('key');
        self::assertTrue(5 === $result, 'Wrong data type. If we store an int we must get an int back.');
        self::assertTrue(is_int($result), 'Wrong data type. If we store an int we must get an int back.');
    }

    public function testDataTypeFloat(): void
    {
        if (isset($this->skippedTests[__FUNCTION__])) {
            $this->markTestSkipped($this->skippedTests[__FUNCTION__]);
        }

        $float = 1.23456789;
        $this->cache->set('key', $float);
        $result = $this->cache->get('key');
        self::assertTrue(is_float($result), 'Wrong data type. If we store float we must get an float back.');
        self::assertEquals($float, $result);
    }

    public function testDataTypeBoolean(): void
    {
        if (isset($this->skippedTests[__FUNCTION__])) {
            $this->markTestSkipped($this->skippedTests[__FUNCTION__]);
        }

        $this->cache->set('key', false);
        $result = $this->cache->get('key');
        self::assertTrue(is_bool($result), 'Wrong data type. If we store boolean we must get an boolean back.');
        self::assertFalse($result);
        self::assertTrue($this->cache->has('key'), 'has() should return true when true are stored. ');
    }

    public function testDataTypeArray(): void
    {
        if (isset($this->skippedTests[__FUNCTION__])) {
            $this->markTestSkipped($this->skippedTests[__FUNCTION__]);
        }

        $array = ['a' => 'foo', 2 => 'bar'];
        $this->cache->set('key', $array);
        $result = $this->cache->get('key');
        self::assertTrue(is_array($result), 'Wrong data type. If we store array we must get an array back.');
        self::assertEquals($array, $result);
    }

    public function testDataTypeObject(): void
    {
        if (isset($this->skippedTests[__FUNCTION__])) {
            $this->markTestSkipped($this->skippedTests[__FUNCTION__]);
        }

        $object    = new stdClass();
        $object->a = 'foo';
        $this->cache->set('key', $object);
        $result = $this->cache->get('key');
        self::assertTrue(is_object($result), 'Wrong data type. If we store object we must get an object back.');
        self::assertEquals($object, $result);
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

        $this->cache->set('key', $data);
        $result = $this->cache->get('key');
        self::assertTrue($data === $result, 'Binary data must survive a round trip.');
    }

    /**
     * @dataProvider validKeys
     */
    public function testSetValidKeys(string $key): void
    {
        if (isset($this->skippedTests[__FUNCTION__])) {
            $this->markTestSkipped($this->skippedTests[__FUNCTION__]);
        }

        $this->cache->set($key, 'foobar');
        self::assertEquals('foobar', $this->cache->get($key));
    }

    /**
     * @dataProvider validKeys
     */
    public function testSetMultipleValidKeys(string $key): void
    {
        if (isset($this->skippedTests[__FUNCTION__])) {
            $this->markTestSkipped($this->skippedTests[__FUNCTION__]);
        }

        $this->cache->setMultiple([$key => 'foobar']);
        $result = $this->cache->getMultiple([$key]);
        $keys   = [];
        foreach ($result as $i => $r) {
            $keys[] = $i;
            self::assertEquals($key, $i);
            self::assertEquals('foobar', $r);
        }
        self::assertSame([$key], $keys);
    }

    /**
     * @param mixed $data
     * @dataProvider validData
     */
    public function testSetValidData($data): void
    {
        if (isset($this->skippedTests[__FUNCTION__])) {
            $this->markTestSkipped($this->skippedTests[__FUNCTION__]);
        }

        $this->cache->set('key', $data);
        self::assertEquals($data, $this->cache->get('key'));
    }

    /**
     * @param mixed $data
     * @dataProvider validData
     */
    public function testSetMultipleValidData($data): void
    {
        if (isset($this->skippedTests[__FUNCTION__])) {
            $this->markTestSkipped($this->skippedTests[__FUNCTION__]);
        }

        $this->cache->setMultiple(['key' => $data]);
        $result = $this->cache->getMultiple(['key']);
        $keys   = [];
        foreach ($result as $i => $r) {
            $keys[] = $i;
            self::assertEquals($data, $r);
        }
        self::assertSame(['key'], $keys);
    }

    public function testObjectAsDefaultValue(): void
    {
        if (isset($this->skippedTests[__FUNCTION__])) {
            $this->markTestSkipped($this->skippedTests[__FUNCTION__]);
        }

        $obj      = new stdClass();
        $obj->foo = 'value';
        self::assertEquals($obj, $this->cache->get('key', $obj));
    }

    public function testObjectDoesNotChangeInCache(): void
    {
        if (isset($this->skippedTests[__FUNCTION__])) {
            $this->markTestSkipped($this->skippedTests[__FUNCTION__]);
        }

        $obj      = new stdClass();
        $obj->foo = 'value';
        $this->cache->set('key', $obj);
        $obj->foo = 'changed';

        $cacheObject = $this->cache->get('key');
        self::assertIsObject($cacheObject);
        self::assertEquals('value', $cacheObject->foo, 'Object in cache should not have their values changed.');
    }
}
