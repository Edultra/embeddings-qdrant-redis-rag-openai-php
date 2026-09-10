<?php

class OpenAI {
    private $url;
    private $apiKey;
    private $modelName;
    private $temperature;
    private $maxOutputTokens;
    private $maxRetries;
    private $caBundle;

    public function __construct($config) {
        $this->url = rtrim($config['url'], '/');
        $this->apiKey = $config['apiKey'];
        $this->modelName = $config['modelName'];
        $this->temperature = $this->modelName === 'gpt-5.6-luna'
            ? null
            : ($config['temperature'] ?? 0.3);
        $this->maxOutputTokens = max(1, (int) ($config['maxOutputTokens'] ?? 350));
        $this->maxRetries = max(0, (int) ($config['maxRetries'] ?? 1));
        $this->caBundle = $config['caBundle'] ?? '';
    }

    public function invoke($prompt) {
        $payload = $this->buildPayload($prompt, false);
        $lastError = 'Erro desconhecido ao chamar a OpenAI.';

        for ($attempt = 0; $attempt <= $this->maxRetries; $attempt++) {
            [$rawResponse, $curlError, $httpCode] = $this->request('/responses', $payload);
            if ($rawResponse !== false && $httpCode >= 200 && $httpCode < 300) {
                $decodedResponse = json_decode($rawResponse, true);
                $content = $this->extractResponseText($decodedResponse);
                if (is_string($content)) {
                    return [
                        'response' => $content,
                        'input_tokens' => isset($decodedResponse['usage']['input_tokens']) ? (int) $decodedResponse['usage']['input_tokens'] : null,
                        'output_tokens' => isset($decodedResponse['usage']['output_tokens']) ? (int) $decodedResponse['usage']['output_tokens'] : null,
                    ];
                }
                $lastError = 'Resposta JSON da OpenAI não contém texto de saída.';
            } else {
                $lastError = $this->formatError($curlError, $httpCode, $rawResponse);
            }
        }

        throw new RuntimeException($lastError);
    }

    public function invokeStreaming($prompt, $onChunk) {
        $payload = $this->buildPayload($prompt, true);
        $lastError = 'Erro desconhecido ao chamar a OpenAI.';

        for ($attempt = 0; $attempt <= $this->maxRetries; $attempt++) {
            $buffer = '';
            $fullResponse = '';
            $usage = [];
            $handle = curl_init($this->url . '/responses');
            curl_setopt_array($handle, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_HTTPHEADER => $this->headers('text/event-stream'),
                CURLOPT_RETURNTRANSFER => false,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => 300,
                CURLOPT_WRITEFUNCTION => static function ($handle, $data) use (&$buffer, &$fullResponse, &$usage, $onChunk) {
                    $buffer .= $data;
                    while (($lineEnd = strpos($buffer, "\n")) !== false) {
                        $line = trim(substr($buffer, 0, $lineEnd));
                        $buffer = substr($buffer, $lineEnd + 1);
                        if ($line === '' || strpos($line, ':') === 0 || strpos($line, 'data:') !== 0) {
                            continue;
                        }

                        $decoded = json_decode(trim(substr($line, 5)), true);
                        if (!is_array($decoded)) {
                            continue;
                        }

                        if (($decoded['type'] ?? '') === 'response.output_text.delta') {
                            $text = $decoded['delta'] ?? '';
                            if (is_string($text) && $text !== '') {
                                $fullResponse .= $text;
                                $onChunk($text);
                            }
                        }

                        if (($decoded['type'] ?? '') === 'response.completed') {
                            $usage = $decoded['response']['usage'] ?? [];
                        }
                    }
                    return strlen($data);
                },
            ]);
            if ($this->caBundle !== '') {
                curl_setopt($handle, CURLOPT_CAINFO, $this->caBundle);
            }

            $curlResult = curl_exec($handle);
            $curlError = curl_error($handle);
            $httpCode = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
            curl_close($handle);

            if ($curlResult !== false && $httpCode >= 200 && $httpCode < 300 && $fullResponse !== '') {
                return [
                    'response' => $fullResponse,
                    'input_tokens' => isset($usage['input_tokens']) ? (int) $usage['input_tokens'] : null,
                    'output_tokens' => isset($usage['output_tokens']) ? (int) $usage['output_tokens'] : null,
                ];
            }
            $lastError = $this->formatError($curlError, $httpCode, '');
        }

        throw new RuntimeException($lastError);
    }

    private function buildPayload($prompt, $stream) {
        $payload = [
            'model' => $this->modelName,
            'input' => $prompt,
            'max_output_tokens' => $this->maxOutputTokens,
            'stream' => $stream,
        ];
        if ($this->temperature !== null) {
            $payload['temperature'] = $this->temperature;
        }
        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            throw new RuntimeException('Não foi possível serializar a requisição para a OpenAI.');
        }
        return $encoded;
    }

    private function headers($accept) {
        return [
            'Authorization: Bearer ' . $this->apiKey,
            'Content-Type: application/json',
            'Accept: ' . $accept,
        ];
    }

    private function extractResponseText($response) {
        if (isset($response['output_text']) && is_string($response['output_text'])) {
            return $response['output_text'];
        }

        $text = '';
        foreach ($response['output'] ?? [] as $item) {
            foreach ($item['content'] ?? [] as $content) {
                if (($content['type'] ?? '') === 'output_text' && isset($content['text'])) {
                    $text .= (string) $content['text'];
                }
            }
        }
        return $text !== '' ? $text : null;
    }

    private function request($path, $payload) {
        $handle = curl_init($this->url . $path);
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => $this->headers('application/json'),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 300,
        ]);
        if ($this->caBundle !== '') {
            curl_setopt($handle, CURLOPT_CAINFO, $this->caBundle);
        }
        $rawResponse = curl_exec($handle);
        $curlError = curl_error($handle);
        $httpCode = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        curl_close($handle);
        return [$rawResponse, $curlError, $httpCode];
    }

    private function formatError($curlError, $httpCode, $rawResponse) {
        if ($curlError !== '') {
            return $curlError;
        }
        $decoded = json_decode((string) $rawResponse, true);
        $message = $decoded['error']['message'] ?? '';
        return $message !== '' ? $message : "OpenAI respondeu com HTTP {$httpCode}.";
    }
}
