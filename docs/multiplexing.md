# Redis Multiplexing Adapter

A Redis cache adapter for Swoole that serves many concurrent coroutines from a
single Redis TCP connection.

## When to use it vs. a connection pool

Most Swoole apps reach for a pool (`Adapter\Pool` wrapping `Adapter\Redis`):
each request checks out one connection, runs its commands, returns it. Pool
size = max concurrent commands.

`Redis\Multiplexing` is the alternative for *cache* traffic specifically:

- One TCP socket serving thousands of concurrent commands.
- No pool sizing to tune, no checkout backpressure on bursts.
- Lower memory: ~1 connection per worker, not N.

You'd still want a pool for transactions, pub/sub, blocking commands, or any
workload where commands are not independent. Many Swoole apps end up using
both — multiplex the cache, pool the rest.

## Quick start

```php
use Swoole\Coroutine;
use Utopia\Cache\Cache;
use Utopia\Cache\Adapter\Redis\Multiplexing;

Coroutine\run(function () {
    $adapter = new Multiplexing(host: 'redis');
    $cache   = new Cache($adapter);

    $cache->save('user:42', ['name' => 'Ada'], 'profile');
    $cache->load('user:42', ttl: 60, hash: 'profile');

    $adapter->disconnect();
});
```

Construct once at worker start, share across requests, `disconnect()` on
`WorkerStop`. The adapter must be constructed inside a Swoole coroutine.

## Constructor

```php
new Multiplexing(
    host:        'redis',
    port:        6379,
    timeout:     1.0,    // connect timeout (s)
    readTimeout: 0.25,   // per-command response timeout (s)
    auth:        null,   // string password, [user, password], or null
    dbIndex:     0,
);
```

`readTimeout` defaults to **250 ms**. A timeout fails the command *and tears
down the connection* — every other in-flight command on it also fails with
`ConnectionException`, and the next command reconnects. This is intentional:
caches should fail fast and let callers fall through to the source of truth.
Per-request resync would add significant complexity for little gain.

## Errors

- `\RedisException` — Redis-side error (`WRONGTYPE`, `NOAUTH`, …). Connection
  is fine; the command was wrong. Not retried.
- `Utopia\Cache\Adapter\Redis\ConnectionException` — transport failure
  (timeout, socket closed, send failed). Connection has been discarded.
  Retried if `setMaxRetries(n)` was called.

## Telemetry

Implements `Cache\Feature\Telemetry`. Emits a
`cache.redis_multiplexing.pending.depth` gauge after each enqueue — non-zero
steady-state means callers are queueing faster than Redis is replying. Wire
up via `Cache::setTelemetry()`.

## Limitations

- Swoole coroutine context required.
- No pub/sub, transactions, blocking commands, or pipelining APIs.
- No Redis Cluster (use `Adapter\RedisCluster`).
- One Redis host per adapter instance; one connection per worker process.
