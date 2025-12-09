CREATE TABLE daily_dispositions (
    id INT AUTO_INCREMENT PRIMARY KEY,

    phone_number VARCHAR(32) NOT NULL,          -- clave para limpiar
    first_name   VARCHAR(100) NULL,
    last_name    VARCHAR(100) NULL,

    agent        VARCHAR(100) NULL,             -- nombre/mnemónico del agente
    -- (opcional) si manejas id numérico: agent_id BIGINT NULL,

    status ENUM(
        'NOT_INTERESTED',       -- no interesado
        'SALE',                 -- venta / enrollment completed
        'WRONG_NUMBER',         -- número equivocado
        'INVALID_NUMBER',       -- número erróneo
        'DNC',                  -- do not call
        'CALLBACK_SCHEDULED'    -- call back
    ) NOT NULL,

    disposition_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,  -- cuándo se calificó
    disposition_date DATE AS (DATE(disposition_at)) STORED,      -- para llave única diaria

    -- Para evitar duplicados del mismo teléfono en el mismo día:
    UNIQUE KEY uk_phone_day (phone_number, disposition_date),

    -- Índices útiles:
    KEY idx_phone (phone_number(20)),
    KEY idx_status (status),
    KEY idx_disposition_at (disposition_at)
)
ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_0900_ai_ci;
