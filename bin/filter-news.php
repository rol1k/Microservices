<?php
// Получает новость от RATCHET RECEIVE
// Проверяет на стоп-слова
// argv[1] - адрес Network Topology
// argv[2] - собственный адрес tcp
// argv[3] - адрес Network Topology Router
// Пример вызова: php filter-news.php 127.0.0.1:5500 127.0.0.1:5540 127.0.0.1:5502

define('LINK', 'NEWS FILTER');
define('MESSAGE_DELIMITER', '|');

require __DIR__.'/../vendor/autoload.php';
// $time = new Microservices\Timestamp;

$loop = React\EventLoop\Factory::create();
$context = new React\ZMQ\Context($loop);

$dealer = $context->getSocket(\ZMQ::SOCKET_DEALER);
$dealer->connect('tcp://' . $argv[3]);
$dealer->send('get_topology_pub|');

$sub = $context->getSocket(\ZMQ::SOCKET_SUB);
$sub->subscribe('RATCHET RECEIVE TCP');
$sub->subscribe('RATCHET PUBLISH TCP');

$pub = $context->getSocket(ZMQ::SOCKET_PUB);
$pub->bind('tcp://' . $argv[2]);

$ratchetReceiveTcpList = new \SplObjectStorage();
$ratchetPublishTcpList = new \SplObjectStorage();

$dealer->on('message', function ($address) use ($loop, $sub, $dealer, $argv){
	list($action,$address) = explode(MESSAGE_DELIMITER, $address, 2);
	$address = json_decode($address);

	if('get_topology_pub' == $action) {
		$sub->connect('tcp://'.$address);
		$loop->addTimer(1, function() use ($dealer, $argv){
			$message = [
				'action' => 'add_node',
				'cluster' => LINK,
				'address' => $argv[2]
			];
			$dealer->send( implode(MESSAGE_DELIMITER, $message) );
		});
	}
});

$addressIsset = function($address, $nodeList) {
	foreach ($nodeList as $node) {
		if($address == $nodeList->offsetGet($node)){
			return true;
		}
	}
	return false;
};

$sub->on('message', function($msg) use ($context, &$ratchetReceiveTcpList, &$dbCommunicationList, $addressIsset, $pub) {
	list($linkName, $addresses) = explode(MESSAGE_DELIMITER, $msg, 2);
	$addresses = json_decode($addresses);
	if(0 == count($addresses)) {
		return;
	}
	if('RATCHET RECEIVE TCP' == $linkName) {
		foreach($addresses as $address) {
			if($addressIsset($address, $ratchetReceiveTcpList)) {
				continue;
			} else {
				$pull = $context->getSocket(\ZMQ::SOCKET_PULL);
				$pull->connect('tcp://'.$address);
				$pull->on('message', function($msg) use ($pub){
					echo 'Получил сообщение ', $msg, PHP_EOL;
					$pub->send( $msg );
				});
				$ratchetReceiveTcpList->attach($pull, $address);
			}
		}
	} elseif('RATCHET PUBLISH TCP' == $linkName) {
		foreach($addresses as $address) {
			if($addressIsset($address, $ratchetPublishTcpList)) {
				continue;
			} else {
				$pub = $context->getSocket(\ZMQ::SOCKET_PUB);
				$pub->connect('tcp://'.$address);
				$ratchetPublishTcpList->attach($pub, $address);
			}
		}
	}
});

$loop->run();
