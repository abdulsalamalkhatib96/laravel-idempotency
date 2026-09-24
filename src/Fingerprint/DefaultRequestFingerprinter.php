<?php

namespace Abdulsalam\LaravelIdempotency\Fingerprint;

use Abdulsalam\LaravelIdempotency\Contracts\RequestFingerprinter;
use Abdulsalam\LaravelIdempotency\Support\Canonicalizer;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Throwable;

final class DefaultRequestFingerprinter implements RequestFingerprinter
{
    public function __construct(private readonly Canonicalizer $canonicalizer) {}

    public function fingerprint(Request $request, string $operation): string
    {
        $parts = [
            'version' => (string) config('idempotency.fingerprint_version', 'v1'),
            'method' => strtoupper($request->method()),
            'operation' => $operation,
        ];

        if ((bool) config('idempotency.fingerprint.include_route_parameters', true)) {
            $route = $request->route();
            $params = is_object($route) && method_exists($route, 'parameters') ? $route->parameters() : [];
            $parts['route'] = $this->normalizeScalars($params);
        }

        if ((bool) config('idempotency.fingerprint.include_query', true)) {
            $query = $request->query->all();
            $query = $this->canonicalizer->forgetPaths($query, (array) config('idempotency.fingerprint.ignored_query_fields', []));
            $parts['query'] = $query;
        }

        $headers = (array) config('idempotency.fingerprint.include_headers', []);
        if ($headers !== []) {
            $parts['headers'] = [];
            foreach ($headers as $header) {
                $name = strtolower((string) $header);
                $parts['headers'][$name] = $request->headers->all($name);
            }
        }

        $parts['body'] = $this->body($request);

        if ((bool) config('idempotency.fingerprint.include_files', true)) {
            $parts['files'] = $this->files($request->allFiles());
        }

        return hash('sha256', $this->canonicalizer->json($parts));
    }

    private function body(Request $request): mixed
    {
        if ($request->isJson()) {
            try {
                $decoded = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
                if (is_array($decoded)) {
                    return $this->canonicalizer->forgetPaths(
                        $decoded,
                        (array) config('idempotency.fingerprint.ignored_body_fields', []),
                    );
                }

                return $decoded;
            } catch (Throwable) {
                return ['raw_sha256' => hash('sha256', $request->getContent())];
            }
        }

        if ($request->request->count() > 0) {
            return $this->canonicalizer->forgetPaths(
                $request->request->all(),
                (array) config('idempotency.fingerprint.ignored_body_fields', []),
            );
        }

        $raw = $request->getContent();

        return $raw === '' ? null : ['raw_sha256' => hash('sha256', $raw)];
    }

    private function files(array $files): array
    {
        $result = [];

        foreach ($files as $field => $file) {
            if (is_array($file)) {
                $result[$field] = $this->files($file);
                continue;
            }

            if (! $file instanceof UploadedFile) {
                continue;
            }

            $path = $file->getRealPath();
            $hash = $path !== false && is_readable($path) ? hash_file('sha256', $path) : null;

            $result[$field] = [
                'name' => $file->getClientOriginalName(),
                'mime' => $file->getClientMimeType(),
                'size' => $file->getSize(),
                'sha256' => $hash,
            ];
        }

        return $result;
    }

    private function normalizeScalars(array $values): array
    {
        $result = [];

        foreach ($values as $key => $value) {
            if (is_object($value) && method_exists($value, 'getRouteKey')) {
                $result[$key] = [
                    'type' => $value::class,
                    'route_key' => $value->getRouteKey(),
                ];
                continue;
            }

            $result[$key] = is_scalar($value) || $value === null ? $value : (string) $value;
        }

        return $result;
    }
}
