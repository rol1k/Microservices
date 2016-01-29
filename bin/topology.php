<?php
// Управляет топологие сети

define('TOPOLOGY_ADDRESS', '127.0.0.1:5500');
define('TOPOLOGY_WS_ADDRESS', '127.0.0.1:8090');
define('TOPOLOGY_PUB_ADDRESS', '127.0.0.1:5501');

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

$rep->on('message', function ($msg) use ($loop, $rep) {
	$loop->addTimer(0, function() use ($rep, $msg) {
		// printf('%s %s %s', $action, $linkName, $address);
		echo $msg, PHP_EOL;
		list($action, $msg) = explode('|', $msg, 2);
		$action = strtolower($action);

		if('addnode' == $action) {
			list($linkName, $address) = explode('|', $msg);
			$rep->emit('addnode', array($action, strtoupper($linkName), $address));
		} elseif('gettopologypub' == $action) {
			$rep->emit('gettopologypub', array($action));
		} else {
			$rep->send( $action.'|'.json_encode("Действие {$action} не найдено") );
		}
	});
});

$rep->on('gettopologypub', function($action) use ($rep){
	$response = TOPOLOGY_PUB_ADDRESS;
	$rep->send( $action.'|'.json_encode($response) );
});

$rep->on('addnode', function($action, $linkName, $address) use ($rep, $pub, $nt){
	if(false === $nt->addNode($linkName, $address)) {
		$response = 'Адрес не добавлен';
	} else {
		$response = 'Адрес добавлен';
	}

	if ($linksName = $nt->getLinksName()) {
		foreach ($linksName as $linkName) {
			$pub->send($linkName.'|'.json_encode($nt->getListNode($linkName)));
		}
	}

	$rep->send( $action.'|'.json_encode($response));
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