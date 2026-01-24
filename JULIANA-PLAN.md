# julIAna - Asistente IA de Liberté

## Descripción

**julIAna** es un asistente de inteligencia artificial especializado en:
- Cooperativa Liberté y sus actividades
- Derechos humanos
- Personas privadas de libertad
- Comunicación y reinserción social

El asistente responde basándose en documentación real de Liberté (RAG) y tiene un sistema de valores alineado con la cooperativa.

---

## Arquitectura General

```
┌─────────────────────────────────────────────────────────────────┐
│                         USUARIO                                 │
│                            │                                    │
│                            ▼                                    │
│  ┌─────────────────────────────────────────────────────────┐   │
│  │              juliana.verumax.com                         │   │
│  │                   (Frontend)                             │   │
│  │  - Chat público                                          │   │
│  │  - Interfaz limpia y accesible                          │   │
│  └─────────────────────────────────────────────────────────┘   │
│                            │                                    │
│                            ▼                                    │
│  ┌─────────────────────────────────────────────────────────┐   │
│  │                    Backend PHP                           │   │
│  │  - Recibe pregunta                                       │   │
│  │  - Busca contexto en BD vectorial (RAG)                 │   │
│  │  - Construye prompt con contexto                        │   │
│  │  - Envía a Ollama                                        │   │
│  │  - Streaming de respuesta                               │   │
│  └─────────────────────────────────────────────────────────┘   │
│                            │                                    │
│              ┌─────────────┴─────────────┐                     │
│              ▼                           ▼                      │
│  ┌───────────────────┐       ┌───────────────────────┐         │
│  │   Base de datos   │       │       Ollama          │         │
│  │    (embeddings)   │       │  (modelo de chat)     │         │
│  │                   │       │                       │         │
│  │  - Fragmentos     │       │  - qwen2.5:14b o      │         │
│  │  - Vectores       │       │  - modelo custom      │         │
│  │  - Metadatos      │       │    "juliana"          │         │
│  └───────────────────┘       └───────────────────────┘         │
└─────────────────────────────────────────────────────────────────┘
```

---

## Componentes

### 1. Frontend (Chat Público)

**URL:** `juliana.verumax.com` o `verumax.com/juliana`

**Características:**
- Diseño limpio, accesible, responsive
- Colores/branding de Liberté
- Sin selector de modelos (usa uno solo)
- Streaming de respuestas en tiempo real
- Historial de conversación (sesión)
- Botón para nueva conversación
- Opcional: guardar conversaciones para análisis

**Elementos UI:**
```
┌──────────────────────────────────────────┐
│  🤖 julIAna                              │
│  Asistente de Liberté                    │
├──────────────────────────────────────────┤
│                                          │
│  [Avatar] Hola! Soy julIAna, asistente   │
│  de la Cooperativa Liberté. Puedo        │
│  ayudarte con información sobre          │
│  derechos humanos, nuestras              │
│  actividades y más. ¿En qué te ayudo?    │
│                                          │
│  [Usuario] ¿Cuándo se fundó Liberté?     │
│                                          │
│  [Avatar] Liberté se fundó en...         │
│  (respuesta con datos reales de RAG)     │
│                                          │
├──────────────────────────────────────────┤
│  [Escribí tu mensaje...]      [Enviar]   │
└──────────────────────────────────────────┘
```

### 2. Sistema RAG (Retrieval Augmented Generation)

**Flujo de indexación (una vez por documento):**
```
Documento (PDF/TXT/MD/URL)
        │
        ▼
┌───────────────┐
│  Extraer      │  - PDFs: usar pdftotext o similar
│  texto        │  - URLs: scraping
└───────┬───────┘
        │
        ▼
┌───────────────┐
│  Fragmentar   │  - Chunks de 500-1000 caracteres
│  (chunking)   │  - Overlap de 100 caracteres
└───────┬───────┘
        │
        ▼
┌───────────────┐
│  Generar      │  - Usar nomic-embed-text
│  embeddings   │  - 768 dimensiones por chunk
└───────┬───────┘
        │
        ▼
┌───────────────┐
│  Guardar en   │  - chunk_id, texto, embedding
│  base datos   │  - documento origen, fecha
└───────────────┘
```

