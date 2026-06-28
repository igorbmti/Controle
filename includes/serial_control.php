<?php

declare(strict_types=1);

function serialControlStatusLabel(string $status): string
{
    return match (strtoupper(trim($status))) {
        'ENTREGUE' => 'Entregue',
        'EM_MANUTENCAO' => 'Em manutenção',
        default => 'Disponível',
    };
}

function serialControlNormalizarLista(string $valor): array
{
    $partes = preg_split('/[\r\n,;]+/', $valor) ?: [];
    $seriais = [];

    foreach ($partes as $parte) {
        $serial = trim($parte);
        if ($serial !== '') {
            $seriais[] = $serial;
        }
    }

    if (!$seriais) {
        return ['N/A'];
    }

    $vistos = [];
    $resultado = [];
    foreach ($seriais as $serial) {
        $chave = mb_strtoupper($serial, 'UTF-8');
        if ($chave !== 'N/A' && isset($vistos[$chave])) {
            continue;
        }
        $vistos[$chave] = true;
        $resultado[] = $serial;
    }

    return $resultado;
}

function serialControlCriarItem(PDO $pdo, int $produtoId, string $produtoNome, string $serial): int
{
    $serialItem = trim($serial);
    if ($serialItem === '' || strtoupper($serialItem) === 'N/A') {
        $serialItem = 'AUTO-SERIAL-' . $produtoId . '-' . bin2hex(random_bytes(8));
    }

    $stmt = $pdo->prepare('
        SELECT id, produto_id
        FROM itens
        WHERE serial = :serial
        LIMIT 1
    ');
    $stmt->execute([':serial' => $serialItem]);
    $existente = $stmt->fetch();
    if ($existente) {
        if ((int) $existente['produto_id'] !== $produtoId) {
            throw new RuntimeException('O serial ' . $serial . ' já pertence a outro equipamento.');
        }
        return (int) $existente['id'];
    }

    $stmt = $pdo->prepare("
        INSERT INTO itens (produto_id, serial, patrimonio, status, observacao)
        VALUES (:produto_id, :serial, NULL, 'Estoque', :observacao)
    ");
    $stmt->execute([
        ':produto_id' => $produtoId,
        ':serial' => $serialItem,
        ':observacao' => 'Item vinculado ao controle individual de serial. Produto: ' . $produtoNome,
    ]);

    return (int) $pdo->lastInsertId();
}

function serialControlCadastrar(PDO $pdo, int $produtoId, string $produtoNome, array $seriais): int
{
    $stmtExiste = $pdo->prepare('
        SELECT id_serial
        FROM equipamento_seriais
        WHERE serial_unico = UPPER(TRIM(:serial))
        LIMIT 1
    ');
    $stmtInserir = $pdo->prepare('
        INSERT INTO equipamento_seriais (id_equipamento, id_item, serial, status)
        VALUES (:id_equipamento, :id_item, :serial, "DISPONIVEL")
    ');
    $total = 0;

    foreach ($seriais as $valor) {
        $serial = trim((string) $valor);
        $serial = $serial !== '' ? $serial : 'N/A';

        if (strtoupper($serial) !== 'N/A') {
            $stmtExiste->execute([':serial' => $serial]);
            if ($stmtExiste->fetchColumn()) {
                throw new RuntimeException('O serial ' . $serial . ' já está cadastrado.');
            }
        }

        $itemId = serialControlCriarItem($pdo, $produtoId, $produtoNome, $serial);
        $stmtInserir->execute([
            ':id_equipamento' => $produtoId,
            ':id_item' => $itemId,
            ':serial' => $serial,
        ]);
        $total++;
    }

    return $total;
}

function serialControlSincronizarEstoque(PDO $pdo, int $produtoId): int
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM equipamento_seriais
        WHERE id_equipamento = :produto_id
          AND status = 'DISPONIVEL'
    ");
    $stmt->execute([':produto_id' => $produtoId]);
    $quantidade = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare('
        UPDATE estoque_equipamentos
        SET quantidade = :quantidade
        WHERE produto_id = :produto_id
          AND loja_id IS NULL
    ');
    $stmt->execute([
        ':quantidade' => $quantidade,
        ':produto_id' => $produtoId,
    ]);

    return $quantidade;
}

function serialControlBuscar(PDO $pdo, int $idSerial, int $produtoId, bool $bloquear = false): ?array
{
    $sql = '
        SELECT
            es.id_serial,
            es.id_equipamento,
            es.id_item,
            es.serial,
            es.status,
            es.loja_atual,
            es.id_movimentacao_atual
        FROM equipamento_seriais es
        WHERE es.id_serial = :id_serial
          AND es.id_equipamento = :produto_id
        LIMIT 1
    ';
    if ($bloquear) {
        $sql .= ' FOR UPDATE';
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':id_serial' => $idSerial,
        ':produto_id' => $produtoId,
    ]);

    return $stmt->fetch() ?: null;
}

function serialControlValidarDisponivel(?array $serial): void
{
    if (!$serial) {
        throw new RuntimeException('O serial informado não existe ou não pertence ao equipamento selecionado.');
    }

    $status = strtoupper((string) ($serial['status'] ?? ''));
    if ($status === 'ENTREGUE') {
        throw new RuntimeException('O serial informado já está entregue.');
    }
    if ($status === 'EM_MANUTENCAO') {
        throw new RuntimeException('O serial informado está em manutenção.');
    }
    if ($status !== 'DISPONIVEL') {
        throw new RuntimeException('O serial informado não está disponível no estoque.');
    }
}
