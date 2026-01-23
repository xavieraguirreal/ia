<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use App\Models\Article;
use Exception;

/**
 * Servicio de Embeddings e IA
 * Soporta tanto OpenAI como API local (Ollama/Qwen)
 */
class EmbeddingsService
{
    protected string $apiKey;
    protected string $baseUrl;
    protected string $embeddingModel;
    protected string $chatModel;
    protected bool $useLocalAI;

    public function __construct()
    {
        // Determinar si usar API local o OpenAI
        $this->useLocalAI = config('services.local_ai.enabled', false);

        if ($this->useLocalAI) {
            // Configuración API Local (Ollama/Qwen)
            $this->apiKey = config('services.local_ai.api_key', '');
            $this->baseUrl = rtrim(config('services.local_ai.base_url', 'http://localhost:8000/v1'), '/');
            $this->embeddingModel = config('services.local_ai.embedding_model', 'nomic-embed-text');
            $this->chatModel = config('services.local_ai.chat_model', 'qwen2.5:7b-instruct');
        } else {
            // Configuración OpenAI
            $this->apiKey = config('services.openai.api_key');
            $this->baseUrl = config('services.openai.base_url', 'https://api.openai.com/v1');
            $this->embeddingModel = config('services.openai.embedding_model', 'text-embedding-3-small');
            $this->chatModel = config('services.openai.chat_model', 'gpt-4o-mini');
        }
    }

