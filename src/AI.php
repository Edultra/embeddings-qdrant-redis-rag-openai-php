<?php

class AI {
    private $vectorStore;
    private $nlpModel;
    private $debugLog;
    private $promptConfig;
    private $templateText;
    private $topK;

    public function __construct($params) {
        $this->vectorStore = $params['vectorStore'];
        $this->nlpModel = $params['nlpModel'];
        $this->debugLog = $params['debugLog'];
        $this->promptConfig = $params['promptConfig'];
        $this->templateText = $params['templateText'];
        $this->topK = $params['topK'];
    }

    public function retrieveVectorSearchResults($input) {
        if (empty($input['question'])) {
            return [
                'question' => $input['question'],
                'error' => "Desculpe, não encontrei informações relevantes sobre essa pergunta na base de conhecimento."
            ];
        }

        $vectorResults = $this->vectorStore->similaritySearchWithScore(
            $input['question'],
            $this->topK,
            $input['source'] ?? null
        );

        if (empty($vectorResults)) {
            return [
                'question' => $input['question'],
                'error' => "Desculpe, não encontrei informações relevantes sobre essa pergunta na base de conhecimento."
            ];
        }

        $topScore = 0;
        $sources = [];
        
        // Converte os documentos relevantes em um único texto para o prompt
        $contexts = '';
        foreach ($vectorResults as $result) {
            $document = $result[0] ?? [];
            $content = is_array($document) ? ($document['pageContent'] ?? '') : (string) $document;
            $score = (float) ($result[1] ?? 0);

            $source = $document['metadata']['source'] ?? '';
            if ($source !== '' && !in_array($source, $sources, true)) {
                $sources[] = $source;
            }

            if ($content !== '') {
                $contexts .= $content . "\n\n---\n\n";
            }

            $topScore = max($topScore, $score);
        }

        if ($contexts === '') {
            return [
                'question' => $input['question'],
                'error' => "Desculpe, não encontrei informações relevantes sobre essa pergunta na base de conhecimento."
            ];
        }
        
        return [
            'question' => $input['question'],
            'context' => $contexts,
            'topScore' => $topScore,
            'sources' => $sources
        ];
    }

    public function generateNLPResponse($input) {
        // Evita chamar o modelo quando a etapa anterior encontrou um erro
        if (isset($input['error']) && $input['error']) {
            return $input;
        }
        
        $prompt = $this->buildPrompt($input);
        if (trim((string) ($input['context'] ?? '')) === '') {
            return [
                'question' => $input['question'],
                'error' => 'Não foi possível recuperar contexto do documento indexado.',
                'sources' => $input['sources'] ?? [],
            ];
        }
        $onChunk = $input['onChunk'] ?? null;
        $modelResult = is_callable($onChunk) && method_exists($this->nlpModel, 'invokeStreaming')
            ? $this->nlpModel->invokeStreaming($prompt, $onChunk)
            : $this->nlpModel->invoke($prompt);
        $response = is_array($modelResult) ? ($modelResult['response'] ?? '') : (string) $modelResult;
        
        return [
            'question' => $input['question'],
            'answer' => $response,
            'context' => $input['context'],
            'sources' => $input['sources'] ?? [],
            'usage' => [
                'input_tokens' => is_array($modelResult) ? ($modelResult['input_tokens'] ?? null) : null,
                'output_tokens' => is_array($modelResult) ? ($modelResult['output_tokens'] ?? null) : null,
            ]
        ];
    }

    private function buildPrompt($input) {
        $instructions = $this->promptConfig['instructions'] ?? [];
        if (is_array($instructions)) {
            $instructions = implode("\n", array_map(function ($instruction) {
                return '- ' . $instruction;
            }, $instructions));
        }

        $replacements = [
            '{role}' => $this->promptConfig['role'] ?? 'assistente',
            '{task}' => $this->promptConfig['task'] ?? '',
            '{tone}' => $this->promptConfig['constraints']['tone'] ?? '',
            '{language}' => $this->promptConfig['constraints']['language'] ?? 'pt-BR',
            '{format}' => $this->promptConfig['constraints']['format'] ?? '',
            '{instructions}' => $instructions,
            '{question}' => $input['question'],
            '{context}' => $input['context'],
        ];

        return strtr($this->templateText, $replacements);
    }

    public function answerQuestion($question, $source = null, $onChunk = null) {
        // Simular o pipeline completo
        $chainState = ['question' => $question, 'source' => $source, 'onChunk' => $onChunk];
        
        // Busca os resultados vetoriais
        $retrievalResult = $this->retrieveVectorSearchResults($chainState);
        
        // Gera a resposta NLP
        $responseResult = $this->generateNLPResponse($retrievalResult);
        
        return $responseResult;
    }
}