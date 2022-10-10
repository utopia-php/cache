<?php

namespace Utopia\Tests;

use Utopia\Cache\Cache;
use Utopia\Cache\Adapter\Filesystem;
use Utopia\Tests\Base;

class FilesystemTest extends Base
{
    public static function setUpBeforeClass(): void
    {
        self::$cache = new Cache(new Filesystem('tests/data'));
    }

    public static function tearDownAfterClass(): void
    {
        self::$cache::setCaseSensitivity(false);
        // @phpstan-ignore-next-line
        self::$cache = null;
    }
}
