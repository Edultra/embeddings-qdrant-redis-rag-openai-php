<?php

class Config {
    public static $promptConfig;
    public static $templateText;
    public static $output;
    public static $qdrant;
    public static $openai;
    public static $pdf;
    public static $textSplitter;
    public static $embedding;
    public static $similarity;

    public static function load() {
        self::loadEnvironment();

        // Carregar o conteúdo do arquivo de prompts
        self::$promptConfig = json_decode(file_get_contents(__DIR__ . '/../prompts/answerPrompt.json'), true);
        self::$templateText = file_get_contents(__DIR__ . '/../prompts/template.txt');
        
        self::$output = [
            'answersFolder' => __DIR__ . '/../respostas',
            'fileName' => 'resposta',
        ];
        
        // Configurações do Qdrant
        self::$qdrant = [
            'url' => $_ENV['QDRANT_URL'] ?? 'http://127.0.0.1:6333',
            'apiKey' => $_ENV['QDRANT_API_KEY'] ?? '',
            'collection' => $_ENV['QDRANT_COLLECTION'] ?? 'rag_documents',
            'batchSize' => max(1, (int) ($_ENV['QDRANT_BATCH_SIZE'] ?? 32)),
        ];
        
        // Configurações da OpenAI
        self::$openai = [
            'url' => $_ENV['OPENAI_API_URL'] ?? 'https://api.openai.com/v1',
            'apiKey' => $_ENV['OPENAI_API_KEY'] ?? '',
            'modelName' => $_ENV['OPENAI_MODEL'] ?? 'gpt-4o-mini',
            'temperature' => 0.3,
            'maxOutputTokens' => max(1, (int) ($_ENV['OPENAI_MAX_OUTPUT_TOKENS'] ?? 350)),
            'maxRetries' => max(0, (int) ($_ENV['OPENAI_MAX_RETRIES'] ?? 1)),
            'caBundle' => $_ENV['OPENAI_CA_BUNDLE'] ?? '',
        ];
        
        // Configurações do PDF
        self::$pdf = [
            'path' => __DIR__ . '/../tensores.pdf',
        ];
        
        // Configurações do text splitter
        self::$textSplitter = [
            'chunkSize' => 1000,
            'chunkOverlap' => 200,
        ];
        
        // Configurações de embeddings
        self::$embedding = [
            'modelName' => $_ENV['OPENAI_EMBEDDING_MODEL'] ?? 'text-embedding-3-small',
            'pretrainedOptions' => [],
        ];
        
        // Configurações de similaridade
        self::$similarity = [
            'topK' => 3,
        ];
    }

    private static function loadEnvironment() {
        $envPath = __DIR__ . '/../.env';
        if (!is_file($envPath)) {
            return;
        }

        $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, '#') === 0 || strpos($line, '=') === false) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            if ($key !== '' && !isset($_ENV[$key])) {
                $_ENV[$key] = trim($value, "\"'");
            }
        }
    }
}