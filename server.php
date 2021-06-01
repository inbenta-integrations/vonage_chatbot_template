<?php

require "vendor/autoload.php";

use Inbenta\NexmoConnector\NexmoConnector;

//Instance new NexmoConnector
$appPath=__DIR__.'/';
$app = new NexmoConnector($appPath);

//Handle the incoming request
$app->handleRequest();
