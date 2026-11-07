<?php declare(strict_types=1);

namespace Beliq\WooCommerce\Tests;

use Beliq\Core\Exception\BeliqTransportException;
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

    /**
     * WordPress hands back a transport failure as a WP_Error whose message is
     * "cURL error <number>: <libcurl's text>" (Requests' cURL transport). The
     * number and, for a timeout, libcurl's text say where the request stopped.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function transportFailures(): iterable
    {
        yield 'refused' => ['cURL error 7: Failed to connect to api.beliq.eu port 443 after 3 ms: Could not connect to server', BeliqTransportException::CONNECT_FAILED];
        yield 'no such host' => ['cURL error 6: Could not resolve host: api.beliq.eu', BeliqTransportException::CONNECT_FAILED];
        yield 'connect timeout' => ['cURL error 28: Connection timed out after 10002 milliseconds', BeliqTransportException::CONNECT_TIMEOUT];
        yield 'resolve timeout' => ['cURL error 28: Resolving timed out after 10000 milliseconds', BeliqTransportException::CONNECT_TIMEOUT];
        yield 'own timeout' => ['cURL error 28: Operation timed out after 30001 milliseconds with 0 bytes received', BeliqTransportException::TIMEOUT];
        yield 'reset after the send' => ['cURL error 56: Recv failure: Connection reset by peer', BeliqTransportException::CONNECTION_LOST];
        yield 'empty reply' => ['cURL error 52: Empty reply from server', BeliqTransportException::CONNECTION_LOST];
        yield 'stalled TLS handshake' => ['cURL error 28: Connection timed out after 10001 milliseconds', BeliqTransportException::CONNECT_TIMEOUT];
        yield 'mistyped base URL' => ['cURL error 3: URL rejected: Malformed input to a URL function', BeliqTransportException::CONFIGURATION];
        yield 'certificate' => ['cURL error 60: SSL certificate problem: unable to get local issuer certificate', BeliqTransportException::TLS];
        yield 'handshake' => ['cURL error 35: OpenSSL SSL_connect: SSL_ERROR_SYSCALL in connection to api.beliq.eu:443', BeliqTransportException::TLS];
        // The fsockopen transport words its failures freely, so where it
        // stopped is unknown: the request may have been sent.
        yield 'another transport' => ['fsockopen(): Unable to connect to api.beliq.eu:443', BeliqTransportException::CONNECTION_LOST];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('transportFailures')]
    public function testATransportFailureCarriesWhereTheRequestStopped(string $message, string $kind): void
    {
        WpHttpStub::$response = new WpErrorStub($message);

        try {
            (new WpHttpClient())->request('GET', 'https://api.beliq.eu/v1/me');
            self::fail('Expected a transport failure.');
        } catch (BeliqTransportException $exception) {
            self::assertSame($kind, $exception->kind);
        }
    }
}
