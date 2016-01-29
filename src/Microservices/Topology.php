<?php
namespace Microservices;
use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;

class Topology implements MessageComponentInterface {
	protected $clients;
	private $nt;

	public function __construct(\Microservices\NetworkTopology $nt) {
		$this->clients = new \SplObjectStorage;
		$this->nt = $nt;
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

		list($action, $msg) = explode('|', $msg);

		if('getlistnode' == $action) {
			$msg = json_decode($msg);
			$listNode = $this->nt->getListNode($msg);
			$from->send( $action.'|'.json_encode($listNode) );
		}
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