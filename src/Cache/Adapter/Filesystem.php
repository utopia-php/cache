<?php

namespace Utopia\Cache\Adapter;

use Exception;
use Utopia\Cache\Adapter;
use Utopia\Cache\Token;

class Filesystem implements Adapter
{
    private const TOKEN_PREFIX = '__utopia_cache_token__:';

    /**
     * @var string
     */
    protected $path = '';

    /**
     * @var bool
     */
    protected bool $streaming = false;

    /**
     * Filesystem constructor.
     *
     * @param  string  $path
     * @param  bool  $streaming
     */
    public function __construct(string $path, bool $streaming = false)
    {
        $this->path = $path;
        $this->streaming = $streaming;
    }

    /**
     * @param  string  $key
     * @param  int  $ttl time in seconds
     * @param  string  $hash optional
     * @return mixed
     */
    public function load(string $key, int $ttl, string $hash = ''): mixed
    {
        $file = $this->getPath($key);

        if (\file_exists($file)) {
            if (\filemtime($file) + $ttl <= \time()) {
                return false;
            }

            $contents = \file_get_contents($file);
            if ($this->isTokenContents($contents)) {
                $token = $this->purge($key, $hash);

                return $token === false ? false : new Token($token);
            }

            if ($this->streaming) {
                return \fopen($file, 'rb');
            }

            return $contents;
        }

        $token = $this->purge($key, $hash);

        return $token === false ? false : new Token($token);
    }

    /**
     * @param  string  $key
     * @param  array<int|string, mixed>|string  $data
     * @param  string  $hash optional
     * @return bool|string|array<int|string, mixed>
     *
     * @throws Exception
     */
    public function save(string $key, array|string $data, string $hash = '', ?string $token = null): bool|string|array
    {
        if (empty($data)) {
            return false;
        }

        $file = $this->getPath($key);
        $dir = dirname($file);
        try {
            if (! file_exists($dir)) {
                if (! mkdir($dir, 0755, true) && ! file_exists($dir)) {
                    throw new Exception("Can't create directory {$dir}");
                }
            }

            if ($token !== null) {
                $contents = \file_exists($file) ? \file_get_contents($file) : false;
                if ($contents !== self::TOKEN_PREFIX.$token) {
                    return false;
                }
            }

            return (\file_put_contents($file, $data, LOCK_EX)) ? $data : false;
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }
    }

    /**
     * @param  string  $key
     * @param  string  $hash optional
     * @return bool
     */
    public function touch(string $key, string $hash = ''): bool
    {
        $file = $this->getPath($key);

        if (! file_exists($file)) {
            return false;
        }

        $contents = \file_get_contents($file);
        if ($this->isTokenContents($contents)) {
            return false;
        }

        if (! touch($file)) {
            return false;
        }

        clearstatcache(true, $file);

        return true;
    }

    /**
     * @param  string  $key
     * @return string[]
     */
    public function list(string $key): array
    {
        return [];
    }

    /**
     * @param  string  $key
     * @param  string  $hash optional
     * @return string|false
     */
    public function purge(string $key, string $hash = ''): string|false
    {
        $file = $this->getPath($key);

        $token = \bin2hex(\random_bytes(16));
        $dir = dirname($file);

        if (! file_exists($dir)) {
            if (! mkdir($dir, 0755, true) && ! file_exists($dir)) {
                return false;
            }
        }

        return \file_put_contents($file, self::TOKEN_PREFIX.$token, LOCK_EX) ? $token : false;
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
                $contents = \file_get_contents($path);
                if ($this->isTokenContents($contents)) {
                    continue;
                }

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

    private function isTokenContents(string|false $contents): bool
    {
        return \is_string($contents) && \str_starts_with($contents, self::TOKEN_PREFIX);
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

    /**
     * @param  string|null  $key
     * @return string
     */
    public function getName(?string $key = null): string
    {
        return 'filesystem';
    }
}
