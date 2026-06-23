<?php

namespace Utopia\Cache;

use Utopia\Telemetry\Adapter as Telemetry;
use Utopia\Telemetry\Adapter\None as NoTelemetry;
use Utopia\Telemetry\Counter;
use Utopia\Telemetry\Histogram;

class Cache
{
    private const MAX_PENDING_TOKENS = 1024;

    private const TOKEN_CONTEXT_PREFIX = '__utopia_cache_tokens:';

    private Adapter $adapter;

    /**
     * @var bool If cache keys are case-sensitive
     */
    public bool $caseSensitive = false;

    protected Telemetry $telemetry;

    /**
     * @var Histogram|null
     */
    protected ?Histogram $operationDuration = null;

    /**
     * @var Counter|null
     */
    protected ?Counter $loadResults = null;

    /**
     * @var array<string, Token>
     */
    private array $tokens = [];

    private int $tokenGeneration = 0;

    /**
     * Set telemetry adapter. Instruments are created lazily on first use to
     * avoid emitting empty data point streams on every export interval.
     *
     * @param  Telemetry  $telemetry
     */
    public function setTelemetry(Telemetry $telemetry): void
    {
        $this->telemetry = $telemetry;
        $this->operationDuration = null;
        $this->loadResults = null;

        if ($this->adapter instanceof Feature\Telemetry) {
            $this->adapter->setTelemetry($telemetry);
        }
    }

    private function getOperationDuration(): Histogram
    {
        return $this->operationDuration ??= $this->telemetry->createHistogram(
            'cache.operation.duration',
            's',
            null,
            ['ExplicitBucketBoundaries' => [0.001, 0.005, 0.01, 0.025, 0.05, 0.1, 0.25, 0.5, 1]]
        );
    }

    private function getLoadResults(): Counter
    {
        return $this->loadResults ??= $this->telemetry->createCounter(
            'cache.load.total',
            null,
            'Cache load operations broken down by hit/miss result.',
        );
    }

    /**
     * Initialize with a no-op telemetry adapter by default.
     *
     * @param  Adapter  $adapter
     */
    public function __construct(Adapter $adapter)
    {
        $this->adapter = $adapter;
        $this->telemetry = new NoTelemetry();
    }

    /**
     * Toggle case sensitivity of keys inside cache
     *
     * @param  bool  $value if true, cache keys will be case-sensitive
     * @return bool
     */
    public function setCaseSensitivity(bool $value): bool
    {
        return $this->caseSensitive = $value;
    }

    /**
     * Load cached data. return false in no valid cache.
     *
     * @param  string  $key
     * @param  int  $ttl time in seconds
     * @param  string  $hash optional
     * @return mixed
     */
    public function load(string $key, int $ttl, string $hash = ''): mixed
    {
        $key = $this->caseSensitive ? $key : \strtolower($key);
        $hash = $this->caseSensitive ? $hash : \strtolower($hash);
        $effectiveHash = empty($hash) ? $key : $hash;

        $start = microtime(true);
        $result = $this->adapter instanceof Feature\FencedFill
            ? $this->adapter->loadFenced($key, $ttl, $hash)
            : $this->adapter->load($key, $ttl, $hash);
        $tokenKey = $this->getTokenKey($key, $effectiveHash);
        if ($result instanceof Token) {
            $this->rememberToken($tokenKey, $result);
            $result = false;
        } else {
            $this->forgetToken($tokenKey);
        }
        $duration = microtime(true) - $start;
        $adapterName = $this->adapter->getName($key);
        $this->getOperationDuration()->record($duration, [
            'operation' => 'load',
            'adapter' => $adapterName,
        ]);
        $this->getLoadResults()->add(1, [
            'adapter' => $adapterName,
            'result' => $result === false ? 'miss' : 'hit',
        ]);

        return $result;
    }

