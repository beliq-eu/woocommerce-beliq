<?php declare(strict_types=1);

/**
 * The slice of the WordPress HTTP API that WpHttpClient calls. WordPress is not
 * loaded in the offline suite, so the test drives the client through these and
 * inspects what it asked for. WpHttpStub::$args carries the captured request.
 */

final class WpHttpStub
{
    /** @var array<string, mixed>|null */
    public static ?array $args = null;

    /** @var array<string, mixed>|WpErrorStub */
    public static mixed $response = [];

    public static function reset(): void
    {
        self::$args = null;
        self::$response = [];
    }
}

final class WpErrorStub
{
    public function __construct(private readonly string $message)
    {
    }

    public function get_error_message(): string
    {
        return $this->message;
    }
}

if (!function_exists('wp_remote_request')) {
    function wp_remote_request(string $url, array $args = []): mixed
    {
        WpHttpStub::$args = ['url' => $url] + $args;

        return WpHttpStub::$response;
    }
}

if (!function_exists('is_wp_error')) {
    function is_wp_error(mixed $thing): bool
    {
        return $thing instanceof WpErrorStub;
    }
}

if (!function_exists('wp_remote_retrieve_response_code')) {
    function wp_remote_retrieve_response_code(mixed $response): mixed
    {
        return $response['response']['code'] ?? '';
    }
}

if (!function_exists('wp_remote_retrieve_body')) {
    function wp_remote_retrieve_body(mixed $response): string
    {
        return $response['body'] ?? '';
    }
}

if (!function_exists('wp_remote_retrieve_headers')) {
    function wp_remote_retrieve_headers(mixed $response): array
    {
        return $response['headers'] ?? [];
    }
}
