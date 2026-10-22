<?php

namespace Tests\Unit;

use App\Support\StreamPump;
use GuzzleHttp\Psr7\Utils;
use PHPUnit\Framework\TestCase;

class StreamPumpTest extends TestCase
{
    protected function tearDown(): void
    {
        StreamPump::$abortedResolver = null;

        parent::tearDown();
    }

    public function test_copy_resource_to_copies_exact_bytes(): void
    {
        $canary = 'CANARY-TEST-RESOURCE-PUMP-'.str_repeat('x', 20000);
        $source = fopen('php://memory', 'w+b');
        fwrite($source, $canary);
        rewind($source);

        $target = fopen('php://memory', 'w+b');
        StreamPump::copyResourceTo($source, $target);

        rewind($target);
        $result = stream_get_contents($target);

        fclose($source);
        fclose($target);

        $this->assertSame($canary, $result);
    }

    public function test_copy_resource_to_stops_when_aborted(): void
    {
        StreamPump::$abortedResolver = fn () => true;

        $source = fopen('php://memory', 'w+b');
        fwrite($source, 'SHOULD-NOT-BE-WRITTEN');
        rewind($source);

        $target = fopen('php://memory', 'w+b');
        StreamPump::copyResourceTo($source, $target);

        rewind($target);
        $result = stream_get_contents($target);

        fclose($source);
        fclose($target);

        $this->assertSame('', $result);
    }

    public function test_copy_resource_to_respects_deadline(): void
    {
        $source = fopen('php://memory', 'w+b');
        fwrite($source, 'SHOULD-NOT-BE-WRITTEN-EXPIRED');
        rewind($source);

        $target = fopen('php://memory', 'w+b');
        // Limit of negative seconds reaches deadline immediately
        StreamPump::copyResourceTo($source, $target, -1.0);

        rewind($target);
        $result = stream_get_contents($target);

        fclose($source);
        fclose($target);

        $this->assertSame('', $result);
    }

    public function test_copy_psr_to_delegates_native_resource(): void
    {
        $canary = 'DELEGATED-RESOURCE-DATA';
        $source = fopen('php://memory', 'w+b');
        fwrite($source, $canary);
        rewind($source);

        $target = fopen('php://memory', 'w+b');
        StreamPump::copyPsrTo($source, $target);

        rewind($target);
        $result = stream_get_contents($target);

        fclose($source);
        fclose($target);

        $this->assertSame($canary, $result);
    }

    public function test_copy_psr_to_copies_psr7_stream(): void
    {
        $canary = 'PSR7-STREAM-CANARY-DATA-'.str_repeat('y', 20000);
        $stream = Utils::streamFor($canary);

        $target = fopen('php://memory', 'w+b');
        StreamPump::copyPsrTo($stream, $target);

        rewind($target);
        $result = stream_get_contents($target);

        fclose($target);

        $this->assertSame($canary, $result);
    }
}
