<?php

namespace Utopia\Tests;

use Utopia\Cache\Adapter\Filesystem;
use Utopia\Cache\Cache;

class FilesystemTest extends Base
{
    public static function setUpBeforeClass(): void
    {
        $path = __DIR__.'/tests/data';
        if (! file_exists($path)) {
            mkdir($path, 0777, true);
        }

        self::$cache = new Cache(new Filesystem($path));
    }

    public function testGetSize(): void
    {
       self::$cache->save('test', 'test');
       $this->assertEquals(4, self::$cache->getSize());
    }

    public static function tearDownAfterClass(): void
    {
        self::$cache::setCaseSensitivity(false);
        // @phpstan-ignore-next-line
        self::$cache = null;
    }
}
