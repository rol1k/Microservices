<?php
// Получает текст от receive-news.php
// Проверяет на стоп-слова
// Отправляет результат обратно
// argv[1] - адрес Network Topology
// argv[2] - собственный адрес tcp
// Пример вызова: php bin/text-handler.php 127.0.0.1:5500 127.0.0.1:5800

require __DIR__.'/../vendor/autoload.php';

// define('TIME_TO_CONNECT', 1);
define('MESSAGE_DELIMITER', '|');

$logger = new Monolog\Logger('text handler');
$logger->pushHandler(new Monolog\Handler\StreamHandler('log.txt', Monolog\Logger::DEBUG));
$logger->addDebug( 'Server is running', ['TOPOLOGY' => $argv[1], 'NEWS HANDLER TCP' => $argv[2]] );

$loop = React\EventLoop\Factory::create();
$context = new React\ZMQ\Context($loop);

$topology = $context->getSocket(ZMQ::SOCKET_DEALER);
$topology->connect('tcp://' . $argv[1]);
$logger->addDebug( 'Сonnected to topology', [$argv[1]]);

$text_handler = $context->getSocket(ZMQ::SOCKET_ROUTER);
$text_handler_name = uniqid();
$text_handler->setSockOpt(ZMQ::SOCKOPT_IDENTITY, $text_handler_name);
$text_handler->bind('tcp://' . $argv[2]);

$news = [];

$message = [
	'action' => 'add_node',
	'cluster' => 'NEWS HANDLER TCP',
	'address' => $argv[2],
	'name' => $text_handler_name
];
$topology->send( json_encode($message) );
$logger->addInfo( 'Request to topology', $message );


$topology->on('message', function ($msg) use ($loop, $argv, $topology) {
	$msg = json_decode($msg);

	if ('add_node' == $msg->action && $msg->error) {
		// exit($msg->error_message);
	}
});

$stop_words = [
	'one', 'two', 'three'
];

$check_stop_words = function($text) use ($stop_words) {
	$used_stop_words = [];
	foreach ($stop_words as $word) {
		if(preg_match('/'.$word.'/', $text)) {
			$used_stop_words[] = $word;
		}
	}
	return $used_stop_words;
};

$text_handler->on('messages', function($msg) use (&$news, $loop, $text_handler, $check_stop_words, $logger) {
	$from = $msg[0];
	$msg = json_decode($msg[1]);

	if ('new' == $msg->type) {
		$news[$msg->id] = null;
		$logger->addInfo( 'Request from the node', ['request id' => $msg->id, 'node' => $from] );
	} elseif('chunk' == $msg->type) {
		// порция данных
		$news[$msg->id] .= $msg->text;
	} elseif('end' == $msg->type) {
		// передача завершена
		$message = [
			'id' => $msg->id,
			'stop_words' => $check_stop_words( strtolower($news[$msg->id]) ),
			'news' => $news[$msg->id]
		];
		$text_handler->send([$from, json_encode($message)]);
		$logger->addInfo( 'Response to the node', ['node' => $from, 'message' => $message] );
		unset($news[$msg->id]);
	}
});

$loop->run();
