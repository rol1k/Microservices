<?php
// php client.php 127.0.0.1:5500

require __DIR__.'/../vendor/autoload.php';

define('MESSAGE_STATUS_QUEUE', 0);
define('MESSAGE_STATUS_SENT', 1);
define('MESSAGE_STATUS_DELIVERED', 2);
define('MAX_RESPONSE_TIME', 3);
define('NUM_UNDELIVERABLE_MESSAGES', 1);
define('TIME_TO_CONNECT', 1);
define('MESSAGE_DELIMITER', '|');

$logger = new Monolog\Logger('client');
$logger->pushHandler(new Monolog\Handler\StreamHandler('php://stdout', Monolog\Logger::DEBUG));
$logger->addInfo( 'Server is running' );

$nt = new Microservices\NetworkServices;

$loop = React\EventLoop\Factory::create();
$context = new \React\ZMQ\Context($loop);

$topology = $context->getSocket(ZMQ::SOCKET_DEALER);
$topology->connect('tcp://'.$argv[1]);
$logger->addDebug( 'Сonnected to topology', [$argv[1]]);

$communication_with = ['HANDLER TCP'];
$messages_storage = [];

$message = ['action' => 'get_topology_pub'];
$topology->send( json_encode($message) );
$logger->addInfo( 'Request to topology', $message );

$message = [
	'action' => 'get_list_node',
	'clusters' => $communication_with
];
$topology->send( json_encode($message) );
$logger->addInfo( 'Request to topology', $message );

$topology_pub = $context->getSocket(\ZMQ::SOCKET_SUB);
foreach ($communication_with as $cluster) {
	$nt->add_cluster($cluster);
	$topology_pub->subscribe( $cluster );
}
$logger->addDebug('Subscribe to changes in clusters', $nt->get_clusters_name());

$handler_new_connections = function($cluster, $nodes) use ($topology_pub, $logger, $context, &$messages_storage, $nt) {
	if('HANDLER TCP' == $cluster) {
		foreach($nodes as $node) {
			$handler = $context->getSocket(\ZMQ::SOCKET_ROUTER);
			$handler->connect('tcp://'.$node->address);
			$logger->addDebug( 'Connected to handler', [$node->address] );
			$handler->on('messages', function($msg) use ($logger, &$messages_storage) {
				// $from = $msg[0];
				$msg = json_decode($msg[1]);
				// $logger->addDebug(' Receive message', ['from' => $from, 'message' => $msg]);
				if('delivered' == $msg->type) {
					if (isset($messages_storage[$msg->message_id])) {
						$messages_storage[$msg->message_id][1] = MESSAGE_STATUS_DELIVERED;
						unset($messages_storage[$msg->message_id]);
						$logger->addDebug('Доставка подтверждена. Сообщение удалено', ['message_id' => $msg->message_id]);
					} else {
						$logger->addDebug('Доставка подтверждена, но из-за долгого ответа сообщение уже доставлено другому узлу', ['message_id' => $msg->message_id]);
					}
				}
			});
			$nt->add_node($cluster, $node->address, $node->name, $handler);
		}
	}
};

$handler_new_connections_pub = function($msg) use ($topology_pub, $logger, $context, &$messages_storage, $nt) {
	if('new' == $msg->type) {
		if('HANDLER TCP' == $msg->cluster) {
			$handler = $context->getSocket(\ZMQ::SOCKET_ROUTER);
			$handler->connect('tcp://'.$msg->address);
			$logger->addDebug( 'Connected to handler', [$msg->address] );
			$handler->on('messages', function($msg) use ($logger, &$messages_storage) {
				$from = $msg[0];
				$msg = json_decode($msg[1]);
				// $logger->addDebug(' Receive message', ['from' => $from, 'message' => $msg]);
				if('delivered' == $msg->type) {
					if (isset($messages_storage[$msg->message_id])) {
						$messages_storage[$msg->message_id][1] = MESSAGE_STATUS_DELIVERED;
						unset($messages_storage[$msg->message_id]);
						$logger->addDebug('Доставка подтверждена. Сообщение удалено', ['message_id' => $msg->message_id]);
					} else {
						$logger->addDebug('Доставка подтверждена, но из-за долгого ответа сообщение уже доставлено другому узлу', ['message_id' => $msg->message_id]);
					}
				}
			});
			$nt->add_node($msg->cluster, $msg->address, $msg->name, $handler);
			$logger->addInfo('New server was added', ['cluster'=>$msg->cluster, 'address'=>$msg->address, 'name' => $msg->name]);
		}
	} elseif ('update' == $msg->type) {
		$nt->update_node($msg->cluster, $msg->address, $msg->name, $msg->not_prefer);
		$logger->addInfo('Server was updated', ['cluster'=>$msg->cluster, 'address'=>$msg->address, 'name' => $msg->name, 'not_prefer' => $msg->not_prefer]);
	} elseif ('remove' == $msg->type) {
		$nt->remove_node($msg->cluster, $msg->address, $msg->name);
		$logger->addInfo('Server was removed', ['cluster'=>$msg->cluster, 'address'=>$msg->address, 'name' => $msg->name]);
	}
};

