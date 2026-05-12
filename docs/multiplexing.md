# Redis Multiplexing Adapter

A Redis cache adapter for Swoole applications that lets many concurrent
coroutines share a single Redis TCP connection. Use it when you have a process
running lots of cooperative coroutines (an HTTP server, a worker pool) and you
do not want to open one Redis socket per request.

This page covers usage. For internals, see the source under
`src/Cache/Adapter/Redis/`.

## When to use it

Pick `Redis\Multiplexing` if:

- Your application runs inside Swoole (`Swoole\Coroutine`).
- You want one Redis connection serving N concurrent coroutines.
- Your Redis usage is request/response — no pub/sub, no transactions, no
  pipelining APIs.

Pick the plain `PhpRedis` adapter instead if:

- You are not using Swoole.
- You manage one Redis connection per process or per request and that is fine.
- You need pub/sub, `MULTI`/`EXEC`, blocking commands, or anything outside
  simple request/response.

## Installation

```bash
composer require utopia-php/cache
```

The adapter requires:

- PHP 8.4+
- The Swoole extension (with coroutine support)
- A reachable Redis server

## Quick start

```php
use Swoole\Coroutine;
use Utopia\Cache\Cache;
use Utopia\Cache\Adapter\Redis\Multiplexing;

Coroutine\run(function () {
    $adapter = new Multiplexing(host: 'redis', port: 6379);
    $cache   = new Cache($adapter);

    $cache->save('user:42', ['name' => 'Ada'], 'profile');
    $loaded = $cache->load('user:42', ttl: 60, hash: 'profile');

    $adapter->disconnect();
});
```

`Multiplexing` must be constructed and used inside a Swoole coroutine. The
constructor opens the TCP connection, runs `AUTH` and `SELECT` if you supplied
them, and starts a background reader coroutine.

## Constructor options

```php
new Multiplexing(
    host:        'redis',
    port:        6379,
    timeout:     0.0,    // connect timeout (seconds, 0 = library default ~1s)
    readTimeout: 0.25,   // per-command response timeout (seconds)
    auth:        null,   // string password, or [username, password], or null
    dbIndex:     0,
);
```

### `readTimeout` — caches should fail fast

The default is **250 ms**. If Redis does not respond to a command within that
window, the adapter throws a `Redis\ConnectionException`, tears down the
connection, and the next command will reconnect. The intent is that a slow or
unreachable cache surfaces immediately so you can fall back to your source of
truth, instead of stalling request-handling coroutines.

If you have a higher-latency Redis (cross-region, encrypted tunnel) tune this
upwards. If you have a strict request budget, tune it down.

### `auth`

Pass either a string (password only) or `[username, password]` for ACL-style
auth. The adapter sends `AUTH` automatically before any user command runs on a
connection. A literal `'0'` is a valid password and will be sent — only `null`
suppresses `AUTH`.

### `dbIndex`

If non-zero, the adapter runs `SELECT <dbIndex>` after `AUTH`.

## Adapter API

`Multiplexing` implements `Utopia\Cache\Adapter`:

| Method | Behaviour |
|---|---|
| `save($key, $data, $hash = '')` | Stores `data` under `key/hash`. Returns the saved data on success. |
| `load($key, $ttl, $hash = '')` | Returns the data if it was stored within the last `$ttl` seconds; `false` otherwise. |
| `touch($key, $hash = '')` | Re-stamps an existing entry's timestamp without rewriting its data. Returns `false` if the key is missing. |
| `purge($key, $hash = '')` | Deletes a single field (with `$hash`) or the whole key. |
| `list($key)` | Returns the field names (`HKEYS`) under a key. |
| `flush()` | Issues `FLUSHDB`. |
| `getSize()` | Returns `DBSIZE`. |
| `ping()` | Returns `true` if Redis answers `PONG`. |

In addition:

| Method | Purpose |
|---|---|
| `disconnect()` | Closes the connection and stops the reader coroutine. Call this on shutdown. |
| `setMaxRetries($n)` / `setRetryDelay($ms)` | Configure connection-error retries. Defaults: 0 retries. |
| `getName()` | Returns `'redis-multiplexing'`. |

### Storage format

Cached entries are stored as JSON envelopes:

```json
{"time": 1700000000, "data": <your value>}
```

`load()` returns `data` only if `time + ttl > now()`. This means TTLs are
enforced by the adapter, not by Redis — a freshly-flushed Redis loses
everything, but a long-running Redis will keep stale entries until they are
overwritten or purged. If you need Redis-side eviction, set a `maxmemory`
policy on the server.

## Concurrency model

A single `Multiplexing` instance is safe to share across coroutines.
Coroutines issue commands concurrently; the adapter serialises bytes onto the
wire and dispatches replies back to the originating coroutine. Redis's
guarantee of in-order replies is what makes this work — the adapter pairs the
N-th reply with the N-th request.

You can run thousands of coroutines through one `Multiplexing` instance. The
practical limit is the latency of your Redis: if each round-trip is 1 ms and
you sustain 1000 commands per second, queueing inside the adapter will be
minimal. If you saturate Redis, all coroutines slow down equally.

## Error handling

Two exception types come out of the adapter:

- `\RedisException` — Redis returned a server-side error (`WRONGTYPE`,
  `NOAUTH`, etc.). The connection is fine; the command was wrong.
- `Utopia\Cache\Adapter\Redis\ConnectionException` — the underlying connection
  failed (TCP error, timeout, send failure, server closed the socket). The
  adapter has already torn down the connection; the next command reconnects.

Only `ConnectionException` is retried (when `setMaxRetries()` is non-zero).

### What happens on a timeout

If one command's response takes longer than `readTimeout`, that command fails
with `ConnectionException`. As a side-effect the adapter discards the
connection — every other in-flight command on that connection also fails with
`ConnectionException`. The next command from any coroutine will open a fresh
connection.

This is by design. The alternative — letting one slow command block while
others continue — would require per-request resync logic that adds significant
complexity for little gain. A cache should fail fast and let callers fall
through to the source of truth.

## Lifecycle and shutdown

The adapter starts a background reader coroutine when it connects. That
coroutine holds a reference to the adapter, which means PHP's `__destruct`
will not fire while the connection is open. Call `disconnect()` explicitly
when you are done:

```php
Coroutine\run(function () {
    $adapter = new Multiplexing(host: 'redis');
    try {
        // ... use $cache ...
    } finally {
        $adapter->disconnect();
    }
});
```

In an HTTP server, that typically means: open the adapter once at server
start, share it across requests, close it on `WorkerStop`.

## Limitations

- **Swoole only.** Constructing the adapter outside a coroutine context does
  not work.
- **No pub/sub.** Use a dedicated connection.
- **No transactions or pipelining APIs.** Commands are multiplexed
  individually.
- **No cluster support.** Use `Adapter\RedisCluster` (non-multiplexed).
- **One Redis per adapter instance.** Multiple Redis hosts means multiple
  adapters.
- **Connection sharing is per-process.** Each PHP worker has its own adapter
  instance and its own TCP socket.

## See also

- `Utopia\Cache\Adapter\PhpRedis` — non-coroutine Redis adapter built on
  `ext-redis`.
- `Utopia\Cache\Adapter\RedisCluster` — Redis Cluster support.
- `Utopia\Cache\Adapter\Sharding` — client-side sharding across multiple
  cache adapters.
