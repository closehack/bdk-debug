<?php

namespace bdk\DebugTests\Psr7lite;

use bdk\Debug\Psr7lite\Message;
use bdk\Debug\Psr7lite\Stream;
use PHPUnit\Framework\TestCase;
use ReflectionObject;
use stdClass;

/**
 *
 */
class MessageTest extends TestCase
{
    use \bdk\DebugTests\PolyFill\AssertionTrait;
    use \bdk\DebugTests\PolyFill\ExpectExceptionTrait;

    public function testConstruct()
    {
        $message = new Message();
        $this->assertTrue($message instanceof Message);
    }

    public function testGetMethods()
    {
        $message = $this->testSetHeaders();
        $this->assertSame('1.1', $message->getProtocolVersion());
        $this->assertEquals(['Mozilla/5.0 (Windows NT 10.0; Win64; x64)'], $message->getHeader('user-agent'));
        $this->assertEquals([], $message->getHeader('header-not-exists'));
        $this->assertEquals('Mozilla/5.0 (Windows NT 10.0; Win64; x64)', $message->getHeaderLine('user-agent'));
        // Test - has
        $this->assertTrue($message->hasHeader('user-agent'));
    }

    public function testWithMethods()
    {
        $message = $this->testSetHeaders();
        $newMessage = $message->withProtocolVersion('2.0')->withHeader('hello-world', 'ok');
        $this->assertSame('2.0', $newMessage->getProtocolVersion());
        $this->assertEquals(['ok'], $newMessage->getHeader('hello-world'));
        $new2Message = $newMessage
            ->withAddedHeader('hello-world', 'not-ok')
            ->withAddedHeader('foo-bar', 'okok')
            ->withAddedHeader('others', 2)
            ->withAddedHeader('others', 6.4);
        $this->assertEquals(['ok', 'not-ok'], $new2Message->getHeader('hello-world'));
        $this->assertEquals(['okok'], $new2Message->getHeader('foo-bar'));
        $this->assertEquals(['2', '6.4'], $new2Message->getHeader('others'));
        // Test - without
        $new3Message = $new2Message->withoutHeader('hello-world');
        $this->assertFalse($new3Message->hasHeader('hello-world'));
    }

    public function testBodyMethods()
    {
        $resource = \fopen(TEST_DIR . '/assets/logo.png', 'r+');
        $stream = new Stream($resource);
        $message = new Message();
        $newMessage = $message->withBody($stream);
        $this->assertEquals($stream, $newMessage->getBody());
    }

    public function testSetHeaders()
    {
        $message = new Message();
        $testArray = [
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
            'Custom-Value' => '1234',
        ];
        $expectedArray = [
            'User-Agent' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64)'],
            'Custom-Value' => ['1234'],
        ];
        $reflection = new ReflectionObject($message);
        $setHeaders = $reflection->getMethod('setHeaders');
        $setHeaders->setAccessible(true);
        $setHeaders->invokeArgs($message, [$testArray]);
        $this->assertEquals($expectedArray, $message->getHeaders());
        $this->assertTrue($message instanceof Message);
        return $message;
    }

    public function testWithAddedHeaderArrayValueAndKeys()
    {
        $message = new Message();
        $message = $message->withAddedHeader('content-type', [
            'foo' => 'text/html',
        ]);
        $message = $message->withAddedHeader('content-type', [
            'foo' => 'text/plain',
            'bar' => 'application/json',
        ]);

        $headerLine = $message->getHeaderLine('content-type');
        $this->assertStringContainsString('text/html', $headerLine);
        $this->assertStringContainsString('text/plain', $headerLine);
        $this->assertStringContainsString('application/json', $headerLine);

        $message = $message->withAddedHeader('foo', '');
        $headerLine = $message->getHeaderLine('foo');
        $this->assertSame('', $headerLine);
    }

    /*
        Exceptions
    */

    public function testExceptionHeaderName()
    {
        $this->expectException('InvalidArgumentException');
        $message = new Message();
        // Exception => "hello-wo)rld" is not valid header name, it must be an RFC 7230 compatible string.
        $message->withHeader('hello-wo)rld', 'ok');
    }

    public function testExceptionHeaderName2()
    {
        $this->expectException('InvalidArgumentException');
        $message = new Message();
        // Exception => "hello-wo)rld" is not valid header name, it must be an RFC 7230 compatible string.
        $message->withHeader(['test'], 'ok');
    }

    public function testExceptionHeaderValueBooolean()
    {
        $this->expectException('InvalidArgumentException');
        $message = new Message();
        // Exception => The header field value only accepts string and array, but "boolean" provided.
        $message->withHeader('hello-world', false);
    }

    public function testExceptioneaderValueNull()
    {
        $this->expectException('InvalidArgumentException');
        $message = new Message();
        // Exception => The header field value only accepts string and array, but "NULL" provided.
        $message->withHeader('hello-world', null);
    }

    public function testExceptionHeaderValueObject()
    {
        $this->expectException('InvalidArgumentException');
        $message = new Message();
        $mockObject = new stdClass();
        $mockObject->test = 1;
        // Exception => The header field value only accepts string and array, but "object" provided.
        $message->withHeader('hello-world', $mockObject);
    }

    public function testExceptionHeaderValueArray()
    {
        $this->expectException('InvalidArgumentException');
        // An invalid type is inside the array.
        $testArr = array(
            'test',
            true
        );
        $message = new Message();
        // Exception => The header values only accept string and number, but "boolean" provided.
        $message->withHeader('hello-world', $testArr);
    }

    public function testExceptionHeaderValueInvalidString()
    {
        $this->expectException('InvalidArgumentException');
        $message = new Message();
        // Exception => "This string contains many invisible spaces." is not valid header
        //    value, it must contains visible ASCII characters only.
        $message->withHeader('hello-world', 'This string contains many invisible spaces.');
    }
}
