"""
API compatible con OpenAI para usar modelos locales via Ollama.
Reemplaza:
- gpt-4o-mini -> qwen2.5:7b-instruct (o el modelo configurado)
- text-embedding-3-small -> nomic-embed-text (o el modelo configurado)
"""

import os
import time
import hashlib
from typing import List, Optional, Union
from fastapi import FastAPI, HTTPException, Header
from fastapi.middleware.cors import CORSMiddleware
from pydantic import BaseModel, Field
import httpx
from dotenv import load_dotenv

load_dotenv()

# Configuración
OLLAMA_HOST = os.getenv("OLLAMA_HOST", "http://localhost:11434")
CHAT_MODEL = os.getenv("CHAT_MODEL", "qwen2.5:7b-instruct")
EMBEDDING_MODEL = os.getenv("EMBEDDING_MODEL", "nomic-embed-text")
API_KEY = os.getenv("API_KEY", "")  # Opcional, para autenticación básica
DEBUG = os.getenv("DEBUG", "false").lower() == "true"

app = FastAPI(
    title="Local AI API",
    description="API compatible con OpenAI usando Ollama",
    version="1.0.0"
)

# CORS - permitir desde cualquier origen (ajustar en producción)
app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)


# ============ MODELOS PYDANTIC ============

class ChatMessage(BaseModel):
    role: str
    content: str


class ChatCompletionRequest(BaseModel):
    model: str = CHAT_MODEL
    messages: List[ChatMessage]
    max_tokens: Optional[int] = 2048
    temperature: Optional[float] = 0.7
    top_p: Optional[float] = 1.0
    stream: Optional[bool] = False
    stop: Optional[Union[str, List[str]]] = None


class EmbeddingRequest(BaseModel):
    model: str = EMBEDDING_MODEL
    input: Union[str, List[str]]
    encoding_format: Optional[str] = "float"


# ============ UTILIDADES ============

def verify_api_key(authorization: Optional[str] = None) -> bool:
    """Verifica la API key si está configurada."""
    if not API_KEY:
        return True
    if not authorization:
        return False
    if authorization.startswith("Bearer "):
        token = authorization[7:]
        return token == API_KEY
    return False


def generate_id(prefix: str = "chatcmpl") -> str:
    """Genera un ID único estilo OpenAI."""
    timestamp = str(time.time()).encode()
    hash_obj = hashlib.md5(timestamp)
    return f"{prefix}-{hash_obj.hexdigest()[:24]}"


# ============ ENDPOINTS ============

@app.get("/")
async def root():
    """Health check."""
    return {
        "status": "ok",
        "message": "Local AI API running",
        "models": {
            "chat": CHAT_MODEL,
            "embedding": EMBEDDING_MODEL
        }
    }


@app.get("/v1/models")
async def list_models():
    """Lista los modelos disponibles (formato OpenAI)."""
    return {
        "object": "list",
        "data": [
            {
                "id": CHAT_MODEL,
                "object": "model",
                "created": int(time.time()),
                "owned_by": "local"
            },
            {
                "id": EMBEDDING_MODEL,
                "object": "model",
                "created": int(time.time()),
                "owned_by": "local"
            }
        ]
    }


@app.post("/v1/chat/completions")
async def chat_completions(
    request: ChatCompletionRequest,
    authorization: Optional[str] = Header(None)
):
    """
    Endpoint de chat completions compatible con OpenAI.
    Usa Ollama internamente con el modelo configurado.
    """
    if not verify_api_key(authorization):
        raise HTTPException(status_code=401, detail="Invalid API key")

    # Convertir mensajes al formato de Ollama
    messages = [{"role": m.role, "content": m.content} for m in request.messages]

    # Usar el modelo configurado, ignorando el modelo del request
    model_to_use = CHAT_MODEL

    if DEBUG:
        print(f"[DEBUG] Chat request - Model: {model_to_use}")
        print(f"[DEBUG] Messages: {messages}")

    try:
        async with httpx.AsyncClient(timeout=120.0) as client:
            response = await client.post(
                f"{OLLAMA_HOST}/api/chat",
                json={
                    "model": model_to_use,
                    "messages": messages,
                    "stream": False,
                    "options": {
                        "temperature": request.temperature,
                        "top_p": request.top_p,
                        "num_predict": request.max_tokens,
                    }
                }
            )

            if response.status_code != 200:
                raise HTTPException(
                    status_code=response.status_code,
                    detail=f"Ollama error: {response.text}"
                )

            result = response.json()

            if DEBUG:
                print(f"[DEBUG] Ollama response: {result}")

            # Convertir respuesta al formato OpenAI
            content = result.get("message", {}).get("content", "")

            # Estimar tokens (aproximación)
            prompt_tokens = sum(len(m.content.split()) * 1.3 for m in request.messages)
            completion_tokens = len(content.split()) * 1.3

            return {
                "id": generate_id("chatcmpl"),
                "object": "chat.completion",
                "created": int(time.time()),
                "model": model_to_use,
                "choices": [
                    {
                        "index": 0,
                        "message": {
                            "role": "assistant",
                            "content": content
                        },
                        "finish_reason": "stop"
                    }
                ],
                "usage": {
                    "prompt_tokens": int(prompt_tokens),
                    "completion_tokens": int(completion_tokens),
                    "total_tokens": int(prompt_tokens + completion_tokens)
                }
            }

    except httpx.TimeoutException:
        raise HTTPException(status_code=504, detail="Ollama timeout - model may be loading")
    except httpx.ConnectError:
        raise HTTPException(status_code=503, detail="Cannot connect to Ollama. Is it running?")
    except Exception as e:
        raise HTTPException(status_code=500, detail=str(e))


