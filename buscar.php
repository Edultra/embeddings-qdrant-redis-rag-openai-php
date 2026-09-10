<?php

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/src/Config.php';
require_once __DIR__ . '/src/DocumentProcessor.php';
require_once __DIR__ . '/src/AI.php';
require_once __DIR__ . '/src/QdrantVectorStore.php';
require_once __DIR__ . '/src/Embeddings.php';
require_once __DIR__ . '/src/OpenAI.php';
require_once __DIR__ . '/src/Cache.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Método não permitido.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$payload = json_decode(file_get_contents('php://input'), true);
$action = is_array($payload) ? ($payload['action'] ?? 'ask') : 'ask';

if ($action === 'index_document') {
    $selectedDocument = is_array($payload) ? ($payload['document'] ?? '') : '';
    $documentDirectory = realpath(__DIR__ . '/documents');
    $documentPath = $documentDirectory ? realpath($documentDirectory . DIRECTORY_SEPARATOR . basename((string) $selectedDocument)) : false;

    if (!$documentDirectory || !$documentPath || strpos($documentPath, $documentDirectory . DIRECTORY_SEPARATOR) !== 0 || strtolower(pathinfo($documentPath, PATHINFO_EXTENSION)) !== 'pdf') {
        http_response_code(422);
        echo json_encode(['error' => 'Selecione um arquivo PDF válido da pasta documents.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $vectorStore = null;
    ob_start();
    try {
        Config::load();
        $processor = new DocumentProcessor($documentPath, Config::$textSplitter);
        $documents = $processor->loadAndSplit();
        $embeddings = new Embeddings(Config::$embedding['modelName'], Config::$embedding['pretrainedOptions'], Config::$openai);
        $vectorStore = QdrantVectorStore::fromExistingCollection($embeddings, Config::$qdrant);
        $source = basename($documentPath);

        if ($vectorStore->hasDocumentSource($source)) {
            ob_end_clean();
            echo json_encode([
                'success' => true,
                'already_indexed' => true,
                'message' => $source . ' já foi enviado anteriormente para o Qdrant.',
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $vectorStore->addDocuments($documents);
        ob_end_clean();
        echo json_encode(['success' => true, 'message' => $source . ' foi indexado com sucesso (' . count($documents) . ' partes).'], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $error) {
        ob_end_clean();
        http_response_code(500);
        echo json_encode(['error' => 'Não foi possível indexar o documento. Verifique o Qdrant e o arquivo selecionado.'], JSON_UNESCAPED_UNICODE);
    } finally {
        if ($vectorStore) {
            ob_start();
            $vectorStore->close();
            ob_end_clean();
        }
    }
    exit;
}

$question = is_array($payload) ? ($payload['question'] ?? '') : ($_POST['question'] ?? '');
$question = trim((string) $question);
$source = is_array($payload) ? basename(trim((string) ($payload['document'] ?? ''))) : '';
$streaming = is_array($payload) && !empty($payload['stream']);

if ($source === '') {
    http_response_code(422);
    echo json_encode(['error' => 'Selecione um documento antes de fazer uma pergunta.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($question === '') {
    http_response_code(422);
    echo json_encode(['error' => 'Escreva uma pergunta antes de enviar.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (strlen($question) > 200) {
    http_response_code(422);
    echo json_encode(['error' => 'A pergunta deve ter no máximo 200 caracteres.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$vectorStore = null;
$streamStarted = false;
ob_start();

try {
    Config::load();
    $cacheKey = hash('xxh128', $source . $question);
    $cache = getCache($cacheKey, 3600, $path = '');

    if (empty($cache)) {
        //echo 'não esta usando cache'; die;
        $embeddings = new Embeddings(Config::$embedding['modelName'], Config::$embedding['pretrainedOptions'], Config::$openai);
        $vectorStore = QdrantVectorStore::fromExistingCollection($embeddings, Config::$qdrant);
        $ai = new AI([
            'nlpModel' => new OpenAI(Config::$openai),
            'debugLog' => static function () {},
            'vectorStore' => $vectorStore,
            'promptConfig' => Config::$promptConfig,
            'templateText' => Config::$templateText,
            'topK' => Config::$similarity['topK'],
        ]);

        if ($streaming) {
            ob_end_clean();
            $streamStarted = true;
            header('Content-Type: text/event-stream; charset=utf-8');
            header('Cache-Control: no-cache, no-store, must-revalidate');
            header('Connection: keep-alive');
            header('Content-Encoding: none');
            header('X-Accel-Buffering: no');
            ini_set('output_buffering', 'off');
            ini_set('zlib.output_compression', '0');
            ob_implicit_flush(true);
            echo ": connected\n" . str_repeat(' ', 4096) . "\n\n";
            flush();
            $result = $ai->answerQuestion($question, $source, static function ($chunk) {
                echo 'data: ' . json_encode(['type' => 'chunk', 'text' => $chunk], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n" . str_repeat(' ', 4096) . "\n\n";
                while (ob_get_level() > 0) {
                    ob_end_flush();
                }
                flush();
            });
        } else {
            $result = $ai->answerQuestion($question, $source);
        }

        if ($streaming) {
            if (empty($result['error'])) {
                saveCache($cacheKey, $result);
            }
            if (!empty($result['error'])) {
                echo 'data: ' . json_encode(['type' => 'error', 'error' => $result['error']], JSON_UNESCAPED_UNICODE) . "\n" . str_repeat(' ', 4096) . "\n\n";
            } else {
                echo 'data: ' . json_encode([
                    'type' => 'done',
                    'answer' => $result['answer'] ?? '',
                    'sources' => $result['sources'] ?? [],
                    'usage' => $result['usage'] ?? [],
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n" . str_repeat(' ', 4096) . "\n\n";
            }
            flush();
            exit;
        }
        ob_end_clean();

        if (!empty($result['error'])) {
            http_response_code(422);
            echo json_encode(['error' => $result['error']], JSON_UNESCAPED_UNICODE);
        } else {
            saveCache($cacheKey, $result);
            echo json_encode([
                'question' => $result['question'],
                'answer' => $result['answer'],
                'sources' => $result['sources'] ?? [],
                'usage' => $result['usage'] ?? [],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
    } else {
        if ($streaming) {
            ob_end_clean();
            $streamStarted = true;
            header('Content-Type: text/event-stream; charset=utf-8');
            header('Cache-Control: no-cache, no-store, must-revalidate');
            header('Connection: keep-alive');
            header('Content-Encoding: none');
            header('X-Accel-Buffering: no');
            echo 'data: ' . json_encode([
                'type' => 'done',
                'answer' => $cache['answer'],
                'sources' => $cache['sources'] ?? [],
                'usage' => $cache['usage'] ?? [],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
            flush();
            exit;
        }

        echo json_encode([
            'question' => $cache['question'],
            'answer' => $cache['answer'],
            'sources' => $cache['sources'] ?? [],
            'usage' => $cache['usage'] ?? [],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
} catch (Throwable $error) {
    if ($streamStarted) {
        echo 'data: ' . json_encode(['type' => 'error', 'error' => 'Não foi possível obter uma resposta agora.'], JSON_UNESCAPED_UNICODE) . "\n" . str_repeat(' ', 4096) . "\n\n";
        flush();
    } else {
        ob_end_clean();
        http_response_code(500);
        echo json_encode(['error' => 'Não foi possível obter uma resposta agora. Verifique a chave da OpenAI e o Qdrant.'], JSON_UNESCAPED_UNICODE);
    }
} finally {
    if ($vectorStore) {
        ob_start();
        $vectorStore->close();
        ob_end_clean();
    }
}
