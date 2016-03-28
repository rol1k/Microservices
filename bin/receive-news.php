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
// Пример вызова: php bin/receive-news.php 127.0.0.1:5500 127.0.0.1:5610 127.0.0.1:5600

require dirname(__DIR__) . '/vendor/autoload.php';

define('MESSAGE_DELIMITER', '|');
define('POST_MESSAGE_DELIMITER', 'delimiter');

$loop = React\EventLoop\Factory::create();
$context = new React\ZMQ\Context($loop);

$topology = $context->getSocket(ZMQ::SOCKET_DEALER);
$topology->connect('tcp://'.$argv[1]);
$topology->send( json_encode(['action' => 'get_topology_pub']) );

$sub = $context->getSocket(\ZMQ::SOCKET_SUB);
$sub->subscribe('');

$image_handler_tcp_list = new \SplObjectStorage();
$news_handler_tcp_list = new \SplObjectStorage();

$response_data = [];
// $image_deferred = [];
// $news_deferred = [];

// WS Server
list($ip, $port) = explode(':', $argv[2]);
$webSock = new React\Socket\Server($loop);
$webSock->listen($port, $ip);
$webServer = new Ratchet\Server\IoServer(
	new Ratchet\Http\HttpServer(
		new Ratchet\WebSocket\WsServer(
			new Microservices\Receive( $response_data )
		)
	),
	$webSock
);

// HTTP Server
list($ip, $port) = explode(':', $argv[3]);
$socket = new React\Socket\Server($loop);
$http = new React\Http\Server($socket, $loop);
$socket->listen($port, $ip);