$confirm_delivery = function($message_id, $node) use (&$confirm_delivery, &$messages_storage, $logger, $loop, $nt, $topology) {
	$loop->addTimer(MAX_RESPONSE_TIME, function() use (&$confirm_delivery, &$messages_storage, $message_id, $logger, $node, $nt, $topology) {
		if(isset($messages_storage[$message_id])) {
			if($messages_storage[$message_id][1] == MESSAGE_STATUS_SENT) {
				$nt->update_node('HANDLER TCP', $node->address, $node->name, null, $node->num_undeliverable_msg++);
				// $handler_tcp_list[$handler]->undeliverable++;
				$logger->addWarning( 'Вышло время ожидания для подтверждения сообщения', ['message_id' => $message_id] );

				if($node->not_prefer == false && $node->num_undeliverable_msg+1 > NUM_UNDELIVERABLE_MESSAGES) {
					$message = [
						'action' => 'check_availability',
						'cluster' => 'HANDLER TCP',
						'address' => $node->address,
						'name' => $node->name
					];
					$topology->send(json_encode($message));
					$logger->addWarning( 'Cервер не отвечает. Уведомил топологию', ['node_name' => $node->name] );
					$nt->update_node('HANDLER TCP', $node->address, $node->name, true, null);
					// $handler_tcp_list[$handler]->not_prefer = true;
					$logger->addDebug( 'Установил статус сервера', ['node_name' => $node->name, 'not_prefer' => true]);
				}

				$next_node = $nt->get_next_node('HANDLER TCP');
				$new_handler = $next_node->zmq_object;
				$new_handler_address = $next_node->address;
				$new_handler_name = $next_node->name;

				$new_handler->send( [ $new_handler_name, json_encode( $messages_storage[$message_id][0] ) ] );
				$logger->addNotice( 'Переслал сообщение', ['node_name' => $new_handler_name, 'message_id' => $message_id] );

				$confirm_delivery($message_id, $next_node);
			}
		}
	});
};

$topology->on('message', function ($msg) use ($topology_pub, $logger, $handler_new_connections) {
	var_dump($msg);
	$msg = json_decode($msg);
	if('get_topology_pub' == $msg->action) {
		$topology_pub->connect('tcp://'.$msg->address);
		$logger->addDebug( 'Сonnected to topology pub', [$msg->address] );
	} elseif ('get_list_node' == $msg->action) {

		foreach ($msg->node_list as $cluster => $nodes) {
			$handler_new_connections($cluster, $nodes);
		}
	} elseif ('add_node' == $msg->action && $msg->error) {
		//exit($msg->error_message);
	}
});

$topology_pub->on('message', function ($msg) use ($handler_new_connections_pub) {
	$msg = json_decode(explode(MESSAGE_DELIMITER, $msg, 2)[1]);
	$handler_new_connections_pub($msg);
});

$get_next_message = function() use (&$messages_storage) {
	$num_messages = count($messages_storage);
	if (0 == $num_messages) {
		return null;
	}

	for ($i=0; $i < $num_messages; $i++) {
		$message = array_shift($messages_storage);
		if(MESSAGE_STATUS_QUEUE == $message[1]) {
			$message[1] = MESSAGE_STATUS_SENT;
			array_push($messages_storage, $message);
		}
	}
};

$run_test = function() use (&$messages_storage, $logger, $loop, $nt, $confirm_delivery) {
	for ($i=0; $i < 3; $i++) {
		$message_id = uniqid();

		$message = [
			'type' => 'test',
			'message_id' => $message_id,
			'message' => 'Сообщение'
		];

		$node = $nt->get_next_node('HANDLER TCP');
		if(!$node) {
			$logger->addError('Нет обработчиков');
			return;
		};

		$handler = $node->zmq_object;
		$handler_name = $node->name;

		$messages_storage[$message_id] = [$message, MESSAGE_STATUS_QUEUE, 'HANDLER TCP'];

		// $logger->addInfo( 'Добавил сообщение в хранилище', ['message_id' => $message_id] );

		// if(isset($messages_storage[$message_id]) && MESSAGE_STATUS_QUEUE == $messages_storage[$message_id][1]) {
		$messages_storage[$message_id][1] = MESSAGE_STATUS_SENT;
		$handler->send( [ $handler_name, json_encode( $message ) ] );
		$logger->addNotice( 'Отправил сообщение', ['node_name' => $handler_name, 'message_id' => $message_id] );
		// }

		$confirm_delivery($message_id, $node);
	}

	$loop->addPeriodicTimer(1, function(React\EventLoop\Timer\Timer $timer) use (&$messages_storage, $logger) {
		if (0 == count($messages_storage)) {
			$logger->addDebug('Все сообщения подтверждены');
			$timer->cancel();
		}
	});

	// $loop->addPeriodicTimer(0, function(React\EventLoop\Timer\Timer $timer) use (&$messages_storage, $logger, $nt, $confirm_delivery, $) {

	// });
};

$loop->addTimer(2, $run_test);

$loop->run();