<?php
// Принимает новости от NEWS FILTER
// Пересылает Пользователям
// argv[1] - адрес Network Topology
// argv[2] - собственный адрес WS
// argv[3] - адрес Network Topology Router
// Пример вызова: php publish-news.php 127.0.0.1:5500 127.0.0.1:8083 127.0.0.1:5502

require __DIR__.'/../vendor/autoload.php';

define('LINK_WS', 'RATCHET PUBLISH WS');
// define('LINK_TCP', 'RATCHET PUBLISH TCP');
define('MESSAGE_DELIMITER', '|');

list($ip, $port) = explode(':', $argv[2]);

$loop = React\EventLoop\Factory::create();
$pusher = new Microservices\Pusher;

$context = new React\ZMQ\Context($loop);

$dealer = $context->getSocket(\ZMQ::SOCKET_DEALER);
$dealer->connect('tcp://' . $argv[3]);
$dealer->send('get_topology_pub' . MESSAGE_DELIMITER);

$subTopology = $context->getSocket(\ZMQ::SOCKET_SUB);
$subTopology->subscribe('NEWS FILTER');

$dealer->on('message', function ($message) use ($loop, $subTopology, $dealer, $argv){
	list($action, $address) = explode(MESSAGE_DELIMITER, $message, 2);
	$address = json_decode($address);

	if('get_topology_pub' == $action) {
		$subTopology->connect('tcp://'.$address);
		$loop->addTimer(1, function() use ($dealer, $argv){
			$message = [
				'type' => 'add_node',
				'cluster' => LINK_WS,
				'address' => $argv[2]
			];
			$dealer->send( implode(MESSAGE_DELIMITER, $message) );
		});
	}
});

$newsFilterList = new \SplObjectStorage();

$addressIsset = function($address, $nodeList) {
	foreach ($nodeList as $node) {
		if($address == $nodeList->offsetGet($node)){
			return true;
		}
	}
	return false;
};

$subTopology->on('message', function($msg) use ($context, &$newsFilterList, $addressIsset, $pusher) {
	list($linkName, $addresses) = explode(MESSAGE_DELIMITER, $msg, 2);
	$addresses = json_decode($addresses);
	if(0 == count($addresses)) {
		return;
	}
	if('NEWS FILTER' == $linkName) {
		foreach($addresses as $address) {
			if($addressIsset($address, $newsFilterList)) {
				continue;
			} else {
				$subFilterNew = $context->getSocket(ZMQ::SOCKET_SUB);
				$subFilterNew->connect('tcp://'.$address);
				$subFilterNew->subscribe('');
				$subFilterNew->on('message', array($pusher, 'onNewsEntry'));
				$newsFilterList->attach($subFilterNew, $address);
			}
		}
	}
});

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