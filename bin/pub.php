<?php
require __DIR__.'/../vendor/autoload.php';

$loop = React\EventLoop\Factory::create();
$context = new React\ZMQ\Context($loop);

$pull = $context->getSocket(ZMQ::SOCKET_PULL);
$pull->bind('tcp://127.0.0.1:5554');

$pub = $context->getSocket(ZMQ::SOCKET_PUB);
$pub->connect('tcp://127.0.0.1:5555');

// $i = 0;
// $loop->addPeriodicTimer(1, function () use (&$i, $pub) {
// 	$i++;
// 	switch(mt_rand(1,3)){
// 		case 1: $subject = 'Автомобили'; break;
// 		case 2: $subject = 'Электроника'; break;
// 		case 3: $subject = 'Спорт'; break;
// 	}
// 	$entryData = array(
// 		'category' => $subject,
// 		'news' => $i,
// 		'image' => $i,
// 		'date' => time()
// 	);
// 	printf('Отправил сообщение [%d] | Тема сообщения [%s]'.PHP_EOL, $i, $subject);
// 	$pub->send( json_encode($entryData) );
// });

$pull->on('message', function($msg) use ($pub){
	echo 'Получил сообщение: ', $msg, PHP_EOL;
	$pub->send( $msg );
});

$loop->run();
