<?php

namespace App\Services;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * AI Service Layer - Abstracts AI provider (Ollama, OpenAI, Anthropic, etc.)
 * Includes rate limiting, caching, and error handling
 */
class AiService
{
    protected $client;

    protected $provider;

    protected $model;

    protected $baseUrl;

    protected $apiKey;

    protected $maxTokens = 2000;

    protected $temperature = 0.7;

    // Rate limiting
    protected $rateLimit = 60; // requests per hour

    protected $rateLimitWindow = 3600; // 1 hour

    protected $cacheEnabled = true;

    protected $cacheTtl = 3600; // 1 hour

    public function __construct()
    {
        $this->provider = config('ai.provider', 'ollama');
        $this->model = config('ai.model', 'mistral');
        $this->baseUrl = config('ai.base_url', 'http://localhost:11434');
        $this->apiKey = config('ai.api_key');
        $this->rateLimit = config('ai.rate_limit.requests_per_hour', 1000);
        $this->cacheEnabled = config('ai.caching.enabled', true);
        $this->cacheTtl = config('ai.caching.ttl', 3600);

        $this->client = new Client([
            'timeout' => 30,
            'connect_timeout' => 10,
        ]);
    }

    /**
     * Check if user is within rate limits
     */
    protected function checkRateLimit(): array
    {
        $userId = Auth::id() ?? 'guest';
        $cacheKey = "ai_rate_limit_{$userId}";

        $count = Cache::get($cacheKey, 0);

        if ($count >= $this->rateLimit) {
            return [
                'allowed' => false,
                'error' => "Rate limit exceeded. Max {$this->rateLimit} requests per hour.",
                'reset_at' => Cache::get($cacheKey.'_reset'),
            ];
        }

        // Increment counter
        Cache::put($cacheKey, $count + 1, $this->rateLimitWindow);

        if ($count === 0) {
            // Set reset time on first request
            Cache::put($cacheKey.'_reset', now()->addHour(), $this->rateLimitWindow);
        }

        return ['allowed' => true];
    }

    /**
     * Get cache key for a prompt
     */
    protected function getCacheKey(string $prompt, array $options = []): string
    {
        $hash = md5($prompt.json_encode($options));

        return "ai_response_{$this->provider}_{$hash}";
    }

