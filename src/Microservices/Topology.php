<?php
namespace Microservices;
use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;

class Topology implements MessageComponentInterface {
	protected $clients;
	private $nt;
	private $logger;

	public function __construct(\Microservices\NetworkTopology $nt, \Monolog\Logger $logger) {
		// $this->clients = new \SplObjectStorage;
		$this->nt = $nt;
		$this->logger = $logger;
	}

	public function onOpen(ConnectionInterface $conn) {
		// $this->clients->attach($conn);
	}

	public function onMessage(ConnectionInterface $from, $msg) {
		$numRecv = count($this->clients) - 1;

		$msg = json_decode($msg);

		$this->logger->addInfo( 'Request from the user', [ 'user' => $from->resourceId, 'message' => get_object_vars($msg)] );

		if('get_list_node' == $msg->action) {
			$list_node = $this->nt->get_list_node($msg->cluster);
			$message = [
				'action' => $msg->action,
				'cluster' => $msg->cluster,
				'list_node' => $list_node
			];
			$this->logger->addInfo( 'Response to the user', [ 'user' => $from->resourceId, 'message' => $message] );
			$from->send( json_encode($message) );
		} elseif('get_next_node' == $msg->action) {
			$next_node = $this->nt->get_next_node($msg->cluster);
			$message = [
				'action' => $msg->action,
				'cluster' => $msg->cluster,
				'next_node' => $next_node
			];
			$this->logger->addInfo( 'Response to the user', [ 'user' => $from->resourceId, 'message' => $message] );
			$from->send( json_encode($message) );
		}
	}

	public function onClose(ConnectionInterface $conn) {
		// $this->clients->detach($conn);
	}

	public function onError(ConnectionInterface $conn, \Exception $e) {
		// echo "Error: {$e->getMessage()}", PHP_EOL;
		$conn->close();
	}
}