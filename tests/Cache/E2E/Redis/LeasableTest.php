<?php

namespace Utopia\Tests\E2E\Redis;

use PHPUnit\Framework\TestCase;
use Redis as Redis;
use Utopia\Cache\Adapter\Redis as RedisAdapter;
use Utopia\Cache\Cache;

class LeasableTest extends TestCase
{
    protected Cache $cache;

    protected function setUp(): void
    {
        $redis = new Redis();
        $redis->connect('redis', 6379);
        $this->cache = new Cache(new RedisAdapter($redis));
        $this->cache->flush();
    }

    public function testFreshKeyHasZeroGeneration(): void
    {
        $this->assertSame('0', $this->cache->getGeneration('doc:1'));
    }

    public function testSaveWithLeaseStoresWhenGenerationUnchanged(): void
    {
        $generation = $this->cache->getGeneration('doc:1');

        $this->assertNotFalse($this->cache->saveWithLease('doc:1', ['v' => 1], 'doc:1', $generation));
        $this->assertSame(['v' => 1], $this->cache->load('doc:1', 60, 'doc:1'));
    }

    public function testPurgeAdvancesGeneration(): void
    {
        $before = $this->cache->getGeneration('doc:1');
        $this->cache->saveWithLease('doc:1', ['v' => 1], 'doc:1', $before);
        $this->cache->purge('doc:1');

        $this->assertNotSame($before, $this->cache->getGeneration('doc:1'));
    }

    /**
     * The core read-after-write guarantee: a reader that captured the generation
     * BEFORE a concurrent purge must NOT be able to re-cache its now-stale value
     * AFTER the purge. Ordering here simulates the race deterministically.
     */
    public function testStaleSaveAfterPurgeIsRejected(): void
    {
        $captured = $this->cache->getGeneration('doc:1');

        // A writer commits and purges; the generation advances.
        $this->cache->purge('doc:1');

        // The reader tries to re-cache the value it read before the purge.
        $result = $this->cache->saveWithLease('doc:1', ['stale' => true], 'doc:1', $captured);

        $this->assertFalse($result, 'Stale re-cache must be rejected after a purge advanced the generation');
        $this->assertFalse($this->cache->load('doc:1', 60, 'doc:1'), 'Cache must not hold the stale value');
    }

    public function testFreshSaveAfterPurgeSucceeds(): void
    {
        $this->cache->purge('doc:1');

        $generation = $this->cache->getGeneration('doc:1');
        $this->assertNotFalse($this->cache->saveWithLease('doc:1', ['v' => 2], 'doc:1', $generation));
        $this->assertSame(['v' => 2], $this->cache->load('doc:1', 60, 'doc:1'));
    }

    public function testPurgePreservesDeletionResult(): void
    {
        $this->assertFalse($this->cache->purge('doc:absent'), 'Purging a missing key returns false');

        $this->cache->saveWithLease('doc:1', ['v' => 1], 'doc:1', $this->cache->getGeneration('doc:1'));
        $this->assertTrue($this->cache->purge('doc:1'), 'Purging an existing key returns true');
    }

    public function testPurgeDropsValueButKeepsGenerationField(): void
    {
        $this->cache->flush();
        $this->cache->saveWithLease('doc:1', ['v' => 1], 'doc:1', $this->cache->getGeneration('doc:1'));
        $this->assertSame(['v' => 1], $this->cache->load('doc:1', 60, 'doc:1'));

        $this->cache->purge('doc:1');

        // The value is gone, but the key keeps its in-hash generation so a stale
        // reader is still rejected. The residual hash therefore persists and is
        // counted by getSize() — the documented trade-off of storing the
        // generation in-hash rather than in a separately-countable sidecar key.
        $this->assertFalse($this->cache->load('doc:1', 60, 'doc:1'));
        $this->assertSame('1', $this->cache->getGeneration('doc:1'));
        $this->assertSame(1, $this->cache->getSize());
    }
}
