<?php declare(strict_types=1);

// Router for `php -S`: a stand-in beliq API for the retry smoke, run on the host
// and reached from the containers. It answers from a script of responses that
// the smoke sets over HTTP, so nothing but the network is shared:
//   POST /__stub/script    body: a JSON list of {"status", "headers", "body", "sleep"};
//                          resets the request log. Each request takes the next
//                          entry and the last one repeats.
//   GET  /__stub/requests  the requests since the last script, as a JSON list.
// Every other path is answered from the script. BELIQ_STUB_DIR holds the state.
// Start it with PHP_CLI_SERVER_WORKERS above 1, so a sleeping answer does not
// hold the control endpoints.

$dir = getenv('BELIQ_STUB_DIR');
if ($dir === false || $dir === '') {
    http_response_code(500);
    echo 'BELIQ_STUB_DIR is not set';

    return true;
}

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$lock = fopen($dir . '/lock', 'c');
flock($lock, LOCK_EX);

if ($path === '/__stub/script') {
    file_put_contents($dir . '/responses.json', file_get_contents('php://input'));
    file_put_contents($dir . '/requests.log', '');
    file_put_contents($dir . '/counter', '0');
    flock($lock, LOCK_UN);
    echo 'ok';

    return true;
}
if ($path === '/__stub/requests') {
    $lines = array_filter(explode("\n", (string) @file_get_contents($dir . '/requests.log')));
    flock($lock, LOCK_UN);
    header('Content-Type: application/json');
    echo '[' . implode(',', $lines) . ']';

    return true;
}

$responses = json_decode((string) @file_get_contents($dir . '/responses.json'), true) ?: [['status' => 500, 'body' => 'no script']];
$counter = (int) @file_get_contents($dir . '/counter');
file_put_contents($dir . '/counter', (string) ($counter + 1));
file_put_contents($dir . '/requests.log', json_encode([
    'n' => $counter,
    'at' => microtime(true),
    'method' => $_SERVER['REQUEST_METHOD'],
    'path' => $_SERVER['REQUEST_URI'],
]) . "\n", FILE_APPEND);
flock($lock, LOCK_UN);

$response = $responses[min($counter, count($responses) - 1)];
if (isset($response['sleep'])) {
    sleep((int) $response['sleep']);
}
http_response_code((int) $response['status']);
foreach ($response['headers'] ?? [] as $name => $value) {
    header($name . ': ' . $value);
}
echo $response['body'] ?? '';

return true;
