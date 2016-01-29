<?php
// Передаёт новость от пользователя на модерацию
// Передаёт пользователю ответ модерации
// argv[1] - адрес Network Topology
// argv[2] - собственный адрес WS
// argv[3] - собственный адрес tcp
// Пример вызова: php receive-news.php 127.0.0.1:5500 127.0.0.1:8081 127.0.0.1:5530

require dirname(__DIR__) . '/vendor/autoload.php';

define('LINK_WS', 'RATCHET RECEIVE WS');
define('LINK_TCP', 'RATCHET RECEIVE TCP');

list($ip, $port) = explode(':', $argv[2]);

$loop = React\EventLoop\Factory::create();

$context = new React\ZMQ\Context($loop);
$push = $context->getSocket(ZMQ::SOCKET_PUSH);
$push->bind('tcp://'.$argv[3]);

$req = $context->getSocket(\ZMQ::SOCKET_REQ);
$req->connect('tcp://' . $argv[1]);
$req->send("addnode|".LINK_WS."|{$argv[2]}");
$req->send("addnode|".LINK_TCP."|{$argv[3]}");

$req->on('message', function ($address){
	// var_dump($address);

	list($action,$address) = explode('|', $address, 2);
	$address = json_decode($address);
});

$receive = new Microservices\Receive($push);

$webSock = new React\Socket\Server($loop);
$webSock->listen($port, $ip);
$webServer = new Ratchet\Server\IoServer(
    new Ratchet\Http\HttpServer(
        new Ratchet\WebSocket\WsServer(
        	$receive
        )
    ),
    $webSock
);

$loop->run();