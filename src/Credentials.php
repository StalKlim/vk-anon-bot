<?php

class Credentials
{
    protected static $map = [
        <GROUP_ID> => [
            'key' => '<SECRET KEYY>',
            'token' => '<BOT API KEY WITH MESSAGE ACCESS>',
            'sex' => true
        ]
    ];
    public static function load($groupId) {
        if (in_array($groupId, array_keys(self::$map))) {
            $data = self::$map[$groupId];
            define('WEBHOOK_KEY', $data['key']);
            define('BOT_TOKEN', $data['token']);
            define('GROUP_ID', (int)$groupId);
            define('USE_SEX', $data['sex']);
        } else {
            throw new \Exception('Unknown key '.$groupId);
        }
    }
}