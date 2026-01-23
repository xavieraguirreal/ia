#!/bin/bash

# ============================================================
# Script para probar la API local
# ============================================================

API_URL="${1:-http://localhost:8000}"
API_KEY="${2:-}"

echo "Probando API en: $API_URL"
echo "========================================"

# Headers con API key si se proporciona
if [ -n "$API_KEY" ]; then
    AUTH_HEADER="Authorization: Bearer $API_KEY"
else
    AUTH_HEADER="X-No-Auth: true"
fi

# 1. Test de conexión
echo -e "\n[1] Test de conexión básica..."
curl -s "$API_URL/" | python3 -m json.tool

# 2. Test de conexión con Ollama
echo -e "\n[2] Test de conexión con Ollama y modelos..."
curl -s "$API_URL/test" | python3 -m json.tool

# 3. Test de embeddings
echo -e "\n[3] Test de embeddings..."
curl -s -X POST "$API_URL/v1/embeddings" \
    -H "Content-Type: application/json" \
    -H "$AUTH_HEADER" \
    -d '{
        "model": "nomic-embed-text",
        "input": "Hola, este es un texto de prueba para generar embeddings."
    }' | python3 -c "
import sys, json
data = json.load(sys.stdin)
if 'data' in data and len(data['data']) > 0:
    emb = data['data'][0]['embedding']
    print(f'Embedding generado correctamente')
    print(f'Dimensiones: {len(emb)}')
    print(f'Primeros 5 valores: {emb[:5]}')
else:
    print(f'Error: {data}')
"

# 4. Test de chat
echo -e "\n[4] Test de chat completions..."
echo "Enviando mensaje de prueba (puede tardar 10-30 segundos)..."
curl -s -X POST "$API_URL/v1/chat/completions" \
    -H "Content-Type: application/json" \
    -H "$AUTH_HEADER" \
    -d '{
        "model": "qwen2.5:7b-instruct",
        "messages": [
            {"role": "system", "content": "Eres un asistente útil. Responde en español de forma concisa."},
            {"role": "user", "content": "Genera 3 palabras clave para un artículo sobre educación digital."}
        ],
        "max_tokens": 100,
        "temperature": 0.3
    }' | python3 -c "
import sys, json
data = json.load(sys.stdin)
if 'choices' in data and len(data['choices']) > 0:
    content = data['choices'][0]['message']['content']
    print(f'Respuesta del modelo:')
    print(f'{content}')
    print(f'\nTokens usados: {data.get(\"usage\", {})}')
else:
    print(f'Error: {data}')
"

echo -e "\n========================================"
echo "Tests completados"
