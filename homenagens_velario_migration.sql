ALTER TABLE lqa_tributes
  DROP CHECK chk_lqa_tributes_consentimento,
  DROP INDEX uq_lqa_tributes_uuid,
  DROP INDEX idx_lqa_tributes_publicas,
  DROP INDEX idx_lqa_tributes_data,
  CHANGE COLUMN forma_exibicao forma_exibicao ENUM('hidden', 'full', 'initials', 'oculto', 'completo', 'iniciais') NOT NULL DEFAULT 'hidden';

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
