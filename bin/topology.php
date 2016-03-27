<?php
// Управляет топологией сети
// Пример вызова: php bin/topology.php

define('TOPOLOGY_ADDRESS', '127.0.0.1:5500');
define('TOPOLOGY_WS_ADDRESS', '127.0.0.1:5520');
define('TOPOLOGY_PUB_ADDRESS', '127.0.0.1:5510');
define('MESSAGE_DELIMITER', '|');

require __DIR__.'/../vendor/autoload.php';

$nt = new Microservices\NetworkTopology;
$nt->add_cluster('RECEIVE WS', 'Уведомляет пользователя о статусе новости');
$nt->add_cluster('RECEIVE HTTP', 'Получает новость от пользователя');
$nt->add_cluster('PUBLISH WS', 'Публикует новость');
$nt->add_cluster('PUBLISH TCP', 'Получает проверенную новость');
$nt->add_cluster('IMAGE HANDLER TCP', 'Обрабатывает изображение');
$nt->add_cluster('TEXT HANDLER TCP', 'Проверяет новость на стоп-слова');

$loop = React\EventLoop\Factory::create();
$context = new React\ZMQ\Context($loop);

$pub = $context->getSocket(ZMQ::SOCKET_PUB);
$pub->bind('tcp://' . TOPOLOGY_PUB_ADDRESS);

$router = $context->getSocket(ZMQ::SOCKET_ROUTER);
$router->bind('tcp://' . TOPOLOGY_ADDRESS);

$add_node = function($action, $cluster, $address, $from) use ($router, $pub, $nt) {
	if(false === $nt->add_node($cluster, $address)) {
		$message = [
			'action' => $action,
			'error' => true,
			'error_message' => 'Адрес не добавлен'
		];
	} else {
		$message = [
			'action' => $action,
			'error' => false,
			'error_message' => null
		];
	}

	$router->send( [$from, json_encode($message)] );

	// !! TODO: Проверить условие, кажется что работает не правильно
	if ($clusters = $nt->get_clusters_name()) {
		foreach ($clusters as $cluster) {
			$message = [
				'cluster' => $cluster,
				'list_node' => $nt->get_list_node($cluster)
			];
			$pub->send( json_encode($message) );
		}
	}
};

$get_topology_pub = function($action, $from) use ($router) {
	$message = [
		'action' => $action,
		'address' => TOPOLOGY_PUB_ADDRESS
	];
	$router->send( [$from, json_encode($message)] );
};

$router->on('messages', function ($msg) use ($loop, $router, $add_node, $get_topology_pub) {
	$from = $msg[0];
	$msg = json_decode($msg[1]);

	printf('From: node %s. ', $from);
	foreach ($msg as $key => $value) {
		printf("%s: %s\t", $key, $value);
	}
	echo PHP_EOL;

	if('add_node' == $msg->action) {
		$add_node($msg->action, $msg->cluster, $msg->address, $from);
	} elseif('get_topology_pub' == $msg->action) {
		$get_topology_pub($msg->action, $from);
	} else {
		$message = [
			'action' => $msg->action,
			'error' => "Действие {$msg->action} не найдено"
		];
		$router->send( [$from, json_encode($message)] );
	}
});

list($ip, $port) = explode(':', TOPOLOGY_WS_ADDRESS);

$topology = new Microservices\Topology($nt);

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