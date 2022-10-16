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
        self::$cache = null;
    }

    public function testEmptyCacheKey()
    {
        self::$cache->purge($this->key);

        $data = self::$cache->load($this->key, 60 * 60 * 24 * 30 * 3 /* 3 months */);

        $this->assertEquals(false, $data);
    }

    public function testCacheSave()
    {
        $result = self::$cache->save($this->key, $this->data);

        $this->assertEquals(false, $result);
    }

    /**
     * @depends testCacheSave
     */
    public function testCacheLoad()
    {
        $data = self::$cache->load($this->key, 60 * 60 * 24 * 30 * 3 /* 3 months */);

        $this->assertEquals(false, $data);
    }

    /**
     * @depends testCacheLoad
     */
    public function testNotEmptyCacheKey()
    {
        $data = self::$cache->load($this->key, 60 * 60 * 24 * 30 * 3 /* 3 months */);

        $this->assertEquals(false, $data);
    }

    public function testCachePurge()
    {
        $result = self::$cache->purge($this->key);

        $this->assertEquals(true, $result);
    }

    public function testCaseInsensitivity()
    {
        // None adapter does not expect case sensitivity/insensitivy
        $this->assertEquals(true, true);
    }
}
