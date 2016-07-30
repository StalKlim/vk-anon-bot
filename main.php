<?php

require 'src/head.php';
require 'src/tools.php';
require 'vendor/autoload.php';
require 'src/User.php';
require 'src/Credentials.php';


function setUserState($user, $state)
{
    $user['stage'] = $state;
    User::getInstance()->update($user);
}

function confirmWebHook() {
    echo WEBHOOK_KEY;
    die();
}


function processMessage($message)
{
    $type = $message['type'];
    $object = $message['object'];

    if ($type == 'confirmation') {
        confirmWebHook();
    } else if( $type == 'message_new' ) {
        process($object);
    }
    echo "ok";
}

function process($message) {
    $fromId = !empty($message['user_id']) ? $message['user_id'] : 0;
    $store = User::getInstance();
    try {
        $user = $store->read($fromId);
        if (!empty($user)) {
            processMessageFromUser($user, $message);
        } else {
            createNewUser($fromId);
        }
    } catch (\Exception $e) {
        $error = [
            $e->getMessage() . ' #' . $e->getCode(),
            $e->getFile() . ':' . $e->getLine(),
            $e->getTraceAsString(),
            json_encode($message),
            json_encode(!empty($user) ? $user : null)
        ];
        $error = implode("\n", $error);
        error($error);
    }
}


function processMessageFromUser($user, $message) {
    $store = User::getInstance();
    $uState = $user['stage'];
    $userId = $user['id'];
    $text = isset($message['body']) ? $message['body'] : '';
    $text = trim($text);
    if ($uState == User::STAGE_INIT || $uState == User::WAIT_FOR_ACTION) {
        error('INPUT MESSAGE: '.$text.' '.json_encode($message));
        if ( in_array($text, ['го', 'Го', 'ГО']) ) {
            $sex = $user['sex'];
            $company = $store->getFreeUser($sex, $user['id']);
            if ($company) {
                $user['chatId'] = $company['id'];
                $company['chatId'] = $user['id'];
                setUserState($user, User::STAGE_CHAT);
                setUserState($company, User::STAGE_CHAT);
                $text = '['.date('H:i:s').' Чат начат, поздоровайтесь с собеседником.'
                    ."\n"
                    .'Внимание! Мы не поддерживаем стикеры в сообщениях и пересылку сообщений]';
                api('messages.send', [
                    'peer_id'=> $userId,
                    'message' => $text
                ]);
                api('messages.send', [
                    'peer_id'=> $company['id'],
                    'message' => $text
                ]);
            } else {
                setUserState($user, User::STAGE_WAIT_CHAT);
                $text = "[".date('H:i:s')." Мы ищем для вас свободного собеседника, пожалуйста подождите...]";
                api('messages.send', [
                    'peer_id'=> $userId,
                    'message' => $text
                ]);
            }
        } else {
            startMessage($userId, $text);
        }

    } elseif ($uState == User::STAGE_WAIT_CHAT) {

        if ($text == "!стоп" || in_array( $text, ['!Стоп', '!СТОП', 'Стоп', 'стоп', 'СТОП'] )) {
            setUserState($user, User::WAIT_FOR_ACTION);
            $text = '[Поиск отменен]';
            api('messages.send', [
                'peer_id'=> $userId,
                'message' => $text
            ]);
        } else {
            $text = '['.date('H:i:s').' Мы ищем для вас собеседника, пожалуйста подождите...]';
            $text .= "\n\nОтправьте стоп чтобы отменить поиск";
            api('messages.send', [
                'peer_id'=> $userId,
                'message' => $text
            ]);
        }

    } elseif ($uState == User::STAGE_CHAT) {

        if ($text == "!стоп" || in_array( $text, ['!Стоп', '!СТОП', 'Стоп', 'стоп', 'СТОП'] )) {
            setUserState($user, User::WAIT_FOR_ACTION);
            $text = '[Чат завершен, ваш собеседник отключился, чтобы начать новый чат вновь отправьте слово "го"]';
            api('messages.send', [
                'peer_id'=> $userId,
                'message' => $text
            ]);
            api('messages.send', [
                'peer_id'=> $user['chatId'],
                'message' => $text
            ]);
            $company = $store->read($user['chatId']);
            if ($company) {
                setUserState($company, User::WAIT_FOR_ACTION);
            }
        } else {
            copyMessage($message, $user['chatId']);
        }

    } else {
        setUserState($user, User::STAGE_INIT);
    }
}

function copyMessage($message, $chatId) {

    $parameters = [];
    unset($parameters['user_id']);
    unset($parameters['user_ids']);
    unset($parameters['domain']);
    unset($parameters['chat_id']);

    $parameters['peer_id'] = $chatId;
    $parameters['message'] = isset($message['body'])?$message['body'] : '';

    if (isset($message['geo'])) {
        $parameters['lat'] = isset($message['geo']['place']['latitude']) ? $message['geo']['place']['latitude'] : 0;
        $parameters['long'] = isset($message['geo']['place']['latitude']) ? $message['geo']['place']['longitude'] : 0;
    }
    
    $attachments = isset($message['attachments']) ? $message['attachments'] : [];
    $attachIds = [];
    foreach ($attachments as $attach) {
        $type = $attach['type'];
        $id = $type;
        $attach = $attach[$type];
        if (isset($attach['id'], $attach['owner_id'])) {
            $id .= $attach['owner_id'].'_'.$attach['id'];
            if (isset($attach['access_key'])) {
                $id .= '_'.$attach['access_key'];
            }
            $attachIds[] = $id;
        } else if ($type == 'wall') {
            $attachIds[] = 'wall'.$attach['to_id'].'_'.$attach['id'];
        } else if ($type == 'sticker') {
            $parameters['message'] .= ' [стикер] '.$attach['photo_352'];
//            $parameters['sticker_id'] = $attach['id'];
        } else if ($type == 'link') {
            $parameters['message'] .= ' '.$attach['url'];
        } else if ($type == 'gift') {
            $parameters['message'] .= ' '.$attach['thumb_256'];
        }
    }
    if (!empty($attachIds)) {
        $parameters['attachment'] = implode(',',$attachIds);
    }

    api('messages.send', $parameters);
}

function createNewUser($userId) {

    $userData = apir('users.get', ['user_ids'=>$userId, 'fields'=>'sex', 'no-token'=>'Y']);

    $user = array_pop($userData);

    $sex = isset($user['sex']) ? $user['sex'] : 1;

    if (!in_array($sex, [1,2])) {
        $sex = 2;
    }
    $store = User::getInstance();
    $store->findOrCreate($userId, $sex);
    startMessage($userId);
}


$content = file_get_contents("php://input");
$update = json_decode($content, true);

if (!$update) {
    echo "receive wrong update, must not happen";
    exit;
}

if (isset($update["type"])) {
    $groupId = $update['group_id'];
    Credentials::load($groupId);
    processMessage($update);
} else {
    echo "ERROR";
}