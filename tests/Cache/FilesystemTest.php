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

    public function testTouchDoesNotModifyFileContent(): void
    {
        $key = 'touch-test';
        $data = 'original-data';

        // Save initial data
        self::$cache->save($key, $data);

        $filePath = __DIR__.'/tests/data/'.$key;
        $this->assertFileExists($filePath);

        $contentBefore = file_get_contents($filePath);
        $mtimeBefore = filemtime($filePath);

        // Wait so that mtime can be updated
        sleep(1);

        // Load should trigger touch() inside the adapter
        $loaded = self::$cache->load($key, 10);
        $this->assertEquals($data, $loaded);

        $contentAfter = file_get_contents($filePath);
        $mtimeAfter = filemtime($filePath);

        // Ensure payload stays the same
        $this->assertEquals($contentBefore, $contentAfter);
        // Ensure modification time has advanced
        $this->assertGreaterThan($mtimeBefore, $mtimeAfter);
    }

    public function testSlidingTTL(): void
    {
        $ttl = 2; // seconds
        $key = 'sliding-ttl-test';
        $data = 'slide';

        self::$cache->save($key, $data);

        // After 1 second the cache is still valid and touch() will extend it
        sleep(1);
        $this->assertEquals($data, self::$cache->load($key, $ttl));

        // Another second – still within the refreshed TTL
        sleep(1);
        $this->assertEquals($data, self::$cache->load($key, $ttl));

        // Wait past TTL without accessing – now it should expire
        sleep(2);
        $this->assertFalse(self::$cache->load($key, $ttl));
    }

    public function testTTLExpiresWithoutAccess(): void
    {
        $ttl = 1; // second
        $key = 'expire-without-access';
        $data = 'data';

        self::$cache->save($key, $data);

        // Do not access within TTL window
        sleep(2);
        $this->assertFalse(self::$cache->load($key, $ttl));
    }
}
