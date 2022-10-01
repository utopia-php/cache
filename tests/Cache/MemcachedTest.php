<?php

namespace Utopia\Tests;

use Memcached as Memcached;
use Utopia\Cache\Cache;
use Utopia\Cache\Adapter\Memcached as MemcachedAdapter;
use Utopia\Tests\Base;

class MemcachedTest extends Base
{
    public static function setUpBeforeClass(): void
    {
        $mc = new Memcached();
        $mc->addServer("memcached", 11211);

        self::$cache = new Cache(new MemcachedAdapter($mc));
    }

    public static function tearDownAfterClass(): void
    {
        self::$cache::setCaseSensitivity(false);
        self::$cache = null;
    }
}
