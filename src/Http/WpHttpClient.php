<?php declare(strict_types=1);

namespace Beliq\WooCommerce\Http;

use Beliq\Core\Exception\BeliqTransportException;
use Beliq\Core\Service\HttpClient;

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
 *
 * @phpstan-import-type Kind from BeliqTransportException
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
            $message = $response->get_error_message();
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- the message reaches wc_get_logger() only; the order shows the failure kind, a fixed string.
            throw new BeliqTransportException(self::failureKind($message), 'beliq request failed: ' . $message);
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

    /**
     * Where the request stopped, read from the WP_Error message. Requests' cURL
     * transport words it "cURL error <number>: <libcurl's text>". For a timeout
     * (28), libcurl's text says "Resolving timed out" or "Connection timed out"
     * until the connection, its TLS handshake included, is up (measured on
     * libcurl 8.14 and 8.22), and "Operation timed out" after. Any other wording,
     * the fsockopen transport's included, may have come after the send.
     *
     * @return Kind
     */
    private static function failureKind(string $message): string
    {
        if (preg_match('/^cURL error (\d+): (.*)$/s', $message, $match) !== 1) {
            return BeliqTransportException::CONNECTION_LOST;
        }

        return BeliqTransportException::kindForCurlError(
            (int) $match[1],
            preg_match('/^(Resolving|Connection) timed out/', $match[2]) !== 1,
        );
    }
}
