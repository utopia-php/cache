<?php

namespace Utopia\Cache\Adapter\Redis;

/**
 * Internal signal: the server has no cached script for the SHA sent via EVALSHA,
 * so the caller must resend the full body with EVAL (which also re-caches it).
 * Deliberately not a RedisException subclass so the adapters' reconnect/retry
 * paths — which key on RedisException — leave it alone and let leaseRun() catch it.
 */
final class NoScript extends \RuntimeException
{
}