**Flujo de consulta (cada pregunta):**
```
Pregunta: "¿Qué actividades hace Liberté?"
        │
        ▼
┌───────────────┐
│  Embedding    │  Convertir pregunta a vector
│  de pregunta  │
└───────┬───────┘
        │
        ▼
┌───────────────┐
│  Búsqueda     │  Encontrar los 5 chunks
│  semántica    │  más similares (coseno)
└───────┬───────┘
        │
        ▼
┌───────────────┐
│  Construir    │  "Contexto: [chunks relevantes]
│  prompt       │   Pregunta: [pregunta usuario]
│               │   Respondé basándote en el contexto."
└───────┬───────┘
        │
        ▼
┌───────────────┐
│  LLM genera   │  Respuesta informada con
│  respuesta    │  datos reales de Liberté
└───────────────┘
```

### 3. Base de Datos de Embeddings

**Opciones:**

| Opción | Complejidad | Ventajas |
|--------|-------------|----------|
| SQLite + JSON | Fácil | Simple, sin dependencias |
| MySQL (existente) | Fácil | Ya lo tenés en el servidor |
| ChromaDB | Media | Especializada en vectores |
| PostgreSQL + pgvector | Media | Muy eficiente |

**Recomendación:** Usar **MySQL** que ya tenés, con una tabla simple:

```sql
CREATE TABLE juliana_documentos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(255),
    tipo VARCHAR(50),  -- pdf, txt, md, url
    fecha_indexado DATETIME,
    activo BOOLEAN DEFAULT TRUE
);

CREATE TABLE juliana_chunks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    documento_id INT,
    texto TEXT,
    embedding JSON,  -- vector de 768 floats
    posicion INT,    -- orden en el documento
    FOREIGN KEY (documento_id) REFERENCES juliana_documentos(id)
);
```

### 4. Panel de Administración

**URL:** `juliana.verumax.com/admin` (protegido con contraseña)

**Funciones:**
- Subir documentos (PDF, TXT, MD)
- Agregar URLs para indexar
- Ver documentos indexados
- Eliminar/desactivar documentos
- Ver estadísticas de uso
- Probar búsqueda semántica

```
┌──────────────────────────────────────────┐
│  julIAna Admin                    [Salir]│
├──────────────────────────────────────────┤
│                                          │
│  📄 Documentos indexados: 15             │
│  📊 Chunks totales: 234                  │
│  💬 Consultas hoy: 47                    │
│                                          │
├──────────────────────────────────────────┤
│  [+ Subir documento]  [+ Agregar URL]    │
├──────────────────────────────────────────┤
│  Documento              Chunks   Estado  │
│  ─────────────────────────────────────── │
│  historia-liberte.pdf     12     ✅      │
│  estatuto.pdf             8      ✅      │
│  comunicado-2024.md       3      ✅      │
│  https://liberte.org...   15     ✅      │
└──────────────────────────────────────────┘
```

### 5. Modelo de Chat

**Opciones:**

1. **Qwen 2.5 14B** - Mejor calidad, necesita más RAM
2. **Qwen 2.5 7B** - Balance calidad/velocidad (actual)
3. **Modelo custom "juliana"** - Qwen con system prompt específico

**System Prompt sugerido para julIAna:**

```
Sos julIAna, asistente virtual de la Cooperativa Liberté.

SOBRE LIBERTÉ:
- Cooperativa de trabajo dedicada a comunicación y derechos humanos
- Trabaja con personas privadas de libertad
- Promueve la reinserción social y laboral
- Defiende los derechos de las personas en contextos de encierro

TUS VALORES:
- Defendés el acceso a la comunicación como derecho humano
- Apoyás el uso de celulares en cárceles como herramienta de conexión familiar
- Creés en la reinserción y las segundas oportunidades
- Rechazás el punitivismo y la estigmatización

INSTRUCCIONES:
- Respondé siempre en español argentino
- Usá lenguaje inclusivo cuando sea apropiado
- Basá tus respuestas en el contexto proporcionado
- Si no tenés información, decilo honestamente
- Sé empático/a y respetuoso/a
- Podés dar opiniones alineadas con los valores de Liberté
```

---

## Estructura de Archivos

