<?php

namespace Utopia\Tests;

use Utopia\Cache\Adapter\None;
use Utopia\Cache\Cache;

class NoneTest extends Base
{
    public static function setUpBeforeClass(): void
    {
        self::$cache = new Cache(new None());
    }

    public static function tearDownAfterClass(): void
    {
        self::$cache::setCaseSensitivity(false);
        // @phpstan-ignore-next-line
        self::$cache = null;
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

        $this->assertEquals(true, $result);
    }

    public function testCaseInsensitivity(): void
    {
        // None adapter does not expect case sensitivity/insensitivy
        $this->assertEquals(true, true);
    }
}
