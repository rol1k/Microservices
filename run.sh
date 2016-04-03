#!/bin/bash
php bin/topology.php &
php bin/receive-news.php 127.0.0.1:5500 127.0.0.1:5610 127.0.0.1:5600 127.0.0.1:5400 &
php bin/image-handler.php 127.0.0.1:5500 127.0.0.1:5700 127.0.0.1:5400 &
php bin/text-handler.php 127.0.0.1:5500 127.0.0.1:5800 &
php bin/publish-news.php 127.0.0.1:5500 127.0.0.1:5900 127.0.0.1:5910 &
cd ./http
php -S 127.0.0.1:5400 > /dev/null &
cd ../
