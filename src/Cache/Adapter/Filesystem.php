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
     * @param  string|array<int|string, mixed>  $data
     * @return bool|string|array<int|string, mixed>
     *
     * @throws \Exception
     */
    public function save(string $key, mixed $data): bool|string|array
    {
        if (empty($data)) {
            return false;
        }

        $file = $this->getPath($key);
        $dir = dirname($file);
        try {
            if (! file_exists($dir)) {
                if (! mkdir($dir, 0755, true)) {
                    if (! file_exists($dir)) {
                        throw new Exception("Can't create directory {$dir}");
                    }
                }
            }

            return file_put_contents($file, $data, LOCK_EX) !== false;
        } catch (\Exception $e) {
            throw new Exception($e->getMessage());
        }
    }

    /**
     * @param  string  $key
     * @return bool
     *
     * @throws Exception
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
     * @return bool
     */
    public function ping(): bool
    {
        return file_exists($this->path) && is_writable($this->path) && is_readable($this->path);
    }

    /**
     * Returning root directory size in bytes
     *
     * @return int
     */
    public function getSize(): int
    {
        try {
            return $this->getDirectorySize(dirname($this->path));
        } catch (Exception) {
            return 0;
        }
    }

    /**
     * @param  string  $dir
     * @return int
     */
    private function getDirectorySize(string $dir): int
    {
        $size = 0;
        $normalizedPath = rtrim($dir, '/').'/*';

        $paths = glob($normalizedPath, GLOB_NOSORT);
        if ($paths === false) {
            return $size;
        }

        foreach ($paths as $path) {
            if (is_file($path)) {
                $fileSize = filesize($path);
                $size += $fileSize !== false ? $fileSize : 0;
            } elseif (is_dir($path)) {
                $size += $this->getDirectorySize($path);
            }
        }

        return $size;
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
     *
     * @throws Exception
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

        if (! $files) {
            throw new Exception('Error happened during glob');
        }

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
