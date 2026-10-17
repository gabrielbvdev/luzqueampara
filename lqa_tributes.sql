CREATE TABLE IF NOT EXISTS homenagens_velario (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  nome_homenageado VARCHAR(80) NOT NULL,
  nome_usuario VARCHAR(60) NULL,
  email VARCHAR(254) NULL,
  telefone VARCHAR(30) NULL,
  nome_homenageado_publico VARCHAR(80) NULL,
  nome_usuario_publico VARCHAR(60) NULL,
  mensagem VARCHAR(140) NOT NULL DEFAULT '',
  forma_exibicao ENUM('oculto', 'completo', 'iniciais') NOT NULL DEFAULT 'oculto',
  consentimento TINYINT(1) NOT NULL DEFAULT 0,
  versao_consentimento VARCHAR(32) NULL,
  data_consentimento DATETIME(3) NULL,
  data_criacao DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uq_homenagens_velario_uuid (uuid),
  KEY idx_homenagens_velario_publicas (forma_exibicao, data_criacao, uuid),
  KEY idx_homenagens_velario_data (data_criacao, uuid),
  CONSTRAINT chk_homenagens_velario_consentimento
    CHECK (
      (forma_exibicao = 'oculto' AND consentimento = 0)
      OR (forma_exibicao IN ('completo', 'iniciais') AND consentimento = 1)
    )
) ENGINE=InnoDB
  DEFAULT CHARACTER SET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;
