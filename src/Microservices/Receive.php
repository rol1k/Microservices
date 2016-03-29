<?php
namespace Microservices;
use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;

class Receive implements MessageComponentInterface {
	public $response_data = [];
	private $logger;

	public function __construct( array &$response_data, \Monolog\Logger $logger ) {
		$this->response_data = &$response_data;
		$this->logger = $logger;
	}

	public function onOpen(ConnectionInterface $conn) {
	}

	public function onMessage(ConnectionInterface $from, $msg) {
		$msg = json_decode($msg);
		$this->logger->addInfo( 'The user requests the results of processing news', ['user' => $from->resourceId, 'news id' => $msg->id] );

		if(isset( $this->response_data[$msg->id] )) {
			$this->response_data[$msg->id]['user_connection'] = $from;
			while(0 < count($this->response_data[$msg->id]['buffer'])) {
				$buffer_message = $this->response_data[$msg->id]['buffer'];
				$this->response_data[$msg->id]['buffer'] = [];

				$from->send( json_encode( $buffer_message) );
			}
		} else {
			$from->send( json_encode('The results of the query have been removed') );
			$this->logger->addDebug( 'Response to the user', ['user' => $from->resourceId, 'request id' => $msg->id, 'message' => 'The results of the query have been removed'] );
		}
	}

	public function onClose(ConnectionInterface $conn) {
	}

	public function onError(ConnectionInterface $conn, \Exception $e) {
	}
}