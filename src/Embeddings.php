<?php

class Embeddings {
    private $modelName;
    private $apiKey;
    private $url;
    private $caBundle;

    public function __construct($modelName, $pretrainedOptions = [], $config = []) {
        $this->modelName = $modelName;
        $this->apiKey = $config['apiKey'] ?? '';
        $this->url = rtrim($config['url'] ?? 'https://api.openai.com/v1', '/');
        $this->caBundle = $config['caBundle'] ?? '';
    }

    public function embedQuery($query) {
        return $this->request($query);
    }

    public function embedDocuments($documents) {
        $texts = [];
        foreach ($documents as $doc) {
            $texts[] = (string) ($doc['pageContent'] ?? '');
        }
        return $texts === [] ? [] : $this->request($texts);
    }

    private function request($text) {
        $payload = json_encode([
            'model' => $this->modelName,
            'input' => $text,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($payload === false) {
            throw new RuntimeException('Não foi possível serializar a requisição de embedding para a OpenAI.');
        }
        
        $handle = curl_init($this->url . '/embeddings');
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->apiKey,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
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

        if ($rawResponse === false || $httpCode < 200 || $httpCode >= 300) {
            if ($curlError !== '') {
                throw new RuntimeException($curlError);
            }
            $decoded = json_decode((string) $rawResponse, true);
            $message = $decoded['error']['message'] ?? "OpenAI respondeu com HTTP {$httpCode}.";
            throw new RuntimeException($message);
        }

        $decoded = json_decode($rawResponse, true);
        $items = $decoded['data'] ?? [];
        usort($items, static function ($left, $right) {
            return ($left['index'] ?? 0) <=> ($right['index'] ?? 0);
        });
        $embeddings = array_map(static function ($item) {
            return $item['embedding'] ?? null;
        }, $items);
        if (is_string($text)) {
            $embedding = $embeddings[0] ?? null;
            if (!is_array($embedding) || empty($embedding)) {
                throw new RuntimeException('Resposta de embedding da OpenAI inválida.');
            }
            return $embedding;
        }
        if (count($embeddings) !== count($text) || in_array(null, $embeddings, true)) {
            throw new RuntimeException('Resposta de embedding da OpenAI inválida.');
        }
        return $embeddings;
    }
}