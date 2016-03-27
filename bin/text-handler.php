<?php
// Получает текст от receive-news.php
// Проверяет на стоп-слова
// Отправляет результат обратно
// argv[1] - адрес Network Topology
// argv[2] - собственный адрес tcp
// Пример вызова: php bin/text-handler.php 127.0.0.1:5500 127.0.0.1:5800

define('MESSAGE_DELIMITER', '|');

require __DIR__.'/../vendor/autoload.php';

$loop = React\EventLoop\Factory::create();
$context = new React\ZMQ\Context($loop);

$topology = $context->getSocket(\ZMQ::SOCKET_DEALER);
$topology->connect('tcp://' . $argv[1]);

$text_handler = $context->getSocket(ZMQ::SOCKET_ROUTER);
$text_handler->bind('tcp://' . $argv[2]);

$news = [];

$message = [
	'action' => 'add_node',
	'cluster' => 'TEXT HANDLER TCP',
	'address' => $argv[2]
];
$topology->send( json_encode($message) );

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

$text_handler->on('messages', function($msg) use (&$news, $loop, $text_handler, $check_stop_words) {
	$from = $msg[0];
	$msg = json_decode($msg[1]);

	if ('new' == $msg->type) {
		$news[$msg->id] = null;
	} elseif('chunk' == $msg->type) {
		// порция данных
		$news[$msg->id] .= $msg->text;
	} elseif('end' == $msg->type) {
		// передача завершена
		$message = [
			'id' => $msg->id,
			'stop_words' => $check_stop_words( $news[$msg->id] )
		];
		sleep(3);
		$text_handler->send([$from, json_encode($message)]);
		unset($news[$msg->id]);
	}
});

$loop->run();
