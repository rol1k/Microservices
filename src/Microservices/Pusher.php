<?php
namespace Microservices;
use Ratchet\ConnectionInterface;
use Ratchet\Wamp\WampServerInterface;

class Pusher implements WampServerInterface {
	/**
	 * A lookup of all the topics clients have subscribed to
	 */
	protected $subscribedTopics = array();

	public function onSubscribe(ConnectionInterface $conn, $topic) {
		echo "Соединение ({$conn->resourceId}) подписалсось на \"{$topic}\"", PHP_EOL;
		$this->subscribedTopics[$topic->getId()] = $topic;
	}

	/**
	 * @param string JSON'ified string we'll receive from ZeroMQ
	 */
	public function onNewsEntry($entry) {
		$entryData = json_decode($entry, true);

		// If the lookup topic object isn't set there is no one to publish to
		if (!array_key_exists($entryData['topics'][0], $this->subscribedTopics)) {
			return;
		}

		$topic = $this->subscribedTopics[$entryData['topics'][0]];

		// re-send the data to all the clients subscribed to that category
		$topic->broadcast($entryData);
	}

	public function onUnSubscribe(ConnectionInterface $conn, $topic) {}

	public function onOpen(ConnectionInterface $conn) {
		echo "Новое соединение ({$conn->resourceId})", PHP_EOL;
	}
	public function onClose(ConnectionInterface $conn) {
		echo "Соединение ({$conn->resourceId}) закрыто", PHP_EOL;
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
		echo "Ошибка: {$e->getMessage()}", PHP_EOL;
		$conn->close();
	}

	/* The rest of our methods were as they were, omitted from docs to save space */
}