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

	public function add_node($cluster_name, $address, $name = null) {
		if(!$this->contains_cluster($cluster_name)) {
			return false;
		}

		$node_list = &$this->node_list->$cluster_name->nodes;

		foreach ($node_list as $key => $node) {
			if($address == $node->address) {
				return false;
			}
		}

		$new_node = new \stdClass;
		$new_node->address = $address;
		$new_node->name = $name;
		$node_list[] = $new_node;
		return true;
	}

	public function remove_node($cluster_name, $address) {
		if(!$this->contains_cluster($cluster_name)) {
			return false;
		}

		$node_list = &$this->node_list->$cluster_name->nodes;

		foreach ($node_list as $key => $node) {
			if($node->address == $address) {
				unset($node_list[$key]);
				return true;
			}
		}

		return false;
	}

	public function get_list_node($cluster_name) {
		if(!$this->contains_cluster($cluster_name)) {
			return [];
		} else {
			return $this->node_list->$cluster_name->nodes;
		}
	}

	public function get_next_node($cluster_name) {
		$nodes = &$this->node_list->$cluster_name->nodes;
		if(!$this->contains_cluster($cluster_name) || count($nodes) < 1) {
			return null;
		}

		$node = array_shift($nodes);
		array_push($nodes, $node);
		return $node;
	}

	public function get_description($cluster_name) {
		if(!$this->contains_cluster($cluster_name)) {
			return false;
		}

		return $this->node_list->$cluster_name->description;
	}
}