$topology->on('message', function ($msg) use ($sub, $loop, $argv, $topology) {
	$msg = json_decode($msg);

	if('get_topology_pub' == $msg->action) {
		$sub->connect('tcp://'.$msg->address);
		$loop->addTimer(1, function() use ($topology, $argv){
			$message = [
				'action' => 'add_node',
				'cluster' => 'RECEIVE HTTP',
				'address' => $argv[3]
			];
			$topology->send( json_encode($message) );
			$message = [
				'action' => 'add_node',
				'cluster' => 'RECEIVE WS',
				'address' => $argv[2]
			];
			$topology->send( json_encode($message) );
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

$sub->on('message', function($msg) use ($context, &$image_handler_tcp_list, &$news_handler_tcp_list, $address_isset, &$response_data) {
	$msg = json_decode($msg);

	if(('IMAGE HANDLER TCP' != $msg->cluster) && ('TEXT HANDLER TCP' != $msg->cluster)) {
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
				$dealer->on('message', function($msg) use (&$response_data) {
					$msg = json_decode($msg);
					if(isset($response_data[$msg->id]['image_deferred'])) {
						if ($msg->error) {
							$message = [
								'type' => 'image',
								'error' => true,
								'message' => 'Изображение не сохранено'
							];
							$response_data[$msg->id]['image_deferred']->reject( $message );
						} else {
							$message = [
								'type' => 'image',
								'error' => false,
								'message' => 'Изображение сохранено. '.$msg->path
							];
							$response_data[$msg->id]['image_deferred']->resolve( $message );
						}
					} else {
						printf('Невозможно записать ответ на запрос %s в буфер. Буфер сообщений уже удалён. Долгая обработка изображения'.PHP_EOL, $msg->id);
					}
				});
				$image_handler_tcp_list->attach($dealer, $address);
			}
		}
	} elseif('TEXT HANDLER TCP' == $msg->cluster) {
		foreach($msg->list_node as $address) {
			if($address_isset($address, $news_handler_tcp_list)) {
				continue;
			} else {
				$dealer = $context->getSocket(\ZMQ::SOCKET_DEALER);
				$dealer->connect('tcp://'.$address);
				$dealer->on('message', function($msg) use (&$response_data) {
					$msg = json_decode($msg);
					if(isset($response_data[$msg->id]['news_deferred'])) {
						if ( 0 < count($msg->stop_words) ) {
							$message = [
								'type' => 'news',
								'error' => true,
								'message' => 'Следующие слова запрещены: '. implode(', ', $msg->stop_words)
							];
							$response_data[$msg->id]['news_deferred']->reject( $message );
						} else {
							$message = [
								'type' => 'news',
								'error' => false,
								'message' => 'Нет стоп-слов'
							];
							$response_data[$msg->id]['news_deferred']->resolve( $message );
						}
					} else {
						printf('Невозможно записать ответ на запрос %s в буфер. Буфер сообщений уже удалён. Долгая обработка текста'.PHP_EOL, $msg->id);
					}
				});
				$news_handler_tcp_list->attach($dealer, $address);
			}
		}
	}
});

$http->on('request', function (React\Http\Request $request, React\Http\Response $response) use ($loop, &$image_handler_tcp_list, &$news_handler_tcp_list, &$response_data) {
	if('/send' == $request->getPath() && 'POST' == $request->getMethod()) {
		$content_type = $request->getHeaders()['Content-Type'];
		$boundary = explode('boundary=', $content_type)[1];
		$requestBody='';
		$headers=$request->getHeaders();
		$contentLength=(int)$headers['Content-Length'];
		$receivedData = 0;
		$request->on('data',function($data) use ($request, $response, &$requestBody, &$receivedData, $contentLength) {
			$requestBody.=$data;
			$receivedData+=strlen($data);
			if ($receivedData>=$contentLength) {
					$response->writeHead(301, ['Location' => 'http://127.0.0.1:5400/publish-news.html']);
					$response->end();
			}
		});
		$request->on('end', function() use (&$requestBody, $boundary, $loop, &$image_handler_tcp_list, &$news_handler_tcp_list, &$response_data) {
			$mp = new Microservices\MultipartParser($requestBody, $boundary);
			$mp->parse();

			// !! TODO Если все узлы обработки лежат, то сразу дать ответ пользователю
			// !! TODO Добавить проверку типов загружаемых файлов

			if(0 == count($image_handler_tcp_list)) {
				echo 'Нет обработчиков изображений', PHP_EOL;
				return;
			}

			if(0 == count($news_handler_tcp_list)) {
				echo 'Нет обработчиков текста', PHP_EOL;
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

			$id = $mp->getPost()['unique_id'];
			$topic = $mp->getPost()['topic'];
			$news = $mp->getPost()['news'];

			$news_stream = fopen('php://temp/maxmemory:512000', 'r+');
			fwrite($news_stream, $news);
			fseek($news_stream, 0);

			$image_stream = $mp->getFiles()['image']['stream'];
			$original_name = $mp->getFiles()['image']['name'];
			$image_size = $mp->getFiles()['image']['size'];

			$response_data[$id] = [
				'user_connection' => null,
				'buffer' => [],
				'image_deferred' => null,
				'news_deferred' => null
			];

			$image_news_response_handler = function($msg) use (&$response_data, $id) {
				array_push($response_data[$id]['buffer'], $msg['message']);
				return $msg;
			};

			$image_deferred = new React\Promise\Deferred();
			$image_promise = $image_deferred->promise()->then(
				$image_news_response_handler,
				$image_news_response_handler
			);
			$response_data[$id]['image_deferred'] = $image_deferred;

			$news_deferred = new React\Promise\Deferred();
			$news_promise = $news_deferred->promise()->then(
				$image_news_response_handler,
				$image_news_response_handler
			);
			$response_data[$id]['news_deferred'] = $news_deferred;

			$all_promise[] = $news_promise;
			if(0 == $image_size) {
				$all_promise[] = React\Promise\resolve( ['type' => 'image', 'error' => false, 'message' => 'Передано пустое изображение'] )->then($image_news_response_handler);
			} else {
				$all_promise[] = $image_promise;
			}

			React\Promise\all($all_promise)->then(function($results) use (&$response_data, $id) {
				// $error = false;
				// $empty_image = false;
				if(isset($response_data[$id]['user_connection'])) {
					$message = json_encode($response_data[$id]['buffer']);
					$response_data[$id]['user_connection']->send( $message );
				}
			});

			$loop->addTimer(2*60, function() use (&$response_data, $id){
				printf('Буфер сообщений %s удалён. Истекло время жизни'.PHP_EOL, $id);
				unset($response_data[$id]);
			});

			// отправка новости
			$news = new \React\Stream\Stream($news_stream, $loop);
			$news->bufferSize = 100;

			$message = [
				'type' => 'new',
				'id' => $id
			];
			$news_handler->send( json_encode($message) );

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