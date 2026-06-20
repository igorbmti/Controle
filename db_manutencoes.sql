CREATE TABLE IF NOT EXISTS manutencoes (
    id_manutencao INT AUTO_INCREMENT PRIMARY KEY,
    id_item INT NOT NULL,
    id_loja INT NOT NULL,
    descricao TEXT NOT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'EM_MANUTENCAO',
    data_registro DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    id_usuario INT NOT NULL,
    data_conclusao DATETIME NULL,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    INDEX idx_manutencoes_item (id_item),
    INDEX idx_manutencoes_loja (id_loja),
    INDEX idx_manutencoes_usuario (id_usuario),
    INDEX idx_manutencoes_status (status),
    INDEX idx_manutencoes_data_registro (data_registro)
);
