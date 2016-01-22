<?php
require dirname(__DIR__) . '/vendor/autoload.php';

$loop   = React\EventLoop\Factory::create();

$context = new React\ZMQ\Context($loop);
$push = $context->getSocket(ZMQ::SOCKET_PUSH);
$push->connect('tcp://127.0.0.1:5554');
$writer = new Microservices\Writer($push);

$webSock = new React\Socket\Server($loop);
$webSock->listen(8081, '0.0.0.0');
$webServer = new Ratchet\Server\IoServer(
    new Ratchet\Http\HttpServer(
        new Ratchet\WebSocket\WsServer(
        	$writer
        )
    ),
    $webSock
);

$loop->run();

// use Ratchet\Server\IoServer;
// use Ratchet\Http\HttpServer;
// use Ratchet\WebSocket\WsServer;
// use Microservices\Writer;

// $server = IoServer::factory(
// 	new HttpServer(
// 		new WsServer(
// 			new Writer()
// 		)
// 	),
// 	8081
// );

// $server->run();
