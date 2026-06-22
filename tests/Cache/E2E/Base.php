<?php

namespace Utopia\Tests\E2E;

use PHPUnit\Framework\TestCase;
use Utopia\Cache\Cache;

abstract class Base extends TestCase
{
    protected static Cache $cache;

    protected string $key = 'test-key-for-cache';

    protected string $data = 'test data string';

    /**
     * @var array<string>
     */
    protected array $dataArray = ['test', 'data', 'string'];

    /**
     * General tests
     * Can be overwritten in a specific adapter if required, such as None cache
     */
    public function testCacheSave(): void
    {
        // test $data array
        $result = self::$cache->save($this->key, $this->dataArray, $this->key);

        $this->assertEquals($this->dataArray, $result);

        // test $data string
        $result = self::$cache->save($this->key, $this->data, $this->key);

        $this->assertEquals($this->data, $result);
    }

    public function testNotEmptyCacheKey(): void
    {
        $data = self::$cache->load($this->key, 60 * 60 * 24 * 30 * 3 /* 3 months */, $this->key);

        $this->assertEquals($this->data, $data);
    }

    public function testCachePurge(): void
    {
        $data = self::$cache->load($this->key, 60 * 60 * 24 * 30 * 3 /* 3 months */, $this->key);

        $this->assertEquals($this->data, $data);

        $result = self::$cache->purge($this->key);

        $this->assertEquals(true, $result);

        $data = self::$cache->load($this->key, 60 * 60 * 24 * 30 * 3 /* 3 months */, $this->key);

        $this->assertEquals(false, $data);
    }

    public function testCacheTouch(): void
    {
        $result = self::$cache->save('touch-key', 'touch data', 'touch-key');
        $this->assertEquals('touch data', $result);

        sleep(3);

        $this->assertEquals(false, self::$cache->load('touch-key', 2, 'touch-key'));
        $this->assertEquals(true, self::$cache->touch('touch-key', 'touch-key'));
        $this->assertEquals('touch data', self::$cache->load('touch-key', 2, 'touch-key'));
        $this->assertEquals(false, self::$cache->touch('missing-touch-key', 'missing-touch-key'));

        self::$cache->purge('touch-key');
    }

    public function testCacheLease(): void
    {
        self::$cache->purge('lease-key', 'lease-key');

        $token = self::$cache->lease('lease-key', 'lease-key');
        if ($token === false) {
            $this->assertFalse(self::$cache->saveLease('lease-key', 'leased data', 'missing-token', 'lease-key'));

            return;
        }

        $this->assertFalse(self::$cache->load('lease-key', 60, 'lease-key'));
        $this->assertFalse(self::$cache->lease('lease-key', 'lease-key'));
        $this->assertFalse(self::$cache->saveLease('lease-key', 'leased data', 'missing-token', 'lease-key'));
        $this->assertEquals('leased data', self::$cache->saveLease('lease-key', 'leased data', $token, 'lease-key'));
        $this->assertEquals('leased data', self::$cache->load('lease-key', 60, 'lease-key'));
        $this->assertFalse(self::$cache->lease('lease-key', 'lease-key'));

        self::$cache->purge('lease-key', 'lease-key');
    }

    public function testPurgedCacheLeaseDoesNotSave(): void
    {
        self::$cache->purge('purged-lease-key', 'purged-lease-key');

        $token = self::$cache->lease('purged-lease-key', 'purged-lease-key');
        if ($token === false) {
            $this->assertFalse(self::$cache->saveLease('purged-lease-key', 'leased data', 'missing-token', 'purged-lease-key'));

            return;
        }

        self::$cache->purge('purged-lease-key', 'purged-lease-key');

        $this->assertFalse(self::$cache->saveLease('purged-lease-key', 'leased data', $token, 'purged-lease-key'));
        $this->assertFalse(self::$cache->load('purged-lease-key', 60, 'purged-lease-key'));
    }

    public function testExpiredCacheDoesNotBlockLease(): void
    {
        self::$cache->purge('expired-lease-key', 'expired-lease-key');
        self::$cache->save('expired-lease-key', 'expired data', 'expired-lease-key');
        $this->assertFalse(self::$cache->load('expired-lease-key', 0, 'expired-lease-key'));

        $token = self::$cache->lease('expired-lease-key', 'expired-lease-key', 0);
        if ($token === false) {
            $this->assertFalse(self::$cache->saveLease('expired-lease-key', 'leased data', 'missing-token', 'expired-lease-key'));

            return;
        }

        $this->assertEquals('leased data', self::$cache->saveLease('expired-lease-key', 'leased data', $token, 'expired-lease-key'));
        $this->assertEquals('leased data', self::$cache->load('expired-lease-key', 60, 'expired-lease-key'));

        self::$cache->purge('expired-lease-key', 'expired-lease-key');
    }

    public function testCaseInsensitivity(): void
    {
        // Ensure case in-sensitivity first (configured in adapter's setUp)
        $data = self::$cache->save('planet', 'Earth', 'planet');
        $this->assertEquals('Earth', $data);

        $data = self::$cache->load('planet', 60 * 60 * 24 * 30 * 3 /* 3 months */, 'planet');
        $this->assertEquals('Earth', $data);
        $data = self::$cache->load('PLANET', 60 * 60 * 24 * 30 * 3 /* 3 months */, 'PLANET');
        $this->assertEquals('Earth', $data);
        $data = self::$cache->load('PlAnEt', 60 * 60 * 24 * 30 * 3 /* 3 months */, 'PlAnEt');
        $this->assertEquals('Earth', $data);

        $result = self::$cache->purge('PLaNEt');
        $this->assertEquals(true, $result);

        $data = self::$cache->load('planet', 60 * 60 * 24 * 30 * 3 /* 3 months */, 'planet');
        $this->assertEquals(false, $data);
        $data = self::$cache->load('PLANET', 60 * 60 * 24 * 30 * 3 /* 3 months */, 'PLANET');
        $this->assertEquals(false, $data);

        // Test case sensitivity
        self::$cache->setCaseSensitivity(true);

        $data = self::$cache->save('color', 'pink', 'color');
        $this->assertEquals('pink', $data);
        $data = self::$cache->load('color', 60 * 60 * 24 * 30 * 3 /* 3 months */, 'color');
        $this->assertEquals('pink', $data);
        $data = self::$cache->load('COLOR', 60 * 60 * 24 * 30 * 3 /* 3 months */, 'COLOR');
        $this->assertEquals(false, $data);

        $result = self::$cache->purge('color');
        $this->assertEquals(true, $result);
    }

    public function testPing(): void
    {
        $this->assertEquals(true, self::$cache->ping());
    }

    public function testFlush(): void
    {
        $result1 = self::$cache->save('x', 'x', 'x');
        $result2 = self::$cache->save('y', 'y', 'y');

        $this->assertEquals($result1, self::$cache->load('x', 100, 'x'));
        $this->assertEquals($result2, self::$cache->load('y', 100, 'y'));

        // test $data string
        $result = self::$cache->flush();

        $this->assertEquals(true, $result);
        $this->assertEquals(false, self::$cache->load('x', 100, 'x'));
        $this->assertEquals(false, self::$cache->load('y', 100, 'y'));
    }
}
