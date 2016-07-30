<?php

$dRoot = isset($_SERVER['DOCUMENT_ROOT']) ? $_SERVER['DOCUMENT_ROOT'] : "";
define('APP_DEBUG', strpos($dRoot, '/Users') === 0);

