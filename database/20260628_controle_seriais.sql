ALTER TABLE produtos
    ADD COLUMN controla_serial TINYINT(1) NOT NULL DEFAULT 0 AFTER ativo;

CREATE TABLE equipamento_seriais (
    id_serial INT NOT NULL AUTO_INCREMENT,
    id_equipamento INT NOT NULL,
    id_item INT NULL,
    serial VARCHAR(120) NOT NULL DEFAULT 'N/A',
    serial_unico VARCHAR(120)
        GENERATED ALWAYS AS (
            CASE
                WHEN UPPER(TRIM(serial)) = 'N/A' THEN NULL
                ELSE UPPER(TRIM(serial))
            END
        ) STORED,
    status VARCHAR(30) NOT NULL DEFAULT 'DISPONIVEL',
    loja_atual INT NULL,
    id_movimentacao_atual INT NULL,
    data_cadastro DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id_serial),
    UNIQUE KEY uk_equipamento_seriais_serial (serial_unico),
    UNIQUE KEY uk_equipamento_seriais_item (id_item),
    KEY idx_equipamento_seriais_equipamento_status (id_equipamento, status),
    KEY idx_equipamento_seriais_loja (loja_atual),
    KEY idx_equipamento_seriais_movimentacao (id_movimentacao_atual),
    CONSTRAINT chk_equipamento_seriais_status
        CHECK (status IN ('DISPONIVEL', 'ENTREGUE', 'EM_MANUTENCAO')),
    CONSTRAINT fk_equipamento_seriais_produto
        FOREIGN KEY (id_equipamento) REFERENCES produtos (id),
    CONSTRAINT fk_equipamento_seriais_item
        FOREIGN KEY (id_item) REFERENCES itens (id) ON DELETE SET NULL,
    CONSTRAINT fk_equipamento_seriais_loja
        FOREIGN KEY (loja_atual) REFERENCES lojas (id) ON DELETE SET NULL,
    CONSTRAINT fk_equipamento_seriais_movimentacao
        FOREIGN KEY (id_movimentacao_atual) REFERENCES movimentacoes (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE movimentacoes
    ADD COLUMN id_serial INT NULL AFTER item_id,
    ADD KEY idx_movimentacoes_serial (id_serial),
    ADD CONSTRAINT fk_movimentacoes_serial
        FOREIGN KEY (id_serial) REFERENCES equipamento_seriais (id_serial) ON DELETE SET NULL;

ALTER TABLE manutencoes
    ADD COLUMN id_serial INT NULL AFTER id_item,
    ADD KEY idx_manutencoes_serial (id_serial),
    ADD CONSTRAINT fk_manutencoes_serial
        FOREIGN KEY (id_serial) REFERENCES equipamento_seriais (id_serial) ON DELETE SET NULL;
