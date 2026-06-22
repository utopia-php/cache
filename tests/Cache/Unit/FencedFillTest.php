<?php

namespace Utopia\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Utopia\Cache\Adapter;
use Utopia\Cache\Cache;
use Utopia\Cache\Feature\FencedFill;
use Utopia\Cache\Token;

class FencedFillTest extends TestCase
{
    public function testSaveKeepsOriginalEmptyHashForCustomAdapters(): void
    {
        $adapter = new class implements Adapter, FencedFill
        {
            public ?string $loadHash = null;

            public ?string $saveHash = null;

            public ?Token $saveToken = null;

            public function load(string $key, int $ttl, string $hash = ''): mixed
            {
                return false;
            }

            public function loadFenced(string $key, int $ttl, string $hash = ''): mixed
            {
                $this->loadHash = $hash;

                return new Token('token');
            }

            public function save(string $key, array|string $data, string $hash = '', ?Token $token = null): bool|string|array
            {
                $this->saveHash = $hash;
                $this->saveToken = $token;

                return $hash === '' && $token?->value === 'token' ? $data : false;
            }

            public function touch(string $key, string $hash = ''): bool
            {
                return false;
            }

            public function list(string $key): array
            {
                return [];
            }

            public function purge(string $key, string $hash = ''): Token|false
            {
                return new Token('token');
            }

            public function flush(): bool
            {
                return true;
            }

            public function ping(): bool
            {
                return true;
            }

            public function getSize(): int
            {
                return 0;
            }

            public function getName(?string $key = null): string
            {
                return 'custom';
            }
        };

        $cache = new Cache($adapter);

        $this->assertFalse($cache->load('key', 60));
        $this->assertSame('value', $cache->save('key', 'value'));
        $this->assertSame('', $adapter->loadHash);
        $this->assertSame('', $adapter->saveHash);
        $this->assertSame('token', $adapter->saveToken?->value);
    }

    public function testPendingFillTokensAreBounded(): void
    {
        $cache = new Cache(new class implements Adapter, FencedFill
        {
            public function load(string $key, int $ttl, string $hash = ''): mixed
            {
                return false;
            }

            public function loadFenced(string $key, int $ttl, string $hash = ''): mixed
            {
                return new Token($key);
            }

            public function save(string $key, array|string $data, string $hash = '', ?Token $token = null): bool|string|array
            {
                return $data;
            }

            public function touch(string $key, string $hash = ''): bool
            {
                return false;
            }

            public function list(string $key): array
            {
                return [];
            }

            public function purge(string $key, string $hash = ''): Token|false
            {
                return new Token('token');
            }

            public function flush(): bool
            {
                return true;
            }

            public function ping(): bool
            {
                return true;
            }

            public function getSize(): int
            {
                return 0;
            }

            public function getName(?string $key = null): string
            {
                return 'custom';
            }
        });

        for ($i = 0; $i < 1100; $i++) {
            $cache->load('key-'.$i, 60);
        }

        $reflection = new \ReflectionProperty(Cache::class, 'tokens');

        /** @var array<string, Token> $tokens */
        $tokens = $reflection->getValue($cache);
        $this->assertCount(1024, $tokens);
    }
}
