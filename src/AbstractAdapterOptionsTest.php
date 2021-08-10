<?php

declare(strict_types=1);

namespace LaminasTest\Cache\Storage\Adapter;

use ArrayObject;
use Laminas\Cache\Exception;
use Laminas\Cache\Storage\Adapter\AbstractAdapter;
use Laminas\Cache\Storage\Adapter\AdapterOptions;
use Laminas\Cache\Storage\Event;
use PHPUnit\Framework\TestCase;
use ReflectionObject;

use function func_get_args;

/**
 * @template TOptions of AdapterOptions
 */
abstract class AbstractAdapterOptionsTest extends TestCase
{
    /**
     * @var AdapterOptions
     * @psalm-var TOptions
     */
    protected $options;

    protected function setUp(): void
    {
        $this->options = $this->createAdapterOptions();
        parent::setUp();
    }

    /**
     * @psalm-return TOptions
     */
    abstract protected function createAdapterOptions(): AdapterOptions;

    public function testKeyPattern(): void
    {
        // test default value
        self::assertSame('', $this->options->getKeyPattern());

        self::assertSame($this->options, $this->options->setKeyPattern('/./'));
        self::assertSame('/./', $this->options->getKeyPattern());
    }

    public function testSetKeyPatternAllowEmptyString(): void
    {
        // first change to something different as an empty string is the default
        $this->options->setKeyPattern('/.*/');

        $this->options->setKeyPattern('');
        self::assertSame('', $this->options->getKeyPattern());
    }

    public function testSetKeyPatternThrowsInvalidArgumentExceptionOnInvalidPattern(): void
    {
        $this->expectException(Exception\InvalidArgumentException::class);
        $this->options->setKeyPattern('foo bar');
    }

    public function testNamespace(): void
    {
        self::assertSame($this->options, $this->options->setNamespace('foobar'));
        self::assertSame('foobar', $this->options->getNamespace());
    }

    public function testReadable(): void
    {
        self::assertSame($this->options, $this->options->setReadable(false));
        self::assertSame(false, $this->options->getReadable());

        self::assertSame($this->options, $this->options->setReadable(true));
        self::assertSame(true, $this->options->getReadable());
    }

    public function testWritable(): void
    {
        self::assertSame($this->options, $this->options->setWritable(false));
        self::assertSame(false, $this->options->getWritable());

        self::assertSame($this->options, $this->options->setWritable(true));
        self::assertSame(true, $this->options->getWritable());
    }

    public function testTtl(): void
    {
        // infinite default value
        self::assertSame(0, $this->options->getTtl());

        self::assertSame($this->options, $this->options->setTtl(12345));
        self::assertSame(12345, $this->options->getTtl());
    }

    public function testSetTtlThrowsInvalidArgumentExceptionOnNegativeValue(): void
    {
        $this->expectException(Exception\InvalidArgumentException::class);
        $this->options->setTtl(-1);
    }

    public function testSetTtlAutoconvertToIntIfPossible(): void
    {
        $this->options->setTtl(12345.0);
        self::assertSame(12345, $this->options->getTtl());

        $this->options->setTtl(12345.678);
        self::assertSame(12345.678, $this->options->getTtl());
    }

    public function testTriggerOptionEvent(): void
    {
        // setup an adapter implements EventsCapableInterface
        $adapter = $this->getMockForAbstractClass(AbstractAdapter::class);
        $this->options->setAdapter($adapter);

        // setup event listener
        $calledArgs = null;
        $adapter->getEventManager()->attach('option', function () use (&$calledArgs) {
            $calledArgs = func_get_args();
        });

        // trigger by changing an option
        $this->options->setWritable(false);

        // assert (hopefully) called listener and arguments
        self::assertIsArray($calledArgs, '"option" event was not triggered');
        $args = $calledArgs[0];
        self::assertInstanceOf(Event::class, $args);
        $params = $args->getParams();
        self::assertInstanceOf(ArrayObject::class, $params);
        self::assertEquals(['writable' => false], $params->getArrayCopy());
    }

    public function testSetFromArrayWithoutPrioritizedOptions(): void
    {
        self::assertSame($this->options, $this->options->setFromArray([
            'kEy_pattERN' => '/./',
            'nameSPACE'   => 'foobar',
        ]));
        self::assertSame('/./', $this->options->getKeyPattern());
        self::assertSame('foobar', $this->options->getNamespace());
    }

    public function testSetFromArrayWithPrioritizedOptions(): void
    {
        $options = $this->getMockBuilder(AdapterOptions::class)
            ->onlyMethods(['setKeyPattern', 'setNamespace', 'setWritable'])
            ->getMock();

        // set key_pattern and namespace to be a prioritized options
        $optionsRef = new ReflectionObject($options);
        $propRef    = $optionsRef->getProperty('__prioritizedProperties__');
        $propRef->setAccessible(true);
        $propRef->setValue($options, ['key_pattern', 'namespace']);

        // expected order of setter be called
        $options->expects($this->any())
            ->method('setKeyPattern')
            ->with($this->equalTo('/./'));

        $options->expects($this->any())
            ->method('setNamespace')
            ->with($this->equalTo('foobar'));

        $options->expects($this->any())
            ->method('setWritable')
            ->with($this->equalTo(false));

        // send unordered options array
        self::assertSame($options, $options->setFromArray([
            'nAmeSpace'   => 'foobar',
            'WriTAble'    => false,
            'KEY_paTTern' => '/./',
        ]));
    }
}
