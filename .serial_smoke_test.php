<?php
require __DIR__ . '/config/conexao.php';
require __DIR__ . '/includes/serial_control.php';

$pdo = getConnection();
$pdo->beginTransaction();

try {
    $nome = '__TESTE_SERIAL_' . bin2hex(random_bytes(4));
    $stmt = $pdo->prepare("INSERT INTO produtos (nome, categoria, ativo, controla_serial) VALUES (:nome, 'Teste', 1, 1)");
    $stmt->execute([':nome' => $nome]);
    $produtoId = (int) $pdo->lastInsertId();

    $stmt = $pdo->prepare('INSERT INTO estoque_equipamentos (produto_id, loja_id, quantidade) VALUES (:produto_id, NULL, 0)');
    $stmt->execute([':produto_id' => $produtoId]);

    serialControlCadastrar($pdo, $produtoId, $nome, ['TEST-' . bin2hex(random_bytes(4)), 'N/A', 'N/A']);
    $quantidade = serialControlSincronizarEstoque($pdo, $produtoId);
    if ($quantidade !== 3) {
        throw new RuntimeException('Quantidade automática incorreta.');
    }

    $stmt = $pdo->prepare("SELECT id_serial FROM equipamento_seriais WHERE id_equipamento = :produto_id AND status = 'DISPONIVEL' LIMIT 1");
    $stmt->execute([':produto_id' => $produtoId]);
    $serial = serialControlBuscar($pdo, (int) $stmt->fetchColumn(), $produtoId, true);
    serialControlValidarDisponivel($serial);

    echo "serial-smoke-ok\n";
} finally {
    $pdo->rollBack();
}
