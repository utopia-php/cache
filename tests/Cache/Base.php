<?php

namespace Utopia\Tests;

use PHPUnit\Framework\TestCase;

abstract class Base extends TestCase
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

    /**
     * @var array
     */
    protected $dataArray = ['test', 'data', 'string'];

    /**
     * General tests
     * Can be overwritten in specific adapter if required, such as None Cache
     */
    public function testCacheSave()
    {
        \var_dump("Save 1");
        // test $data array
        $result = $this->cache->save($this->key, $this->dataArray);

        $this->assertEquals($this->dataArray, $result);

        // test $data string
        $result = $this->cache->save($this->key, $this->data);

        $this->assertEquals($this->data, $result);
        \var_dump("Save 2");
    }

    /**
     * @depends testCacheSave
     */
    public function testNotEmptyCacheKey()
    {
        \var_dump("Load 1");
        $data = $this->cache->load($this->key, 60 * 60 * 24 * 30 * 3 /* 3 months */);

        $this->assertEquals($this->data, $data);
        \var_dump("Load 2");
    }

    /**
     * @depends testCacheSave
     */
    public function testCachePurge()
    {
        $data = $this->cache->load($this->key, 60 * 60 * 24 * 30 * 3 /* 3 months */);

        $this->assertEquals($this->data, $data);

        $result = $this->cache->purge($this->key);

        $this->assertEquals(true, $result);

        $data = $this->cache->load($this->key, 60 * 60 * 24 * 30 * 3 /* 3 months */);

        $this->assertEquals(false, $data);
    }

    public function testCachePurgeWildcard()
    {
        $data1 = $this->cache->save('test:file1', 'file1');
        $data2 = $this->cache->save('test:file2', 'file2');

        $this->assertEquals('file1', $data1);
        $this->assertEquals('file2', $data2);

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
        // Ensure case sensitivity first (configured in adapter's setUp)
        $data = $this->cache->save('color', 'pink');
        $this->assertEquals('pink', $data);
        $data = $this->cache->load('color', 60 * 60 * 24 * 30 * 3 /* 3 months */);
        $this->assertEquals('pink', $data);
        $data = $this->cache->load('COLOR', 60 * 60 * 24 * 30 * 3 /* 3 months */);
        $this->assertEquals(false, $data);


        // Test case insensitivity
        $this->cache::setCaseSensitivity(false);

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