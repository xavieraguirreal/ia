<?php

/**
 * AGREGAR ESTO A config/services.php
 *
 * Este archivo muestra la configuración que debes agregar
 * al archivo config/services.php de tu proyecto Laravel
 */

return [
    // ... otras configuraciones existentes ...

    // ============================================
    // CONFIGURACIÓN OPENAI (existente)
    // ============================================
    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
        'embedding_model' => env('OPENAI_EMBEDDING_MODEL', 'text-embedding-3-small'),
        'chat_model' => env('OPENAI_CHAT_MODEL', 'gpt-4o-mini'),
    ],

    // ============================================
    // CONFIGURACIÓN API LOCAL (Ollama/Qwen) - NUEVA
    // ============================================
    'local_ai' => [
        'enabled' => env('LOCAL_AI_ENABLED', false),
        'api_key' => env('LOCAL_AI_API_KEY', ''),
        'base_url' => env('LOCAL_AI_BASE_URL', 'http://localhost:8000/v1'),
        'chat_model' => env('LOCAL_AI_CHAT_MODEL', 'qwen2.5:7b-instruct'),
        'embedding_model' => env('LOCAL_AI_EMBEDDING_MODEL', 'nomic-embed-text'),
    ],

];