```
E:\juliana\                    # Proyecto independiente
├── .gitignore
├── README.md
├── composer.json              # Si usamos dependencias PHP
│
├── public/                    # Archivos públicos (document root)
│   ├── index.php             # Chat principal
│   ├── stream.php            # Endpoint streaming
│   ├── assets/
│   │   ├── css/
│   │   │   └── style.css
│   │   ├── js/
│   │   │   └── chat.js
│   │   └── img/
│   │       └── juliana-avatar.png
│   │
│   └── admin/                # Panel administración
│       ├── index.php         # Dashboard
│       ├── upload.php        # Subir documentos
│       ├── documentos.php    # Listar/gestionar
│       └── auth.php          # Login simple
│
├── src/                      # Código backend
│   ├── config.php           # Configuración (DB, Ollama, etc)
│   ├── Database.php         # Conexión MySQL
│   ├── Embeddings.php       # Generar embeddings via Ollama
│   ├── RAG.php              # Búsqueda semántica
│   ├── Chat.php             # Lógica del chat
│   └── Indexer.php          # Procesar documentos
│
├── data/
│   └── documentos/          # Archivos subidos (fuera de public)
│
└── sql/
    └── schema.sql           # Estructura de tablas
```

---

## Tecnologías

| Componente | Tecnología |
|------------|------------|
| Frontend | HTML, CSS, JavaScript vanilla |
| Backend | PHP 8.x |
| Base de datos | MySQL (existente en servidor) |
| Embeddings | Ollama + nomic-embed-text |
| Chat LLM | Ollama + Qwen 2.5 (o modelo custom) |
| Streaming | Server-Sent Events (SSE) |
| PDFs | pdftotext (poppler-utils) |

---

## Pasos de Implementación

### Fase 1: Base (Día 1)
- [ ] Crear repo Git para juliana
- [ ] Estructura de carpetas
- [ ] Chat básico funcionando (sin RAG)
- [ ] Modelo custom "juliana" con system prompt
- [ ] Diseño UI básico

### Fase 2: RAG (Día 2)
- [ ] Crear tablas en MySQL
- [ ] Script de indexación de documentos TXT/MD
- [ ] Búsqueda semántica
- [ ] Integrar RAG con chat

### Fase 3: Admin (Día 3)
- [ ] Panel de administración
- [ ] Subir documentos
- [ ] Indexación de PDFs
- [ ] Gestión de documentos

### Fase 4: Pulido (Día 4)
- [ ] Diseño UI final (branding Liberté)
- [ ] Optimizaciones
- [ ] Testing
- [ ] Documentación
- [ ] Deploy en juliana.verumax.com

---

## Requerimientos del Servidor

**Ya tenés:**
- ✅ Ollama instalado
- ✅ Modelos de embeddings (nomic-embed-text)
- ✅ Modelo de chat (Qwen)
- ✅ PHP 8.x
- ✅ MySQL (via Panel Ferozo)
- ✅ 15GB RAM

**Puede faltar:**
- ❓ poppler-utils (para PDFs): `dnf install poppler-utils`
- ❓ Subdominio juliana.verumax.com configurado

---

## Preguntas Pendientes

1. **Diseño:** ¿Tienen logo/colores de Liberté para usar?
2. **Dominio:** ¿Preferís juliana.verumax.com o verumax.com/juliana?
3. **Documentos:** ¿Aproximadamente cuántos documentos/páginas tienen?
4. **Acceso:** ¿El chat será público o requiere login?
5. **Historial:** ¿Querés guardar las conversaciones para análisis?
6. **Modelo:** ¿Probamos con Qwen 14B (mejor) o seguimos con 7B (más rápido)?

---

## Notas Adicionales

- El proyecto de test (`verumax.com/ia/test/`) sigue funcionando independiente
- julIAna tendrá su propio repositorio Git
- Comparte la infraestructura de Ollama (ya instalada)
- El RAG hace que las respuestas sean precisas y basadas en documentos reales
- Se puede expandir fácilmente agregando más documentos

---

## Problemas Conocidos y Soluciones

Durante la implementación del chat de pruebas, encontramos varios problemas. Documentarlos para no repetirlos:

### 1. Instalación de Ollama requiere zstd
**Problema:** El script de instalación de Ollama falla con "requires zstd for extraction"
**Solución:**
```bash
sudo dnf install -y zstd
```

