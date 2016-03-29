<?php
// Получает новость от пользователя
// Пересылает текст на проверку text-handler.php
// Пересылает изображение для сохранения image-handler.php
// Уведомляет пользователя об ошибках
// Сохраняет новость в базу
// Уведомляет пользователя о публикации новости
// Передаёт новость publish-news.php
// argv[1] - адрес Network Topology
// argv[2] - собственный адрес WS
// argv[3] - собственный адрес http
// argv[4] - адрес http сервера
// Пример вызова: php bin/receive-news.php 127.0.0.1:5500 127.0.0.1:5610 127.0.0.1:5600 127.0.0.1:5400

require dirname(__DIR__) . '/vendor/autoload.php';

define('MESSAGE_DELIMITER', '|');
define('POST_MESSAGE_DELIMITER', 'delimiter');

$logger = new Monolog\Logger('receive news');
$logger->pushHandler(new Monolog\Handler\StreamHandler('php://stdout', Monolog\Logger::DEBUG));
$logger->addDebug( 'Server is running', ['TOPOLOGY' => $argv[1], 'RECEIVE WS' => $argv[2], 'RECEIVE HTTP' => $argv[3], 'HTTP SERVER' => $argv[4]] );

$loop = React\EventLoop\Factory::create();
$context = new React\ZMQ\Context($loop);

$topology = $context->getSocket(ZMQ::SOCKET_DEALER);
$topology->connect('tcp://'.$argv[1]);
$logger->addDebug( 'Сonnected to topology', [$argv[1]]);

$message = ['action' => 'get_topology_pub'];
$topology->send( json_encode($message) );
$logger->addInfo( 'Request to topology', $message );

$sub = $context->getSocket(\ZMQ::SOCKET_SUB);
$sub->subscribe('');

$image_handler_tcp_list = new \SplObjectStorage();
$news_handler_tcp_list = new \SplObjectStorage();

$response_data = [];

// WS Server
list($ip, $port) = explode(':', $argv[2]);
$webSock = new React\Socket\Server($loop);
$webSock->listen($port, $ip);
$webServer = new Ratchet\Server\IoServer(
	new Ratchet\Http\HttpServer(
		new Ratchet\WebSocket\WsServer(
			new Microservices\Receive( $response_data, $logger )
		)
	),
	$webSock
);
$logger->addDebug( 'WS server is running', [$argv[2]] );

// HTTP Server
list($ip, $port) = explode(':', $argv[3]);
$socket = new React\Socket\Server($loop);
$http = new React\Http\Server($socket, $loop);
$socket->listen($port, $ip);
$logger->addDebug( 'HTTP server is running', [$argv[3]] );

$topology->on('message', function ($msg) use ($sub, $loop, $argv, $topology, $logger) {
	$msg = json_decode($msg);

	if('get_topology_pub' == $msg->action) {
		$sub->connect('tcp://'.$msg->address);
		$logger->addDebug( 'Сonnected to topology pub', [$msg->address] );
		// дать время подсоедениться к topology pub
		$loop->addTimer(1, function() use ($topology, $argv, $logger){
			$message = [
				'action' => 'add_node',
				'cluster' => 'RECEIVE HTTP',
				'address' => $argv[3]
			];
			$topology->send( json_encode($message) );
			$logger->addInfo( 'Request to topology', [$message] );

			$message = [
				'action' => 'add_node',
				'cluster' => 'RECEIVE WS',
				'address' => $argv[2]
			];
			$topology->send( json_encode($message) );
			$logger->addInfo( 'Request to topology', [$message] );
		});
	} elseif ('add_node' == $msg->action && $msg->error) {
		//exit($msg->error_message);
	}
});

$address_isset = function($address, $node_list) {
	foreach ($node_list as $node) {
		if($address == $node_list->offsetGet($node)){
			return true;
		}
	}
	return false;
};

