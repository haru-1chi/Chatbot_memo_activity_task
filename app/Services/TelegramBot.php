<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class TelegramBot
{
    protected $token;
    protected $api_endpoint;
    protected $headers;

    public function __construct()
    {
        $this->token = env('TELEGRAM_BOT_TOKEN');
        $this->api_endpoint = env('TELEGRAM_API_ENDPOINT');
        $this->setHeaders();
    }

    protected function setHeaders()
    {
        $this->headers = [
            "Content-Type" => "application/json",
            "Accept" => "application/json",
        ];
    }

    public function sendMessage($chat_id, $text = '')
    {
        $params = [
            'chat_id' => $chat_id,
            'text' => $text,
        ];

        return $this->apiRequest('sendMessage', $params);
    }

    public function sendDocument($chatId, $filePath)
    {
        $fileName = pathinfo($filePath, PATHINFO_BASENAME);

        $ch = curl_init();

        $url = "{$this->api_endpoint}/{$this->token}/sendDocument?chat_id={$chatId}";

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, 1);

        $finfo = finfo_file(finfo_open(FILEINFO_MIME_TYPE), $filePath);
        $cFile = new \CURLFile($filePath, $finfo, $fileName);

        curl_setopt($ch, CURLOPT_POSTFIELDS, [
            "document" => $cFile,
            "caption" => $fileName
        ]);

        $result = curl_exec($ch);

        curl_close($ch);
        
        return $result;
    }

    public function sendMessageWithKeyboard($chatId, $text, $options)
{
    $params = [
        'chat_id' => $chatId,
        'text' => $text,
        'reply_markup' => $this->keyboardBtn($options),
    ];

    return $this->apiRequest('sendMessage', $params);
}

    public function apiRequest($method, $parameters = [])
    {
        $url = "{$this->api_endpoint}/{$this->token}/{$method}";
        $handle = curl_init($url);
        curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($handle, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($handle, CURLOPT_TIMEOUT, 60);
        curl_setopt($handle, CURLOPT_POSTFIELDS, http_build_query($parameters));
        $response = curl_exec($handle);
        if ($response === false) {
            curl_close($handle);
            return false;
        }
        curl_close($handle);
        $decoded_response = json_decode($response, true); // Decode the actual response, not the cURL handle
        if ($decoded_response === null || !isset($decoded_response['ok']) || $decoded_response['ok'] === false) {
            return false;
        }
        return $decoded_response['result'];
    }

    public function keyboardBtn($options)
    {
        $keyboard = [
            'keyboard' => $options,
            'resize_keyboard' => true,
            'one_time_keyboard' => true,
            'selective' => true
        ];
        $keyboard = json_encode($keyboard);
        return $keyboard;
    }
}