<?php

class Ollama {
    private $url;
    private $modelName;
    private $temperature;
    private $maxRetries;

    public function __construct($config) {
        $this->url = $config['url'];
        $this->modelName = $config['modelName'];
        $this->temperature = $config['temperature'] ?? 0.3;
        $this->maxRetries = $config['maxRetries'] ?? 2;
    }

    public function invoke($prompt) {
        echo "🤖 Chamando modelo Ollama: {$this->modelName}\n";

        $payload = json_encode([
            'model' => $this->modelName,
            'prompt' => $prompt,
            'stream' => false,
            'options' => [
                'temperature' => $this->temperature,
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($payload === false) {
            throw new RuntimeException('Não foi possível serializar a requisição para o Ollama.');
        }

        $endpoint = rtrim($this->url, '/') . '/api/generate';
        $lastError = 'Erro desconhecido ao chamar o Ollama.';

        for ($attempt = 0; $attempt <= $this->maxRetries; $attempt++) {
            $handle = curl_init($endpoint);
            curl_setopt_array($handle, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Accept: application/json',
                ],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => 300,
            ]);

            $rawResponse = curl_exec($handle);
            $curlError = curl_error($handle);
            $httpCode = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
            curl_close($handle);

            if ($rawResponse !== false && $httpCode >= 200 && $httpCode < 300) {
                $decodedResponse = json_decode($rawResponse, true);
                if (is_array($decodedResponse) && isset($decodedResponse['response'])) {
                    return [
                        'response' => (string) $decodedResponse['response'],
                        'input_tokens' => isset($decodedResponse['prompt_eval_count']) ? (int) $decodedResponse['prompt_eval_count'] : null,
                        'output_tokens' => isset($decodedResponse['eval_count']) ? (int) $decodedResponse['eval_count'] : null,
                    ];
                }

                $lastError = 'Resposta JSON do Ollama não contém o campo response.';
            } else {
                $lastError = $curlError !== ''
                    ? $curlError
                    : "Ollama respondeu com HTTP {$httpCode}.";
            }
        }

        throw new RuntimeException($lastError);
    }

    public function invokeStreaming($prompt, $onChunk) {
        $payload = json_encode([
            'model' => $this->modelName,
            'prompt' => $prompt,
            'stream' => true,
            'options' => ['temperature' => $this->temperature],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($payload === false) {
            throw new RuntimeException('Não foi possível serializar a requisição para o Ollama.');
        }

        $endpoint = rtrim($this->url, '/') . '/api/generate';
        $lastError = 'Erro desconhecido ao chamar o Ollama.';
        for ($attempt = 0; $attempt <= $this->maxRetries; $attempt++) {
            $buffer = '';
            $lastResponse = [];
            $fullResponse = '';
            $handle = curl_init($endpoint);
            curl_setopt_array($handle, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/x-ndjson'],
                CURLOPT_RETURNTRANSFER => false,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => 300,
                CURLOPT_WRITEFUNCTION => static function ($handle, $data) use (&$buffer, &$lastResponse, &$fullResponse, $onChunk) {
                    $buffer .= $data;
                    while (($lineEnd = strpos($buffer, "\n")) !== false) {
                        $line = trim(substr($buffer, 0, $lineEnd));
                        $buffer = substr($buffer, $lineEnd + 1);
                        if ($line === '') continue;
                        $decoded = json_decode($line, true);
                        if (!is_array($decoded)) continue;
                        $lastResponse = $decoded;
                        if (!empty($decoded['response'])) {
                            $text = (string) $decoded['response'];
                            $fullResponse .= $text;
                            $onChunk($text);
                        }
                    }
                    return strlen($data);
                },
            ]);
            $curlResult = curl_exec($handle);
            $curlError = curl_error($handle);
            $httpCode = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
            curl_close($handle);

            if ($curlResult !== false && $httpCode >= 200 && $httpCode < 300 && !empty($lastResponse)) {
                return [
                    'response' => $fullResponse,
                    'input_tokens' => isset($lastResponse['prompt_eval_count']) ? (int) $lastResponse['prompt_eval_count'] : null,
                    'output_tokens' => isset($lastResponse['eval_count']) ? (int) $lastResponse['eval_count'] : null,
                ];
            }
            $lastError = $curlError !== '' ? $curlError : "Ollama respondeu com HTTP {$httpCode}.";
        }
        throw new RuntimeException($lastError);
    }
}