    /**
     * Generate AI response for a prompt
     */
    public function generateResponse(string $prompt, array $options = []): array
    {
        // Demo mode - return mock responses
        if (config('ai.demo_mode', false)) {
            return $this->generateDemoResponse($prompt, $options);
        }

        // Check rate limiting
        $rateCheck = $this->checkRateLimit();
        if (! $rateCheck['allowed']) {
            return $this->errorResponse($rateCheck['error']);
        }

        // Check cache
        if ($this->cacheEnabled) {
            $cached = Cache::get($this->getCacheKey($prompt, $options));
            if ($cached) {
                Log::info('AI response from cache', ['provider' => $this->provider]);

                return array_merge($cached, ['cached' => true]);
            }
        }

        try {
            $method = 'generate'.ucfirst($this->provider);

            if (! method_exists($this, $method)) {
                return $this->errorResponse("Provider '{$this->provider}' not supported");
            }

            $result = $this->$method($prompt, $options);

            // Cache successful responses
            if ($result['success'] && $this->cacheEnabled) {
                Cache::put($this->getCacheKey($prompt, $options), $result, $this->cacheTtl);
            }

            return $result;
        } catch (\Exception $e) {
            Log::error('AI Service Error', [
                'provider' => $this->provider,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->errorResponse($e->getMessage());
        }
    }

    /**
     * Generate demo/mock response for testing
     */
    protected function generateDemoResponse(string $prompt, array $options = []): array
    {
        // Detect prompt type from keywords
        $prompt_lower = strtolower($prompt);

        if (str_contains($prompt_lower, 'formula') || str_contains($prompt_lower, 'sum') ||
            str_contains($prompt_lower, 'average') || str_contains($prompt_lower, 'calculate')) {
            $response = '=SUM(A1:A10) will sum all values in cells A1 through A10. Formula: =SUM(A1:A10)';
        } elseif (str_contains($prompt_lower, 'chart') || str_contains($prompt_lower, 'graph') ||
                  str_contains($prompt_lower, 'visual')) {
            $response = 'A bar chart would work well for this data. Consider using Chart type: bar';
        } elseif (str_contains($prompt_lower, 'clean') || str_contains($prompt_lower, 'duplicate') ||
                  str_contains($prompt_lower, 'missing')) {
            $response = 'Data analysis found: 2 duplicate entries, 0 missing values. Suggestion: Remove duplicates from rows 5 and 12.';
        } elseif (str_contains($prompt_lower, 'analyze') || str_contains($prompt_lower, 'insight') ||
                  str_contains($prompt_lower, 'trend')) {
            $response = config('ai.demo_responses.analysis',
                'The data shows a consistent upward trend with an average increase of 5% month-over-month.');
        } else {
            $response = config('ai.demo_responses.insight',
                'Based on your query, I recommend reviewing the data in columns A and B for patterns.');
        }

        return [
            'success' => true,
            'response' => $response,
            'provider' => 'demo',
            'model' => 'demo',
            'demo' => true,
        ];
    }

    /**
     * Generate response using Ollama (local LLM)
     */
    protected function generateOllama(string $prompt, array $options = []): array
    {
        $response = $this->client->post($this->baseUrl.'/api/generate', [
            'json' => [
                'model' => $options['model'] ?? $this->model,
                'prompt' => $prompt,
                'stream' => false,
                'keep_alive' => '5m',
                'options' => [
                    'num_predict' => $options['max_tokens'] ?? $this->maxTokens,
                    'temperature' => $options['temperature'] ?? $this->temperature,
                ],
            ],
        ]);

        $data = json_decode($response->getBody(), true);

        if (! isset($data['response'])) {
            return $this->errorResponse('Invalid response from Ollama');
        }

        return [
            'success' => true,
            'response' => $data['response'],
            'provider' => 'ollama',
            'model' => $data['model'] ?? $this->model,
            'total_duration' => $data['total_duration'] ?? 0,
        ];
    }

    /**
     * Generate response using OpenAI
     */
    protected function generateOpenai(string $prompt, array $options = []): array
    {
        if (! $this->apiKey) {
            return $this->errorResponse('OpenAI API key not configured');
        }

        $response = $this->client->post('https://api.openai.com/v1/chat/completions', [
            'headers' => [
                'Authorization' => "Bearer {$this->apiKey}",
                'Content-Type' => 'application/json',
            ],
            'json' => [
                'model' => $options['model'] ?? 'gpt-3.5-turbo',
                'messages' => [
                    ['role' => 'system', 'content' => 'You are a helpful spreadsheet assistant.'],
                    ['role' => 'user', 'content' => $prompt],
                ],
                'max_tokens' => $options['max_tokens'] ?? $this->maxTokens,
                'temperature' => $options['temperature'] ?? $this->temperature,
            ],
        ]);

        $data = json_decode($response->getBody(), true);

        if (! isset($data['choices'][0]['message']['content'])) {
            return $this->errorResponse('Invalid response from OpenAI');
        }

        return [
            'success' => true,
            'response' => $data['choices'][0]['message']['content'],
            'provider' => 'openai',
            'model' => $data['model'],
            'usage' => $data['usage'] ?? [],
        ];
    }

    /**
     * Generate response using Anthropic Claude
     */
    protected function generateAnthropic(string $prompt, array $options = []): array
    {
        if (! $this->apiKey) {
            return $this->errorResponse('Anthropic API key not configured');
        }

        $response = $this->client->post('https://api.anthropic.com/v1/messages', [
            'headers' => [
                'x-api-key' => $this->apiKey,
                'anthropic-version' => '2023-06-01',
                'content-type' => 'application/json',
            ],
            'json' => [
                'model' => $options['model'] ?? 'claude-3-sonnet-20240229',
                'max_tokens' => $options['max_tokens'] ?? $this->maxTokens,
                'messages' => [
                    ['role' => 'user', 'content' => $prompt],
                ],
                'system' => 'You are a helpful spreadsheet assistant.',
            ],
        ]);

        $data = json_decode($response->getBody(), true);

        if (! isset($data['content'][0]['text'])) {
            return $this->errorResponse('Invalid response from Anthropic');
        }

        return [
            'success' => true,
            'response' => $data['content'][0]['text'],
            'provider' => 'anthropic',
            'model' => $data['model'],
            'usage' => $data['usage'] ?? [],
        ];
    }

    /**
     * Generate formula from description
     */
    public function generateFormula(string $description, array $context = []): array
    {
        $prompt = $this->buildFormulaPrompt($description, $context);
        $response = $this->generateResponse($prompt, ['max_tokens' => 500]);

        if (! $response['success']) {
            return $response;
        }

        // Extract formula from response
        $formula = $this->extractFormula($response['response']);

        return array_merge($response, [
            'formula' => $formula,
            'type' => 'formula_generation',
        ]);
    }

    /**
     * Analyze data and provide insights
     */
    public function analyzeData(array $data, string $question = ''): array
    {
        $prompt = $this->buildAnalysisPrompt($data, $question);
        $response = $this->generateResponse($prompt);

        if (! $response['success']) {
            return $response;
        }

        return array_merge($response, [
            'type' => 'data_analysis',
        ]);
    }

    /**
     * Generate chart recommendation
     */
    public function recommendChart(array $data): array
    {
        $prompt = $this->buildChartRecommendationPrompt($data);
        $response = $this->generateResponse($prompt, ['max_tokens' => 300]);

        if (! $response['success']) {
            return $response;
        }

        $chartType = $this->extractChartType($response['response']);

        return array_merge($response, [
            'chart_type' => $chartType,
            'type' => 'chart_recommendation',
        ]);
    }

    /**
     * Clean data - identify issues and suggest fixes
     */
    public function cleanData(array $data): array
    {
        $prompt = $this->buildDataCleaningPrompt($data);
        $response = $this->generateResponse($prompt);

        if (! $response['success']) {
            return $response;
        }

        return array_merge($response, [
            'type' => 'data_cleaning',
        ]);
    }

    /**
     * Process natural language query
     */
    public function processNaturalLanguageQuery(string $query, array $spreadsheetContext = []): array
    {
        $prompt = $this->buildNLQueryPrompt($query, $spreadsheetContext);
        $response = $this->generateResponse($prompt);

        if (! $response['success']) {
            return $response;
        }

        $action = $this->parseQueryAction($response['response']);

        return array_merge($response, [
            'query' => $query,
            'action' => $action,
            'type' => 'natural_language_query',
        ]);
    }

    /**
     * Build formula generation prompt
     */
    protected function buildFormulaPrompt(string $description, array $context = []): string
    {
        $cellRef = $context['cell'] ?? 'A1';
        $availableCells = implode(', ', $context['available_cells'] ?? ['A1', 'A2', 'B1', 'B2', 'C1', 'C2']);

        return <<<PROMPT
You are a spreadsheet formula expert. Generate a single spreadsheet formula based on the user's description.

User wants: $description

Available cells: $availableCells
Target cell: $cellRef

Requirements:
1. Return ONLY the formula (starting with =)
2. Use available cells as references
3. Use standard spreadsheet functions (SUM, AVERAGE, IF, etc.)
4. Make the formula as simple and efficient as possible
5. Do not include explanations

Formula:
PROMPT;
    }

    /**
     * Build data analysis prompt
     */
    protected function buildAnalysisPrompt(array $data, string $question = ''): string
    {
        $dataStr = json_encode($data, JSON_PRETTY_PRINT);
        $qaSection = $question ? "Specific question: $question\n\n" : '';

        return <<<PROMPT
You are a data analysis expert. Analyze the following spreadsheet data and provide insights.

Data:
$dataStr

${qaSection}
Provide:
1. Summary of the data
2. Key insights and patterns
3. Anomalies or outliers
4. Recommendations for further analysis

Analysis:
PROMPT;
    }

    /**
     * Build chart recommendation prompt
     */
    protected function buildChartRecommendationPrompt(array $data): string
    {
        $dataStr = json_encode($data, JSON_PRETTY_PRINT);

        return <<<PROMPT
You are a data visualization expert. Based on the data, recommend the best chart type.

Data:
$dataStr

Recommend one chart type from: bar, line, pie, scatter, area, column

Return format: chart_type: [type], reason: [brief reason]

Recommendation:
PROMPT;
    }

    /**
     * Build data cleaning prompt
     */
    protected function buildDataCleaningPrompt(array $data): string
    {
        $dataStr = json_encode($data, JSON_PRETTY_PRINT);

        return <<<PROMPT
You are a data quality expert. Analyze this data for quality issues.

Data:
$dataStr

Identify:
1. Missing or blank values
2. Duplicates
3. Outliers or anomalies
4. Data type inconsistencies
5. Formatting issues

Suggest fixes for each issue.

Analysis:
PROMPT;
    }

    /**
     * Build natural language query prompt
     */
    protected function buildNLQueryPrompt(string $query, array $context = []): string
    {
        $columns = implode(', ', $context['columns'] ?? ['A', 'B', 'C', 'D', 'E']);
        $dataPreview = json_encode($context['preview'] ?? [], JSON_PRETTY_PRINT);

        return <<<PROMPT
You are a spreadsheet assistant. Convert this natural language query into spreadsheet actions.

User query: $query

Available columns: $columns
Data preview:
$dataPreview

Respond with:
1. action_type: (filter|sort|formula|highlight|summarize)
2. action_description: (what specifically to do)
3. formula: (if applicable)
4. parameters: (any additional parameters)

Response:
PROMPT;
    }

    /**
     * Extract formula from AI response
     */
    protected function extractFormula(string $response): ?string
    {
        // Look for formula pattern: =...
        if (preg_match('/^=(.+)$/m', trim($response), $matches)) {
            return $matches[0];
        }

        // Alternative: look for formula in quotes
        if (preg_match('/["\']?(=.+?)["\']?(?:\s|$)/', $response, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Extract chart type from response
     */
    protected function extractChartType(string $response): string
    {
        $types = ['bar', 'line', 'pie', 'scatter', 'area', 'column'];

        foreach ($types as $type) {
            if (stripos($response, $type) !== false) {
                return $type;
            }
        }

        return 'bar'; // Default
    }

    /**
     * Parse query action from response
     */
    protected function parseQueryAction(string $response): array
    {
        $actions = [];

        // Try to parse structured response
        $lines = explode("\n", $response);
        foreach ($lines as $line) {
            if (stripos($line, 'action_type') !== false) {
                preg_match('/:\s*(.+)/', $line, $matches);
                $actions['type'] = trim($matches[1] ?? 'unknown');
            } elseif (stripos($line, 'formula') !== false) {
                preg_match('/:\s*(.+)/', $line, $matches);
                $actions['formula'] = trim($matches[1] ?? null);
            }
        }

        return $actions;
    }

    /**
     * Error response helper
     */
    protected function errorResponse(string $message): array
    {
        return [
            'success' => false,
            'response' => null,
            'error' => $message,
            'provider' => $this->provider,
        ];
    }

    /**
     * Check if AI service is available
     */
    public function isAvailable(): bool
    {
        try {
            if ($this->provider === 'ollama') {
                $response = $this->client->get($this->baseUrl.'/api/tags', [
                    'timeout' => 5,
                ]);

                return $response->getStatusCode() === 200;
            }

            if ($this->provider === 'openai' || $this->provider === 'anthropic') {
                return ! empty($this->apiKey);
            }

            return false;
        } catch (\Exception $e) {
            Log::warning('AI Service availability check failed', ['error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * Get available models
     */
    public function getAvailableModels(): array
    {
        if ($this->provider === 'ollama') {
            try {
                $response = $this->client->get($this->baseUrl.'/api/tags', ['timeout' => 5]);
                $data = json_decode($response->getBody(), true);

                return array_map(fn ($model) => $model['name'], $data['models'] ?? []);
            } catch (\Exception $e) {
                Log::error('Failed to fetch Ollama models', ['error' => $e->getMessage()]);

                return [];
            }
        }

        return [];
    }

    /**
     * Detect quality issues from a simple value list.
     */
    public function detectDataCleaningIssues(array $values): array
    {
        $normalized = array_map(static function ($value) {
            if ($value === null) {
                return null;
            }

            return is_string($value) ? trim($value) : $value;
        }, $values);

        $nonNull = array_values(array_filter($normalized, static fn ($v) => $v !== null && $v !== ''));
        $duplicates = count($nonNull) - count(array_unique(array_map(static fn ($v) => (string) $v, $nonNull)));
        $missing = count($values) - count($nonNull);

        $numericLike = 0;
        $textLike = 0;
        foreach ($nonNull as $value) {
            if (is_numeric($value)) {
                $numericLike++;
            } else {
                $textLike++;
            }
        }

        $issues = [];
        if ($duplicates > 0) {
            $issues[] = [
                'issue' => 'Duplicate values detected',
                'severity' => 'medium',
                'detail' => "{$duplicates} duplicate entries found",
            ];
        }

        if ($missing > 0) {
            $issues[] = [
                'issue' => 'Missing values detected',
                'severity' => 'medium',
                'detail' => "{$missing} blank cells found",
            ];
        }

        if ($numericLike > 0 && $textLike > 0) {
            $issues[] = [
                'issue' => 'Mixed data types',
                'severity' => 'low',
                'detail' => 'Range contains both numeric and text-like values',
            ];
        }

        return [
            'success' => true,
            'issues' => $issues,
            'stats' => [
                'total' => count($values),
                'non_empty' => count($nonNull),
                'duplicates' => $duplicates,
                'missing' => $missing,
                'numeric_like' => $numericLike,
                'text_like' => $textLike,
            ],
            'type' => 'data_cleaning_local',
        ];
    }

    /**
     * Normalize free-form text for spreadsheet cleaning operations.
     */
    public function normalizeCellTextForCleaning(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        return preg_replace('/\s+/', ' ', trim($value));
    }

    /**
     * Lightweight sentiment analysis over text values.
     */
    public function analyzeSentiment(array $texts): array
    {
        $positiveWords = ['good', 'great', 'excellent', 'happy', 'love', 'win', 'success', 'positive', 'amazing', 'best'];
        $negativeWords = ['bad', 'poor', 'terrible', 'sad', 'hate', 'loss', 'fail', 'negative', 'awful', 'worst'];

        $score = 0;
        $processed = 0;

        foreach ($texts as $text) {
            if (! is_string($text) || trim($text) === '') {
                continue;
            }

            $processed++;
            $tokens = preg_split('/\W+/', Str::lower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            foreach ($tokens as $token) {
                if (in_array($token, $positiveWords, true)) {
                    $score++;
                }
                if (in_array($token, $negativeWords, true)) {
                    $score--;
                }
            }
        }

        $label = 'neutral';
        if ($score > 1) {
            $label = 'positive';
        } elseif ($score < -1) {
            $label = 'negative';
        }

        return [
            'success' => true,
            'type' => 'sentiment_analysis',
            'label' => $label,
            'score' => $score,
            'samples' => $processed,
        ];
    }

    /**
     * OCR extraction from an uploaded image path.
     */
    public function extractTextFromImage(string $imagePath): array
    {
        if (! is_file($imagePath)) {
            return $this->errorResponse('Image file not found for OCR');
        }

        if (trim((string) shell_exec('command -v tesseract')) === '') {
            return [
                'success' => false,
                'response' => null,
                'error' => 'Tesseract OCR is not installed on the server.',
                'provider' => 'local-ocr',
            ];
        }

        $cmd = 'tesseract '.escapeshellarg($imagePath).' stdout 2>/dev/null';
        $output = shell_exec($cmd);

        if ($output === null) {
            return $this->errorResponse('OCR extraction failed');
        }

        return [
            'success' => true,
            'type' => 'image_ocr',
            'provider' => 'local-ocr',
            'response' => trim($output),
        ];
    }
}
