-- Migracion: agregar 'translate' al ENUM de ia_usage_logs.endpoint
-- Fecha: 2026-04-22
-- Ejecutar una sola vez contra la DB verumax_ia

ALTER TABLE ia_usage_logs
    MODIFY COLUMN endpoint ENUM('chat', 'embeddings', 'translate') NOT NULL;

-- Verificar
SHOW COLUMNS FROM ia_usage_logs LIKE 'endpoint';
