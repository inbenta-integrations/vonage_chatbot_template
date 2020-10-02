<?php

include "vendor/autoload.php";

use Inbenta\NexmoConnector\NexmoConnector;

//Instance new FacebookConnector
$appPath=__DIR__.'/';
$app = new NexmoConnector($appPath);

//Handle the incoming request
$app->handleRequest();
