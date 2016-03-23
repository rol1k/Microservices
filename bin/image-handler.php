<?php
// Сохраняет изображения.
// argv[1] - адрес Network Topology
// argv[2] - собственный адрес tcp
// Пример вызова: php image-handler.php 127.0.0.1:5500 127.0.0.1:5700

define('MESSAGE_DELIMITER', '|');
define('POST_MESSAGE_DELIMITER', 'delimiter');

require __DIR__.'/../vendor/autoload.php';

$loop = \React\EventLoop\Factory::create();
$context = new \React\ZMQ\Context($loop);

$topology = $context->getSocket(ZMQ::SOCKET_DEALER);
$topology->connect('tcp://' . $argv[1]);

$image_handler = $context->getSocket(ZMQ::SOCKET_ROUTER);
$image_handler->bind('tcp://' . $argv[2]);

$message = [
	'type' => 'add_node',
	'cluster' => 'IMAGE HANDLER TCP',
	'address' => $argv[2]
];
$topology->send( implode(MESSAGE_DELIMITER, $message) );

$images = [];

$images_path = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'http' . DIRECTORY_SEPARATOR . 'images' . DIRECTORY_SEPARATOR;

$image_handler->on('messages', function($message) use (&$images, $images_path, $loop, $image_handler) {
	$from = $message[0];
	$message = explode(POST_MESSAGE_DELIMITER, $message[1]);
	$message_type = array_shift($message);

	if ('new' == $message_type) {
		// новое изображение
		list($id, $original_name) = $message;
		$file_type = strrev(explode('.', strrev($original_name), 2)[0]);
		$file_name = $images_path . $id . '.' . $file_type;
		$images[$id] = new \React\Stream\Stream(fopen($file_name, 'w'), $loop);
	} elseif('chunk' == $message_type) {
		// запись данных в поток
		list($id, $data) = $message;
		$images[$id]->write($data);
	} elseif('end' == $message_type) {
		// закрыть поток
		list($id) = $message;
		$images[$id]->end();
		unset($images[$id]);
		$image_handler->send([$from, 'Изображение сохранено']);
	}
});

$loop->run();