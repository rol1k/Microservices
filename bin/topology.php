<?php
// Управляет топологией сети
// Пример вызова: php bin/topology.php

require __DIR__.'/../vendor/autoload.php';

define('TOPOLOGY_ADDRESS', '127.0.0.1:5500');
define('TOPOLOGY_WS_ADDRESS', '127.0.0.1:5520');
define('TOPOLOGY_PUB_ADDRESS', '127.0.0.1:5510');
define('MESSAGE_DELIMITER', '|');

$logger = new Monolog\Logger('topology');
$logger->pushHandler(new Monolog\Handler\StreamHandler('log.txt', Monolog\Logger::DEBUG));
$logger->addDebug('Server is running', ['TOPOLOGY' => TOPOLOGY_ADDRESS, 'TOPOLOGY_WS' => TOPOLOGY_WS_ADDRESS, 'TOPOLOGY_PUB' => TOPOLOGY_PUB_ADDRESS]);

$nt = new Microservices\NetworkTopology;
$nt->add_cluster('RECEIVE WS', 'Уведомляет пользователя о статусе новости');
$nt->add_cluster('RECEIVE HTTP', 'Получает новость от пользователя');
$nt->add_cluster('PUBLISH WS', 'Публикует новость');
$nt->add_cluster('PUBLISH TCP', 'Получает проверенную новость');
$nt->add_cluster('IMAGE HANDLER TCP', 'Обрабатывает изображение');
$nt->add_cluster('NEWS HANDLER TCP', 'Проверяет новость на стоп-слова');
$logger->addDebug('Added clusters', $nt->get_clusters_name());

$loop = React\EventLoop\Factory::create();
$context = new React\ZMQ\Context($loop);

$pub = $context->getSocket(ZMQ::SOCKET_PUB);
$pub->bind('tcp://' . TOPOLOGY_PUB_ADDRESS);

$topology = $context->getSocket(ZMQ::SOCKET_ROUTER);
$topology->setSockOpt(ZMQ::SOCKOPT_IDENTITY, 'topology');
$topology->bind('tcp://' . TOPOLOGY_ADDRESS);

$add_node = function($action, $cluster, $address, $name, $from) use ($topology, $pub, $nt, $logger) {
	if(false === $nt->add_node($cluster, $address, $name)) {
		$message = [
			'action' => $action,
			'error' => true,
			'error_message' => 'Address not added'
		];
		$logger->addDebug( 'Address not added', ['cluster' => $cluster, 'address' => $address, 'name' => $name] );
	} else {
		$message = [
			'action' => $action,
			'error' => false,
			'error_message' => null
		];
		$logger->addDebug( 'Address added', ['cluster' => $cluster, 'address' => $address, 'name' => $name] );
	}

	$logger->addInfo( 'Response to the node', ['node' => $from, 'message' => $message] );
	$topology->send( [$from, json_encode($message)] );

	foreach ($nt->get_clusters_name() as $cluster) {
		$message = [
			'cluster' => $cluster,
			'list_node' => $nt->get_list_node($cluster)
		];
		$pub->send( json_encode($message) );
	}
	$logger->addInfo( 'Notified subscribers about the new server', ['cluster' => $cluster, 'address' => $address, 'name' => $name]);
};

$get_topology_pub = function($action, $from) use ($topology, $logger) {
	$message = [
		'action' => $action,
		'address' => TOPOLOGY_PUB_ADDRESS
	];
	$logger->addInfo( 'Response to the node', ['node' => $from, 'message' => $message] );
	$topology->send( [$from, json_encode($message)] );
};

$topology->on('messages', function ($msg) use ($loop, $topology, $add_node, $get_topology_pub, $logger) {
	$from = $msg[0];
	$msg = json_decode($msg[1]);

	$logger->addInfo( sprintf('Request from the node %s', $from), get_object_vars($msg) );

	if('add_node' == $msg->action) {
		$add_node($msg->action, $msg->cluster, $msg->address, isset($msg->name) ? $msg->name : null, $from);
	} elseif('get_topology_pub' == $msg->action) {
		$get_topology_pub($msg->action, $from);
	} else {
		$message = [
			'action' => $msg->action,
			'error' => "Action \"{$msg->action}\" not found"
		];
		$logger->addWarning( "Action \"{$msg->action}\" not found", ['from' => $from] );
		$logger->addInfo( 'Response to the node', ['node' => $from, 'message' => $message] );
		$topology->send( [$from, json_encode($message)] );
	}
});

list($ip, $port) = explode(':', TOPOLOGY_WS_ADDRESS);

$topology = new Microservices\Topology($nt, $logger);

$webSock = new React\Socket\Server($loop);
$webSock->listen($port, $ip);
$webServer = new Ratchet\Server\IoServer(
	new Ratchet\Http\HttpServer(
		new Ratchet\WebSocket\WsServer(
			$topology
		)
	),
	$webSock
);

$loop->run();