CREATE TABLE IF NOT EXISTS lojas_backup_padronizacao_20260618 AS
SELECT * FROM lojas;

CREATE TEMPORARY TABLE loja_map (old_id INT PRIMARY KEY, new_id INT NOT NULL);

INSERT INTO loja_map (old_id, new_id) VALUES
(12,1),(23,1),
(13,2),(24,2),
(15,4),(26,4),
(16,5),(27,5),
(17,6),(28,6),
(19,8),(30,8),
(20,9),(31,9),
(21,10),(32,10),
(22,11),(33,11);

UPDATE movimentacoes m JOIN loja_map lm ON lm.old_id = m.loja_id SET m.loja_id = lm.new_id;
UPDATE entregas e JOIN loja_map lm ON lm.old_id = e.loja_id SET e.loja_id = lm.new_id;
UPDATE trocas t JOIN loja_map lm ON lm.old_id = t.loja_id SET t.loja_id = lm.new_id;
UPDATE funcionarios f JOIN loja_map lm ON lm.old_id = f.loja_id SET f.loja_id = lm.new_id;
UPDATE estoque_equipamentos ee JOIN loja_map lm ON lm.old_id = ee.loja_id SET ee.loja_id = lm.new_id;

UPDATE lojas SET nome = 'Loja 01', ativo = 1 WHERE id = 1;
UPDATE lojas SET nome = 'Loja 02', ativo = 1 WHERE id = 2;
UPDATE lojas SET nome = 'Loja 04', ativo = 1 WHERE id = 4;
UPDATE lojas SET nome = 'Loja 05', ativo = 1 WHERE id = 5;
UPDATE lojas SET nome = 'Loja 06', ativo = 1 WHERE id = 6;
UPDATE lojas SET nome = 'Loja 08', ativo = 1 WHERE id = 8;
UPDATE lojas SET nome = 'Loja 09', ativo = 1 WHERE id = 9;
UPDATE lojas SET nome = CONCAT(_utf8mb4'Dep', CHAR(195,179 USING utf8mb4), _utf8mb4'sito 73'), ativo = 1 WHERE id = 10;
UPDATE lojas SET nome = CONCAT(_utf8mb4'Dep', CHAR(195,179 USING utf8mb4), _utf8mb4'sito 77'), ativo = 1 WHERE id = 11;

UPDATE lojas
SET ativo = 0
WHERE id IN (3,7,12,13,14,15,16,17,18,19,20,21,22,23,24,25,26,27,28,29,30,31,32,33);

SELECT id, nome, ativo FROM lojas ORDER BY id;
