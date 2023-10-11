<?php

namespace Utopia\Cache\Adapter;

use Utopia\Cache\Adapter;
use Mcrouter\Mcrouter;

class McrouterAdapter implements Adapter
{
    /**
     * @var Mcrouter
     */
    protected Mcrouter $mcrouter;

    /**
     * Mcrouter constructor.
     *
     * @param  Mcrouter  $mcrouter
     */
    public function __construct(Mcrouter $mcrouter)
    {
        $this->mcrouter = $mcrouter;
    }

    /**
     * @param  string  $key
     * @param  int  $ttl time in seconds
     * @return mixed
     */
    public function load(string $key, int $ttl): mixed
    {
        $result = $this->mcrouter->get($key);

        if ($result->getResult() === null) {
          return false;
        }
        $cache = json_decode($result->getResult(), true);

        if ($cache['time'] + $ttl > time()) { // Cache is valid
            return $cache['data'];
        }

        return false;
    }

    /**
     * @param  string  $key
     * @param  string|array<int|string, mixed>  $data
     * @return bool|string|array<int|string, mixed>
     */
    public function save(string $key, $data): bool|string|array
    {
        if (empty($key) || empty($data)) {
            return false;
        }

        $cache = [
            'time' => time(),
            'data' => $data,
        ];

        $result = $this->mcrouter->set($key, json_encode($cache));

        return $result->getResult() ? $data : false;
    }

    /**
     * @param  string  $key
     * @return bool
     */
    public function purge(string $key): bool
    {
        $result = $this->mcrouter->delete($key);

        return $result->getResult() === 'DELETED';
    }

    /**
     * @return bool
     */
    public function flush(): bool
    {
        $keys = $this->getKeys();

        foreach ($keys as $key) {
            $this->mcrouter->delete($key);
        }

        return true;
    }

    /**
     * @return bool
     */
    public function ping(): bool
    {
        try {
            $this->mcrouter->version();

            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
    /**
     * Get all keys from Mcrouter
     *
     * @return array
     */
    private function getKeys(): array
    {
        $keysResult = $this->mcrouter->getStats('all');

        $keys = [];

        foreach ($keysResult as $serverStats) {
            foreach ($serverStats as $key => $value) {
                if (strpos($key, 'key_') === 0) {
                    $keys[] = substr($key, 4); 
                }
            }
        }

        return $keys;
    }
}