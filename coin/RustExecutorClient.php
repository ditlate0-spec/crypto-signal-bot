<?php
// RustExecutorClient.php

class RustExecutorClient
{
    private string $baseUrl;

    public function __construct(?string $baseUrl = null)
    {
        $this->baseUrl = $baseUrl
            ?? getenv('EXECUTOR_URL')
            ?: 'http://executor:8080';
    }

    public function executeTrade(array $params): array
    {
        $url = rtrim($this->baseUrl, '/') . '/execute';

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($params),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            return ['status' => 'error', 'error' => "curl failed: $curlError"];
        }

        $decoded = json_decode($response, true);

        if (!is_array($decoded)) {
            return ['status' => 'error', 'error' => "invalid json (http $httpCode): $response"];
        }

        return $decoded;
    }
}