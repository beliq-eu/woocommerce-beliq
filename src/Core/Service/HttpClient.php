<?php declare(strict_types=1);

namespace Beliq\Core\Service;

/**
 * The HTTP seam. The plugin injects WpHttpClient, which goes through the
 * WordPress HTTP API; tests inject a fake sender. The body is raw bytes so
 * binary responses (PDF) survive intact.
 */
interface HttpClient
{
    /**
     * @param array<string, string> $headers
     * @return array{status: int, body: string, headers: array<string, string>}
     */
    public function request(string $method, string $url, array $headers = [], ?string $body = null): array;
}
