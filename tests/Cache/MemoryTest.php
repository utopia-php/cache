<?php

namespace Utopia\Tests;

use Utopia\Cache\Cache;
use Utopia\Cache\Adapter\Memory;
use Utopia\Tests\Base;

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
