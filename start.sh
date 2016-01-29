#!/bin/bash
cd bin
php topology.php
php receive-news.php 127.0.0.1:5500 127.0.0.1:8081 127.0.0.1:5530
php publish-news.php 127.0.0.1:5500 127.0.0.1:8083
php filter-news.php 127.0.0.1:5500 127.0.0.1:5540