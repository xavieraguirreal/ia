#!/bin/bash

# ============================================================
# Script de instalación para VPS (AlmaLinux 8)
# Instala Ollama + Python API + Modelos
# ============================================================

set -e

echo "========================================"
echo "  Instalación de IA Local con Ollama"
echo "========================================"

# Colores para output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Verificar que se ejecuta como root o con sudo
if [ "$EUID" -ne 0 ]; then
    echo -e "${RED}Por favor ejecutar con sudo${NC}"
    exit 1
fi

# ============ 1. ACTUALIZAR SISTEMA ============
echo -e "\n${YELLOW}[1/6] Actualizando sistema...${NC}"
dnf update -y
dnf install -y epel-release
dnf install -y curl wget git python3 python3-pip

# ============ 2. INSTALAR OLLAMA ============
echo -e "\n${YELLOW}[2/6] Instalando Ollama...${NC}"
if command -v ollama &> /dev/null; then
    echo "Ollama ya está instalado"
else
    curl -fsSL https://ollama.com/install.sh | sh
fi

# Iniciar servicio Ollama
systemctl enable ollama
systemctl start ollama

# Esperar a que Ollama esté listo
echo "Esperando a que Ollama inicie..."
sleep 5

# ============ 3. DESCARGAR MODELOS ============
echo -e "\n${YELLOW}[3/6] Descargando modelos (esto puede tardar)...${NC}"

# Modelo de chat - Qwen 7B (aproximadamente 4.4GB)
echo "Descargando qwen2.5:7b-instruct..."
ollama pull qwen2.5:7b-instruct

# Modelo de embeddings - nomic-embed-text (aproximadamente 274MB)
echo "Descargando nomic-embed-text..."
ollama pull nomic-embed-text

echo -e "${GREEN}Modelos descargados correctamente${NC}"

# ============ 4. CONFIGURAR API PYTHON ============
echo -e "\n${YELLOW}[4/6] Configurando API Python...${NC}"

# Crear directorio de la aplicación
APP_DIR="/opt/local-ai-api"
mkdir -p $APP_DIR

# Copiar archivos (asumiendo que están en el directorio actual)
if [ -f "./api/main.py" ]; then
    cp -r ./api/* $APP_DIR/
else
    echo -e "${RED}Error: No se encontraron los archivos de la API${NC}"
    echo "Asegúrate de ejecutar este script desde el directorio del proyecto"
    exit 1
fi

# Crear entorno virtual
cd $APP_DIR
python3 -m venv venv
source venv/bin/activate

# Instalar dependencias
pip install --upgrade pip
pip install -r requirements.txt

# Crear archivo .env si no existe
if [ ! -f ".env" ]; then
    cp .env.example .env
    # Generar API key aleatoria
    API_KEY=$(openssl rand -hex 32)
    sed -i "s/tu-api-key-secreta/$API_KEY/" .env
    echo -e "${GREEN}API Key generada: $API_KEY${NC}"
    echo -e "${YELLOW}Guarda esta API key, la necesitarás para configurar Laravel${NC}"
fi

deactivate

# ============ 5. CREAR SERVICIO SYSTEMD ============
echo -e "\n${YELLOW}[5/6] Creando servicio systemd...${NC}"

cat > /etc/systemd/system/local-ai-api.service << 'EOF'
[Unit]
Description=Local AI API (FastAPI + Ollama)
After=network.target ollama.service
Requires=ollama.service

[Service]
Type=simple
User=root
WorkingDirectory=/opt/local-ai-api
Environment="PATH=/opt/local-ai-api/venv/bin"
ExecStart=/opt/local-ai-api/venv/bin/uvicorn main:app --host 0.0.0.0 --port 8000
Restart=always
RestartSec=10

[Install]
WantedBy=multi-user.target
EOF

# Recargar systemd y habilitar servicio
systemctl daemon-reload
systemctl enable local-ai-api
systemctl start local-ai-api

# ============ 6. CONFIGURAR FIREWALL ============
echo -e "\n${YELLOW}[6/6] Configurando firewall...${NC}"

# Abrir puerto 8000
if command -v firewall-cmd &> /dev/null; then
    firewall-cmd --permanent --add-port=8000/tcp
    firewall-cmd --reload
    echo "Puerto 8000 abierto en firewall"
else
    echo "firewall-cmd no encontrado, configurar manualmente si es necesario"
fi

# ============ RESUMEN ============
echo -e "\n${GREEN}========================================"
echo "  Instalación completada"
echo "========================================${NC}"

# Obtener IP del servidor
SERVER_IP=$(hostname -I | awk '{print $1}')

echo -e "\n${GREEN}API disponible en:${NC}"
echo "  - Local: http://localhost:8000"
echo "  - Remoto: http://$SERVER_IP:8000"

echo -e "\n${GREEN}Endpoints:${NC}"
echo "  - GET  /test              - Verificar conexión"
echo "  - GET  /v1/models         - Listar modelos"
echo "  - POST /v1/chat/completions - Chat (reemplaza gpt-4o-mini)"
echo "  - POST /v1/embeddings     - Embeddings (reemplaza text-embedding-3-small)"

echo -e "\n${GREEN}Comandos útiles:${NC}"
echo "  - Ver logs: journalctl -u local-ai-api -f"
echo "  - Reiniciar: systemctl restart local-ai-api"
echo "  - Ver estado: systemctl status local-ai-api"
echo "  - Ver modelos Ollama: ollama list"

echo -e "\n${YELLOW}Para configurar en Laravel (.env):${NC}"
echo "  OPENAI_API_KEY=\$(cat /opt/local-ai-api/.env | grep API_KEY | cut -d= -f2)"
echo "  OPENAI_BASE_URL=http://$SERVER_IP:8000/v1"

echo -e "\n${GREEN}¡Listo!${NC}"