$sub->on('message', function($msg) use ($context, &$image_handler_tcp_list, &$news_handler_tcp_list, $address_isset, &$response_data, $logger) {
	$msg = json_decode($msg);

	if(('IMAGE HANDLER TCP' != $msg->cluster) && ('NEWS HANDLER TCP' != $msg->cluster)) {
		return;
	}

	if(0 == count($msg->list_node)) {
		return;
	}

	if('IMAGE HANDLER TCP' == $msg->cluster) {
		foreach($msg->list_node as $address) {
			if($address_isset($address, $image_handler_tcp_list)) {
				continue;
			} else {
				$dealer = $context->getSocket(\ZMQ::SOCKET_DEALER);
				$dealer->connect('tcp://'.$address);
				$logger->addDebug( 'Connected to image handler', [$address] );
				$dealer->on('message', function($msg) use (&$response_data, $logger) {
					$msg = json_decode($msg);
					if(isset($response_data[$msg->id]['image_deferred'])) {
						if ($msg->error) {
							$message = [
								'type' => 'image',
								'error' => true,
								'message' => 'Image isn\'t saved'
							];
							$response_data[$msg->id]['image_deferred']->reject( $message );
						} else {
							$message = [
								'type' => 'image',
								'error' => false,
								'message' => 'Image is saved. '.$msg->path
							];
							$response_data[$msg->id]['image_deferred']->resolve( $message );
						}
					} else {
						$logger->addDebug( "Can't record the response in the buffer. A message buffer is already deleted. Long image processing", ['buffer id' => $msg->id] );
					}
				});
				$image_handler_tcp_list->attach($dealer, $address);
			}
		}
	} elseif('NEWS HANDLER TCP' == $msg->cluster) {
		foreach($msg->list_node as $address) {
			if($address_isset($address, $news_handler_tcp_list)) {
				continue;
			} else {
				$dealer = $context->getSocket(\ZMQ::SOCKET_DEALER);
				$dealer->connect('tcp://'.$address);
				$logger->addDebug( 'Connected to news handler', [$address] );
				$dealer->on('message', function($msg) use (&$response_data, $logger) {
					$msg = json_decode($msg);
					if(isset($response_data[$msg->id]['news_deferred'])) {
						if ( 0 < count($msg->stop_words) ) {
							$message = [
								'type' => 'news',
								'error' => true,
								'message' => 'The following words are prohibited: '. implode(', ', $msg->stop_words)
							];
							$response_data[$msg->id]['news_deferred']->reject( $message );
						} else {
							$message = [
								'type' => 'news',
								'error' => false,
								'message' => 'No stop words'
							];
							$response_data[$msg->id]['news_deferred']->resolve( $message );
						}
					} else {
						$logger->addDebug( "Can't record the response in the buffer. A message buffer is already deleted. Long text processing", ['buffer id' => $msg->id] );
					}
				});
				$news_handler_tcp_list->attach($dealer, $address);
			}
		}
	}
});

