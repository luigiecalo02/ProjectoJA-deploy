<?php

namespace App\Modules\Auth\Services;

use Illuminate\Http\Request;

final class ClubesHostMap
{
    public function idForRequest(Request $request): ?int
    {
        $map = $this->all();
        if ($map === []) {
            return null;
        }

        $host = $this->hostForRequest($request);
        if ($host === null) {
            return null;
        }

        $id = $map[$host] ?? null;

        return is_int($id) && $id > 0 ? $id : null;
    }

    public function hostForRequest(Request $request): ?string
    {
        return $this->clientHost($request);
    }

    public function originForHost(string $host): string
    {
        $normalized = $this->normalizeHost($host);
        $local = str_contains($normalized, 'localhost') || str_contains($normalized, '127.0.0.1');

        return ($local ? 'http' : 'https').'://'.$normalized;
    }

    /**
     * @return list<string>
     */
    public function hostsForRootId(int $rootId): array
    {
        $hosts = [];
        foreach ($this->all() as $host => $id) {
            if ($id === $rootId) {
                $hosts[] = $host;
            }
        }

        return $hosts;
    }

    public function preferredHostForRoot(int $rootId, ?string $currentHost = null): ?string
    {
        $hosts = $this->hostsForRootId($rootId);
        if ($hosts === []) {
            return null;
        }

        if ($currentHost !== null && in_array($currentHost, $hosts, true)) {
            return $currentHost;
        }

        foreach ($hosts as $host) {
            if (substr_count($host, '.') >= 2 && ! str_starts_with($host, 'www.')) {
                return $host;
            }
        }

        return $hosts[0];
    }

    /**
     * @return array<string, int>
     */
    public function all(): array
    {
        $raw = config('clubes.hosts', '');

        if (is_array($raw)) {
            return $this->fromPairs($raw);
        }

        return $this->parse((string) $raw);
    }

    /**
     * @param  array<array-key, mixed>  $pairs
     * @return array<string, int>
     */
    private function fromPairs(array $pairs): array
    {
        $map = [];
        foreach ($pairs as $host => $id) {
            $normalized = $this->normalizeHost((string) $host);
            $orgId = (int) $id;
            if ($normalized !== '' && $orgId > 0) {
                $map[$normalized] = $orgId;
            }
        }

        return $map;
    }

    /**
     * @return array<string, int>
     */
    private function parse(string $raw): array
    {
        $map = [];
        foreach (explode(',', $raw) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }

            $colon = strrpos($part, ':');
            if ($colon === false || $colon === 0) {
                continue;
            }

            $host = $this->normalizeHost(substr($part, 0, $colon));
            $id = (int) trim(substr($part, $colon + 1));
            if ($host !== '' && $id > 0) {
                $map[$host] = $id;
            }
        }

        return $map;
    }

    private function clientHost(Request $request): ?string
    {
        $origin = $request->headers->get('Origin') ?: $request->headers->get('Referer');
        if (is_string($origin) && $origin !== '') {
            $host = $this->normalizeHost($origin);
            if ($host !== '') {
                return $host;
            }
        }

        $host = $this->normalizeHost($request->getHost());

        return $host !== '' ? $host : null;
    }

    private function normalizeHost(string $value): string
    {
        $value = strtolower(trim($value));
        if ($value === '') {
            return '';
        }

        if (str_contains($value, '://')) {
            $host = parse_url($value, PHP_URL_HOST);

            return is_string($host) ? strtolower($host) : '';
        }

        $value = explode('/', $value, 2)[0];

        return explode(':', $value, 2)[0];
    }
}
