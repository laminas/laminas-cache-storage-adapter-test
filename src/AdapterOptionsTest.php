<?php

namespace LaminasTest\Cache\Storage\Adapter;

use Laminas\Cache\Exception;
use Laminas\Cache\Storage\Adapter\AbstractAdapter;
use Laminas\Cache\Storage\Adapter\AdapterOptions;
use Laminas\Cache\Storage\Event;
use PHPUnit\Framework\TestCase;
use ReflectionObject;

use function func_get_args;

/**
 * @group      Laminas_Cache
 * @covers Laminas\Cache\Storage\Adapter\AdapterOptions<extended>
 */
class AdapterOptionsTest extends TestCase
{
    /** @var AbstractAdapter */
    protected $storage;

    /** @var AdapterOptions */
    protected $options;

    public function setUp(): void
    {
        $this->options = new AdapterOptions();
    }

    public function testKeyPattern(): void
    {
        // test default value
        $this->assertSame('', $this->options->getKeyPattern());

        $this->assertSame($this->options, $this->options->setKeyPattern('/./'));
        $this->assertSame('/./', $this->options->getKeyPattern());
    }

    public function testSetKeyPatternAllowEmptyString(): void
    {
        // first change to something different as an empty string is the default
        $this->options->setKeyPattern('/.*/');

        $this->options->setKeyPattern('');
        $this->assertSame('', $this->options->getKeyPattern());
    }

    public function testSetKeyPatternThrowsInvalidArgumentExceptionOnInvalidPattern(): void
    {
        $this->expectException(Exception\InvalidArgumentException::class);
        $this->options->setKeyPattern('foo bar');
    }

    public function testNamespace(): void
    {
        $this->assertSame($this->options, $this->options->setNamespace('foobar'));
        $this->assertSame('foobar', $this->options->getNamespace());
    }

    public function testReadable(): void
    {
        $this->assertSame($this->options, $this->options->setReadable(false));
        $this->assertSame(false, $this->options->getReadable());

        $this->assertSame($this->options, $this->options->setReadable(true));
        $this->assertSame(true, $this->options->getReadable());
    }

    public function testWritable(): void
    {
        $this->assertSame($this->options, $this->options->setWritable(false));
        $this->assertSame(false, $this->options->getWritable());

        $this->assertSame($this->options, $this->options->setWritable(true));
        $this->assertSame(true, $this->options->getWritable());
    }

    public function testTtl(): void
    {
        // infinite default value
        $this->assertSame(0, $this->options->getTtl());

        $this->assertSame($this->options, $this->options->setTtl(12345));
        $this->assertSame(12345, $this->options->getTtl());
    }

    public function testSetTtlThrowsInvalidArgumentExceptionOnNegativeValue(): void
    {
        $this->expectException(Exception\InvalidArgumentException::class);
        $this->options->setTtl(-1);
    }

    public function testSetTtlAutoconvertToIntIfPossible(): void
    {
        $this->options->setTtl(12345.0);
        $this->assertSame(12345, $this->options->getTtl());

        $this->options->setTtl(12345.678);
        $this->assertSame(12345.678, $this->options->getTtl());
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
        $this->assertCount(1, $calledArgs, '"option" event was not triggered or got a wrong number of arguments');
        $this->assertInstanceOf(Event::class, $calledArgs[0]);
        $this->assertEquals(['writable' => false], $calledArgs[0]->getParams()->getArrayCopy());
    }

    public function testSetFromArrayWithoutPrioritizedOptions(): void
    {
        $this->assertSame($this->options, $this->options->setFromArray([
            'kEy_pattERN' => '/./',
            'nameSPACE'   => 'foobar',
        ]));
        $this->assertSame('/./', $this->options->getKeyPattern());
        $this->assertSame('foobar', $this->options->getNamespace());
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
        $this->assertSame($options, $options->setFromArray([
            'nAmeSpace'   => 'foobar',
            'WriTAble'    => false,
            'KEY_paTTern' => '/./',
        ]));
    }
}
