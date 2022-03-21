<?php
/**
 * Utopia PHP Framework
 *
 * @package Framework
 * @subpackage Tests
 *
 * @link https://github.com/utopia-php/framework
 * @author Eldad Fux <eldad@appwrite.io>
 * @version 1.0 RC4
 * @license The MIT License (MIT) <http://www.opensource.org/licenses/mit-license.php>
 */

namespace Utopia\Tests;

use Utopia\Cache\Cache;
use Utopia\Cache\Adapter\None;
use Utopia\Tests\Base;

class NoneTest extends Base
{
    protected function setUp(): void
    {
        $this->cache = new Cache(new None());
        $this->cache::setCaseSensitivity(true);
    }

    protected function tearDown(): void
    {
        $this->cache = null;
    }

    public function testEmptyCacheKey()
    {
        $this->cache->purge($this->key);

        $data  = $this->cache->load($this->key, 60 * 60 * 24 * 30 * 3 /* 3 months */);

        $this->assertEquals(false, $data);
    }

    public function testCacheSave()
    {
        $result = $this->cache->save($this->key, $this->data);

        $this->assertEquals(false, $result);
    }
    /**
     * @depends testCacheSave
     */
    public function testCacheLoad()
    {
        $data  = $this->cache->load($this->key, 60 * 60 * 24 * 30 * 3 /* 3 months */);

        $this->assertEquals(false, $data);
    }

    public function testNotEmptyCacheKey()
    {
        $data = $this->cache->load($this->key, 60 * 60 * 24 * 30 * 3 /* 3 months */);

        $this->assertEquals(false, $data);
    }

    public function testCachePurge()
    {
        $result = $this->cache->purge($this->key);

        $this->assertEquals(true, $result);
    }

    public function testCachePurgeWildcard()
    {
        $data1 = $this->cache->save('test:file1', 'file1');
        $data2 = $this->cache->save('test:file2', 'file2');

        $this->assertEquals(false, $data1);
        $this->assertEquals(false, $data2);

        $result = $this->cache->purge('test:*');
        $this->assertEquals(true, $result);

        $data = $this->cache->load('test:file1', 60 * 60 * 24 * 30 * 3 /* 3 months */);
        $this->assertEquals(false, $data);
        $data = $this->cache->load('test:file2', 60 * 60 * 24 * 30 * 3 /* 3 months */);
        $this->assertEquals(false, $data);

        /**
         * Test for failure
         * Try to glob keys that do not exist
         */
        $result = $this->cache->purge('test:*');
        $this->assertEquals(false, $result);
    }

    public function testCaseInsensitivity() {
        // None adapter does not expect case sensitivity/insensitivy
        $this->assertEquals(true, true);
    }
}
