# Local AI API - Reemplazo de OpenAI con Qwen

API local compatible con OpenAI usando Ollama + Qwen para reemplazar:
- `gpt-4o-mini` → `qwen2.5:7b-instruct`
- `text-embedding-3-small` → `nomic-embed-text`

## Requisitos del VPS

- **RAM mínima**: 8 GB (recomendado 12-16 GB)
- **CPU**: 4+ vCPUs
- **Disco**: ~10 GB libres para modelos
- **OS**: AlmaLinux 8, CentOS 8, Rocky Linux 8, o similar

## Instalación Rápida

```bash
# 1. Clonar/subir el proyecto al VPS
cd /tmp
git clone <tu-repo> local-ai
cd local-ai

# 2. Ejecutar instalación
sudo chmod +x scripts/install.sh
sudo ./scripts/install.sh
```

La instalación:
1. Instala Ollama
2. Descarga los modelos (~5 GB total)
3. Configura la API Python con FastAPI
4. Crea un servicio systemd
5. Abre el puerto 8000

## Instalación Manual

### 1. Instalar Ollama

```bash
curl -fsSL https://ollama.com/install.sh | sh
systemctl enable ollama
systemctl start ollama
```

### 2. Descargar modelos

```bash
# Modelo de chat (~4.4 GB)
ollama pull qwen2.5:7b-instruct

# Modelo de embeddings (~274 MB)
ollama pull nomic-embed-text
```

### 3. Configurar API Python

```bash
# Crear directorio
sudo mkdir -p /opt/local-ai-api
sudo cp -r api/* /opt/local-ai-api/
cd /opt/local-ai-api

# Entorno virtual
python3 -m venv venv
source venv/bin/activate
pip install -r requirements.txt

# Configurar
cp .env.example .env
nano .env  # Editar configuración
```

### 4. Ejecutar

```bash
# Desarrollo
source venv/bin/activate
uvicorn main:app --host 0.0.0.0 --port 8000

# Producción (ver scripts/install.sh para servicio systemd)
```

## Configuración Laravel

### Opción A: Variables de entorno separadas (recomendado)

En tu `.env` de Laravel:

```env
# Mantener OpenAI como backup
OPENAI_API_KEY=sk-xxx
OPENAI_EMBEDDING_MODEL=text-embedding-3-small

# Nueva API local
LOCAL_AI_ENABLED=true
LOCAL_AI_BASE_URL=http://TU-IP-VPS:8000/v1
LOCAL_AI_API_KEY=tu-api-key-generada
LOCAL_AI_CHAT_MODEL=qwen2.5:7b-instruct
LOCAL_AI_EMBEDDING_MODEL=nomic-embed-text
```

### Opción B: Reemplazo directo

```env
OPENAI_API_KEY=tu-api-key-generada
OPENAI_BASE_URL=http://TU-IP-VPS:8000/v1
OPENAI_EMBEDDING_MODEL=nomic-embed-text
```

## Endpoints API

| Método | Endpoint | Descripción |
|--------|----------|-------------|
| GET | `/` | Health check |
| GET | `/test` | Verifica conexión con Ollama |
| GET | `/v1/models` | Lista modelos disponibles |
| POST | `/v1/chat/completions` | Genera texto (compatible OpenAI) |
| POST | `/v1/embeddings` | Genera embeddings (compatible OpenAI) |

### Ejemplo: Chat

```bash
curl -X POST http://localhost:8000/v1/chat/completions \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer tu-api-key" \
  -d '{
    "model": "qwen2.5:7b-instruct",
    "messages": [
      {"role": "system", "content": "Eres un asistente útil."},
      {"role": "user", "content": "Hola, genera 3 keywords para educación"}
    ],
    "max_tokens": 100,
    "temperature": 0.3
  }'
```

### Ejemplo: Embeddings

```bash
curl -X POST http://localhost:8000/v1/embeddings \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer tu-api-key" \
  -d '{
    "model": "nomic-embed-text",
    "input": "Texto para generar embedding"
  }'
```

## Diferencias con OpenAI

| Aspecto | OpenAI | Local |
|---------|--------|-------|
| Dimensiones embedding | 1536 | 768 |
| Velocidad chat | ~1-2 seg | ~10-30 seg |
| Velocidad embedding | ~0.5 seg | ~1-2 seg |
| Costo | Por token | Gratis |
| Censura | Sí | No (Qwen uncensored) |

**IMPORTANTE**: Los embeddings tienen dimensiones diferentes. Debes regenerar todos los embeddings de artículos existentes después de migrar.

## Comandos Útiles

```bash
# Ver logs de la API
journalctl -u local-ai-api -f

# Reiniciar API
systemctl restart local-ai-api

# Ver estado
systemctl status local-ai-api

# Ver modelos instalados en Ollama
ollama list

# Probar modelo directamente
ollama run qwen2.5:7b-instruct "Hola, cómo estás?"

# Monitorear recursos
htop
```

## Modelos Alternativos

Si necesitas modelos más pequeños (menos RAM) o más grandes (mejor calidad):

### Chat
- `qwen2.5:3b-instruct` - 3B params, ~2GB, más rápido
- `qwen2.5:7b-instruct` - 7B params, ~4.4GB, balance
- `qwen2.5:14b-instruct` - 14B params, ~9GB, mejor calidad
- `llama3.2:3b-instruct` - 3B params, alternativa
- `mistral:7b-instruct` - 7B params, muy bueno en español

### Embeddings
- `nomic-embed-text` - 768 dims, recomendado
- `mxbai-embed-large` - 1024 dims, mejor calidad
- `all-minilm` - 384 dims, más rápido

Para cambiar, edita `/opt/local-ai-api/.env` y reinicia el servicio.

## Troubleshooting

### Error: "Cannot connect to Ollama"
```bash
systemctl status ollama
systemctl restart ollama
```

### Error: "Model not found"
```bash
ollama list
ollama pull qwen2.5:7b-instruct
```

### Respuestas muy lentas
- Aumentar RAM del VPS
- Usar modelo más pequeño (qwen2.5:3b)
- Verificar que no hay otros procesos consumiendo recursos

### Out of memory
- Usar modelo más pequeño
- Aumentar swap: `sudo fallocate -l 4G /swapfile && sudo mkswap /swapfile && sudo swapon /swapfile`
