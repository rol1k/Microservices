<?php
// http сервер
// argv[1] - адрес Network Topology
// argv[2] - собственный адрес http
// Пример вызова: php bin/http-server.php 127.0.0.1:5400

use React\EventLoop\Factory;
use React\Filesystem\Filesystem;
use React\Filesystem\Node\File;
use React\Filesystem\Node\Directory;
use React\Http\Request;
use React\Http\Response;
use React\Filesystem\ModeTypeDetector;
use React\Promise\FulfilledPromise;
use React\Promise\RejectedPromise;
use Microservices\MimeType;

define('WEBROOT', __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'http' . DIRECTORY_SEPARATOR);

require __DIR__.'/../vendor/autoload.php';

$loop = Factory::create();
$socket = new React\Socket\Server($loop);
$http = new React\Http\Server($socket, $loop);
$filesystem = Filesystem::create($loop);

$http->on('request', function (Request $request, Response $response) use ($filesystem, $loop) {
	$filename = WEBROOT . $request->getPath();

	if('/favicon.ico' == $request->getPath()) {
		$content_type = MimeType::get_mime_type($request->getPath());
		$response->writeHead(200, ['Content-Type' => $content_type]);
		$response->end();
		return 0;
	}

	$timer = $loop->addTimer(30, function() use ($response) {
		$response->writeHead(408);
		$response->end('408 Request Timeout');
	});

	// $get_contents_recursively = function($node){
	// 	if($node instanceof File) {
	// 		return $node->getContents()->then(
	// 			function($contents) use ($node){
	// 				$content_type = MimeType::get_mime_type($node->getName());
	// 				return [$contents, 200, ['Content-Type' => $content_type]];
	// 			},
	// 			function($e) use ($node) {
	// 				if('Unknown error calling "eio_open"' == $e->getMessage()) {
	// 					$get_contents_recursively($node, $get_contents_recursively);
	// 				} else {
	// 					return ['500 Internal Server Error: '. $e->getMessage(), 500, []];
	// 				}
	// 			});
	// 	} elseif ($node instanceof Directory) {
	// 		return ['403 Forbidden', 403, []];
	// 	}
	// };

	$file = $filesystem->file( WEBROOT . $request->getPath() );
	$file->exists()->then(
		function() use ($file, $response) {
			return new FulfilledPromise($file);
		},
		function($e) use ($response, $timer) {
			if($timer->isActive()) {
				$timer->cancel();
				$response->writeHead(404);
				$response->end('404 File not found');
			}
			return new RejectedPromise($e);
		}
	)->then(
		function($file) use ($filesystem) {
			$type = \React\Filesystem\detectType([new ModeTypeDetector($filesystem)],['path' => $file->getPath()]);
			return $type->then(function ($destination){
				return $destination;
			});
		}
	)->then(function($node){
		if($node instanceof File) {
			return $node->getContents()->then(
				function($contents) use ($node){
					$content_type = MimeType::get_mime_type($node->getName());
					return [$contents, 200, ['Content-Type' => $content_type]];
				},
				function($e) use ($node) {
					if('Unknown error calling "eio_open"' == $e->getMessage()) {
						if($node instanceof File) {
									return $node->getContents()->then(
										function($contents) use ($node){
											$content_type = MimeType::get_mime_type($node->getName());
											return [$contents, 200, ['Content-Type' => $content_type]];
										},
										function($e) use ($node) {
											if('Unknown error calling "eio_open"' == $e->getMessage()) {
												//$get_contents_recursively($node, $get_contents_recursively);
												return ['500 Internal Server Error: '. $e->getMessage(), 500, []];
											} else {
												return ['500 Internal Server Error: '. $e->getMessage(), 500, []];
											}
										});
								} elseif ($node instanceof Directory) {
									return ['403 Forbidden', 403, []];
								}
					} else {
						return ['500 Internal Server Error: '. $e->getMessage(), 500, []];
					}
				});
		} elseif ($node instanceof Directory) {
			return ['403 Forbidden', 403, []];
		}
	})->then(function(array $data) use ($timer, $response){
		if($timer->isActive()) {
			$timer->cancel();
			list($contents, $status_code, $headers) = $data;
			$response->writeHead($status_code, $headers);
			$response->end($contents);
			$response->end();
		}
	});
});

list($ip, $port) = explode(':', $argv[1]);
$socket->listen($port, $ip);
$loop->run();