<?php

return [
    /*
    |--------------------------------------------------------------------------
    | AI Provider Configuration
    |--------------------------------------------------------------------------
    |
    | Configure which AI provider to use and its settings
    | Supported: ollama, openai, anthropic, mock (mock needs no live
    | provider -- same deterministic responses as demo_mode below, useful
    | for local dev/CI; see AiService::generateMock()).
    |
    */

    'provider' => env('AI_PROVIDER', 'ollama'),

    /*
    |--------------------------------------------------------------------------
    | Provider Base URLs
    |--------------------------------------------------------------------------
    */

    'providers' => [
        'ollama' => [
            'base_url' => env('OLLAMA_BASE_URL', 'http://localhost:11434'),
            'model' => env('OLLAMA_MODEL', 'mistral'),
        ],
        'openai' => [
            'api_key' => env('OPENAI_API_KEY'),
            'model' => env('OPENAI_MODEL', 'gpt-3.5-turbo'),
        ],
        'anthropic' => [
            'api_key' => env('ANTHROPIC_API_KEY'),
            'model' => env('ANTHROPIC_MODEL', 'claude-3-5-sonnet-latest'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Model
    |--------------------------------------------------------------------------
    */

    'model' => env('AI_MODEL', 'mistral'),

    /*
    |--------------------------------------------------------------------------
    | Base URL (for local LLMs like Ollama)
    |--------------------------------------------------------------------------
    */

    'base_url' => env('AI_BASE_URL', 'http://localhost:11434'),

    /*
    |--------------------------------------------------------------------------
    | API Key
    |--------------------------------------------------------------------------
    */

    'api_key' => env('AI_API_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Generation Parameters
    |--------------------------------------------------------------------------
    */

    'max_tokens' => env('AI_MAX_TOKENS', 2000),
    'temperature' => env('AI_TEMPERATURE', 0.7),
    'top_p' => env('AI_TOP_P', 0.9),
    'top_k' => env('AI_TOP_K', 40),

    /*
    |--------------------------------------------------------------------------
    | Rate Limiting
    |--------------------------------------------------------------------------
    */

    'rate_limit' => [
        'enabled' => env('AI_RATE_LIMIT_ENABLED', true),
        'requests_per_minute' => env('AI_RATE_LIMIT_RPM', 60),
        'requests_per_hour' => env('AI_RATE_LIMIT_RPH', 1000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Caching
    |--------------------------------------------------------------------------
    */

    'cache' => [
        'enabled' => env('AI_CACHE_ENABLED', true),
        'ttl' => env('AI_CACHE_TTL', 3600), // 1 hour
    ],

    /*
    |--------------------------------------------------------------------------
    | Features
    |--------------------------------------------------------------------------
    */

    'features' => [
        'formula_generation' => env('AI_FORMULA_GENERATION', true),
        'data_analysis' => env('AI_DATA_ANALYSIS', true),
        'chart_recommendation' => env('AI_CHART_RECOMMENDATION', true),
        'data_cleaning' => env('AI_DATA_CLEANING', true),
        'natural_language_queries' => env('AI_NLQ', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Prompt Templates
    |--------------------------------------------------------------------------
    */

    'prompts' => [
        'system' => 'You are a helpful spreadsheet assistant. Provide clear, concise responses.',
        'formula_expert' => 'You are a spreadsheet formula expert.',
        'data_analyst' => 'You are a data analysis expert.',
        'data_cleaner' => 'You are a data quality expert.',
    ],

    /*
    |--------------------------------------------------------------------------
    | Logging
    |--------------------------------------------------------------------------
    */

    'logging' => [
        'enabled' => env('AI_LOGGING', true),
        'log_requests' => env('AI_LOG_REQUESTS', false),
        'log_responses' => env('AI_LOG_RESPONSES', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Demo/Development Mode
    |--------------------------------------------------------------------------
    |
    | When enabled, uses mock responses for testing without AI provider
    |
    */

    'demo_mode' => env('AI_DEMO_MODE', false),

    'demo_responses' => [
        'formula' => '=SUM(A1:A10)',
        'analysis' => 'The data shows a consistent upward trend with an average increase of 5% month-over-month.',
        'chart' => 'bar',
        'insight' => 'This dataset appears to have a strong correlation between columns A and B.',
    ],
];
