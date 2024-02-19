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

    public function sendMessage($text = '', $chat_id, $reply_to_message_id = null)
    {
        $result = ['success' => false, 'body' => []];

        $params = [
            'chat_id' => $chat_id,
            'text' => $text,
        ];

        if (!is_null($reply_to_message_id)) {
            $params['reply_to_message_id'] = $reply_to_message_id;
        }

        $url = "{$this->api_endpoint}/{$this->token}/sendMessage";

        try {
            $response = Http::withHeaders($this->headers)->post($url, $params);
            $result = ['success' => $response->ok(), 'body' => $response->json()];
        } catch (\Throwable $th) {
            $result['error'] = $th->getMessage();
        }
        return $result;
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
}