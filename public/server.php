<?php

use Psr\Http\Message\ServerRequestInterface;
use React\EventLoop\Factory;
use React\Http\Response;
use React\Http\Server;

require_once __DIR__ . '/../vendor/autoload.php';

$loop = Factory::create();

$server = new Server(function (ServerRequestInterface $request) {
    $data = $request->getBody();

    $body = '<pre>' . print_r((string)$data, true) . '</pre>';

    return new Response(
        200,
        array(
            'Content-Type' => 'text/plain'
        ),
        $body
    );
});

$server->on('error', function (Exception $e) {
    echo 'Error: ' . $e->getMessage() . PHP_EOL;
    if ($e->getPrevious() !== null) {
        $previousException = $e->getPrevious();
        echo $previousException->getMessage() . PHP_EOL;
    }
});

$socket = new React\Socket\Server(8080, $loop);
$server->listen($socket);

$loop->run();