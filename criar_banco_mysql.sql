-- =====================================================
-- Banco MySQL/MariaDB para o sistema Controle Big TI
-- Use no SQLTools com a conexao: MySQL Local - Servidor
-- =====================================================

CREATE DATABASE IF NOT EXISTS controle_big
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE controle_big;

-- =====================================================
-- Cadastros base
-- =====================================================

CREATE TABLE IF NOT EXISTS usuarios (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nome VARCHAR(150) NOT NULL,
  usuario VARCHAR(50) NOT NULL UNIQUE,
  senha VARCHAR(255) NOT NULL,
  nivel ENUM('admin', 'operador') NOT NULL DEFAULT 'operador',
  ativo TINYINT(1) NOT NULL DEFAULT 1,
  data_criacao DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS lojas (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nome VARCHAR(150) NOT NULL UNIQUE,
  endereco VARCHAR(255) NULL,
  telefone VARCHAR(20) NULL,
  ativo TINYINT(1) NOT NULL DEFAULT 1,
  data_criacao DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS setores (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nome VARCHAR(120) NOT NULL UNIQUE,
  icone VARCHAR(40) NULL,
  cor VARCHAR(20) NULL,
  ativo TINYINT(1) NOT NULL DEFAULT 1,
  data_criacao DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Padroniza uma tabela setores antiga que tenha sido criada como id_setor.
SET @tem_setores_id = (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'setores'
    AND COLUMN_NAME = 'id'
);

SET @tem_setores_id_setor = (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'setores'
    AND COLUMN_NAME = 'id_setor'
);

SET @sql_setores_id = IF(
  @tem_setores_id = 0 AND @tem_setores_id_setor = 1,
  'ALTER TABLE setores CHANGE COLUMN id_setor id INT NOT NULL AUTO_INCREMENT',
  'SELECT 1'
);

PREPARE stmt_setores_id FROM @sql_setores_id;
EXECUTE stmt_setores_id;
DEALLOCATE PREPARE stmt_setores_id;

ALTER TABLE setores ADD COLUMN IF NOT EXISTS icone VARCHAR(40) NULL AFTER nome;
ALTER TABLE setores ADD COLUMN IF NOT EXISTS cor VARCHAR(20) NULL AFTER icone;
ALTER TABLE setores ADD COLUMN IF NOT EXISTS ativo TINYINT(1) NOT NULL DEFAULT 1 AFTER cor;
ALTER TABLE setores ADD COLUMN IF NOT EXISTS data_criacao DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER ativo;

CREATE TABLE IF NOT EXISTS funcionarios (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nome VARCHAR(150) NOT NULL,
  cpf VARCHAR(14) NULL,
  cargo VARCHAR(80) NULL,
  loja_id INT NULL,
  setor_id INT NULL,
  ativo TINYINT(1) NOT NULL DEFAULT 1,
  data_criacao DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_funcionarios_loja FOREIGN KEY (loja_id) REFERENCES lojas(id),
  CONSTRAINT fk_funcionarios_setor FOREIGN KEY (setor_id) REFERENCES setores(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS produtos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nome VARCHAR(150) NOT NULL,
  descricao TEXT NULL,
  categoria VARCHAR(80) NULL,
  ativo TINYINT(1) NOT NULL DEFAULT 1,
  data_criacao DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS itens (
  id INT AUTO_INCREMENT PRIMARY KEY,
  produto_id INT NOT NULL,
  serial VARCHAR(80) NULL UNIQUE,
  patrimonio VARCHAR(80) NULL,
  status ENUM('Estoque', 'Em Uso', 'Manutencao', 'Baixado') NOT NULL DEFAULT 'Estoque',
  observacao TEXT NULL,
  data_ultima_manutencao DATE NULL,
  observacao_manutencao TEXT NULL,
  data_criacao DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_itens_produto FOREIGN KEY (produto_id) REFERENCES produtos(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS estoque_equipamentos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  produto_id INT NOT NULL,
  loja_id INT NULL,
  quantidade INT NOT NULL DEFAULT 0,
  data_atualizacao DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_estoque_produto_loja (produto_id, loja_id),
  CONSTRAINT fk_estoque_produto FOREIGN KEY (produto_id) REFERENCES produtos(id),
  CONSTRAINT fk_estoque_loja FOREIGN KEY (loja_id) REFERENCES lojas(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================================================
-- Movimentacoes para as telas "Nova Entrega" e "Consulta"
-- =====================================================

CREATE TABLE IF NOT EXISTS movimentacoes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tipo ENUM('Entrega', 'Troca') NOT NULL,
  produto_id INT NULL,
  item_id INT NULL,
  loja_id INT NOT NULL,
  setor_id INT NOT NULL,
  funcionario_id INT NULL,
  solicitante_nome VARCHAR(150) NOT NULL,
  quantidade INT NOT NULL DEFAULT 1,
  status ENUM('Pendente', 'Em avaliacao', 'Concluida', 'Cancelada') NOT NULL DEFAULT 'Pendente',
  justificativa TEXT NULL,
  descricao TEXT NULL,
  usuario_id INT NOT NULL,
  data_movimentacao DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  data_conclusao DATETIME NULL,
  CONSTRAINT fk_mov_produto FOREIGN KEY (produto_id) REFERENCES produtos(id),
  CONSTRAINT fk_mov_item FOREIGN KEY (item_id) REFERENCES itens(id),
  CONSTRAINT fk_mov_loja FOREIGN KEY (loja_id) REFERENCES lojas(id),
  CONSTRAINT fk_mov_setor FOREIGN KEY (setor_id) REFERENCES setores(id),
  CONSTRAINT fk_mov_funcionario FOREIGN KEY (funcionario_id) REFERENCES funcionarios(id),
  CONSTRAINT fk_mov_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id),
  INDEX idx_mov_data (data_movimentacao),
  INDEX idx_mov_tipo_status (tipo, status),
  INDEX idx_mov_solicitante (solicitante_nome),
  INDEX idx_mov_loja_setor (loja_id, setor_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tabelas antigas mantidas para compatibilidade com as paginas PHP existentes.
CREATE TABLE IF NOT EXISTS entregas (
  id INT AUTO_INCREMENT PRIMARY KEY,
  item_id INT NOT NULL,
  funcionario_id INT NOT NULL,
  loja_id INT NOT NULL,
  usuario_id INT NOT NULL,
  data_entrega DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  observacao TEXT NULL,
  CONSTRAINT fk_entregas_item FOREIGN KEY (item_id) REFERENCES itens(id),
  CONSTRAINT fk_entregas_funcionario FOREIGN KEY (funcionario_id) REFERENCES funcionarios(id),
  CONSTRAINT fk_entregas_loja FOREIGN KEY (loja_id) REFERENCES lojas(id),
  CONSTRAINT fk_entregas_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS trocas (
  id INT AUTO_INCREMENT PRIMARY KEY,
  item_antigo_id INT NOT NULL,
  item_novo_id INT NOT NULL,
  funcionario_id INT NOT NULL,
  loja_id INT NOT NULL,
  usuario_id INT NOT NULL,
  data_troca DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  motivo TEXT NULL,
  CONSTRAINT fk_trocas_item_antigo FOREIGN KEY (item_antigo_id) REFERENCES itens(id),
  CONSTRAINT fk_trocas_item_novo FOREIGN KEY (item_novo_id) REFERENCES itens(id),
  CONSTRAINT fk_trocas_funcionario FOREIGN KEY (funcionario_id) REFERENCES funcionarios(id),
  CONSTRAINT fk_trocas_loja FOREIGN KEY (loja_id) REFERENCES lojas(id),
  CONSTRAINT fk_trocas_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS fotos_equipamentos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  item_id INT NOT NULL,
  arquivo VARCHAR(255) NOT NULL,
  data_upload DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  usuario_id INT NOT NULL,
  CONSTRAINT fk_fotos_item FOREIGN KEY (item_id) REFERENCES itens(id),
  CONSTRAINT fk_fotos_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS comprovantes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  entrega_id INT NOT NULL,
  arquivo VARCHAR(255) NOT NULL,
  data_upload DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  usuario_id INT NOT NULL,
  CONSTRAINT fk_comprovantes_entrega FOREIGN KEY (entrega_id) REFERENCES entregas(id),
  CONSTRAINT fk_comprovantes_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Ajustes para bancos que ja foram criados pela versao anterior do PHP.
ALTER TABLE funcionarios ADD COLUMN IF NOT EXISTS setor_id INT NULL AFTER loja_id;

ALTER TABLE movimentacoes ADD COLUMN IF NOT EXISTS produto_id INT NULL AFTER tipo;
ALTER TABLE movimentacoes ADD COLUMN IF NOT EXISTS loja_id INT NULL AFTER item_id;
ALTER TABLE movimentacoes ADD COLUMN IF NOT EXISTS setor_id INT NULL AFTER loja_id;
ALTER TABLE movimentacoes ADD COLUMN IF NOT EXISTS funcionario_id INT NULL AFTER setor_id;
ALTER TABLE movimentacoes ADD COLUMN IF NOT EXISTS solicitante_nome VARCHAR(150) NULL AFTER funcionario_id;
ALTER TABLE movimentacoes ADD COLUMN IF NOT EXISTS quantidade INT NOT NULL DEFAULT 1 AFTER solicitante_nome;
ALTER TABLE movimentacoes ADD COLUMN IF NOT EXISTS status VARCHAR(30) NOT NULL DEFAULT 'Pendente' AFTER quantidade;
ALTER TABLE movimentacoes ADD COLUMN IF NOT EXISTS justificativa TEXT NULL AFTER status;
ALTER TABLE movimentacoes ADD COLUMN IF NOT EXISTS data_conclusao DATETIME NULL AFTER data_movimentacao;

-- =====================================================
-- Dados iniciais
-- =====================================================

INSERT IGNORE INTO lojas (nome) VALUES
('Loja 01'), ('Loja 02'), ('Loja 03'), ('Loja 04'), ('Loja 05'),
('Loja 06'), ('Loja 07'), ('Loja 08'), ('Loja 09'),
('Deposito 73'), ('Deposito 77');

INSERT IGNORE INTO setores (nome, icone, cor) VALUES
('Informatica', 'monitor', '#1E90FF'),
('Administrativo', 'briefcase', '#9B5DE5'),
('Financeiro', 'circle-dollar-sign', '#22C55E'),
('Atendimento', 'headphones', '#F59E0B'),
('Recursos Humanos', 'users', '#FACC15'),
('Outros', 'archive', '#94A3B8');

INSERT IGNORE INTO produtos (nome, categoria, descricao) VALUES
('Monitor 24\"', 'Periferico', 'Monitor Full HD de 24 polegadas'),
('Teclado Logitech', 'Periferico', 'Teclado USB Logitech'),
('Mouse Dell', 'Periferico', 'Mouse optico Dell'),
('Cabo HDMI', 'Cabo', 'Cabo HDMI padrao'),
('Notebook Dell', 'Computador', 'Notebook corporativo Dell');

INSERT IGNORE INTO usuarios (nome, usuario, senha, nivel) VALUES
('Administrador', 'admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin'),
('Igor Oliveira', 'igor', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin');

INSERT IGNORE INTO funcionarios (nome, cpf, cargo, loja_id, setor_id) VALUES
('Igor Oliveira', '111.111.111-11', 'Administrador', 1, 1),
('Mariana Costa', '222.222.222-22', 'Analista', 2, 3),
('Carlos Lima', '333.333.333-33', 'Atendente', 3, 4),
('Paulo Mendes', '444.444.444-44', 'Assistente', 1, 2),
('Juliana Silva', '555.555.555-55', 'Analista RH', 2, 5);

INSERT IGNORE INTO itens (produto_id, serial, patrimonio, status, data_ultima_manutencao) VALUES
(1, 'MON-0001', 'PAT001', 'Estoque', '2026-01-15'),
(2, 'TEC-0001', 'PAT002', 'Estoque', '2026-02-10'),
(3, 'MOU-0001', 'PAT003', 'Estoque', NULL),
(4, 'HDM-0001', 'PAT004', 'Estoque', NULL),
(5, 'NTB-0001', 'PAT005', 'Estoque', '2025-12-01');

INSERT INTO estoque_equipamentos (produto_id, loja_id, quantidade)
VALUES
(1, 1, 432),
(2, 2, 298),
(3, 3, 186),
(4, 1, 154),
(5, 2, 98)
ON DUPLICATE KEY UPDATE quantidade = VALUES(quantidade);

-- =====================================================
-- Views para consultas e dashboard
-- =====================================================

CREATE OR REPLACE VIEW vw_consulta_movimentacoes AS
SELECT
  m.id,
  m.data_movimentacao AS data_entrega,
  m.solicitante_nome AS solicitante,
  l.nome AS loja,
  s.nome AS setor_destino,
  p.nome AS equipamento,
  m.quantidade,
  m.tipo,
  m.status,
  u.nome AS usuario,
  m.justificativa
FROM movimentacoes m
INNER JOIN lojas l ON l.id = m.loja_id
INNER JOIN setores s ON s.id = m.setor_id
LEFT JOIN produtos p ON p.id = m.produto_id
LEFT JOIN usuarios u ON u.id = m.usuario_id;

CREATE OR REPLACE VIEW vw_dashboard_cards AS
SELECT
  (SELECT COUNT(*) FROM usuarios WHERE ativo = 1) AS usuarios_ativos,
  (SELECT COUNT(*) FROM movimentacoes WHERE MONTH(data_movimentacao) = MONTH(CURDATE()) AND YEAR(data_movimentacao) = YEAR(CURDATE())) AS movimentacoes_mes,
  (SELECT COUNT(*) FROM movimentacoes WHERE tipo = 'Troca' AND MONTH(data_movimentacao) = MONTH(CURDATE()) AND YEAR(data_movimentacao) = YEAR(CURDATE())) AS trocas_mes,
  (SELECT COUNT(*) FROM movimentacoes WHERE status IN ('Pendente', 'Em avaliacao')) AS em_avaliacao;

CREATE OR REPLACE VIEW vw_dashboard_movimentos_mensais AS
SELECT
  DATE_FORMAT(data_movimentacao, '%Y-%m') AS ano_mes,
  SUM(tipo = 'Entrega') AS entregas,
  SUM(tipo = 'Troca') AS trocas,
  COUNT(*) AS total
FROM movimentacoes
GROUP BY DATE_FORMAT(data_movimentacao, '%Y-%m')
ORDER BY ano_mes;

CREATE OR REPLACE VIEW vw_equipamentos_por_setor AS
SELECT
  s.nome AS setor,
  COUNT(m.id) AS total_movimentacoes,
  COALESCE(SUM(m.quantidade), 0) AS total_equipamentos
FROM setores s
LEFT JOIN movimentacoes m ON m.setor_id = s.id
GROUP BY s.id, s.nome
ORDER BY total_equipamentos DESC, s.nome;

CREATE OR REPLACE VIEW vw_movimentacoes_por_tipo AS
SELECT
  tipo,
  COUNT(*) AS total,
  ROUND(COUNT(*) * 100 / NULLIF((SELECT COUNT(*) FROM movimentacoes), 0), 1) AS percentual
FROM movimentacoes
GROUP BY tipo;

-- =====================================================
-- Procedures para gravar pelas telas
-- =====================================================

DROP PROCEDURE IF EXISTS sp_registrar_movimentacao;
DELIMITER //
CREATE PROCEDURE sp_registrar_movimentacao(
  IN p_tipo VARCHAR(20),
  IN p_produto_id INT,
  IN p_item_id INT,
  IN p_loja_id INT,
  IN p_setor_id INT,
  IN p_funcionario_id INT,
  IN p_solicitante_nome VARCHAR(150),
  IN p_quantidade INT,
  IN p_status VARCHAR(30),
  IN p_justificativa TEXT,
  IN p_usuario_id INT
)
BEGIN
  INSERT INTO movimentacoes (
    tipo, produto_id, item_id, loja_id, setor_id, funcionario_id,
    solicitante_nome, quantidade, status, justificativa, descricao, usuario_id
  )
  VALUES (
    p_tipo, p_produto_id, p_item_id, p_loja_id, p_setor_id, p_funcionario_id,
    p_solicitante_nome, p_quantidade, p_status, p_justificativa,
    CONCAT(p_solicitante_nome, ' registrou ', LOWER(p_tipo), ' de equipamento.'),
    p_usuario_id
  );

  IF p_item_id IS NOT NULL AND p_tipo = 'Entrega' THEN
    UPDATE itens SET status = 'Em Uso' WHERE id = p_item_id;
  END IF;
END //
DELIMITER ;

SELECT 'Banco controle_big preparado para o site Controle Big TI.' AS mensagem;
