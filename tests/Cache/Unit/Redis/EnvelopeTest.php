<?php

namespace Utopia\Tests\Unit\Redis;

use PHPUnit\Framework\TestCase;
use Utopia\Cache\Adapter\Redis\Envelope;

class EnvelopeTest extends TestCase
{
    public function testEncodeWrapsDataAndTime(): void
    {
        $encoded = Envelope::encode('hello', 1700000000);
        $this->assertSame('{"time":1700000000,"data":"hello"}', $encoded);
    }

    public function testEncodeArrayPayload(): void
    {
        $encoded = Envelope::encode(['a' => 1], 42);
        $this->assertSame('{"time":42,"data":{"a":1}}', $encoded);
    }

    public function testDecodeReturnsDataWhenFresh(): void
    {
        $encoded = Envelope::encode(['x' => 1], 100);
        $this->assertSame(['x' => 1], Envelope::decode($encoded, ttl: 60, now: 130));
    }

    public function testDecodeReturnsFalseWhenStale(): void
    {
        $encoded = Envelope::encode('value', 100);
        // 100 + 60 = 160; now = 161 → stale
        $this->assertFalse(Envelope::decode($encoded, ttl: 60, now: 161));
    }

    public function testDecodeBoundaryIsExclusive(): void
    {
        $encoded = Envelope::encode('value', 100);
        // time + ttl > now means strictly greater; equal counts as stale
        $this->assertFalse(Envelope::decode($encoded, ttl: 60, now: 160));
        $this->assertSame('value', Envelope::decode($encoded, ttl: 60, now: 159));
    }

    public function testDecodeTreatsMalformedJsonAsMiss(): void
    {
        $this->assertFalse(Envelope::decode('not json', 60, 0));
        $this->assertFalse(Envelope::decode('', 60, 0));
        $this->assertFalse(Envelope::decode('null', 60, 0));
    }

    public function testDecodeRejectsMissingFields(): void
    {
        $this->assertFalse(Envelope::decode('{"time":100}', 60, 0));
        $this->assertFalse(Envelope::decode('{"data":"x"}', 60, 0));
        $this->assertFalse(Envelope::decode('{}', 60, 0));
    }

    public function testDecodeRejectsNonIntegerTime(): void
    {
        $this->assertFalse(Envelope::decode('{"time":"100","data":"x"}', 60, 0));
        $this->assertFalse(Envelope::decode('{"time":1.5,"data":"x"}', 60, 0));
    }

    public function testDecodePreservesNullData(): void
    {
        // isset() rejects null, so null-data envelopes are treated as a miss.
        // This matches existing adapter behavior; documenting via test.
        $this->assertFalse(Envelope::decode('{"time":100,"data":null}', 60, 130));
    }

    public function testDecodePreservesNestedArrayData(): void
    {
        $data = ['a' => ['b' => ['c' => 'deep']], 'list' => [1, 2, 3]];
        $encoded = Envelope::encode($data, 100);
        $this->assertSame($data, Envelope::decode($encoded, 60, 130));
    }

    public function testTouchRewritesTime(): void
    {
        $encoded = Envelope::encode('value', 100);
        $touched = Envelope::touch($encoded, 200);

        $this->assertIsString($touched);
        $this->assertSame('value', Envelope::decode($touched, 60, 250));
        // Original timestamp would have made this stale
        $this->assertFalse(Envelope::decode($encoded, 60, 250));
    }

    public function testTouchPreservesArrayData(): void
    {
        $data = ['x' => 1, 'y' => [2, 3]];
        $encoded = Envelope::encode($data, 100);
        $touched = Envelope::touch($encoded, 200);

        $this->assertIsString($touched);
        $this->assertSame($data, Envelope::decode($touched, 60, 230));
    }

    public function testTouchReturnsFalseOnMalformedJson(): void
    {
        $this->assertFalse(Envelope::touch('not json', 200));
    }

    public function testTouchReturnsFalseWhenDataKeyMissing(): void
    {
        $this->assertFalse(Envelope::touch('{"time":100}', 200));
        $this->assertFalse(Envelope::touch('{}', 200));
    }

    public function testTouchAcceptsEnvelopeWithoutPriorTimeField(): void
    {
        // touch() only requires a 'data' key — re-stamping is the whole point.
        $touched = Envelope::touch('{"data":"x"}', 200);
        $this->assertIsString($touched);
        $this->assertSame('x', Envelope::decode($touched, 60, 230));
    }
}
