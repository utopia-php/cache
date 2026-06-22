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

        $this->assertNotFalse($result);

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

    public function testCacheMissSaveIsFenced(): void
    {
        self::$cache->purge('fenced-key', 'fenced-key');

        $this->assertFalse(self::$cache->load('fenced-key', 60, 'fenced-key'));
        $this->assertEquals('fresh data', self::$cache->save('fenced-key', 'fresh data', 'fenced-key'));
        $this->assertEquals('fresh data', self::$cache->load('fenced-key', 60, 'fenced-key'));

        self::$cache->purge('fenced-key', 'fenced-key');
    }

    public function testPurgedCacheMissDoesNotSave(): void
    {
        self::$cache->purge('purged-miss-key', 'purged-miss-key');

        $this->assertFalse(self::$cache->load('purged-miss-key', 60, 'purged-miss-key'));
        self::$cache->purge('purged-miss-key', 'purged-miss-key');

        $this->assertFalse(self::$cache->save('purged-miss-key', 'stale data', 'purged-miss-key'));
        $this->assertFalse(self::$cache->load('purged-miss-key', 60, 'purged-miss-key'));
    }

    public function testExpiredCacheDoesNotBlockFencedSave(): void
    {
        self::$cache->purge('expired-fence-key', 'expired-fence-key');
        self::$cache->save('expired-fence-key', 'expired data', 'expired-fence-key');
        $this->assertFalse(self::$cache->load('expired-fence-key', 0, 'expired-fence-key'));

        $this->assertEquals('fresh data', self::$cache->save('expired-fence-key', 'fresh data', 'expired-fence-key'));
        $this->assertEquals('fresh data', self::$cache->load('expired-fence-key', 60, 'expired-fence-key'));

        self::$cache->purge('expired-fence-key', 'expired-fence-key');
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
        $this->assertNotFalse($result);

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
        $this->assertNotFalse($result);
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
