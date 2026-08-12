<?php

declare(strict_types=1);

namespace Utopia\Cache\Adapter;

use stdClass;

final class Json
{
    public static function decode(string $value, int $flags = 0): mixed
    {
        if (preg_match('/\{\s*\}/', $value) === 0) {
            return json_decode($value, true, flags: $flags);
        }

        return self::toAssociative(json_decode($value, flags: $flags));
    }

    private static function toAssociative(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            $properties = get_object_vars($value);
            if ($properties === []) {
                return $value;
            }

            $value = $properties;
        }

        if (\is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = self::toAssociative($item);
            }
        }

        return $value;
    }
}
