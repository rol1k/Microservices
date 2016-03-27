<?php
namespace Microservices;

class NetworkTopology {
	public $node_list;

	public function __construct(){
		$this->node_list = new \stdClass;
	}

	public function add_cluster($cluster_name, $description) {
		$link = new \stdClass;
		$link->description = $description;
		$link->nodes = [];
		$this->node_list->$cluster_name = $link;
	}

	public function get_clusters_name() {
		$linksName = array();
		foreach ($this->node_list as $cluster_name => $node) {
			$linksName[] = $cluster_name;
		}

		return $linksName;
	}

	public function remove_cluster($cluster_name) {
		if($this->contains_cluster($cluster_name)) {
			unset($this->node_list->$cluster_name);
			return true;
		} else {
			return false;
		}
	}

	public function contains_cluster($cluster_name) {
		return isset($this->node_list->$cluster_name) ? true : false;
	}

	public function add_node($cluster_name, $address) {
		if(!$this->contains_cluster($cluster_name)) {
			return false;
		}

		$node_list = &$this->node_list->$cluster_name->nodes;
		// адрес уникальный?
		if(in_array($address, $node_list) === false) {
			$node_list[] = $address;
			return true;
		} else {
			return false;
		}
	}

	public function remove_node($cluster_name, $address) {
		if(!$this->contains_cluster($cluster_name)) {
			return false;
		}

		$node_list = &$this->node_list->$cluster_name->nodes;

		if(($id = array_search($address, $node_list)) !== false) {
			unset($node_list[$id]);
			return true;
		} else {
			return false;
		}
	}

	public function get_list_node($cluster_name) {
		if(!$this->contains_cluster($cluster_name)) {
			return false;
		} else {
			$addresses = $this->node_list->$cluster_name->nodes;
			return $addresses;
		}
	}

	public function get_next_node($cluster_name) {
		$addresses = &$this->node_list->$cluster_name->nodes;
		if(!$this->contains_cluster($cluster_name) || count($addresses) < 1) {
			return false;
		}

		$firstElement = array_shift($addresses);
		array_push($addresses, $firstElement);
		return $firstElement;
	}

	public function get_description($cluster_name) {
		if(!$this->contains_cluster($cluster_name)) {
			return false;
		}

		return $this->node_list->$cluster_name->description;
	}
}