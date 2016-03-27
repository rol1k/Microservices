<?php

namespace Microservices;

class MimeType
{
	public static $mime_types = [
		'png' => 'image/png',
		'jpg' => 'image/jpeg',
		'gif' => 'image/gif',
		'ico' => 'image/x-icon',
		'js' => 'text/javascript',
		'css' => 'text/css',
		'html' => 'text/html',
		'txt' => 'text/plain',
	];

	public static function get_mime_type($file_path) {
		$file_extension = self::get_file_extension($file_path);
		if(!isset(self::$mime_types[$file_extension])) {
			return self::$mime_types['txt'];
		} else {
			return self::$mime_types[$file_extension];
		}
	}

	public static function get_file_extension($path_name){
		$extension = pathinfo($path_name, PATHINFO_EXTENSION);
		// https://vk.com/js/al/common.js?1129_190
		return explode('?', $extension, 2)[0];
	}
}