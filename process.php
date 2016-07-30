<?php

require 'src/head.php';
require 'src/tools.php';
require 'src/Credentials.php';

$method = $_REQUEST['method'];
$parameters = json_decode($_REQUEST['parameters'], true);

if (!empty($method) && !empty($parameters)) {
    Credentials::load($_REQUEST['group_id']);
    api($method, $parameters);
} else {
    error( json_encode($_REQUEST) );
}
