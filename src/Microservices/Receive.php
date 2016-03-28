<?php
namespace Microservices;
use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;

class Receive implements MessageComponentInterface {
	// protected $clients;
	public $response_data = [];

	public function __construct( array &$response_data ) {
		// $this->clients = new \SplObjectStorage;
		$this->response_data = &$response_data;
	}

	public function onOpen(ConnectionInterface $conn) {
		// Store the new connection to send messages to later
		// $this->clients->attach($conn);
		// echo "Новое соединение ({$conn->resourceId})", PHP_EOL;
	}

	public function onMessage(ConnectionInterface $from, $msg) {
		$msg = json_decode($msg);
		printf('From: user %d. id: %s'.PHP_EOL, $from->resourceId, $msg->id);

		if(isset( $this->response_data[$msg->id] )) {
			$this->response_data[$msg->id]['user_connection'] = $from;
			while(0 < count($this->response_data[$msg->id]['buffer'])) {
				$buffer_message = $this->response_data[$msg->id]['buffer'];
				$this->response_data[$msg->id]['buffer'] = [];

				$from->send( json_encode( $buffer_message) );
			}
		} else {
			$from->send( json_encode(sprintf('Результаты запроса %s уже удалены'.PHP_EOL, $msg->id)) );
		}
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