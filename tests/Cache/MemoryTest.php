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

    public static function tearDownAfterClass(): void
    {
        self::$cache::setCaseSensitivity(false);
        self::$cache = null;
    }
}
