<?php
namespace Microservices;
use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;

class Writer implements MessageComponentInterface {
	protected $clients;
	private $push;

	public function __construct(\React\ZMQ\SocketWrapper $push) {
		$this->clients = new \SplObjectStorage;
		$this->push = $push;
	}

	public function onOpen(ConnectionInterface $conn) {
		// Store the new connection to send messages to later
		$this->clients->attach($conn);

		echo "Новое соединение ({$conn->resourceId})", PHP_EOL;
	}

	public function onMessage(ConnectionInterface $from, $msg) {
		$numRecv = count($this->clients) - 1;
		echo sprintf('Connection %d sending message "%s"' . "\n"
			, $from->resourceId, $msg);

		// добавление даты к сообщению
		$msg = json_decode($msg);
		$msg->date = date('d.m.Y H:i:s');
		$msg = json_encode($msg);

		// foreach ($this->clients as $client) {
		// 	if ($from !== $client) {
		// 		// The sender is not the receiver, send to each client connected
		// 		$client->send($msg);
		// 	}
		// }
		$this->push->send($msg);
	}

	public function onClose(ConnectionInterface $conn) {
		// The connection is closed, remove it, as we can no longer send it messages
		$this->clients->detach($conn);

		echo "Соединение ({$conn->resourceId}) закрыто", PHP_EOL;
	}

	public function onError(ConnectionInterface $conn, \Exception $e) {
		echo "Ошибка: {$e->getMessage()}", PHP_EOL;

		$conn->close();
	}
}