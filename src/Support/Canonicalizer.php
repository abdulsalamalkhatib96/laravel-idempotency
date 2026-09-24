<?php

namespace Abdulsalam\LaravelIdempotency\Support;

final class Canonicalizer
{
    public function normalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->normalize($item), $value);
        }

        ksort($value, SORT_STRING);

        foreach ($value as $key => $item) {
            $value[$key] = $this->normalize($item);
        }

        return $value;
    }

    public function json(mixed $value): string
    {
        return json_encode(
            $this->normalize($value),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
        );
    }

    public function forgetPaths(array $value, array $paths): array
    {
        foreach ($paths as $path) {
            $segments = array_values(array_filter(explode('.', (string) $path), static fn (string $s): bool => $s !== ''));
            if ($segments !== []) {
                $this->forget($value, $segments);
            }
        }

        return $value;
    }

    private function forget(array &$array, array $segments): void
    {
        $segment = array_shift($segments);

        if ($segment === null || ! array_key_exists($segment, $array)) {
            return;
        }

        if ($segments === []) {
            unset($array[$segment]);
            return;
        }

        if (is_array($array[$segment])) {
            $this->forget($array[$segment], $segments);
        }
    }
}
