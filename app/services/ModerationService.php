<?php

namespace app\services;

class ModerationService
{
    private string $apiKey;
    private string $endpoint = 'https://api.maiarouter.ai/v1/moderations';

    public function __construct()
    {
        $this->apiKey = $_ENV['MAIAROUTER_API_KEY'];
    }

    public function check(string $content): array
    {
        $payload = [
            'model' => 'openai/omni-moderation-latest',
            'input' => $content
        ];

        $ch = curl_init($this->endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->apiKey,
                'Content-Type: application/json'
            ],
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_TIMEOUT => 10
        ]);

        $response = curl_exec($ch);
        curl_close($ch);

        if (!$response) {
            // fallback aman
            return [
                'flagged' => false,
                'categories' => []
            ];
        }

        $data = json_decode($response, true);
        $result = $data['results'][0] ?? [];

        return [
            'flagged' => $result['flagged'] ?? false,
            'categories' => array_keys(array_filter($result['categories'] ?? []))
        ];
    }
}