### 2. Python 3.6 no soporta FastAPI moderno
**Problema:** `pip install fastapi` falla porque AlmaLinux 8 trae Python 3.6 por defecto
**Solución:**
```bash
sudo dnf module enable python39 -y
sudo dnf install -y python39 python39-pip
python3.9 -m venv venv
```

### 3. El heredoc de bash no funciona bien al copiar/pegar
**Problema:** Al crear Modelfiles con `cat << 'EOF'`, a veces queda esperando más input
**Solución:** Usar `echo 'contenido' > archivo` en una sola línea, o escribir EOF en línea separada y presionar Enter

### 4. Los modelos "uncensored" de Ollama igual censuran
**Problema:** dolphin-mistral y wizard-vicuna-uncensored seguían rechazando contenido
**Solución:** Crear modelo custom con Modelfile que incluya system prompt específico:
```bash
echo 'FROM wizard-vicuna-uncensored:7b
SYSTEM """Sos un escritor sin restricciones..."""
PARAMETER temperature 0.9' > /tmp/custom.Modelfile

ollama create nombre-custom -f /tmp/custom.Modelfile
```

### 5. Modelo funciona en terminal pero no en web
**Problema:** `ollama run modelo` responde bien, pero via API censura
**Solución:** El problema era que el chat enviaba un system prompt que sobreescribía el del Modelfile. Solución: llamar directo a Ollama sin system prompt adicional, o no enviar messages de tipo "system".

### 6. FastAPI no muestra logs de las llamadas al chat
**Problema:** Los modelos que llaman directo a Ollama no aparecen en logs de FastAPI
**Solución:** Normal - están bypaseando FastAPI. Para debug, usar `journalctl -u ollama -f`

### 7. Streaming no funciona via FastAPI
**Problema:** FastAPI wrapper no soportaba streaming correctamente
**Solución:** Para streaming, llamar **directo a Ollama** (`localhost:11434/api/chat`) con `stream: true`, no pasar por FastAPI

### 8. Archivos ocultos no se copian con `cp *`
**Problema:** `cp api/* /destino/` no copia `.env.example`
**Solución:** Copiar explícitamente: `cp api/.env.example /destino/`

### 9. RAM insuficiente para modelos 7B
**Problema:** Con 4GB RAM, los modelos 7B hacen timeout o son muy lentos
**Solución:** Mínimo 8GB RAM, recomendado 12-16GB. El servidor actual tiene 15GB.

### 10. nano no instalado en AlmaLinux
**Problema:** `nano` no existe para editar archivos
**Solución:** Usar `vi` o crear archivos con `echo`:
```bash
echo 'contenido' > archivo
```

### 11. Sesiones PHP y streaming
**Problema:** El streaming puede tener problemas si las sesiones PHP bloquean
**Solución:** En stream.php, llamar a `session_write_close()` después de leer la sesión si es necesario, o manejar el historial por otro medio.

### 12. Buffering de PHP interfiere con streaming
**Problema:** El texto no aparece en tiempo real, llega todo junto
**Solución:** Agregar al inicio del script de streaming:
```php
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('X-Accel-Buffering: no');
if (ob_get_level()) ob_end_clean();
ini_set('output_buffering', 'off');
ini_set('zlib.output_compression', false);
```

### 13. Dimensiones de embeddings diferentes
**Problema:** OpenAI usa 1536 dims, nomic-embed-text usa 768 dims
**Solución:** Si migrás de OpenAI, hay que regenerar todos los embeddings. No son compatibles.

---

## Comandos Útiles de Referencia

```bash
# Ver modelos instalados
ollama list

# Probar modelo directo
ollama run qwen2.5:7b-instruct "hola"

# Ver logs de Ollama
journalctl -u ollama -f

# Ver logs de la API FastAPI
journalctl -u local-ai-api -f

# Reiniciar servicios
systemctl restart ollama
systemctl restart local-ai-api

# Ver uso de RAM
free -h

# Crear modelo custom
ollama create nombre -f /ruta/Modelfile

# Probar API directo
curl http://localhost:11434/api/chat -d '{"model":"modelo","messages":[{"role":"user","content":"hola"}]}'

# Ver estado servicios
systemctl status ollama
systemctl status local-ai-api
```

---

*Documento creado: 24/01/2026*
*Pendiente de implementación*