$http->on('request', function (React\Http\Request $request, React\Http\Response $response) use ($loop, &$image_handler_tcp_list, &$news_handler_tcp_list, &$response_data, $argv, $logger) {
	if('/send' == $request->getPath() && 'POST' == $request->getMethod()) {
		$content_type = $request->getHeaders()['Content-Type'];
		$boundary = explode('boundary=', $content_type)[1];
		$requestBody='';
		$headers=$request->getHeaders();
		$contentLength=(int)$headers['Content-Length'];
		$receivedData = 0;
		$request->on('data',function($data) use ($request, $response, &$requestBody, &$receivedData, $contentLength, $argv) {
			$requestBody.=$data;
			$receivedData+=strlen($data);
			if ($receivedData>=$contentLength) {
					$response->writeHead(301, ['Location' => 'http://' . $argv[4] . '/publish-news.html']);
					$response->end();
			}
		});
		$request->on('end', function() use (&$requestBody, $boundary, $loop, &$image_handler_tcp_list, &$news_handler_tcp_list, &$response_data, $logger) {
			$mp = new Microservices\MultipartParser($requestBody, $boundary);
			$mp->parse();

			// !! TODO Добавить проверку типов загружаемых файлов

			$id = $mp->getPost()['unique_id'];
			$topic = $mp->getPost()['topic'];
			$news = $mp->getPost()['news'];

			$image_stream = $mp->getFiles()['image']['stream'];
			$original_name = $mp->getFiles()['image']['name'];
			$image_size = $mp->getFiles()['image']['size'];

			$response_data[$id] = [
				'user_connection' => null,
				'buffer' => [],
				'image_deferred' => null,
				'news_deferred' => null
			];

			$loop->addTimer(2*60, function() use (&$response_data, $id, $logger){
				unset($response_data[$id]);
				$logger->addDebug( 'The message buffer is deleted. Timed out', ['buffer id' => $id]);
			});

			$push_buffer_message = function($msg) use (&$response_data, $id, $logger) {
				array_push($response_data[$id]['buffer'], $msg['message']);
				$logger->addInfo( 'Added a message in the buffer', ['buffer id' => $id, 'message' => $msg['message']] );
				return $msg;
			};

			if(0 == count($image_handler_tcp_list) || 0 == count($news_handler_tcp_list)) {
				if(0 == count($image_handler_tcp_list)) {
					$message = 'No handlers images';
					$push_buffer_message(['message' => $message]);
					$logger->addError( $message );
				}
				if(0 == count($news_handler_tcp_list)) {
					$message = 'No handlers text';
					$push_buffer_message(['message' => $message]);
					$logger->addError( $message );
				}
				return;
			}

			// выбор обработчика изображений round robin
			if ( !$image_handler_tcp_list->valid() ) {
				$image_handler_tcp_list->rewind();
			}
			$image_handler = $image_handler_tcp_list->current();
			$image_handler_tcp_list->next();

			// выбор обработчика текста round robin
			if ( !$news_handler_tcp_list->valid() ) {
				$news_handler_tcp_list->rewind();
			}
			$news_handler = $news_handler_tcp_list->current();
			$news_handler_tcp_list->next();

			$news_stream = fopen('php://temp/maxmemory:512000', 'r+');
			fwrite($news_stream, $news);
			fseek($news_stream, 0);

			$image_deferred = new React\Promise\Deferred();
			$image_promise = $image_deferred->promise()->then(
				$push_buffer_message,
				$push_buffer_message
			);
			$response_data[$id]['image_deferred'] = $image_deferred;

			$news_deferred = new React\Promise\Deferred();
			$news_promise = $news_deferred->promise()->then(
				$push_buffer_message,
				$push_buffer_message
			);
			$response_data[$id]['news_deferred'] = $news_deferred;

			$all_promise[] = $news_promise;
			if(0 == $image_size) {
				$all_promise[] = React\Promise\resolve( ['type' => 'image', 'error' => false, 'message' => 'Passed an empty image'] )->then($push_buffer_message);
			} else {
				$all_promise[] = $image_promise;
			}

			React\Promise\all($all_promise)->then(function($results) use (&$response_data, $id, $logger) {
				// $error = false;
				// $empty_image = false;
				if(isset($response_data[$id]['user_connection'])) {
					$message = json_encode($response_data[$id]['buffer']);
					$response_data[$id]['user_connection']->send( $message );
					$logger->addInfo( 'Sent a message to the user', [$response_data[$id]['buffer']]);
				}
			});

			// отправка новости
			$news = new \React\Stream\Stream($news_stream, $loop);
			$news->bufferSize = 100;

			$message = [
				'type' => 'new',
				'id' => $id
			];
			$news_handler->send( json_encode($message) );
			$logger->addInfo( 'Sent the news to the processing', ['news id' => $id]);

			$news->on('data', function($chunk) use ($id, $news_handler){
				$message = [
					'type' => 'chunk',
					'id' => $id,
					'text' => $chunk
				];
				$news_handler->send( json_encode($message) );
			});

			$news->on('end', function() use ($id, $news_handler) {
				$message = [
					'type' => 'end',
					'id' => $id
				];
				$news_handler->send( json_encode($message) );
			});

			// отправка изображения
			if(0 != $image_size) {
				$image = new \React\Stream\Stream($image_stream, $loop);
				$image->bufferSize = 100;

				$message = [
					'type' => 'new',
					'id' => $id,
					'original_name' => $original_name
				];
				$image_handler->send( implode(POST_MESSAGE_DELIMITER, $message) );
				$logger->addInfo( 'Sent the image to the processing', ['image id' => $id]);

				$image->on('data', function($data) use ($id, $image_handler) {
					$message = [
						'type' => 'chunk',
						'id' => $id,
						'data' => $data
					];
					$image_handler->send( implode(POST_MESSAGE_DELIMITER, $message) );
				});

				$image->on('end', function() use ($id, $image_handler) {
					$message = [
						'type' => 'end',
						'id' => $id
					];
					$image_handler->send( implode(POST_MESSAGE_DELIMITER, $message) );
				});
			}
		});
	} else {
		$response->end();
	}
});

$loop->run();