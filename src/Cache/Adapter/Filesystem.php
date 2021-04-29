<?php

namespace Utopia\Cache\Adapter;

use Utopia\Cache\Adapter;

class Filesystem implements Adapter
{
    /**
     * @var string
     */
    protected $path = '';

    /**
     * Filesystem constructor.
     * @param string $path
     */
    public function __construct($path)
    {
        $this->path = $path;
    }

    /**
     * @param string $key
     * @param int $ttl time in seconds
     * @return mixed
     * @throws \Exception
     */
    public function load($key, $ttl)
    {
        $file = $this->getPath($key);

        if (\file_exists($file) && (\filemtime($file) + $ttl > \time())) { // Cache is valid
            return \file_get_contents($file);
        }

        return false;
    }

    /**
     * @param string $key
     * @param string|array $data
     * @throws \Exception
     * @return bool|string|array
     */
    public function save($key, $data)
    {
        if (empty($data)) {
            return false;
        }

        $file = $this->getPath($key);

        if (!\file_exists(\dirname($file))) { // Checks if directory path to file exists
            if (!@\mkdir(\dirname($file), 0755, true)) {
                throw new \Exception('Can\'t create directory ' . \dirname($file));
            }

            if (!\file_exists(\dirname($file))) { // Checks race condition for mkdir function
                throw new \Exception('Can\'t create directory ' . \dirname($file));
            }
        }

        return (\file_put_contents($file, $data, LOCK_EX)) ? $data : false;
    }

    /**
     * @param string $key
     * @throws \Exception
     * @return bool
     */
    public function purge($key): bool
    {
        $file = $this->getPath($key);

        if (\file_exists($file)) {
            return \unlink($file);
        }

        return false;
    }

    /**
     * @param string $filename
     * @return string
     */
    public function getPath($filename)
    {
        $path = '';

        for ($i = 0; $i < 4; $i++) {
            $path = ($i < \strlen($filename)) ? $path . DIRECTORY_SEPARATOR . $filename[$i] : $path . DIRECTORY_SEPARATOR . 'x';
        }

        return $this->path . $path . DIRECTORY_SEPARATOR . $filename;
    }
}
