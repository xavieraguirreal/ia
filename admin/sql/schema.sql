-- ============================================
-- SCHEMA PARA ADMIN DE API - IA LOCAL
-- Ejecutar en MySQL
-- ============================================

-- Proyectos y API Keys
CREATE TABLE IF NOT EXISTS ia_proyectos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(100) NOT NULL,
    descripcion TEXT,
    api_key VARCHAR(255) NOT NULL UNIQUE,
    rate_limit_diario INT DEFAULT 1000,
    activo BOOLEAN DEFAULT TRUE,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_api_key (api_key),
    INDEX idx_nombre (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Logs de uso detallado
CREATE TABLE IF NOT EXISTS ia_usage_logs (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    proyecto_id INT NOT NULL,
    endpoint ENUM('chat', 'embeddings') NOT NULL,
    modelo VARCHAR(100),
    tokens_input INT DEFAULT 0,
    tokens_output INT DEFAULT 0,
    tiempo_ms INT,
    ip_address VARCHAR(45),
    status_code INT DEFAULT 200,
    error_message TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (proyecto_id) REFERENCES ia_proyectos(id) ON DELETE CASCADE,
    INDEX idx_proyecto_fecha (proyecto_id, created_at),
    INDEX idx_created (created_at),
    INDEX idx_endpoint (endpoint)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Contadores diarios (para rate limiting rapido)
CREATE TABLE IF NOT EXISTS ia_usage_daily (
    id INT AUTO_INCREMENT PRIMARY KEY,
    proyecto_id INT NOT NULL,
    fecha DATE NOT NULL,
    total_requests INT DEFAULT 0,
    total_tokens_input BIGINT DEFAULT 0,
    total_tokens_output BIGINT DEFAULT 0,
    total_tiempo_ms BIGINT DEFAULT 0,
    UNIQUE KEY uk_proyecto_fecha (proyecto_id, fecha),
    FOREIGN KEY (proyecto_id) REFERENCES ia_proyectos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Monitor de modelos
CREATE TABLE IF NOT EXISTS ia_modelos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(100) NOT NULL UNIQUE,
    digest VARCHAR(255),
    tamano_bytes BIGINT,
    tipo ENUM('chat', 'embedding') NOT NULL,
    activo BOOLEAN DEFAULT TRUE,
    ultima_verificacion DATETIME,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Historial de cambios en modelos
CREATE TABLE IF NOT EXISTS ia_modelos_historial (
    id INT AUTO_INCREMENT PRIMARY KEY,
    modelo_id INT NOT NULL,
    digest_anterior VARCHAR(255),
    digest_nuevo VARCHAR(255),
    detectado_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    revisado BOOLEAN DEFAULT FALSE,
    revisado_at DATETIME,
    notas TEXT,
    FOREIGN KEY (modelo_id) REFERENCES ia_modelos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Alertas del sistema
CREATE TABLE IF NOT EXISTS ia_alertas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tipo ENUM('modelo_actualizado', 'modelo_nuevo', 'rate_limit', 'error', 'servicio_caido') NOT NULL,
    mensaje TEXT NOT NULL,
    datos JSON,
    leida BOOLEAN DEFAULT FALSE,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_leida (leida),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Configuracion del admin
CREATE TABLE IF NOT EXISTS ia_config (
    clave VARCHAR(50) PRIMARY KEY,
    valor TEXT,
    updated_at DATETIME ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Insertar config inicial
INSERT INTO ia_config (clave, valor) VALUES
('admin_password', '$2y$10$defaulthashchangethis'),
('ollama_url', 'http://localhost:11434'),
('fastapi_url', 'http://localhost:8000')
ON DUPLICATE KEY UPDATE clave=clave;
