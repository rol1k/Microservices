<?php
// Принимает новости от NEWS FILTER
// Пересылает Пользователям
// argv[1] - адрес Network Topology
// argv[2] - собственный адрес tcp
// argv[3] - собственный адрес WS
// Пример вызова: php bin/publish-news.php 127.0.0.1:5500 127.0.0.1:5900 127.0.0.1:5910

require __DIR__.'/../vendor/autoload.php';

define('MESSAGE_DELIMITER', '|');

$logger = new Monolog\Logger('publish news');
$logger->pushHandler(new Monolog\Handler\StreamHandler('log.txt', Monolog\Logger::DEBUG));
$logger->addDebug( 'Server is running', ['TOPOLOGY' => $argv[1], 'PUBLISH TCP' => $argv[2]] );

$loop = React\EventLoop\Factory::create();
$pusher = new Microservices\Pusher($logger);

$context = new React\ZMQ\Context($loop);

$topology = $context->getSocket(ZMQ::SOCKET_DEALER);
$topology->connect('tcp://' . $argv[1]);
$logger->addDebug( 'Сonnected to topology', [$argv[1]]);

$publish_news = $context->getSocket(ZMQ::SOCKET_ROUTER);
$publish_news_name = uniqid();
$publish_news->setSockOpt(ZMQ::SOCKOPT_IDENTITY, $publish_news_name);
$publish_news->bind('tcp://' . $argv[2]);

$message = [
	'action' => 'add_node',
	'cluster' => 'PUBLISH TCP',
	'address' => $argv[2],
	'name' => $publish_news_name
];
$topology->send( json_encode($message) );
$logger->addInfo( 'Request to topology', $message );

$message = [
	'action' => 'add_node',
	'cluster' => 'PUBLISH WS',
	'address' => $argv[3],
	'name' => null
];
$topology->send( json_encode($message) );
$logger->addInfo( 'Request to topology', $message );

$publish_news->on('messages', array($pusher, 'onNewsEntry'));

list($ip, $port) = explode(':', $argv[3]);
$webSock = new React\Socket\Server($loop);
$webSock->listen($port, $ip);
$webServer = new Ratchet\Server\IoServer(
    new Ratchet\Http\HttpServer(
        new Ratchet\WebSocket\WsServer(
            new Ratchet\Wamp\WampServer(
                $pusher
            )
        )
    ),
    $webSock
);

$loop->run();