<?php

namespace Utopia\Tests;

use Utopia\Cache\Adapter\Memory;
use Utopia\Cache\Cache;

class MemoryTest extends Base
{
    public static function setUpBeforeClass(): void
    {
        self::$cache = new Cache(new Memory());
    }

    public function testGetSize(): void
    {
        self::$cache->save('test:file33', 'file33');
        self::$cache->save('test:file34', 'file34');
        self::$cache->save('test:file35', 'file35');
        self::$cache->save('test:file36', 'file36');
        $this->assertEquals(4, self::$cache->getSize());
    }

    public function testTouchExpiredEntry(): void
    {
        $adapter = new Memory();
        $cache = new Cache($adapter);

        $cache->save('expired-touch-key', 'expired data');
        /** @var array{time: int, data: string} $stored */
        $stored = $adapter->store['expired-touch-key'];
        $stored['time'] = time() - 10;
        $adapter->store['expired-touch-key'] = $stored;

        $this->assertEquals(false, $cache->load('expired-touch-key', 1));
        $this->assertEquals(true, $cache->touch('expired-touch-key'));
        $this->assertEquals('expired data', $cache->load('expired-touch-key', 1));
    }
}