@app.post("/v1/embeddings")
async def create_embeddings(
    request: EmbeddingRequest,
    authorization: Optional[str] = Header(None)
):
    """
    Endpoint de embeddings compatible con OpenAI.
    Usa Ollama internamente con nomic-embed-text u otro modelo configurado.
    """
    if not verify_api_key(authorization):
        raise HTTPException(status_code=401, detail="Invalid API key")

    # Normalizar input a lista
    inputs = request.input if isinstance(request.input, list) else [request.input]

    model_to_use = EMBEDDING_MODEL

    if DEBUG:
        print(f"[DEBUG] Embedding request - Model: {model_to_use}")
        print(f"[DEBUG] Input count: {len(inputs)}")

    try:
        embeddings_data = []
        total_tokens = 0

        async with httpx.AsyncClient(timeout=60.0) as client:
            for idx, text in enumerate(inputs):
                response = await client.post(
                    f"{OLLAMA_HOST}/api/embeddings",
                    json={
                        "model": model_to_use,
                        "prompt": text
                    }
                )

                if response.status_code != 200:
                    raise HTTPException(
                        status_code=response.status_code,
                        detail=f"Ollama embedding error: {response.text}"
                    )

                result = response.json()
                embedding = result.get("embedding", [])

                if DEBUG:
                    print(f"[DEBUG] Embedding {idx} dimensions: {len(embedding)}")

                embeddings_data.append({
                    "object": "embedding",
                    "index": idx,
                    "embedding": embedding
                })

                # Estimar tokens
                total_tokens += len(text.split()) * 1.3

        return {
            "object": "list",
            "data": embeddings_data,
            "model": model_to_use,
            "usage": {
                "prompt_tokens": int(total_tokens),
                "total_tokens": int(total_tokens)
            }
        }

    except httpx.TimeoutException:
        raise HTTPException(status_code=504, detail="Ollama timeout")
    except httpx.ConnectError:
        raise HTTPException(status_code=503, detail="Cannot connect to Ollama. Is it running?")
    except Exception as e:
        raise HTTPException(status_code=500, detail=str(e))


# ============ ENDPOINT DE PRUEBA ============

@app.get("/test")
async def test_connection():
    """Prueba la conexión con Ollama y los modelos."""
    results = {
        "ollama_connection": False,
        "chat_model": {"available": False, "name": CHAT_MODEL},
        "embedding_model": {"available": False, "name": EMBEDDING_MODEL}
    }

    try:
        async with httpx.AsyncClient(timeout=10.0) as client:
            # Test conexión Ollama
            response = await client.get(f"{OLLAMA_HOST}/api/tags")
            if response.status_code == 200:
                results["ollama_connection"] = True
                models = response.json().get("models", [])
                model_names = [m.get("name", "").split(":")[0] for m in models]

                # Verificar modelos
                chat_base = CHAT_MODEL.split(":")[0]
                embed_base = EMBEDDING_MODEL.split(":")[0]

                results["chat_model"]["available"] = any(chat_base in m for m in model_names)
                results["embedding_model"]["available"] = any(embed_base in m for m in model_names)
                results["available_models"] = model_names

    except Exception as e:
        results["error"] = str(e)

    return results


if __name__ == "__main__":
    import uvicorn
    port = int(os.getenv("PORT", 8000))
    uvicorn.run(app, host="0.0.0.0", port=port)
