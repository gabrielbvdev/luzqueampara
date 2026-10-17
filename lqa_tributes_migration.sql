ALTER TABLE lqa_tributes
  DROP CHECK chk_lqa_tributes_consent_mode,
  DROP INDEX idx_lqa_tributes_public_created,
  DROP INDEX idx_lqa_tributes_created,
  DROP PRIMARY KEY,
  CHANGE COLUMN id uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  ADD COLUMN id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY FIRST,
  CHANGE COLUMN name nome_homenageado VARCHAR(80) NOT NULL,
  CHANGE COLUMN author_name nome_usuario VARCHAR(60) NULL,
  CHANGE COLUMN public_name nome_homenageado_publico VARCHAR(80) NULL,
  CHANGE COLUMN public_author_name nome_usuario_publico VARCHAR(60) NULL,
  CHANGE COLUMN message mensagem VARCHAR(140) NOT NULL DEFAULT '',
  CHANGE COLUMN display_mode forma_exibicao ENUM('hidden', 'full', 'initials', 'oculto', 'completo', 'iniciais') NOT NULL DEFAULT 'hidden',
  CHANGE COLUMN consent consentimento TINYINT(1) NOT NULL DEFAULT 0,
  CHANGE COLUMN consent_version versao_consentimento VARCHAR(32) NULL,
  CHANGE COLUMN consent_at data_consentimento DATETIME(3) NULL,
  CHANGE COLUMN created_at data_criacao DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3);

UPDATE lqa_tributes
SET forma_exibicao = CASE forma_exibicao
  WHEN 'hidden' THEN 'oculto'
  WHEN 'full' THEN 'completo'
  WHEN 'initials' THEN 'iniciais'
  ELSE forma_exibicao
END;

ALTER TABLE lqa_tributes
  CHANGE COLUMN forma_exibicao forma_exibicao ENUM('oculto', 'completo', 'iniciais') NOT NULL DEFAULT 'oculto',
  ADD UNIQUE KEY uq_homenagens_velario_uuid (uuid),
  ADD KEY idx_homenagens_velario_publicas (forma_exibicao, data_criacao, uuid),
  ADD KEY idx_homenagens_velario_data (data_criacao, uuid),
  ADD CONSTRAINT chk_homenagens_velario_consentimento
    CHECK (
      (forma_exibicao = 'oculto' AND consentimento = 0)
      OR (forma_exibicao IN ('completo', 'iniciais') AND consentimento = 1)
    );

RENAME TABLE lqa_tributes TO homenagens_velario;
