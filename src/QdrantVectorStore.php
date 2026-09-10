<?php

class QdrantVectorStore
{
    private $embeddings;
    private $config;
    private $baseUrl;
    private $collection;

    public function __construct($embeddings, $config)
    {
        $this->embeddings = $embeddings;
        $this->config = $config;
        $this->baseUrl = rtrim($config['url'] ?? 'http://127.0.0.1:6333', '/');
        $this->collection = $config['collection'] ?? 'rag_documents';
    }

    public static function fromExistingCollection($embeddings, $config)
    {
        return new self($embeddings, $config);
    }

    public function similaritySearchWithScore($question, $topK, $source = null)
    {
        echo "🔍 Buscando contexto para: $question\n";
        $embedding = $this->getCachedQueryEmbedding($question);
        $filter = null;
        if ($source !== null && $source !== '') {
            $filter = [
                'must' => [[
                    'key' => 'source',
                    'match' => ['value' => $source],
                ]],
            ];
        }

        $body = [
            'query' => $embedding,
            'limit' => (int) $topK,
            'with_payload' => true,
        ];
        if ($filter !== null) {
            $body['filter'] = $filter;
        }

        try {
            $response = $this->request('POST', '/collections/' . rawurlencode($this->collection) . '/points/query', $body);
            $results = [];
            foreach (($response['result']['points'] ?? $response['result'] ?? []) as $point) {
                $payload = $point['payload'] ?? [];
                $content = (string) ($payload['content'] ?? '');
                if ($content === '') {
                    continue;
                }
                $results[] = [[
                    'pageContent' => $content,
                    'metadata' => [
                        'source' => (string) ($payload['source'] ?? ''),
                        'chunk' => $payload['chunk'] ?? null,
                    ],
                ], (float) ($point['score'] ?? 0)];
            }
            return $results;
        } catch (Exception $e) {
            throw new RuntimeException('Falha na busca vetorial do Qdrant: ' . $e->getMessage(), 0, $e);
        }
    }

    public function addDocuments($documents)
    {
        echo "💾 Adicionando " . count($documents) . " documentos ao Qdrant\n";
        if ($documents === []) {
            return;
        }

        try {
            $batchSize = max(1, (int) ($this->config['batchSize'] ?? 32));
            foreach (array_chunk($documents, $batchSize) as $batchNumber => $documentBatch) {
                $vectors = $this->embeddings->embedDocuments($documentBatch);
                $points = [];
                foreach ($documentBatch as $index => $doc) {
                    $content = (string) ($doc['pageContent'] ?? '');
                    $source = (string) ($doc['metadata']['source'] ?? '');
                    $chunk = (int) ($doc['metadata']['chunk'] ?? $index);
                    $points[] = [
                        'id' => $this->pointId($source, $chunk),
                        'vector' => $vectors[$index],
                        'payload' => [
                            'content' => $content,
                            'source' => $source,
                            'chunk' => $chunk,
                        ],
                    ];
                }

                $this->ensureCollection(count($points[0]['vector']));
                $this->request('PUT', '/collections/' . rawurlencode($this->collection) . '/points?wait=true', ['points' => $points]);
                echo "✅ Lote " . ($batchNumber + 1) . " indexado (" . count($points) . " documentos)\n";
            }
        } catch (Exception $e) {
            echo "❌ Erro ao adicionar documentos: " . $e->getMessage() . "\n";
            throw $e;
        }
    }

    public function hasDocumentSource($source)
    {
        try {
            $response = $this->request('POST', '/collections/' . rawurlencode($this->collection) . '/points/count', [
                'exact' => true,
                'filter' => [
                    'must' => [[
                        'key' => 'source',
                        'match' => ['value' => $source],
                    ]],
                ],
            ]);
            return (int) ($response['result']['count'] ?? 0) > 0;
        } catch (RuntimeException $e) {
            if ($e->getCode() === 404) {
                return false;
            }
            throw new RuntimeException('Não foi possível verificar o documento no Qdrant: ' . $e->getMessage(), 0, $e);
        }
    }

    public function close()
    {
        echo "🔒 Conexão com o Qdrant encerrada\n";
    }

    public function clearAll($unused = null)
    {
        $this->request('POST', '/collections/' . rawurlencode($this->collection) . '/points/delete?wait=true', [
            'filter' => [],
        ]);
    }

    private function ensureCollection($dimension)
    {
        try {
            $this->request('GET', '/collections/' . rawurlencode($this->collection));
            return;
        } catch (RuntimeException $e) {
            if ($e->getCode() !== 404) {
                throw $e;
            }
        }

        $this->request('PUT', '/collections/' . rawurlencode($this->collection), [
            'vectors' => [
                'size' => (int) $dimension,
                'distance' => 'Cosine',
            ],
        ]);
    }

    private function pointId($source, $chunk)
    {
        return hexdec(substr(sha1($source . '#' . $chunk), 0, 15));
    }

    // Evita chamadas repetidas à OpenAI para a mesma pergunta (cache de 24h)
    private function getCachedQueryEmbedding($question)
    {
        $cacheKey = 'embedding:' . hash('xxh128', $question);
        $cached = getCache($cacheKey, 86400, 'embeddings');
        if (is_array($cached) && $cached !== []) {
            return $cached;
        }

        $embedding = $this->embeddings->embedQuery($question);
        saveCache($cacheKey, $embedding, 'embeddings', 86400);
        return $embedding;
    }

    private function request($method, $path, $body = null)
    {
        $headers = ['Accept: application/json', 'Content-Type: application/json'];
        if (!empty($this->config['apiKey'])) {
            $headers[] = 'api-key: ' . $this->config['apiKey'];
        }

        $handle = curl_init($this->baseUrl . $path);
        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 300,
        ]);
        if ($body !== null) {
            $payload = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($payload === false) {
                throw new RuntimeException('Não foi possível serializar a requisição para o Qdrant.');
            }
            curl_setopt($handle, CURLOPT_POSTFIELDS, $payload);
        }

        $rawResponse = curl_exec($handle);
        $curlError = curl_error($handle);
        $httpCode = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        curl_close($handle);
        if ($rawResponse === false || $curlError !== '') {
            throw new RuntimeException($curlError !== '' ? $curlError : 'Resposta vazia do Qdrant.');
        }
        $decoded = json_decode($rawResponse, true);
        if ($httpCode < 200 || $httpCode >= 300) {
            $message = $decoded['status']['error'] ?? $decoded['result']['status'] ?? "Qdrant respondeu com HTTP {$httpCode}.";
            throw new RuntimeException($message, $httpCode);
        }
        if (!is_array($decoded)) {
            throw new RuntimeException('Resposta inválida do Qdrant.');
        }
        return $decoded;
    }
}