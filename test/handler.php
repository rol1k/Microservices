<?php
// php handler.php 127.0.0.1:5500 127.0.0.1:5800 true
// php handler.php 127.0.0.1:5500 127.0.0.1:5801 false

require __DIR__.'/../vendor/autoload.php';

$handler_name = uniqid();
$logger = new Monolog\Logger('handler ' . $handler_name);
$logger->pushHandler(new Monolog\Handler\StreamHandler('php://stdout', Monolog\Logger::DEBUG));
$logger->addDebug( 'Server is running', ['TOPOLOGY' => $argv[1], 'HANDLER_TCP' => $argv[2]] );

$loop = React\EventLoop\Factory::create();
$context = new \React\ZMQ\Context($loop);

$topology = $context->getSocket(ZMQ::SOCKET_DEALER);
$topology->connect('tcp://' . $argv[1]);
$logger->addDebug( 'Сonnected to topology', [$argv[1]]);

$handler = $context->getSocket(ZMQ::SOCKET_ROUTER);
$handler->setSockOpt(ZMQ::SOCKOPT_IDENTITY, $handler_name);
$handler->bind('tcp://' . $argv[2]);

$message = [
	'action' => 'add_node',
	'cluster' => 'HANDLER TCP',
	'address' => $argv[2],
	'name' => $handler_name
];
$topology->send( json_encode($message) );
$logger->addInfo( 'Request to topology', $message );

$num_msg = 0;
$handler->on('messages', function($msg) use (&$num_msg, $argv, $handler, $loop, $topology, $logger) {
	$from = $msg[0];
	$msg = json_decode($msg[1]);

	$logger->addDebug( 'Incoming message', ['message_id' => $msg->message_id] );
	$num_msg++;

	if(0 == $num_msg%2 && 'true' === $argv[3]) {
		sleep(10);
		// $logger->addAlert('Server crashed');
		// exit();
	} elseif(1 == $num_msg && 'false' === $argv[3]) {
		// $logger->addAlert('Server crashed');
		// exit();
	}

	if('check' == $msg->type) {
		$message = [
			'type' => 'delivered',
			'node_id' => $msg->node_id,
			'available' => true
		];
		$handler->send([$from, $message]);
		$logger->addDebug( 'Confirmed working' );
	} elseif('test' == $msg->type) {
		$message = [
			'type' => 'delivered',
			'message_id' => $msg->message_id
		];
		$handler->send( [$from, json_encode($message)] );
		$logger->addDebug( 'Message confirmed', ['from' => $from, 'message_id' => $msg->message_id] );
	}
});

$topology->on('message', function($msg) use ($topology) {
	if(isset($msg->action) && 'check' == $msg->action) {
		$message = [
			'action' => 'check_availability_result',
			'availability' => true,
			'message_id' => $messages_id
		];
		$topology->send(['topology', json_encode($message)]);
	}
});

$loop->run();