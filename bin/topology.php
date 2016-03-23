<?php
// Управляет топологией сети

define('TOPOLOGY_ADDRESS', '127.0.0.1:5500');
define('TOPOLOGY_WS_ADDRESS', '127.0.0.1:8090');
define('TOPOLOGY_PUB_ADDRESS', '127.0.0.1:5501');
define('TOPOLOGY_ROUTER_ADDRESS', '127.0.0.1:5502');
define('MESSAGE_DELIMITER', '|');

require __DIR__.'/../vendor/autoload.php';

$nt = new Microservices\NetworkTopology;
$nt->addLink('DB', 'База данных');
$nt->addLink('DB COMMUNICATION', 'Взайимодействие с базой данных');
$nt->addLink('RATCHET RECEIVE WS', 'Получает новости');
$nt->addLink('RATCHET RECEIVE TCP', 'Передаёт новости на модерацию');
$nt->addLink('NEWS FILTER', 'Проверка новостей на стоп-слова');
$nt->addLink('RATCHET PUBLISH WS', 'Публикует новости');

$loop = React\EventLoop\Factory::create();
$context = new React\ZMQ\Context($loop);

$rep = $context->getSocket(ZMQ::SOCKET_REP);
$rep->bind('tcp://' . TOPOLOGY_ADDRESS);

$pub = $context->getSocket(ZMQ::SOCKET_PUB);
$pub->bind('tcp://' . TOPOLOGY_PUB_ADDRESS);

$router = $context->getSocket(ZMQ::SOCKET_ROUTER);
$router->bind('tcp://' . TOPOLOGY_ROUTER_ADDRESS);

$add_node = function($action, $linkName, $address, $from) use ($router, $pub, $nt) {
	if(false === $nt->addNode($linkName, $address)) {
		$response = 'Адрес не добавлен';
	} else {
		$response = 'Адрес добавлен';
	}

	$message = [
		'action' => $action,
		'response' => json_encode($response)
	];
	$router->send( [$from, implode(MESSAGE_DELIMITER, $message)] );

	if ($linksName = $nt->getLinksName()) {
		foreach ($linksName as $linkName) {
			$message = [
				'cluster' => $linkName,
				'cluster_list' => json_encode($nt->getListNode($linkName))
			];
			$pub->send( implode(MESSAGE_DELIMITER, $message) );
		}
	}
};

$get_topology_pub = function($action, $from) use ($router) {
	$response = TOPOLOGY_PUB_ADDRESS;
	$message = [
		'action' => $action,
		'response' => json_encode($response)
	];
	$router->send( [$from, implode(MESSAGE_DELIMITER, $message)] );
};

$router->on('messages', function ($msg) use ($loop, $router, $add_node, $get_topology_pub) {
	$loop->addTimer(0, function() use ($router, $msg, $add_node, $get_topology_pub) {
		$from = $msg[0];
		echo $msg[1], PHP_EOL;
		list($action, $msg) = explode(MESSAGE_DELIMITER, $msg[1], 2);
		$action = strtolower($action);

		if('add_node' == $action) {
			list($linkName, $address) = explode(MESSAGE_DELIMITER, $msg);
			$add_node($action, strtoupper($linkName), $address, $from);
		} elseif('get_topology_pub' == $action) {
			$get_topology_pub($action, $from);
		} else {
			$message = [
				'action' => $action,
				'error' => json_encode("Действие {$action} не найдено")
			];
			$router->send( [$from, implode(MESSAGE_DELIMITER, $message)] );
		}
	});
});

$rep->on('message', function ($msg) use ($loop, $rep) {
	$loop->addTimer(0, function() use ($rep, $msg) {
		// printf('%s %s %s', $action, $linkName, $address);
		echo $msg, PHP_EOL;
		list($action, $msg) = explode(MESSAGE_DELIMITER, $msg, 2);
		$action = strtolower($action);

		if('add_node' == $action) {
			list($linkName, $address) = explode(MESSAGE_DELIMITER, $msg);
			$rep->emit('add_node', array($action, strtoupper($linkName), $address));
		} elseif('get_topology_pub' == $action) {
			$rep->emit('get_topology_pub', array($action));
		} else {
			$message = [
				'action' => $action,
				'error' => json_encode("Действие {$action} не найдено")
			];
			$rep->send( implode(MESSAGE_DELIMITER, $message) );
		}
	});
});

$rep->on('get_topology_pub', function($action) use ($rep){
	$response = TOPOLOGY_PUB_ADDRESS;
	$message = [
		'action' => $action,
		'response' => json_encode($response)
	];
	$rep->send( implode(MESSAGE_DELIMITER, $message) );
});

$rep->on('add_node', function($action, $linkName, $address) use ($rep, $pub, $nt){
	if(false === $nt->addNode($linkName, $address)) {
		$response = 'Адрес не добавлен';
	} else {
		$response = 'Адрес добавлен';
	}

	$message = [
		'action' => $action,
		'response' => json_encode($response)
	];
	$rep->send( implode(MESSAGE_DELIMITER, $message) );

	if ($linksName = $nt->getLinksName()) {
		foreach ($linksName as $linkName) {
			$message = [
				'cluster' => $linkName,
				'cluster_list' => json_encode($nt->getListNode($linkName))
			];
			$pub->send( implode(MESSAGE_DELIMITER, $message) );
		}
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