<?php

class DocumentProcessor
{
    private $pdfPath;
    private $textSplitterConfig;

    public function __construct($pdfPath, $textSplitterConfig)
    {
        $this->pdfPath = $pdfPath;
        $this->textSplitterConfig = $textSplitterConfig;
    }

    public function loadAndSplit()
    {
        // Ler o PDF usando a biblioteca smalot/pdfparser
        echo "📄 Carregando conteúdo do PDF...\n";

        try {
            // Usar a biblioteca PDFParser para extrair texto
            $parser = new \Smalot\PdfParser\Parser();
            $pdf = $parser->parseFile($this->pdfPath);

            // Extrair o texto do PDF
            $content = $this->normalizeUtf8($pdf->getText());

            if (empty(trim($content))) {
                echo "⚠️  Aviso: O conteúdo do PDF está vazio. Usando conteúdo simulado.\n";
            }
        } catch (Exception $e) {
            echo "⚠️  Erro ao ler o PDF: " . $e->getMessage() . "\n";
            throw new RuntimeException('Não foi possível ler o PDF: ' . $e->getMessage(), 0, $e);
        }

        // Dividir o conteúdo em chunks
        $chunks = $this->splitText($content, $this->textSplitterConfig['chunkSize'], $this->textSplitterConfig['chunkOverlap']);

        $documents = [];
        foreach ($chunks as $index => $chunk) {
            $documents[] = [
                'pageContent' => $chunk,
                'metadata' => [
                    'source' => basename($this->pdfPath),
                    'chunk' => $index
                ]
            ];
        }

        echo "✂️  Dividido em " . count($documents) . " chunks\n";

        return $documents;
    }

    private function normalizeUtf8($text)
    {
        $text = (string) $text;
        if (function_exists('iconv')) {
            $normalized = iconv('UTF-8', 'UTF-8//IGNORE', $text);
            if ($normalized !== false) {
                return $normalized;
            }
        }
        return function_exists('mb_convert_encoding')
            ? mb_convert_encoding($text, 'UTF-8', 'UTF-8')
            : $text;
    }


    private function splitText($text, $chunkSize, $chunkOverlap)
    {
        // Implementação simplificada de divisão de texto
        $chunks = [];
        $length = strlen($text);
        $start = 0;

        // Limitar o tamanho máximo do chunk para evitar problemas de memória
        $maxChunkSize = min($chunkSize, (int) (getenv('MAX_CHUNK_SIZE') ?: 500));

        /*
        if ($maxChunkSize <= $chunkOverlap) {
            throw new InvalidArgumentException(
                'chunkOverlap deve ser menor que o tamanho máximo do chunk.'
            );
        }
        */

        while ($start < $length) {
            $end = min($start + $maxChunkSize, $length);
            $chunk = substr($text, $start, $end - $start);

            // Garantir que não estamos cortando palavras
            if ($end < $length && strpos($chunk, ' ') !== false) {
                $lastSpace = strrpos($chunk, ' ');
                if ($lastSpace > 0) {
                    $chunk = substr($chunk, 0, $lastSpace);
                }
            }

            $chunk = $this->normalizeUtf8($chunk);

            $chunks[] = $chunk;
            if ($end >= $length) {
                break;
            }
            $start = $end - $chunkOverlap;

            // Evitar loop infinito
            if (count($chunks) > 5000) break;
        }

        return $chunks;
    }
}
