<?php

namespace Utopia\Cache\Adapter;

use Exception;
use Utopia\Cache\Adapter;

class Filesystem implements Adapter
{
    /**
     * @var string
     */
    protected $path = '';

    /**
     * Filesystem constructor.
     *
     * @param  string  $path
     */
    public function __construct(string $path)
    {
        $this->path = $path;
    }

    /**
     * @param  string  $key
     * @param  int  $ttl time in seconds
     * @return mixed
     *
     * @throws \Exception
     */
    public function load(string $key, int $ttl): mixed
    {
        $file = $this->getPath($key);

        if (\file_exists($file) && (\filemtime($file) + $ttl > \time())) { // Cache is valid
            return \file_get_contents($file);
        }

        return false;
    }

    /**
     * @param  string  $key
     * @param  string|array  $data
     * @return bool|string|array
     *
     * @throws \Exception
     */
    public function save(string $key, mixed $data): bool|string|array
    {
        if (empty($data)) {
            return false;
        }

        $file = $this->getPath($key);

        if (! \file_exists(\dirname($file))) { // Checks if directory path to file exists
            if (! @\mkdir(\dirname($file), 0755, true)) {
                throw new \Exception('Can\'t create directory '.\dirname($file));
            }

            if (! \file_exists(\dirname($file))) { // Checks race condition for mkdir function
                throw new \Exception('Can\'t create directory '.\dirname($file));
            }
        }

        return (\file_put_contents($file, $data, LOCK_EX)) ? $data : false;
    }

    /**
     * @param  string  $key
     * @return bool
     *
     * @throws \Exception
     */
    public function purge(string $key): bool
    {
        $file = $this->getPath($key);

        if (\file_exists($file)) {
            return \unlink($file);
        }

        return false;
    }

    /**
     * @return bool
     */
    public function flush(): bool
    {
        return $this->deleteDirectory($this->path);
    }

    /**
     * @param  string  $filename
     * @return string
     */
    public function getPath(string $filename): string
    {
        return $this->path.DIRECTORY_SEPARATOR.$filename;
    }

    /**
     * @param  string  $path
     * @return bool
     */
    protected function deleteDirectory(string $path): bool
    {
        if (! is_dir($path)) {
            throw new Exception("$path must be a directory");
        }

        if (substr($path, strlen($path) - 1, 1) != '/') {
            $path .= '/';
        }

        $files = glob($path.'*', GLOB_MARK);

        foreach ($files as $file) {
            if (is_dir($file)) {
                self::deleteDirectory($file);
            } else {
                unlink($file);
            }
        }

        return rmdir($path);
    }
}
