# Instrucciones para modificar Laravel (tramaeducativa)

## Paso 1: Actualizar config/services.php

Agregar la configuración de `local_ai` al archivo `config/services.php`:

```php
'local_ai' => [
    'enabled' => env('LOCAL_AI_ENABLED', false),
    'api_key' => env('LOCAL_AI_API_KEY', ''),
    'base_url' => env('LOCAL_AI_BASE_URL', 'http://localhost:8000/v1'),
    'chat_model' => env('LOCAL_AI_CHAT_MODEL', 'qwen2.5:7b-instruct'),
    'embedding_model' => env('LOCAL_AI_EMBEDDING_MODEL', 'nomic-embed-text'),
],
```

## Paso 2: Reemplazar EmbeddingsService.php

Reemplazar el archivo:
- `app/Services/EmbeddingsService.php`

Con el nuevo que está en:
- `laravel-changes/EmbeddingsService.php`

## Paso 3: Actualizar .env (en el servidor remoto via FileZilla)

Agregar estas variables:

```env
LOCAL_AI_ENABLED=true
LOCAL_AI_BASE_URL=http://TU-IP-VPS:8000/v1
LOCAL_AI_API_KEY=tu-api-key-generada
LOCAL_AI_CHAT_MODEL=qwen2.5:7b-instruct
LOCAL_AI_EMBEDDING_MODEL=nomic-embed-text
```

## Paso 4: Regenerar embeddings

IMPORTANTE: Los embeddings de OpenAI (1536 dims) no son compatibles con los de nomic-embed-text (768 dims).

Debes regenerar todos los embeddings existentes:

```bash
# En el servidor Laravel
php artisan articles:generate-embeddings --force
```

O desde el navegador:
```
https://tu-sitio.com/generate-embeddings.php
```

## Paso 5: Limpiar caché

```bash
php artisan cache:clear
php artisan config:clear
```

## Notas importantes

1. **Demora**: Las respuestas del modelo local tardan 10-30 segundos en CPU
2. **Timeouts**: El código ya tiene timeouts ajustados (120s para local)
3. **Dimensiones**: Los nuevos embeddings tienen 768 dimensiones vs 1536 de OpenAI
4. **Fallback**: Si `LOCAL_AI_ENABLED=false`, usa OpenAI automáticamente
