<?php
namespace Microservices;
use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;

class Receive implements MessageComponentInterface {
	protected $clients;
	public $response_info = [];

	public function __construct( array &$response_info ) {
		$this->clients = new \SplObjectStorage;
		$this->response_info = &$response_info;
	}

	public function onOpen(ConnectionInterface $conn) {
		// Store the new connection to send messages to later
		// $this->clients->attach($conn);
		// echo "Новое соединение ({$conn->resourceId})", PHP_EOL;
	}

	public function onMessage(ConnectionInterface $from, $msg) {
		$msg = json_decode($msg);
		printf('From: user %d. id: %s'.PHP_EOL, $from->resourceId, $msg->id);

		if(isset( $this->response_info[$msg->id] )) {
			$this->response_info[$msg->id]['user_connection'] = $from;
			while(0 < count($this->response_info[$msg->id]['buffer'])) {
				$buffer_message = array_shift($this->response_info[$msg->id]['buffer']);
				$from->send( sprintf('По запросу пользователя: %s'.PHP_EOL, $buffer_message) );
			}
		} else {
			$from->send( sprintf('Пользователь: не найден буфер для %s'.PHP_EOL, $msg->id) );
		}
		// if($this->clients->contains($from)) {
		// 	$this->clients->rewind();
		// 	while($this->clients->valid()) {
		// 		if($this->clients->current()->resourceId == $from->resourceId) {
		// 			$this->clients->setInfo($msg->id);
		// 			break;
		// 		}
		// 	}
		// }
	}

	public function onClose(ConnectionInterface $conn) {
		// The connection is closed, remove it, as we can no longer send it messages
		// $this->clients->detach($conn);

		// echo "Соединение ({$conn->resourceId}) закрыто", PHP_EOL;
	}

	public function onError(ConnectionInterface $conn, \Exception $e) {
		// echo "Ошибка: {$e->getMessage()}", PHP_EOL;

		// $conn->close();
	}
}