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

namespace Utopia;

use PHPUnit\Framework\TestCase;
use Utopia\Cache\Cache;
use Utopia\Cache\Adapter\Memory;

class MemoryTest extends TestCase
{
    /**
     * @var Cache
     */
    protected $cache = null;

    /**
     * @var string
     */
    protected $key = 'test-key-for-cache';

    /**
     * @var string
     */
    protected $data = 'test data string';

    protected function setUp(): void
    {
        $this->cache = new Cache(new Memory());
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

        $this->assertEquals($this->data, $result);
    }

    public function testNotEmptyCacheKey()
    {
        $this->cache->save($this->key, $this->data);

        $data = $this->cache->load($this->key, 60 * 60 * 24 * 30 * 3 /* 3 months */);

        $this->assertEquals($this->data, $data);
    }

    public function testCachePurge()
    {
        $this->cache->save($this->key, $this->data);

        $data = $this->cache->load($this->key, 60 * 60 * 24 * 30 * 3 /* 3 months */);

        $this->assertEquals($this->data, $data);

        $result = $this->cache->purge($this->key);

        $this->assertEquals(true, $result);

        $data = $this->cache->load($this->key, 60 * 60 * 24 * 30 * 3 /* 3 months */);

        $this->assertEquals(false, $data);
    }

    public function testCaseInsensitivity() {
        // Ensure case sensitivity first
        $data = $this->cache->save('color', 'pink');
        $this->assertEquals('pink', $data);
        $data = $this->cache->load('color', 60 * 60 * 24 * 30 * 3 /* 3 months */);
        $this->assertEquals('pink', $data);
        $data = $this->cache->load('COLOR', 60 * 60 * 24 * 30 * 3 /* 3 months */);
        $this->assertEquals(false, $data);


        // Test case insensitivity
        $this->cache->setCaseSensitivity(false);

        $data = $this->cache->save('planet', 'Earth');
        $this->assertEquals('Earth', $data);

        $data = $this->cache->load('planet', 60 * 60 * 24 * 30 * 3 /* 3 months */);
        $this->assertEquals('Earth', $data);
        $data = $this->cache->load('PLANET', 60 * 60 * 24 * 30 * 3 /* 3 months */);
        $this->assertEquals('Earth', $data);
        $data = $this->cache->load('PlAnEt', 60 * 60 * 24 * 30 * 3 /* 3 months */);
        $this->assertEquals('Earth', $data);

        $result = $this->cache->purge("PLaNEt");
        $this->assertEquals(true, $result);

        $data = $this->cache->load('planet', 60 * 60 * 24 * 30 * 3 /* 3 months */);
        $this->assertEquals(false, $data);
        $data = $this->cache->load('PLANET', 60 * 60 * 24 * 30 * 3 /* 3 months */);
        $this->assertEquals(false, $data);
    }
}
