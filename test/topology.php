<?php
// Управляет топологией сети
// Пример вызова: php bin/topology.php

require __DIR__.'/../vendor/autoload.php';

define('TOPOLOGY_ADDRESS', '127.0.0.1:5500');
define('TOPOLOGY_PUB_ADDRESS', '127.0.0.1:5510');
define('MESSAGE_DELIMITER', '|');
define('MAX_RESPONSE_TIME', 25);
define('STATUS_SERVER_NOT_SET', 0);
define('STATUS_SERVER_AVAILABLE', 1);
define('STATUS_SERVER_NOT_AVAILABLE', 2);

$logger = new Monolog\Logger('topology');
$logger->pushHandler(new Monolog\Handler\StreamHandler('php://stdout', Monolog\Logger::DEBUG));
$logger->addDebug('Server is running', ['TOPOLOGY' => TOPOLOGY_ADDRESS, 'TOPOLOGY_PUB' => TOPOLOGY_PUB_ADDRESS]);

$nt = new Microservices\NetworkTopologyNew;
$nt->add_cluster('HANDLER TCP', 'Тестовый кластер');
$logger->addDebug('Added clusters', $nt->get_clusters_name());

$loop = React\EventLoop\Factory::create();
$context = new React\ZMQ\Context($loop);

$topology_pub = $context->getSocket(ZMQ::SOCKET_PUB);
$topology_pub->bind('tcp://' . TOPOLOGY_PUB_ADDRESS);

$topology = $context->getSocket(ZMQ::SOCKET_ROUTER);
$topology->setSockOpt(ZMQ::SOCKOPT_IDENTITY, 'topology');
$topology->bind('tcp://' . TOPOLOGY_ADDRESS);

$add_node = function($action, $cluster, $address, $name, $from) use ($topology, $topology_pub, $nt, $logger) {
	if(false === $nt->add_node($cluster, $from, $address, $name)) {
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

		$message_for_topology_pub = [
			'type' => 'new',
			'cluster' => $cluster,
			'address' => $address,
			'name' => $name
		];
		$topology_pub->send( $cluster . MESSAGE_DELIMITER . json_encode($message_for_topology_pub) );
		$logger->addInfo( 'Notified subscribers about the new server', ['cluster' => $cluster, 'address' => $address, 'name' => $name]);
	}
	$logger->addInfo( 'Response to the node ' . $from, ['message' => $message] );
	$topology->send( [$from, json_encode($message)] );
};

$get_topology_topology_pub = function($action, $from) use ($topology, $logger) {
	$message = [
		'action' => $action,
		'address' => TOPOLOGY_PUB_ADDRESS
	];
	$logger->addInfo( 'Response to the node ' . $from, ['message' => $message] );
	$topology->send( [$from, json_encode($message)] );
};

$get_list_node = function($action, array $clusters, $from) use ($topology, $nt, $logger) {
	foreach ($clusters as $cluster) {
		$list_node[$cluster] = $nt->get_list_node_array($cluster);
	}
	$message = [
		'action' => $action,
		'node_list' => $list_node
	];
	$logger->addInfo( 'Response to the node ' . $from, ['message' => $message] );
	$topology->send( [$from, json_encode($message)] );
};

$messages_storage = [];

$check_availability = function($action, $cluster, $address, $name, $from) use (&$messages_storage, $nt, $topology, $logger, $loop, $topology_pub) {

	if($owner_name = $nt->get_node_owner($cluster, $address, $name)) {
		$logger->addDebug('Сhecking server availability', ['server_name'=>$owner_name]);
	} else {
		$logger->addWarning('Attempt to check the server availability. The owner isn`t set');
		$message = [
			'action' => $action,
			'error' => true,
			'error_message' => 'The owner isn`t set'
		];
		$topology->send( [$from, $message] );
		return;
	}

	$message_id = uniqid();
	$message = [
		'type' => 'check',
		'message_id' => $message_id
	];
	$messages_storage[$message_id] = [STATUS_SERVER_NOT_SET, $cluster, $address, $name];

	$topology->send( [$owner_name, json_encode($message)] );

	$loop->addTimer(MAX_RESPONSE_TIME, function() use ($message_id, $cluster, $address, $name, &$messages_storage, $topology_pub, $logger, $nt) {
		if(isset($messages_storage[$message_id]) && $messages_storage[$message_id][0] == STATUS_SERVER_NOT_SET) {
			$messages_storage[$message_id][0] = STATUS_SERVER_NOT_AVAILABLE;
			$message_for_topology_pub = [
				'type' => 'remove',
				'cluster' => $cluster,
				'address' => $address,
				'name' => $name
			];
			$topology_pub->send( $cluster . MESSAGE_DELIMITER . json_encode($message_for_topology_pub) );
			$logger->addInfo( 'Notified subscribers about the remove server', ['cluster' => $cluster, 'address' => $address, 'name' => $name]);
			$nt->remove_node($cluster, $address, $name);
			unset($messages_storage[$message_id]);
		}
	});
};

$check_availability_result = function($action, $message_id, $availability, $from) use (&$messages_storage, $topology_pub, $logger) {
	if(isset($messages_storage[$message_id])) {
		$messages_storage[$message_id][0] = STATUS_SERVER_AVAILABLE;
		$message_for_topology_pub = [
			'type' => 'update',
			'cluster' => $messages_storage[$message_id][1],
			'address' => $messages_storage[$message_id][2],
			'name' => $messages_storage[$message_id][3],
			'not_prefer' => $availability
		];
		$topology_pub->send( $cluster . MESSAGE_DELIMITER . json_encode($message_for_topology_pub) );
		$logger->addInfo( 'Notified subscribers about the update server', ['cluster' => $cluster, 'address' => $address, 'name' => $name]);
		unset($messages_storage[$message_id]);
	}
};

$topology->on('messages', function ($msg) use ($loop, $topology, $add_node, $get_topology_topology_pub, $get_list_node, $logger, $check_availability, $check_availability_result) {
	$from = $msg[0];
	$msg = json_decode($msg[1]);

	$logger->addInfo( sprintf('Request from the node %s', $from)/*, get_object_vars($msg)*/ );

	if('add_node' == $msg->action) {
		$add_node($msg->action, $msg->cluster, $msg->address, isset($msg->name) ? $msg->name : null, $from);
	} elseif('get_topology_pub' == $msg->action) {
		$get_topology_topology_pub($msg->action, $from);
	} elseif('get_list_node' == $msg->action) {
		$get_list_node( $msg->action, $msg->clusters, $from);
	} elseif('check_availability' == $msg->action) {
		$check_availability( $msg->action, $msg->cluster, $msg->address, $msg->name, $from );
	} elseif('check_availability_result' == $msg->action) {
		$check_availability_result( $msg->action, $msg->message_id, $msg->availability, $from );
	} else {
		$message = [
			'action' => $msg->action,
			'error' => "Action \"{$msg->action}\" not found"
		];
		$logger->addWarning( "Action \"{$msg->action}\" not found", ['from' => $from] );
		$logger->addInfo( 'Response to the node ' . $from, ['message' => $message] );
		$topology->send( [$from, json_encode($message)] );
	}
});

$loop->run();