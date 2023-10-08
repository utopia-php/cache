<?php

namespace Utopia\Cache\Adapter;

use Utopia\Cache\Adapter;
use VarnishAdmin;

class Varnish implements Adapter
{
    /**
     * @var VarnishAdmin
     */
    protected VarnishAdmin $varnish;

    /**
     * Varnish constructor.
     *
     * @param string $hostname
     * @param int $port
     */
    public function __construct(string $hostname, int $port)
    {
        $this->varnish = new VarnishAdmin($hostname, $port);
    }

    /**
     * @param string $key
     * @param int $ttl Time-to-live in seconds (ignored in Varnish)
     * @return mixed
     */
    public function load(string $key, int $ttl): mixed
    {
        try {
            return $this->varnish->get($key);
        } catch (\Exception $e) {
            // Handle any other exceptions here
        }
    }

    /**
     * @param string $key
     * @param string|array<int|string, mixed> $data
     * @return bool|string|array
     */
    public function save(string $key, $data): bool|string|array
    {
        try {
            $this->varnish->set($key, $data);
            return $data;
        } catch (\Exception $e) {
            // Handle any other exceptions here
        }
    }

    /**
     * @param string $key
     * @return int
     */
    public function purge(string $key): int
    {
        try {
            $response = $this->varnish->ban('req.url == ' . json_encode($key));
            $purgedObjects = intval($response['purged']);
            return $purgedObjects;
        } catch (\Exception $e) {
            // Handle any other exceptions here
        }
    }

    /**
     * @return bool
     */
    public function flush(?string $pattern = null): bool
    {
        try {
            if ($pattern === null) {
                // Flush all cache by issuing a wildcard ban
                $this->varnish->ban('req.url ~ ".*"');
            } else {
                // Flush cache matching a specific pattern
                $this->varnish->ban('req.url ~ "' . $pattern . '"');
            }

            return true;
        } catch (\Exception $e) {
            // Handle any other exceptions here
        }
    }

    /**
     * @return bool
     */
    public function ping(): bool
    {
        try {
            $this->varnish->ping();

            return true;
        } catch (\Exception $e) {
            // Handle any other exceptions here
        }
    }
}
