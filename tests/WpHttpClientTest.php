<?php declare(strict_types=1);

namespace Beliq\WooCommerce\Tests;

use Beliq\WooCommerce\Http\WpHttpClient;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use WpErrorStub;
use WpHttpStub;

/**
 * WpHttpClient owns the translation between the HttpClient seam and the
 * WordPress HTTP API: which arguments go out, and how the response is read
 * back. That mapping is what these assert. The transport underneath is
 * WordPress's, and the plugin's real requests are covered by smoke/.
 */
final class WpHttpClientTest extends TestCase
{
    protected function setUp(): void
    {
        require_once __DIR__ . '/Support/wp-http-stubs.php';
        WpHttpStub::reset();
    }

    public function testItSendsTheMethodHeadersBodyAndTimeout(): void
    {
        WpHttpStub::$response = [
            'response' => ['code' => 200],
            'body' => '{"data":{}}',
            'headers' => [],
        ];

        (new WpHttpClient(17))->request(
            'POST',
            'https://api.beliq.eu/v1/generate',
            ['X-API-Key' => 'key-123', 'Content-Type' => 'application/json'],
            '{"invoiceNumber":"1"}',
        );

        self::assertSame('https://api.beliq.eu/v1/generate', WpHttpStub::$args['url']);
        self::assertSame('POST', WpHttpStub::$args['method']);
        self::assertSame('key-123', WpHttpStub::$args['headers']['X-API-Key']);
        self::assertSame('{"invoiceNumber":"1"}', WpHttpStub::$args['body']);
        self::assertSame(17, WpHttpStub::$args['timeout']);
    }

    /**
     * A beliq response is read, never followed. The API answers 3xx only for
     * something the plugin has no business chasing, and following one would
     * replay the API key at whatever host the Location names.
     */
    public function testItDoesNotFollowRedirects(): void
    {
        WpHttpStub::$response = ['response' => ['code' => 200], 'body' => '', 'headers' => []];

        (new WpHttpClient())->request('GET', 'https://api.beliq.eu/v1/me');

        self::assertSame(0, WpHttpStub::$args['redirection']);
    }

    public function testAGetCarriesNoBodyKey(): void
    {
        WpHttpStub::$response = ['response' => ['code' => 200], 'body' => '', 'headers' => []];

        (new WpHttpClient())->request('GET', 'https://api.beliq.eu/v1/me');

        self::assertArrayNotHasKey('body', WpHttpStub::$args);
    }

    public function testItReturnsStatusRawBodyAndLowercasedHeaders(): void
    {
        WpHttpStub::$response = [
            'response' => ['code' => 201],
            'body' => "%PDF-1.3\x00binary",
            'headers' => ['Content-Type' => 'application/pdf', 'X-Schematron-Version' => '2024-06'],
        ];

        $res = (new WpHttpClient())->request('POST', 'https://api.beliq.eu/v1/generate');

        self::assertSame(201, $res['status']);
        self::assertSame("%PDF-1.3\x00binary", $res['body']);
        self::assertSame('application/pdf', $res['headers']['content-type']);
        self::assertSame('2024-06', $res['headers']['x-schematron-version']);
    }

    public function testARepeatedHeaderKeepsItsLastValue(): void
    {
        WpHttpStub::$response = [
            'response' => ['code' => 200],
            'body' => '',
            'headers' => ['X-Trace' => ['first', 'second']],
        ];

        $res = (new WpHttpClient())->request('GET', 'https://api.beliq.eu/v1/me');

        self::assertSame('second', $res['headers']['x-trace']);
    }

    public function testAWpErrorBecomesARuntimeExceptionCarryingItsMessage(): void
    {
        WpHttpStub::$response = new WpErrorStub('cURL error 28: Operation timed out');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('beliq request failed: cURL error 28: Operation timed out');

        (new WpHttpClient())->request('GET', 'https://api.beliq.eu/v1/me');
    }
}
