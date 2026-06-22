<?php

namespace Utopia\Tests\E2E;

use Utopia\Cache\Adapter\None;
use Utopia\Cache\Cache;

class NoneTest extends Base
{
    public static function setUpBeforeClass(): void
    {
        self::$cache = new Cache(new None());
    }

    public function testGetSize(): void
    {
        $this->assertEquals(0, self::$cache->getSize());
    }

    public function testEmptyCacheKey(): void
    {
        self::$cache->purge($this->key);

        $data = self::$cache->load($this->key, 60 * 60 * 24 * 30 * 3 /* 3 months */);

        $this->assertEquals(false, $data);
    }

    public function testCacheSave(): void
    {
        $result = self::$cache->save($this->key, $this->data);

        $this->assertEquals(false, $result);
    }

    /**
     * @depends testCacheSave
     */
    public function testCacheLoad(): void
    {
        $data = self::$cache->load($this->key, 60 * 60 * 24 * 30 * 3 /* 3 months */);

        $this->assertEquals(false, $data);
    }

    /**
     * @depends testCacheLoad
     */
    public function testNotEmptyCacheKey(): void
    {
        $data = self::$cache->load($this->key, 60 * 60 * 24 * 30 * 3 /* 3 months */);

        $this->assertEquals(false, $data);
    }

    public function testCachePurge(): void
    {
        $result = self::$cache->purge($this->key);

        $this->assertNotFalse($result);
    }

    public function testCacheTouch(): void
    {
        $this->assertEquals(false, self::$cache->touch($this->key));
    }

    public function testCacheMissSaveIsFenced(): void
    {
        $this->assertFalse(self::$cache->load('fenced-key', 60, 'fenced-key'));
        $this->assertFalse(self::$cache->save('fenced-key', 'fresh data', 'fenced-key'));
        $this->assertFalse(self::$cache->load('fenced-key', 60, 'fenced-key'));
    }

    public function testExpiredCacheDoesNotBlockFencedSave(): void
    {
        $this->assertFalse(self::$cache->save('expired-fence-key', 'expired data', 'expired-fence-key'));
        $this->assertFalse(self::$cache->load('expired-fence-key', 0, 'expired-fence-key'));
        $this->assertFalse(self::$cache->save('expired-fence-key', 'fresh data', 'expired-fence-key'));
        $this->assertFalse(self::$cache->load('expired-fence-key', 60, 'expired-fence-key'));
    }

    public function testCaseInsensitivity(): void
    {
        // None adapter does not expect case sensitivity/insensitivy
        $this->assertEquals(true, true);
    }
}
