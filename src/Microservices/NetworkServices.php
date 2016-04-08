<?php
namespace Microservices;

class NetworkServices extends NetworkTopologyNew {

	public function add_node($cluster_name, $address, $name = null, $zmq_object = null, $not_prefer = false, $num_undeliverable_msg = 0) {
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
		$new_node->address = $address;
		$new_node->name = $name;
		$new_node->zmq_object = $zmq_object;
		$new_node->not_prefer = $not_prefer;
		$new_node->num_undeliverable_msg = $num_undeliverable_msg;
		$node_list[] = $new_node;
		return true;
	}

	public function update_node($cluster_name, $address, $name = null, $not_prefer = false, $num_undeliverable_msg = 0) {
		if(!$this->contains_cluster($cluster_name)) {
			return false;
		}

		$node_list = &$this->node_list->$cluster_name->nodes;

		foreach ($node_list as $key => $node) {
			if($address == $node->address && $name == $node->name) {
				if(null != $not_prefer) {
					$node_list[$key]->not_prefer = $not_prefer;
				}
				if(null != $num_undeliverable_msg) {
					$node_list[$key]->num_undeliverable_msg = $num_undeliverable_msg;
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
				$node->zmq_object->disconnect('tcp://' . $node->address);
				unset($node_list[$key]);
				return true;
			}
		}

		return false;
	}
}