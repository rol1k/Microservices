<?php
// Получает новость от RATCHET RECEIVE
// Проверяет на стоп-слова
// argv[1] - адрес Network Topology
// argv[2] - собственный адрес tcp
// Пример вызова: php filter-news.php 127.0.0.1:5500 127.0.0.1:5540

define('LINK', 'DB COMMUNICATION');

require __DIR__.'/../vendor/autoload.php';
// $time = new Microservices\Timestamp;

$loop = React\EventLoop\Factory::create();
$context = new React\ZMQ\Context($loop);

$req = $context->getSocket(\ZMQ::SOCKET_REQ);
$req->connect('tcp://' . $argv[1]);
$req->send('get_topology_pub|');

$sub = $context->getSocket(\ZMQ::SOCKET_SUB);
// $sub->subscribe('DB');
$sub->subscribe('NEWS FILTER');

$newsFilterList = new \SplObjectStorage();

$req->on('message', function ($address) use ($loop, $sub, $req, $argv){
	list($action,$address) = explode('|', $address, 2);
	$address = json_decode($address);

	if('get_topology_pub' == $action) {
		$sub->connect('tcp://'.$address);
		$loop->addTimer(1, function() use ($req, $argv){
			$req->send("add_node|".LINK."|{$argv[2]}");
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

$sub->on('message', function($msg) use ($context, &$newsFilterList, $addressIsset) {
	list($linkName, $addresses) = explode('|', $msg, 2);
	$addresses = json_decode($addresses);
	if(0 == count($addresses)) {
		return;
	}
	if('NEWS FILTER' == $linkName) {
		foreach($addresses as $address) {
			if($addressIsset($address, $newsFilterList)) {
				continue;
			} else {
				$subNewsFilter = $context->getSocket(\ZMQ::SOCKET_SUB);
				$subNewsFilter->connect('tcp://'.$address);
				$subNewsFilter->subscribe('');
				$subNewsFilter->on('message', function($msg){
					echo 'Получил сообщение ', $msg, PHP_EOL;
				});
				$newsFilterList->attach($subNewsFilter, $address);
			}
		}
	}
});

$loop->run();
