<?php
namespace Microservices;

class NetworkTopology {
	public $nodeList;

	public function __construct(){
		$this->nodeList = new \stdClass;
	}

	public function addLink($linkName, $description) {
		$link = new \stdClass;
		$link->description = $description;
		$link->nodes = [];
		$this->nodeList->$linkName = $link;
	}

	public function getLinksName() {
		$linksName = array();
		foreach ($this->nodeList as $linkName => $node) {
			$linksName[] = $linkName;
		}

		return $linksName;
	}

	public function removeLink($linkName) {
		if($this->containsLink($linkName)) {
			unset($this->nodeList->$linkName);
			return true;
		} else {
			return false;
		}
	}

	public function containsLink($linkName) {
		return isset($this->nodeList->$linkName) ? true : false;
	}

	public function addNode($linkName, $address) {
		if(!$this->containsLink($linkName)) {
			return false;
		}

		$nodeList = &$this->nodeList->$linkName->nodes;
		// адрес уникальный?
		if(in_array($address, $nodeList) === false) {
			$nodeList[] = $address;
			return true;
		}

		return false;
	}

	public function removeNode($linkName, $address) {
		if(!$this->containsLink($linkName)) {
			return false;
		}

		$nodeList = &$this->nodeList->$linkName->nodes;

		if(($id = array_search($address, $nodeList)) !== false) {
			unset($nodeList[$id]);
			return true;
		}

		return false;
	}

	public function getListNode($linkName) {
		$addresses = $this->nodeList->$linkName->nodes;
		if(!$this->containsLink($linkName)) {
			return false;
		}

		return $addresses;
	}

	public function getNextNode($linkName) {
		$addresses = &$this->nodeList->$linkName->nodes;
		if(!$this->containsLink($linkName) || count($addresses) < 1) {
			return false;
		}

		$firstElement = array_shift($addresses);
		array_push($addresses, $firstElement);
		return $firstElement;
	}

	public function getDescription($linkName) {
		if(!$this->containsLink($linkName)) {
			return false;
		}

		return $this->nodeList->$linkName->description;
	}
}

$nt = new NetworkTopology();