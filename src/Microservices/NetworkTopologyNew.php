<?php
namespace Microservices;

class NetworkTopologyNew {
	public $node_list;

	public function __construct(){
		$this->node_list = new \stdClass;
	}

	public function add_cluster($cluster_name, $description = null) {
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

	public function add_node($cluster_name, $owner_name, $address, $name = null, $not_prefer = false) {
		if(!$this->contains_cluster($cluster_name)) {
			return false;
		}

		$node_list = &$this->node_list->$cluster_name->nodes;

		foreach ($node_list as $key => $node) {
			if($address == $node->address && $name == $node->name) {
				return false;
			}
		}

		$new_node = new \stdClass;
		$new_node->owner_name = $owner_name;
		$new_node->address = $address;
		$new_node->name = $name;
		$new_node->not_prefer = $not_prefer;
		$node_list[] = $new_node;
		return true;
	}

	public function update_node($cluster_name, $address, $name = null, $not_prefer = false) {
		if(!$this->contains_cluster($cluster_name)) {
			return false;
		}

		$node_list = &$this->node_list->$cluster_name->nodes;

		foreach ($node_list as $key => $node) {
			if($address == $node->address && $name == $node->name) {
				if(null != $not_prefer) {
					$node_list[$key]->not_prefer = $not_prefer;
				}
				return true;
			}
		}

		return false;
	}

	public function remove_node($cluster_name, $address, $name = null) {
		if(!$this->contains_cluster($cluster_name)) {
			return false;
		}

		$node_list = &$this->node_list->$cluster_name->nodes;

		foreach ($node_list as $key => $node) {
			if($node->address == $address && $node->name == $name) {
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

	public function get_list_node_array($cluster_name) {
		if(!$this->contains_cluster($cluster_name)) {
			return [];
		} else {
			$list_node = [];
			foreach ($this->node_list->$cluster_name->nodes as $node) {
				$list_node[] = ['address' => $node->address, 'name' => $node->name];
			}
			return $list_node;
		}
	}

	public function get_node_owner($cluster_name, $address, $name = null) {
		$nodes = &$this->node_list->$cluster_name->nodes;
		if(!$this->contains_cluster($cluster_name) || count($nodes) < 1) {
			return null;
		}

		foreach ($nodes as $node) {
			if ($address == $node->address && $name == $node->name) {
				return $node->owner_name;
			}
		}

		return null;
	}

	public function get_next_node($cluster_name) {
		$nodes = &$this->node_list->$cluster_name->nodes;
		if(!$this->contains_cluster($cluster_name) || count($nodes) < 1) {
			return null;
		}

		for ($i=0, $num_nodes=count($nodes); $i < $num_nodes; $i++) {
			$node = array_shift($nodes);
			array_push($nodes, $node);
			if(false == $node->not_prefer) {
				return $node;
			}
		}

		return null;
	}

	public function get_description($cluster_name) {
		if(!$this->contains_cluster($cluster_name)) {
			return false;
		}

		return $this->node_list->$cluster_name->description;
	}
}