    /**
     * Obtiene el cliente HTTP configurado
     */
    protected function getHttpClient(int $timeout = 30): \Illuminate\Http\Client\PendingRequest
    {
        $client = Http::withHeaders([
            'Content-Type' => 'application/json',
        ])->timeout($timeout);

        // Agregar Authorization solo si hay API key
        if (!empty($this->apiKey)) {
            $client = $client->withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
            ]);
        }

        return $client;
    }

    /**
     * Genera embedding para un texto
     */
    public function generateEmbedding(string $text): array
    {
        $text = $this->prepareText($text);

        $response = $this->getHttpClient(60)->post($this->baseUrl . '/embeddings', [
            'model' => $this->embeddingModel,
            'input' => $text,
            'encoding_format' => 'float',
        ]);

        if (!$response->successful()) {
            throw new Exception("Error Embeddings API: " . $response->body());
        }

        $result = $response->json();

        return [
            'embedding' => $result['data'][0]['embedding'],
            'tokens' => $result['usage']['total_tokens'] ?? 0,
            'model' => $this->embeddingModel,
            'provider' => $this->useLocalAI ? 'local' : 'openai',
        ];
    }

    /**
     * Genera embedding para un articulo
     */
    public function generateArticleEmbedding(Article $article): array
    {
        $text = $this->prepareArticleText($article);
        return $this->generateEmbedding($text);
    }

    /**
     * Calcula similitud de coseno entre dos embeddings
     */
    public static function cosineSimilarity(array $a, array $b): float
    {
        if (count($a) !== count($b)) {
            throw new Exception("Los embeddings deben tener la misma dimension");
        }

        $dotProduct = 0;
        $normA = 0;
        $normB = 0;

        for ($i = 0; $i < count($a); $i++) {
            $dotProduct += $a[$i] * $b[$i];
            $normA += $a[$i] * $a[$i];
            $normB += $b[$i] * $b[$i];
        }

        $normA = sqrt($normA);
        $normB = sqrt($normB);

        if ($normA == 0 || $normB == 0) {
            return 0;
        }

        return $dotProduct / ($normA * $normB);
    }

    /**
     * Busqueda semantica de articulos
     */
    public function searchArticles(string $query, int $limit = 10, float $threshold = 0.3): array
    {
        // Buscar en cache
        $cacheKey = 'semantic_search_' . md5($query . $this->embeddingModel);
        $cached = Cache::get($cacheKey);

        if ($cached) {
            return array_merge($cached, ['cached' => true]);
        }

        // Generar embedding de la query
        $queryResult = $this->generateEmbedding($query);
        $queryEmbedding = $queryResult['embedding'];

        // Obtener articulos con embeddings
        $articles = Article::published()
            ->whereNotNull('embedding')
            ->with(['category', 'author'])
            ->get();

        if ($articles->isEmpty()) {
            return [
                'results' => [],
                'total' => 0,
                'tokens' => $queryResult['tokens'],
                'cached' => false,
            ];
        }

        // Calcular similitud con cada articulo
        $results = [];
        foreach ($articles as $article) {
            $articleEmbedding = json_decode($article->embedding, true);

            // Verificar que las dimensiones coinciden
            if (count($articleEmbedding) !== count($queryEmbedding)) {
                continue; // Skip artículos con embeddings de dimensión diferente
            }

            $similarity = self::cosineSimilarity($queryEmbedding, $articleEmbedding);

            if ($similarity >= $threshold) {
                $results[] = [
                    'article' => $article,
                    'similarity' => round($similarity, 4),
                    'similarity_percent' => round($similarity * 100, 1) . '%',
                ];
            }
        }

        // Ordenar por similitud
        usort($results, fn($a, $b) => $b['similarity'] <=> $a['similarity']);

        // Limitar
        $results = array_slice($results, 0, $limit);

        $response = [
            'results' => $results,
            'total' => count($results),
            'tokens' => $queryResult['tokens'],
            'cached' => false,
        ];

        // Guardar en cache por 1 hora
        Cache::put($cacheKey, $response, 3600);

        return $response;
    }

    /**
     * Encuentra articulos relacionados usando embeddings (sin llamada a API)
     */
    public function findRelatedArticles(Article $article, int $limit = 3): array
    {
        // Si el articulo no tiene embedding, retornar vacio
        if (!$article->embedding) {
            return [];
        }

        $cacheKey = 'related_articles_' . $article->id . '_' . $this->embeddingModel;
        $cached = Cache::get($cacheKey);

        if ($cached) {
            return $cached;
        }

        $articleEmbedding = json_decode($article->embedding, true);
        $embeddingDimension = count($articleEmbedding);

        // Obtener otros articulos con embeddings de la misma dimensión
        $otherArticles = Article::published()
            ->where('id', '!=', $article->id)
            ->whereNotNull('embedding')
            ->with(['category', 'author'])
            ->get();

        if ($otherArticles->isEmpty()) {
            return [];
        }

        // Calcular similitud
        $results = [];
        foreach ($otherArticles as $other) {
            $otherEmbedding = json_decode($other->embedding, true);

            // Verificar que las dimensiones coinciden
            if (count($otherEmbedding) !== $embeddingDimension) {
                continue;
            }

            $similarity = self::cosineSimilarity($articleEmbedding, $otherEmbedding);

            $results[] = [
                'article' => $other,
                'similarity' => $similarity,
                'similarity_percent' => round($similarity * 100, 1) . '%',
            ];
        }

        // Ordenar por similitud
        usort($results, fn($a, $b) => $b['similarity'] <=> $a['similarity']);

        // Limitar
        $results = array_slice($results, 0, $limit);

        // Cache por 24 horas
        Cache::put($cacheKey, $results, 86400);

        return $results;
    }

    /**
     * Realiza una llamada al endpoint de chat/completions
     */
    protected function chatCompletion(string $systemPrompt, string $userContent, int $maxTokens = 300): string
    {
        // Timeout más largo para API local (modelos en CPU son lentos)
        $timeout = $this->useLocalAI ? 120 : 60;

        $response = $this->getHttpClient($timeout)->post($this->baseUrl . '/chat/completions', [
            'model' => $this->chatModel,
            'messages' => [
                [
                    'role' => 'system',
                    'content' => $systemPrompt
                ],
                [
                    'role' => 'user',
                    'content' => $userContent
                ]
            ],
            'max_tokens' => $maxTokens,
            'temperature' => 0.3,
        ]);

        if (!$response->successful()) {
            throw new Exception("Error Chat API: " . $response->body());
        }

        $result = $response->json();
        return trim($result['choices'][0]['message']['content'] ?? '');
    }

    /**
     * Genera resumen IA de un articulo
     */
    public function generateSummary(Article $article, int $maxPoints = 4): string
    {
        $text = $this->prepareArticleText($article);
        return $this->generateSummaryFromText($text, $maxPoints);
    }

    /**
     * Genera resumen IA a partir de texto
     */
    public function generateSummaryFromText(string $content, int $maxPoints = 4): string
    {
        $text = $this->prepareText($content);

        $systemPrompt = "Eres un editor de noticias educativas. Genera un resumen en bullet points (máximo {$maxPoints} puntos) del artículo. Cada punto debe ser conciso (máximo 15 palabras). Responde SOLO con los bullet points, sin introducción. Usa el formato: • Punto 1\n• Punto 2";

        return $this->chatCompletion($systemPrompt, $text, 300);
    }

    /**
     * Genera extracto/resumen corto a partir del contenido
     */
    public function generateExcerpt(string $content, int $maxWords = 50): string
    {
        $text = $this->prepareText($content);

        $systemPrompt = "Eres un editor de noticias. Genera un extracto/resumen muy breve (máximo {$maxWords} palabras) del siguiente texto. El extracto debe ser atractivo y resumir la idea principal. Responde SOLO con el extracto, sin comillas ni introducción.";

        return $this->chatCompletion($systemPrompt, $text, 150);
    }

    /**
     * Sugiere etiquetas a partir del contenido
     */
    public function suggestTags(string $content, int $maxTags = 5): array
    {
        $text = $this->prepareText($content);

        $systemPrompt = "Eres un editor de noticias educativas. Sugiere {$maxTags} etiquetas/tags relevantes para el siguiente artículo. Las etiquetas deben ser palabras clave cortas (1-3 palabras cada una), relevantes para el contenido educativo. Responde SOLO con las etiquetas separadas por comas, sin números ni explicaciones. Ejemplo: educación, tecnología, docentes";

        $tagsString = $this->chatCompletion($systemPrompt, $text, 100);

        // Convertir string de tags a array
        $tags = array_map('trim', explode(',', $tagsString));
        $tags = array_filter($tags); // Eliminar vacíos

        return $tags;
    }

    /**
     * Prepara el texto del articulo para embedding
     */
    protected function prepareArticleText(Article $article): string
    {
        $parts = [];

        $parts[] = "Titulo: " . $article->title;

        if ($article->category) {
            $parts[] = "Categoria: " . $article->category->name;
        }

        if ($article->tags && $article->tags->count() > 0) {
            $parts[] = "Tags: " . $article->tags->pluck('name')->implode(', ');
        }

        if ($article->excerpt) {
            $parts[] = "Resumen: " . $article->excerpt;
        }

        if ($article->body) {
            $parts[] = "Contenido: " . strip_tags($article->body);
        }

        return implode("\n\n", $parts);
    }

    /**
     * Prepara texto limpiando HTML y espacios
     */
    protected function prepareText(string $text): string
    {
        $text = strip_tags($text);
        $text = preg_replace('/\s+/', ' ', $text);

        // Truncar a ~6000 palabras
        $words = explode(' ', $text);
        if (count($words) > 6000) {
            $words = array_slice($words, 0, 6000);
            $text = implode(' ', $words);
        }

        return trim($text);
    }

    /**
     * Obtiene información del proveedor actual
     */
    public function getProviderInfo(): array
    {
        return [
            'provider' => $this->useLocalAI ? 'local' : 'openai',
            'base_url' => $this->baseUrl,
            'chat_model' => $this->chatModel,
            'embedding_model' => $this->embeddingModel,
        ];
    }
}
