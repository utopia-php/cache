<?php

namespace Utopia\Tests;

use Utopia\Cache\Adapter\Varnish as VarnishAdapter;
use Utopia\Cache\Cache;

class VarnishTest extends Base
{
    public static function setUpBeforeClass(): void
    {
        // Replace 'hostname' and 'port' with your Varnish cache server information
        $hostname = 'hostname';
        $port = 80; // Use the appropriate port number for your Varnish server

        try {
            self::$cache = new Cache(new VarnishAdapter($hostname, $port));
        } catch (Exception $e) {
            // Handle any exceptions that may occur during setup
            echo 'Exception: ' . $e->getMessage();
            self::$cache = null;
        }
    }

    public static function tearDownAfterClass(): void
    {
        // Clean up after the test class
        self::$cache::setCaseSensitivity(false);
        // @phpstan-ignore-next-line
        self::$cache = null;
    }

    public function testCacheOperations(): void
    {
        // Make sure the cache instance is properly initialized
        if (self::$cache === null) {
            $this->markTestSkipped('Cache setup failed.');
            return;
        }

        // Test cache save and load operations
        $key = 'test_key';
        $data = 'test_data';

        $saved = self::$cache->save($key, $data);
        $this->assertTrue($saved);

        $loaded = self::$cache->load($key, 60); // Assuming a TTL of 60 seconds
        $this->assertEquals($data, $loaded);

        // Test cache purge operation
        try {
            $purgedObjects = self::$cache->purge($key);
        } catch (Exception $e) {
            $this->fail($e->getMessage());
        }

        // Verify that the data is no longer available after purging
        $loadedAfterPurge = self::$cache->load($key, 60);
        $this->assertFalse($loadedAfterPurge);

        // Test cache flush operation
        try {
            $flushed = self::$cache->flush();
        } catch (Exception $e) {
            $this->fail($e->getMessage());
        }

        // Verify that all data is cleared after flushing
        $loadedAfterFlush = self::$cache->load($key, 60);
        $this->assertFalse($loadedAfterFlush);
    }
}
