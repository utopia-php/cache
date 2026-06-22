<?php

namespace Utopia\Cache\Adapter\Redis;

use JsonException;

/**
 * Cache envelope codec shared by the Redis-family adapters.
 *
 * Stored payload is `{"time": <unix>, "data": <value>}`. Encoding adds the
 * timestamp; decoding enforces TTL relative to a caller-supplied `now`.
 */
final class Envelope
{
    /**
     * @param  array<int|string, mixed>|string  $data
     *
     * @throws JsonException
     */
    public static function encode(array|string $data, int $time): string
    {
        return json_encode([
            'time' => $time,
            'data' => $data,
        ], flags: JSON_THROW_ON_ERROR);
    }

    /**
     * Decode a stored envelope. Returns the wrapped data if the envelope is
     * well-formed and not yet expired (`time + ttl > now`); returns false
     * otherwise. Never throws — malformed JSON / shape is treated as a miss.
     */
    public static function decode(string $value, int $ttl, int $now): mixed
    {
        $cache = json_decode($value, true);
        if (! is_array($cache) || ! isset($cache['time'], $cache['data']) || ! is_int($cache['time'])) {
            return false;
        }

        if ($cache['time'] + $ttl > $now) {
            return $cache['data'];
        }

        return false;
    }

    /**
     * Re-stamp an existing envelope with a new timestamp, preserving its data.
     * Returns the rewritten JSON, or false if the input is not a valid envelope.
     */
    public static function touch(string $value, int $newTime): string|false
    {
        try {
            $cache = json_decode($value, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return false;
        }

        if (! is_array($cache) || ! array_key_exists('data', $cache)) {
            return false;
        }

        $cache['time'] = $newTime;

        try {
            return json_encode($cache, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return false;
        }
    }
}
