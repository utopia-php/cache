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

    public function testStreamingLoad(): void
    {
        $path = __DIR__.'/tests/stream-data';

        try {
            if (! file_exists($path)) {
                mkdir($path, 0777, true);
            }

            $cache = new Cache(new Filesystem($path, true));
            $cache->save('stream-test', 'stream data');

            $stream = $cache->load('stream-test', 60);

            $this->assertTrue(is_resource($stream));
            $this->assertEquals('stream data', stream_get_contents($stream));

            fclose($stream);
        } finally {
            $this->deletePath($path);
        }
    }

    public function testStreamingLoadMissingKey(): void
    {
        $path = __DIR__.'/tests/stream-missing-data';

        try {
            if (! file_exists($path)) {
                mkdir($path, 0777, true);
            }

            $cache = new Cache(new Filesystem($path, true));

            $this->assertEquals(false, $cache->load('missing-stream-test', 60));
        } finally {
            $this->deletePath($path);
        }
    }

    private function deletePath(string $path): void
    {
        if (! file_exists($path)) {
            return;
        }

        if (is_file($path)) {
            unlink($path);

            return;
        }

        $files = glob($path.'/*');

        if ($files !== false) {
            foreach ($files as $file) {
                $this->deletePath($file);
            }
        }

        rmdir($path);
    }
}
