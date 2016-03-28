<?php
// Сохраняет изображения.
// argv[1] - адрес Network Topology
// argv[2] - собственный адрес tcp
// argv[3] - адрес http сервера
// Пример вызова: php bin/image-handler.php 127.0.0.1:5500 127.0.0.1:5700  127.0.0.1:5400

define('MESSAGE_DELIMITER', '|');
define('POST_MESSAGE_DELIMITER', 'delimiter');

require __DIR__.'/../vendor/autoload.php';

$loop = \React\EventLoop\Factory::create();
$context = new \React\ZMQ\Context($loop);

$topology = $context->getSocket(ZMQ::SOCKET_DEALER);
$topology->connect('tcp://' . $argv[1]);

$image_handler = $context->getSocket(ZMQ::SOCKET_ROUTER);
$image_handler->bind('tcp://' . $argv[2]);

$images = [];
$images_folder = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'http' . DIRECTORY_SEPARATOR . 'images' . DIRECTORY_SEPARATOR;

$message = [
	'action' => 'add_node',
	'cluster' => 'IMAGE HANDLER TCP',
	'address' => $argv[2]
];
$topology->send( json_encode($message) );

$topology->on('message', function ($msg) use ($loop, $argv, $topology) {
	$msg = json_decode($msg);

	if ('add_node' == $msg->action && $msg->error) {
		// exit($msg->error_message);
	}
});

$image_handler->on('messages', function($msg) use (&$images, $images_folder, $loop, $image_handler, $argv) {
	$from = $msg[0];
	$msg = explode(POST_MESSAGE_DELIMITER, $msg[1]);
	$message_type = array_shift($msg);

	if ('new' == $message_type) {
		// новое изображение
		list($id, $original_name) = $msg;
		$image_type = strrev(explode('.', strrev($original_name), 2)[0]);
		$image_name = uniqid() . '.' . $image_type;
		$images_path = $images_folder . $image_name;
		$resource = fopen($images_path, 'w');
		if($resource) {
			$images[$id]['stream'] = new \React\Stream\Stream($resource, $loop);
			$images[$id]['name'] = $image_name;
		}
	} elseif('chunk' == $message_type) {
		// запись данных в поток
		list($id, $data) = $msg;
		if(isset($images[$id])) {
			$images[$id]['stream']->write($data);
		}
	} elseif('end' == $message_type) {
		// закрыть поток
		list($id) = $msg;
		if(isset($images[$id])) {
			$images[$id]['stream']->end();
			$message = [
				'id' => $id,
				'error' => false,
				'path' => $argv[3].'/images/'.$images[$id]['name']
			];
			unset($images[$id]);
		} else {
			$message = [
				'id' => $id,
				'error' => true
			];
		}
		$image_handler->send([$from, json_encode($message)]);
	}
});

$loop->run();