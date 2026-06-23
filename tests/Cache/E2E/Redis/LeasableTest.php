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

    public function testHashPurgeAdvancesGeneration(): void
    {
        $captured = $this->cache->getGeneration('doc:1');
        $this->assertNotFalse($this->cache->saveWithLease('doc:1', ['v' => 1], 'field-a', $captured));

        // A field-level purge must advance the per-key generation, just like a
        // full purge, or the lease is bypassed for hashed entries.
        $this->assertTrue($this->cache->purge('doc:1', 'field-a'));
        $this->assertNotSame($captured, $this->cache->getGeneration('doc:1'));

        // A reader holding the pre-purge generation can no longer re-cache a
        // stale field via saveWithLease.
        $this->assertFalse($this->cache->saveWithLease('doc:1', ['stale' => true], 'field-a', $captured));
        $this->assertFalse($this->cache->load('doc:1', 60, 'field-a'));
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

    public function testReservedGenerationFieldIsProtected(): void
    {
        $this->cache->flush();
        $this->cache->saveWithLease('doc:1', ['v' => 1], 'doc:1', $this->cache->getGeneration('doc:1'));
        $this->cache->purge('doc:1');
        $this->assertSame('1', $this->cache->getGeneration('doc:1'));

        // Reading, writing, or deleting the reserved generation field through the
        // public API must be rejected, so a caller can't clobber it and reset the
        // generation sequence (which would revive stale lease tokens).
        $this->assertFalse($this->cache->save('doc:1', 'attacker', '__utopia_gen__'));
        $this->assertFalse($this->cache->saveWithLease('doc:1', 'attacker', '__utopia_gen__', '1'));
        $this->assertFalse($this->cache->purge('doc:1', '__utopia_gen__'));
        $this->assertFalse($this->cache->load('doc:1', 60, '__utopia_gen__'));

        // Generation is untouched, so an old token is still rejected.
        $this->assertSame('1', $this->cache->getGeneration('doc:1'));
        $this->assertFalse($this->cache->saveWithLease('doc:1', 'stale', 'doc:1', '0'));
    }

    public function testListHidesGenerationField(): void
    {
        $this->cache->flush();
        $this->cache->saveWithLease('doc:1', ['v' => 1], 'field-a', $this->cache->getGeneration('doc:1'));
        $this->assertSame(['field-a'], $this->cache->list('doc:1'));

        // After a purge the key holds only its internal generation field, which
        // must not surface as a listable cache field.
        $this->cache->purge('doc:1');
        $this->assertSame([], $this->cache->list('doc:1'));
    }
}