    /**
     * Save data to cache. Returns data on success of false on failure.
     *
     * @param  string  $key
     * @param  string|array<int|string, mixed>  $data
     * @param  string  $hash optional
     * @return bool|string|array<int|string, mixed>
     */
    public function save(string $key, mixed $data, string $hash = ''): bool|string|array
    {
        $key = $this->caseSensitive ? $key : strtolower($key);
        $hash = $this->caseSensitive ? $hash : strtolower($hash);
        $effectiveHash = empty($hash) ? $key : $hash;
        $tokenKey = $this->getTokenKey($key, $effectiveHash);
        $token = $this->consumeToken($tokenKey);
        $start = microtime(true);

        try {
            if ($token !== null && $this->adapter instanceof Feature\FencedFill) {
                return $this->adapter->saveFenced($key, $data, $token, $hash);
            }

            return $this->adapter->save($key, $data, $hash);
        } finally {
            $duration = microtime(true) - $start;
            $this->getOperationDuration()->record($duration, [
                'operation' => 'save',
                'adapter' => $this->adapter->getName($key),
            ]);
        }
    }

    /**
     * Refresh a cache entry timestamp without replacing its data.
     *
     * @param  string  $key
     * @param  string  $hash optional
     * @return bool
     */
    public function touch(string $key, string $hash = ''): bool
    {
        $key = $this->caseSensitive ? $key : strtolower($key);
        $hash = $this->caseSensitive ? $hash : strtolower($hash);

        $start = microtime(true);
        $result = $this->adapter->touch($key, $hash);
        $duration = microtime(true) - $start;
        $this->getOperationDuration()->record($duration, [
            'operation' => 'touch',
            'adapter' => $this->adapter->getName($key),
        ]);

        return $result;
    }

    /**
     * Returns a list of keys.
     *
     * @param  string  $key
     * @return string[]
     */
    public function list(string $key): array
    {
        $key = $this->caseSensitive ? $key : \strtolower($key);

        $start = microtime(true);
        $result = $this->adapter->list($key);
        $duration = microtime(true) - $start;
        $this->getOperationDuration()->record($duration, [
            'operation' => 'list',
            'adapter' => $this->adapter->getName($key),
        ]);

        return $result;
    }

    /**
     * Removes data from cache. Returns true on success of false on failure.
     *
     * @param  string  $key
     * @param  string  $hash optional
     * @return bool
     */
    public function purge(string $key, string $hash = ''): bool
    {
        $key = $this->caseSensitive ? $key : \strtolower($key);
        $hash = $this->caseSensitive ? $hash : \strtolower($hash);

        $start = microtime(true);
        $result = $this->adapter->purge($key, $hash);
        if ($result) {
            $effectiveHash = empty($hash) ? $key : $hash;
            $this->forgetPurgedTokens($key, empty($hash) ? null : $effectiveHash);
        }
        $duration = microtime(true) - $start;
        $this->getOperationDuration()->record($duration, [
            'operation' => 'purge',
            'adapter' => $this->adapter->getName($key),
        ]);

        return $result;
    }

    /**
     * Removes all data from cache. Returns true on success of false on failure.
     *
     * @return bool
     */
    public function flush(): bool
    {
        $start = microtime(true);
        $result = $this->adapter->flush();
        $this->tokenGeneration++;
        $this->clearTokens();
        $duration = microtime(true) - $start;
        $this->getOperationDuration()->record($duration, [
            'operation' => 'flush',
            'adapter' => $this->adapter->getName(),
        ]);

        return $result;
    }

    private function getTokenKey(string $key, string $hash): string
    {
        return $this->tokenGeneration."\0".$key."\0".$hash;
    }

    private function rememberToken(string $tokenKey, Token $token): void
    {
        $context = $this->getCoroutineContext();
        if ($context !== null) {
            $contextKey = $this->getTokenContextKey();
            $tokens = $this->getContextTokens($context, $contextKey);
            unset($tokens[$tokenKey]);
            $tokens[$tokenKey] = $token;
            $context[$contextKey] = $this->pruneTokens($tokens);

            return;
        }

        unset($this->tokens[$tokenKey]);
        $this->tokens[$tokenKey] = $token;
        $this->tokens = $this->pruneTokens($this->tokens);
    }

