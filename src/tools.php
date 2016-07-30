<?php

use google\appengine\api\log\LogService;


function error($text) {
    LogService::log(3, $text);
}


function api($method, $parameters, $try = 0) {
    error($method.' '.json_encode($parameters));
    $url = 'https://api.vk.com/method/'.$method;

    $parameters = array_merge( $parameters, [
        'v' => '5.52',
        'lang' => 'ru',
        'https' => 1,
        'access_token' => BOT_TOKEN,
    ] );
    
    if (isset($parameters['no-token'])) {
        unset($parameters['access_token']);
        unset($parameters['no-token']);
    }

    $context = stream_context_create(array(
        'http' => array(
            'timeout' => '10',
            'method' => 'POST',
            'header' => 'Content-Type: application/x-www-form-urlencoded' . PHP_EOL,
            'content' => http_build_query($parameters),
        ),
    ));

    $raw = @file_get_contents($url, false, $context);

    $data = json_decode($raw, true);

    if ($data) {
        if (isset($data['error'])) {
            $code = isset($data['error']['error_code']) ? $data['error']['error_code'] : 0;
            if ($code == 9) {
                return ['response'=>[]];
            }
            if (in_array($code, [0, 1, 6, 10]) && $try < 4) {
                error($raw);
                sleep(1);
                return api($method, $parameters, $try + 1);
            } else {
                throw new \Exception('API ERROR '.$raw);
            }
        } else {
            return $data;
        }
    } else {
        error('VK API ERROR: '.$raw);
        if ($try < 4) {
            sleep(1);
            return api($method, $parameters, $try + 1);
        } else {
            throw new \Exception('API ERROR '.$raw);
        }
    }
}

function apir($method, $parameters) {
    return api($method, $parameters)['response'];
}

function apiDelay($method, $parameters) {
    $task = new \google\appengine\api\taskqueue\PushTask('/vk-api', [
        'method' => $method,
        'parameters' => json_encode($parameters),
        'group_id' => GROUP_ID
    ]);
    $task->add('vkapi');
}

function startMessage($userId, $sText = '') {
    if (USE_SEX) {
        $m = 'Привет! Это бот для анонимного общения со случайным собеседником противоположного пола.'."\nОтправь \"го\" чтобы начать чат со случайным человеком или \"!стоп\" чтобы выйти из чата. ".date('H:i:s');
    } else {
        $m = 'Привет! Это бот для анонимного общения со случайным собеседником.'."\nОтправь \"го\" чтобы начать чат со случайным человеком или \"!стоп\" чтобы выйти из чата. ".date('H:i:s');
    }
    $params = [
        'peer_id'=> $userId,
        'message' => $m
    ];
    apiDelay('messages.send', $params);
}