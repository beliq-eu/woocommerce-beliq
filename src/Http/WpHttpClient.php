<?php declare(strict_types=1);

namespace Beliq\WooCommerce\Http;

use Beliq\Core\Service\HttpClient;
use RuntimeException;

/**
 * The beliq transport on top of the WordPress HTTP API, so a site's proxy
 * configuration, WP_HTTP_BLOCK_EXTERNAL and the http_request_args filters reach
 * beliq calls the way they reach every other outbound request from the site.
 *
 * $timeoutSeconds bounds the whole request. The TCP connect is bounded
 * separately by Requests' own 10 second default, which matters because this
 * runs inside the order hook a shop admin is waiting on: against a black-holed
 * host (a mistyped base URL, a host that drops SYNs) libcurl on its own would
 * spend 300 seconds deciding the connect failed.
 */
final class WpHttpClient implements HttpClient
{
    public function __construct(private readonly int $timeoutSeconds = 30)
    {
    }

    public function request(string $method, string $url, array $headers = [], ?string $body = null): array
    {
        $args = [
            'method' => $method,
            'headers' => $headers,
            'timeout' => $this->timeoutSeconds,
            'redirection' => 0,
        ];
        if ($body !== null) {
            $args['body'] = $body;
        }

        $response = wp_remote_request($url, $args);
        if (is_wp_error($response)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- the message reaches wc_get_logger() only; the admin notice it produces is a fixed translated string.
            throw new RuntimeException('beliq request failed: ' . $response->get_error_message());
        }

        $responseHeaders = [];
        foreach (wp_remote_retrieve_headers($response) as $name => $value) {
            $responseHeaders[strtolower((string) $name)] = is_array($value) ? (string) end($value) : (string) $value;
        }

        return [
            'status' => (int) wp_remote_retrieve_response_code($response),
            'body' => (string) wp_remote_retrieve_body($response),
            'headers' => $responseHeaders,
        ];
    }
}
