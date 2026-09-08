USE vacation_brain;

-- Vacation Brain v1.13: Admin-managed AI provider credentials and provider status.
CREATE TABLE IF NOT EXISTS ai_provider_settings (
    provider VARCHAR(32) NOT NULL PRIMARY KEY,
    display_name VARCHAR(80) NOT NULL,
    api_key_encrypted TEXT NULL,
    model_name VARCHAR(160) NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 0,
    is_default_chat TINYINT(1) NOT NULL DEFAULT 0,
    is_default_voice TINYINT(1) NOT NULL DEFAULT 0,
    settings_json JSON NULL,
    last_test_status ENUM('never','success','failed') NOT NULL DEFAULT 'never',
    last_tested_at DATETIME NULL,
    last_error VARCHAR(500) NULL,
    updated_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_ai_provider_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS ai_provider_test_log (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    provider VARCHAR(32) NOT NULL,
    tested_by BIGINT UNSIGNED NULL,
    status ENUM('success','failed') NOT NULL,
    http_status SMALLINT UNSIGNED NULL,
    message VARCHAR(500) NULL,
    tested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ai_provider_test_provider_time (provider,tested_at),
    CONSTRAINT fk_ai_test_provider FOREIGN KEY (provider) REFERENCES ai_provider_settings(provider) ON DELETE CASCADE,
    CONSTRAINT fk_ai_test_user FOREIGN KEY (tested_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;


CREATE TABLE IF NOT EXISTS ai_provider_usage_log (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    provider VARCHAR(32) NOT NULL,
    user_id BIGINT UNSIGNED NULL,
    purpose VARCHAR(80) NOT NULL DEFAULT 'agent_chat',
    model_name VARCHAR(160) NULL,
    input_units INT UNSIGNED NULL,
    output_units INT UNSIGNED NULL,
    success TINYINT(1) NOT NULL DEFAULT 1,
    error_message VARCHAR(500) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ai_usage_provider_time (provider,created_at),
    INDEX idx_ai_usage_user_time (user_id,created_at),
    CONSTRAINT fk_ai_usage_provider FOREIGN KEY (provider) REFERENCES ai_provider_settings(provider) ON DELETE CASCADE,
    CONSTRAINT fk_ai_usage_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT INTO ai_provider_settings (provider,display_name,model_name,enabled,is_default_chat,is_default_voice,settings_json)
VALUES
('openai','OpenAI','gpt-5.6-luna',0,1,0,JSON_OBJECT()),
('anthropic','Claude / Anthropic','claude-sonnet-4-6',0,0,0,JSON_OBJECT()),
('elevenlabs','ElevenLabs','eleven_multilingual_v2',0,0,1,JSON_OBJECT('voice_id',''))
ON DUPLICATE KEY UPDATE display_name=VALUES(display_name);

INSERT INTO app_meta (meta_key,meta_value) VALUES ('app_version','1.13') ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value);