    private function forgetToken(string $tokenKey): void
    {
        $context = $this->getCoroutineContext();
        if ($context !== null) {
            $contextKey = $this->getTokenContextKey();
            $tokens = $this->getContextTokens($context, $contextKey);
            unset($tokens[$tokenKey]);
            $context[$contextKey] = $tokens;

            return;
        }

        unset($this->tokens[$tokenKey]);
    }

    private function forgetPurgedTokens(string $key, ?string $hash): void
    {
        if ($hash !== null) {
            $this->forgetToken($this->getTokenKey($key, $hash));

            return;
        }

        $prefix = $this->tokenGeneration."\0".$key."\0";
        $context = $this->getCoroutineContext();
        if ($context !== null) {
            $contextKey = $this->getTokenContextKey();
            $tokens = $this->getContextTokens($context, $contextKey);
            foreach (\array_keys($tokens) as $tokenKey) {
                if (\str_starts_with($tokenKey, $prefix)) {
                    unset($tokens[$tokenKey]);
                }
            }
            $context[$contextKey] = $tokens;

            return;
        }

        foreach (\array_keys($this->tokens) as $tokenKey) {
            if (\str_starts_with($tokenKey, $prefix)) {
                unset($this->tokens[$tokenKey]);
            }
        }
    }

    private function consumeToken(string $tokenKey): ?Token
    {
        $context = $this->getCoroutineContext();
        if ($context !== null) {
            $contextKey = $this->getTokenContextKey();
            $tokens = $this->getContextTokens($context, $contextKey);
            $token = $tokens[$tokenKey] ?? null;
            unset($tokens[$tokenKey]);
            $context[$contextKey] = $tokens;

            return $token;
        }

        $token = $this->tokens[$tokenKey] ?? null;
        unset($this->tokens[$tokenKey]);

        return $token;
    }

    private function clearTokens(): void
    {
        $context = $this->getCoroutineContext();
        if ($context !== null) {
            unset($context[$this->getTokenContextKey()]);
        }

        $this->tokens = [];
    }

    private function getTokenContextKey(): string
    {
        return self::TOKEN_CONTEXT_PREFIX.\spl_object_id($this);
    }

    /**
     * @return \ArrayAccess<string, mixed>|null
     */
    private function getCoroutineContext(): ?\ArrayAccess
    {
        if (! \class_exists(\Swoole\Coroutine::class, false)) {
            return null;
        }

        $cid = \call_user_func([\Swoole\Coroutine::class, 'getCid']);
        if (! \is_int($cid) || $cid < 0) {
            return null;
        }

        $context = \call_user_func([\Swoole\Coroutine::class, 'getContext']);
        if (! $context instanceof \ArrayAccess) {
            return null;
        }

        return $context;
    }

    /**
     * @param  \ArrayAccess<string, mixed>  $context
     * @return array<string, Token>
     */
    private function getContextTokens(\ArrayAccess $context, string $contextKey): array
    {
        if (! isset($context[$contextKey]) || ! \is_array($context[$contextKey])) {
            return [];
        }

        /** @var array<string, Token> */
        return $context[$contextKey];
    }

    /**
     * @param  array<string, Token>  $tokens
     * @return array<string, Token>
     */
    private function pruneTokens(array $tokens): array
    {
        while (\count($tokens) > self::MAX_PENDING_TOKENS) {
            $oldest = \array_key_first($tokens);
            unset($tokens[$oldest]);
        }

        return $tokens;
    }

    /**
     * Check Cache Connecitivity
     *
     * @return bool
     */
    public function ping(): bool
    {
        return $this->adapter->ping();
    }

    /**
     * Get db size.
     *
     * @return int
     */
    public function getSize(): int
    {
        $start = microtime(true);
        $result = $this->adapter->getSize();
        $duration = microtime(true) - $start;
        $this->getOperationDuration()->record($duration, [
            'operation' => 'size',
            'adapter' => $this->adapter->getName(),
        ]);

        return $result;
    }
}
