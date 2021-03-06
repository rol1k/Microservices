<?php
namespace Microservices;
use Ratchet\ConnectionInterface;
use Ratchet\Wamp\WampServerInterface;

class Pusher implements WampServerInterface {
	public $logger;
	public function __construct($logger) {
		$this->logger = $logger;
	}
	/**
	 * A lookup of all the topics clients have subscribed to
	 */
	protected $subscribedTopics = array();

	public function onSubscribe(ConnectionInterface $conn, $topic) {
		$this->logger->addDebug( "User ({$conn->resourceId}) subscribed to \"{$topic}\"");
		$this->subscribedTopics[$topic->getId()] = $topic;
	}

	/**
	 * @param string JSON'ified string we'll receive from ZeroMQ
	 */
	public function onNewsEntry($entry) {
		$entryData = json_decode($entry[1], true);

		// If the lookup topic object isn't set there is no one to publish to
		if (!array_key_exists($entryData['topic'], $this->subscribedTopics)) {
			return;
		}

		$topic = $this->subscribedTopics[$entryData['topic']];

		// re-send the data to all the clients subscribed to that category
		$topic->broadcast($entryData);
	}

	public function onUnSubscribe(ConnectionInterface $conn, $topic) {}

	public function onOpen(ConnectionInterface $conn) {
		// echo "Новое соединение ({$conn->resourceId})", PHP_EOL;
	}
	public function onClose(ConnectionInterface $conn) {
		// echo "Соединение ({$conn->resourceId}) закрыто", PHP_EOL;
	}
	public function onCall(ConnectionInterface $conn, $id, $topic, array $params) {
		// In this application if clients send data it's because the user hacked around in console
		$conn->callError($id, $topic, 'You are not allowed to make calls')->close();
	}
	public function onPublish(ConnectionInterface $conn, $topic, $event, array $exclude, array $eligible) {
		// In this application if clients send data it's because the user hacked around in console
		$conn->close();
	}
	public function onError(ConnectionInterface $conn, \Exception $e) {
		$this->logger->addError('Error '.$e->getMessage());
		$conn->close();
	}

	/* The rest of our methods were as they were, omitted from docs to save space */
}