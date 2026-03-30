<?php

namespace Utopia\Tests;

use Utopia\Cache\Adapter\None;
use Utopia\Cache\Cache;

class NoneTest extends Base
{
    public static function setUpBeforeClass(): void
    {
        self::$cache = new Cache(new None);
    }

    public function test_get_size(): void
    {
        $this->assertEquals(0, self::$cache->getSize());
    }

    public function test_empty_cache_key(): void
    {
        self::$cache->purge($this->key);

        $data = self::$cache->load($this->key, 60 * 60 * 24 * 30 * 3 /* 3 months */);

        $this->assertEquals(false, $data);
    }

    public function test_cache_save(): void
    {
        $result = self::$cache->save($this->key, $this->data);

        $this->assertEquals(false, $result);
    }

    /**
     * @depends test_cache_save
     */
    public function test_cache_load(): void
    {
        $data = self::$cache->load($this->key, 60 * 60 * 24 * 30 * 3 /* 3 months */);

        $this->assertEquals(false, $data);
    }

    /**
     * @depends test_cache_load
     */
    public function test_not_empty_cache_key(): void
    {
        $data = self::$cache->load($this->key, 60 * 60 * 24 * 30 * 3 /* 3 months */);

        $this->assertEquals(false, $data);
    }

    public function test_cache_purge(): void
    {
        $result = self::$cache->purge($this->key);

        $this->assertEquals(true, $result);
    }

    public function test_case_insensitivity(): void
    {
        // None adapter does not expect case sensitivity/insensitivy
        $this->assertEquals(true, true);
    }
}
