<?php
require_once __DIR__ . '/../includes/auth.php';

verificarLogin('TECNICO');

$pdo = getConnection();
$idUsuario = (int) ($_SESSION['id_usuario'] ?? 0);
$nomeUsuario = (string) ($_SESSION['nome'] ?? 'Usuário');
$erros = [];
$sucesso = (string) ($_SESSION['flash_success'] ?? '');
unset($_SESSION['flash_success']);

if (empty($_SESSION['movimentacao_token'])) {
    $_SESSION['movimentacao_token'] = bin2hex(random_bytes(16));
}

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function postValue(string $key, string $default = ''): string
{
    return trim((string) ($_POST[$key] ?? $default));
}

function fetchOptions(PDO $pdo, string $sql): array
{
    try {
        return $pdo->query($sql)->fetchAll();
    } catch (Throwable $e) {
        error_log('Erro ao carregar opções técnicas: ' . $e->getMessage());
        return [];
    }
}

function buscarFuncionario(PDO $pdo, string $nome, int $lojaId, int $setorId): int
{
    $stmt = $pdo->prepare('
        SELECT id
        FROM funcionarios
        WHERE nome = :nome
          AND ativo = 1
        ORDER BY id DESC
        LIMIT 1
    ');
    $stmt->execute([':nome' => $nome]);
    $id = $stmt->fetchColumn();

    if ($id) {
        return (int) $id;
    }

    $stmt = $pdo->prepare('
        INSERT INTO funcionarios (nome, loja_id, setor_id, ativo)
        VALUES (:nome, :loja_id, :setor_id, 1)
    ');
    $stmt->execute([
        ':nome' => $nome,
        ':loja_id' => $lojaId,
        ':setor_id' => $setorId,
    ]);

    return (int) $pdo->lastInsertId();
}

function buscarOuCriarItemProduto(PDO $pdo, int $produtoId, string $produtoNome, ?int $ignorarItemId = null, string $contexto = 'movimentacao'): int
{
    $stmt = $pdo->prepare('
        SELECT id
        FROM itens
        WHERE produto_id = :produto_id
          AND (:ignorar_item_id_null IS NULL OR id <> :ignorar_item_id_value)
        ORDER BY FIELD(status, "Estoque") DESC, id ASC
        LIMIT 1
    ');
    $stmt->execute([
        ':produto_id' => $produtoId,
        ':ignorar_item_id_null' => $ignorarItemId,
        ':ignorar_item_id_value' => $ignorarItemId,
    ]);
    $id = $stmt->fetchColumn();

    if ($id) {
        return (int) $id;
    }

    $contextoSerial = strtoupper(preg_replace('/[^A-Z0-9]+/i', '-', $contexto));
    $baseSerial = 'AUTO-' . $produtoId . '-' . $contextoSerial . '-' . date('YmdHis');
    $observacao = 'Item operacional criado automaticamente a partir do estoque para permitir registro de movimentação.';

    for ($attempt = 1; $attempt <= 10; $attempt++) {
        $serial = $baseSerial . '-' . $attempt;

        try {
            $stmt = $pdo->prepare('
                INSERT INTO itens (produto_id, serial, patrimonio, status, observacao)
                VALUES (:produto_id, :serial, :patrimonio, :status, :observacao)
            ');
            $stmt->execute([
                ':produto_id' => $produtoId,
                ':serial' => $serial,
                ':patrimonio' => null,
                ':status' => 'Estoque',
                ':observacao' => $observacao . ' Produto: ' . $produtoNome,
            ]);

            return (int) $pdo->lastInsertId();
        } catch (PDOException $e) {
            if ($e->getCode() !== '23000') {
                throw $e;
            }
        }
    }

    throw new RuntimeException('Não foi possível criar o item operacional para este equipamento.');
}

if (isset($_GET['ajax']) && $_GET['ajax'] === 'estoque') {
    header('Content-Type: application/json; charset=utf-8');

    $estoqueId = (int) ($_GET['id'] ?? 0);
    $stmt = $pdo->prepare('
        SELECT quantidade
        FROM estoque_equipamentos
        WHERE id = :id
        LIMIT 1
    ');
    $stmt->execute([':id' => $estoqueId]);
    $quantidade = $stmt->fetchColumn();

    echo json_encode([
        'quantidade' => $quantidade === false ? 0 : (int) $quantidade,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$lojas = fetchOptions($pdo, "
    SELECT MIN(id) AS id, 'Loja 1' AS nome, 1 AS ordem FROM lojas WHERE ativo = 1 AND nome REGEXP '^Loja 0?1$'
    UNION ALL
    SELECT MIN(id) AS id, 'Loja 2' AS nome, 2 AS ordem FROM lojas WHERE ativo = 1 AND nome REGEXP '^Loja 0?2$'
    UNION ALL
    SELECT COALESCE(
        (SELECT MAX(id) FROM lojas WHERE ativo = 1 AND nome = 'Deposito 73'),
        (SELECT MIN(id) FROM lojas WHERE ativo = 1 AND nome LIKE '%73')
    ) AS id, 'Loja 73' AS nome, 3 AS ordem
    UNION ALL
    SELECT MIN(id) AS id, 'Loja 4' AS nome, 4 AS ordem FROM lojas WHERE ativo = 1 AND nome REGEXP '^Loja 0?4$'
    UNION ALL
    SELECT MIN(id) AS id, 'Loja 5' AS nome, 5 AS ordem FROM lojas WHERE ativo = 1 AND nome REGEXP '^Loja 0?5$'
    UNION ALL
    SELECT MIN(id) AS id, 'Loja 6' AS nome, 6 AS ordem FROM lojas WHERE ativo = 1 AND nome REGEXP '^Loja 0?6$'
    UNION ALL
    SELECT COALESCE(
        (SELECT MAX(id) FROM lojas WHERE ativo = 1 AND nome = 'Deposito 77'),
        (SELECT MIN(id) FROM lojas WHERE ativo = 1 AND nome LIKE '%77')
    ) AS id, 'Loja 77' AS nome, 7 AS ordem
    UNION ALL
    SELECT MIN(id) AS id, 'Loja 8' AS nome, 8 AS ordem FROM lojas WHERE ativo = 1 AND nome REGEXP '^Loja 0?8$'
    ORDER BY ordem
");
$setores = fetchOptions($pdo, 'SELECT id, nome FROM setores WHERE ativo = 1 ORDER BY nome');
$equipamentos = fetchOptions($pdo, '
    SELECT
        ee.id,
        ee.produto_id,
        ee.quantidade,
        p.nome AS equipamento
    FROM estoque_equipamentos ee
    INNER JOIN produtos p ON p.id = ee.produto_id
    WHERE COALESCE(p.ativo, 1) = 1
    ORDER BY p.nome
');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['acao'] ?? '') === 'confirmar_movimentacao') {
    $sessionToken = (string) ($_SESSION['movimentacao_token'] ?? '');
    $formToken = postValue('movimentacao_token');
    $lojaId = (int) ($_POST['loja_id'] ?? 0);
    $setorId = (int) ($_POST['setor_id'] ?? 0);
    $solicitante = postValue('solicitante');
    $dataEntrega = postValue('data_entrega', date('Y-m-d'));
    $estoqueId = (int) ($_POST['estoque_id'] ?? 0);
    $quantidade = (int) ($_POST['quantidade'] ?? 1);
    $tipo = strtoupper(postValue('tipo'));
    $justificativa = postValue('justificativa');

    if ($sessionToken === '' || $formToken === '' || !hash_equals($sessionToken, $formToken)) {
        $erros[] = 'Esta movimentacao ja foi processada. Atualize a pagina e tente novamente.';
    } else {
        unset($_SESSION['movimentacao_token']);
    }

    if ($lojaId <= 0) {
        $erros[] = 'Selecione a loja.';
    }
    if ($setorId <= 0) {
        $erros[] = 'Selecione o setor de destino.';
    }
    if ($solicitante === '') {
        $erros[] = 'Informe o nome do solicitante.';
    }
    if ($dataEntrega === '') {
        $erros[] = 'Informe a data da entrega.';
    }
    if ($estoqueId <= 0) {
        $erros[] = 'Selecione o equipamento.';
    }
    if ($quantidade < 1 || $quantidade > 10) {
        $erros[] = 'A quantidade deve estar entre 1 e 10.';
    }
    if (!in_array($tipo, ['ENTREGA', 'TROCA'], true)) {
        $erros[] = 'Selecione o tipo da movimentação.';
    }
    if ($justificativa === '') {
        $erros[] = 'Informe a justificativa da movimentação.';
    }
    if (mb_strlen($justificativa, 'UTF-8') > 1000) {
        $erros[] = 'A justificativa deve ter no máximo 1000 caracteres.';
    }

    if (empty($erros)) {
        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare('
                SELECT
                    ee.id,
                    ee.produto_id,
                    ee.quantidade,
                    p.nome AS equipamento
                FROM estoque_equipamentos ee
                INNER JOIN produtos p ON p.id = ee.produto_id
                WHERE ee.id = :id
                FOR UPDATE
            ');
            $stmt->execute([':id' => $estoqueId]);
            $estoque = $stmt->fetch();

            if (!$estoque) {
                throw new RuntimeException('Equipamento não encontrado no estoque.');
            }
            if ((int) $estoque['quantidade'] < $quantidade) {
                throw new RuntimeException('Quantidade indisponível em estoque.');
            }

            $produtoId = (int) $estoque['produto_id'];
            $itemId = buscarOuCriarItemProduto($pdo, $produtoId, (string) $estoque['equipamento'], null, strtolower($tipo));
            $itemTrocaNovoId = null;

            if ($tipo === 'TROCA') {
                $itemTrocaNovoId = buscarOuCriarItemProduto($pdo, $produtoId, (string) $estoque['equipamento'], $itemId, 'troca-novo');
            }

            $funcionarioId = buscarFuncionario($pdo, $solicitante, $lojaId, $setorId);
            $dataSql = date('Y-m-d', strtotime($dataEntrega));
            $dataMovimentacaoSql = $dataSql . ' ' . date('H:i:s');
            $tipoFormatado = $tipo === 'TROCA' ? 'Troca' : 'Entrega';
            $descricao = $tipoFormatado . ' de ' . $quantidade . ' unidade(s) de ' . $estoque['equipamento'];
            $observacaoEntrega = "Solicitante: {$solicitante}\nStatus: CONCLUIDA\nJustificativa: {$justificativa}";

            $stmt = $pdo->prepare('
                INSERT INTO entregas (item_id, funcionario_id, loja_id, usuario_id, data_entrega, observacao, id_setor)
                VALUES (:item_id, :funcionario_id, :loja_id, :usuario_id, :data_entrega, :observacao, :id_setor)
            ');
            $stmt->execute([
                ':item_id' => $itemId,
                ':funcionario_id' => $funcionarioId,
                ':loja_id' => $lojaId,
                ':usuario_id' => $idUsuario,
                ':data_entrega' => $dataSql,
                ':observacao' => $observacaoEntrega,
                ':id_setor' => $setorId,
            ]);

            $stmt = $pdo->prepare('
                INSERT INTO movimentacoes (
                    tipo, produto_id, item_id, loja_id, setor_id, funcionario_id,
                    solicitante_nome, quantidade, status, justificativa, usuario_id,
                    descricao, data_movimentacao, data_conclusao
                )
                VALUES (
                    :tipo, :produto_id, :item_id, :loja_id, :setor_id, :funcionario_id,
                    :solicitante_nome, :quantidade, :status, :justificativa, :usuario_id,
                    :descricao, :data_movimentacao, NOW()
                )
            ');
            $stmt->execute([
                ':tipo' => $tipoFormatado,
                ':produto_id' => $produtoId,
                ':item_id' => $itemId,
                ':loja_id' => $lojaId,
                ':setor_id' => $setorId,
                ':funcionario_id' => $funcionarioId,
                ':solicitante_nome' => $solicitante,
                ':quantidade' => $quantidade,
                ':status' => 'CONCLUIDA',
                ':justificativa' => $justificativa,
                ':usuario_id' => $idUsuario,
                ':descricao' => $descricao,
                ':data_movimentacao' => $dataMovimentacaoSql,
            ]);

            if ($tipo === 'TROCA') {
                $stmt = $pdo->prepare('
                    INSERT INTO trocas (item_antigo_id, item_novo_id, funcionario_id, loja_id, usuario_id, data_troca, motivo)
                    VALUES (:item_antigo_id, :item_novo_id, :funcionario_id, :loja_id, :usuario_id, :data_troca, :motivo)
                ');
                $stmt->execute([
                    ':item_antigo_id' => $itemId,
                    ':item_novo_id' => $itemTrocaNovoId,
                    ':funcionario_id' => $funcionarioId,
                    ':loja_id' => $lojaId,
                    ':usuario_id' => $idUsuario,
                    ':data_troca' => $dataSql,
                    ':motivo' => $justificativa,
                ]);
            }

            $stmt = $pdo->prepare('
                UPDATE estoque_equipamentos
                SET quantidade = quantidade - :quantidade_saida
                WHERE id = :id
                  AND quantidade >= :quantidade_minima
            ');
            $stmt->execute([
                ':quantidade_saida' => $quantidade,
                ':quantidade_minima' => $quantidade,
                ':id' => $estoqueId,
            ]);

            if ($stmt->rowCount() !== 1) {
                throw new RuntimeException('Quantidade indisponível em estoque.');
            }

            $pdo->commit();
            $_SESSION['flash_success'] = 'Movimentação registrada com sucesso.';
            header('Location: dashboard.php');
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Erro ao registrar movimentação técnica: ' . $e->getMessage());
            $erros[] = in_array($e->getMessage(), ['Quantidade indisponível em estoque.', 'Não foi possível criar o item operacional para este equipamento.'], true)
                ? $e->getMessage()
                : 'Não foi possível concluir a operação. Tente novamente.';
        }
    }
} elseif (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $erros[] = 'Ação inválida. Utilize o botão Confirmar para registrar a movimentação.';
}

if (empty($_SESSION['movimentacao_token'])) {
    $_SESSION['movimentacao_token'] = bin2hex(random_bytes(16));
}

$selectedLoja = (int) ($_POST['loja_id'] ?? 0);
$selectedSetor = (int) ($_POST['setor_id'] ?? 0);
$selectedEstoque = (int) ($_POST['estoque_id'] ?? 0);
$selectedTipo = strtoupper((string) ($_POST['tipo'] ?? ''));
$dataPadrao = $_POST['data_entrega'] ?? date('Y-m-d');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nova Entrega - Controle Big TI</title>
    <style>
        :root {
            --bg: #05080c;
            --panel: #10151c;
            --line: #242c37;
            --text: #f7f8fb;
            --muted: #a7b0be;
            --red: #e50914;
            --green: #27b84d;
            --radius: 8px;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            color: var(--text);
            font-family: "Segoe UI", Arial, sans-serif;
            background: linear-gradient(135deg, #040609 0%, #081018 52%, #05070b 100%);
            animation: pageFadeIn .24s ease both;
            transition: opacity .22s ease, transform .22s ease;
        }
        @media (min-width: 1024px) {
            body { zoom: .82; }
        }
        body.page-leaving {
            opacity: 0;
            transform: translateY(4px);
        }
        @keyframes pageFadeIn {
            from { opacity: 0; transform: translateY(4px); }
            to { opacity: 1; transform: translateY(0); }
        }
        a { color: inherit; text-decoration: none; }
        .app { display: grid; grid-template-columns: 300px minmax(0, 1fr); min-height: 100vh; }
        .sidebar {
            position: sticky;
            top: 0;
            height: 100vh;
            border-right: 1px solid var(--line);
            background: rgba(4, 8, 13, .94);
            display: flex;
            flex-direction: column;
            padding: 34px 18px 24px;
        }
        .brand { display: flex; align-items: flex-start; height: 70px; font-size: 42px; font-weight: 800; line-height: 1; }
        .brand span:nth-child(2) { color: var(--red); }
        .brand .plus {
            color: #fff; background: var(--red); width: 18px; height: 18px; border-radius: 50%;
            font-size: 15px; line-height: 17px; display: inline-flex; align-items: center; justify-content: center; margin-left: 4px;
        }
        .nav { display: grid; gap: 12px; margin-top: 28px; }
        .nav-item {
            min-height: 58px; border-radius: var(--radius); display: flex; align-items: center; gap: 14px;
            padding: 0 18px; font-size: 15px; font-weight: 700; border: 1px solid transparent;
        }
        .nav-item.active { background: linear-gradient(135deg, var(--red), #f01520); box-shadow: 0 14px 30px rgba(229, 9, 20, .24); }
        .nav-item svg, .logout svg { width: 23px; height: 23px; flex: 0 0 auto; }
        .sidebar-footer { margin-top: auto; display: grid; gap: 18px; padding-left: 8px; }
        .profile { display: flex; align-items: center; gap: 12px; padding-left: 8px; }
        .avatar { width: 42px; height: 42px; border: 2px solid #fff; border-radius: 50%; display: grid; place-items: center; }
        .profile strong { display: block; font-size: 14px; font-weight: 700; }
        .profile span { display: block; color: #fff; font-size: 12px; margin-top: 2px; }
        .logout { display: inline-flex; align-items: center; gap: 10px; padding-left: 8px; font-size: 15px; font-weight: 700; }
        .topbar { height: 78px; border-bottom: 1px solid var(--line); display: flex; justify-content: flex-end; align-items: center; padding: 0 32px; }
        .hello { display: flex; align-items: center; gap: 16px; font-size: 18px; }
        .main { min-width: 0; }
        .content { padding: 28px 36px 36px; }
        .page-title { display: flex; align-items: center; gap: 20px; margin-bottom: 24px; }
        .title-icon {
            width: 76px; height: 76px; border-radius: var(--radius);
            background: linear-gradient(135deg, #8d1119, #4a090e); display: grid; place-items: center;
        }
        .title-icon svg { width: 36px; height: 36px; }
        .page-title h1 { margin: 0 0 6px; font-size: 28px; }
        .page-title p { margin: 0; color: #d6dbe5; font-size: 16px; }
        .panel {
            background: linear-gradient(150deg, rgba(255,255,255,.045), transparent 40%), rgba(17,22,30,.88);
            border: 1px solid var(--line); border-radius: var(--radius); margin-bottom: 16px; padding: 24px 26px;
        }
        .panel h2 { margin: 0 0 20px; font-size: 21px; }
        .panel p { margin: -8px 0 22px; color: #f1f4f8; font-size: 15px; }
        .grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 18px; align-items: end; }
        .form-card { display: grid; gap: 24px; }
        .form-row { display: grid; gap: 18px; align-items: end; }
        .form-row.delivery { grid-template-columns: repeat(4, minmax(0, 1fr)); }
        .form-row.equipment { grid-template-columns: minmax(320px, 1.15fr) minmax(360px, .95fr); }
        .form-row.quantity { grid-template-columns: minmax(92px, 120px) 1fr; align-items: start; margin-top: -6px; }
        .equipment-kicker {
            color: #dfe5ef;
            font-size: 14px;
            font-weight: 700;
            margin-bottom: -10px;
        }
        label { display: grid; gap: 9px; font-size: 14px; font-weight: 600; }
        input, select, textarea {
            width: 100%; border: 1px solid var(--line); border-radius: var(--radius); background: #10151c;
            color: #fff; padding: 0 16px; font: inherit; font-size: 15px; outline: none;
        }
        input, select { height: 52px; }
        textarea { min-height: 104px; padding: 16px 18px; resize: vertical; line-height: 1.45; }
        .type-group {
            display: grid;
            gap: 9px;
        }
        .type-group > span {
            color: #f7f8fb;
            font-size: 14px;
            font-weight: 600;
        }
        .type-options {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
        }
        .choice {
            min-height: 52px; border: 1px solid var(--line); border-radius: var(--radius); display: flex;
            align-items: center; justify-content: center; gap: 12px; padding: 0 16px; cursor: pointer; font-size: 15px; font-weight: 700;
            background: rgba(255, 255, 255, .025);
            transition: border-color .18s ease, background .18s ease, box-shadow .18s ease, transform .18s ease;
        }
        .choice:hover { border-color: rgba(229, 9, 20, .5); background: rgba(229, 9, 20, .06); }
        .choice:has(input:checked) {
            border-color: var(--red);
            background: rgba(229, 9, 20, .12);
            box-shadow: 0 0 0 1px rgba(229, 9, 20, .22), 0 12px 24px rgba(0, 0, 0, .18);
            transform: translateY(-1px);
        }
        .choice input { position: absolute; opacity: 0; pointer-events: none; }
        .choice svg { width: 24px; height: 24px; }
        .choice.entrega svg { color: #59d02f; }
        .choice.troca svg { color: var(--red); }
        .quantity-field {
            max-width: 112px;
        }
        .quantity-field select {
            height: 46px;
            padding: 0 12px;
            font-weight: 700;
        }
        .textarea-wrap { position: relative; }
        .counter { position: absolute; right: 18px; bottom: 12px; color: #d6dbe5; font-size: 13px; }
        .form-footer { display: flex; align-items: center; justify-content: space-between; gap: 16px; margin-top: 22px; }
        .alert {
            min-height: 48px; border-radius: var(--radius); border: 1px solid rgba(229,9,20,.5);
            background: rgba(229,9,20,.12); color: #ffdfe2; display: flex; align-items: center; padding: 0 16px; font-weight: 800; font-size: 14px;
        }
        .alert.ok { border-color: rgba(39,184,77,.55); background: rgba(39,184,77,.12); color: #dff9e6; }
        .flash-message {
            animation: flashIn .24s ease both;
            transition: opacity .28s ease, transform .28s ease;
        }
        .flash-message.hiding {
            opacity: 0;
            transform: translateY(-6px);
        }
        @keyframes flashIn {
            from { opacity: 0; transform: translateY(-8px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .submit {
            min-width: 190px; height: 56px; border: 0; border-radius: var(--radius);
            background: linear-gradient(135deg, var(--red), #f01520); color: #fff; font-size: 16px; font-weight: 800; cursor: pointer;
        }
        .stock-note { color: var(--muted); font-size: 12px; margin-top: -2px; }
        @media (max-width: 1050px) {
            .app { grid-template-columns: 1fr; }
            .sidebar { position: static; height: auto; }
            .grid, .form-row.delivery, .form-row.equipment { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .type-group { grid-column: 1 / -1; }
        }
        @media (max-width: 640px) {
            .content { padding: 22px; }
            .grid, .form-row.delivery, .form-row.equipment, .form-row.quantity { grid-template-columns: 1fr; }
            .form-footer { align-items: stretch; flex-direction: column; }
            .submit { width: 100%; }
        }
    </style>
</head>
<body>
<div class="app">
    <aside class="sidebar">
        <div class="brand"><span>Big</span><span>mais</span><span class="plus">+</span></div>
        <nav class="nav">
            <a class="nav-item active" href="dashboard.php">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M12 18v-6"/><path d="M9 15h6"/></svg>
                Nova Entrega
            </a>
            <a class="nav-item" href="consultar_entregas.php">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M8 2v4"/><path d="M16 2v4"/><path d="M3 10h18"/><path d="M8 14h.01"/><path d="M12 14h.01"/><path d="M16 14h.01"/><path d="M8 18h.01"/><path d="M12 18h.01"/></svg>
                Consultar Entregas
            </a>
        </nav>
        <div class="sidebar-footer">
            <div class="profile">
                <div class="avatar"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="5"/><path d="M20 21a8 8 0 0 0-16 0"/></svg></div>
                <div><strong><?php echo e($nomeUsuario); ?></strong><span>Usuário</span></div>
            </div>
            <a class="logout" href="../logout.php">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5"/><path d="M21 12H9"/></svg>
                Sair
            </a>
        </div>
    </aside>
    <main class="main">
        <header class="topbar">
            <div class="hello">Olá, <strong><?php echo e($nomeUsuario); ?></strong><div class="avatar"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="5"/><path d="M20 21a8 8 0 0 0-16 0"/></svg></div></div>
        </header>
        <section class="content">
            <div class="page-title">
                <div class="title-icon"><svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M12 18v-6"/><path d="M9 15h6"/></svg></div>
                <div>
                    <h1>Nova Entrega de Equipamentos</h1>
                    <p>Preencha os dados abaixo para registrar uma entrega ou troca de equipamentos.</p>
                </div>
            </div>

            <form method="post" action="dashboard.php" id="formEntrega">
                <input type="hidden" name="acao" value="confirmar_movimentacao">
                <input type="hidden" name="movimentacao_token" value="<?php echo e((string) $_SESSION['movimentacao_token']); ?>">
                <section class="panel">
                    <div class="form-card">
                        <div class="form-row delivery">
                            <label>Loja
                                <select name="loja_id" required>
                                    <option value="">Selecione a loja</option>
                                    <?php foreach ($lojas as $loja): ?>
                                        <option value="<?php echo (int) $loja['id']; ?>" <?php echo $selectedLoja === (int) $loja['id'] ? 'selected' : ''; ?>><?php echo e($loja['nome']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label>Setor
                                <select name="setor_id" required>
                                    <option value="">Selecione o setor</option>
                                    <?php foreach ($setores as $setor): ?>
                                        <option value="<?php echo (int) $setor['id']; ?>" <?php echo $selectedSetor === (int) $setor['id'] ? 'selected' : ''; ?>><?php echo e($setor['nome']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label>Nome do Solicitante
                                <input type="text" name="solicitante" value="<?php echo e($_POST['solicitante'] ?? ''); ?>" placeholder="Digite o nome do solicitante" required>
                            </label>
                            <label>Data da Entrega
                                <input type="date" name="data_entrega" value="<?php echo e($dataPadrao); ?>" required>
                            </label>
                        </div>

                        <div class="equipment-kicker">Selecione seu equipamento</div>
                        <div class="form-row equipment">
                            <label>Equipamento
                                <select name="estoque_id" id="estoqueSelect" required>
                                    <option value="" data-qtd="0">Selecione o equipamento</option>
                                    <?php foreach ($equipamentos as $item): ?>
                                        <option value="<?php echo (int) $item['id']; ?>" data-qtd="<?php echo (int) $item['quantidade']; ?>" <?php echo $selectedEstoque === (int) $item['id'] ? 'selected' : ''; ?>>
                                            <?php echo e($item['equipamento']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <span class="stock-note" id="stockNote">Disponível em estoque: 0</span>
                            </label>
                            <div class="type-group">
                                <span>Tipo da movimentação</span>
                                <div class="type-options">
                                    <label class="choice entrega">
                                        <input type="radio" name="tipo" value="ENTREGA" <?php echo $selectedTipo === 'ENTREGA' ? 'checked' : ''; ?> required>
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/><path d="m3.3 7 8.7 5 8.7-5"/><path d="M12 22V12"/></svg>
                                        Entrega
                                    </label>
                                    <label class="choice troca">
                                        <input type="radio" name="tipo" value="TROCA" <?php echo $selectedTipo === 'TROCA' ? 'checked' : ''; ?> required>
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12a9 9 0 0 0-15-6.7L3 8"/><path d="M3 3v5h5"/><path d="M3 12a9 9 0 0 0 15 6.7l3-2.7"/><path d="M16 16h5v5"/></svg>
                                        Troca
                                    </label>
                                </div>
                            </div>
                        </div>
                        <div class="form-row quantity">
                            <label class="quantity-field">Qtd.
                                <select name="quantidade" id="quantidadeSelect" required>
                                    <?php for ($i = 1; $i <= 10; $i++): ?>
                                        <option value="<?php echo $i; ?>" <?php echo (int) ($_POST['quantidade'] ?? 1) === $i ? 'selected' : ''; ?>><?php echo $i; ?></option>
                                    <?php endfor; ?>
                                </select>
                            </label>
                        </div>
                    </div>
                </section>

                <section class="panel">
                    <h2>Justificativa da Movimentação</h2>
                    <p>Informe o motivo da movimentação.</p>
                    <label class="textarea-wrap">
                        <textarea name="justificativa" id="justificativa" maxlength="1000" required placeholder="Descreva o problema e o motivo da entrega ou troca..."><?php echo e($_POST['justificativa'] ?? ''); ?></textarea>
                        <span class="counter" id="contador">0/1000</span>
                    </label>
                    <div class="form-footer">
                        <div>
                            <?php if ($sucesso !== ''): ?><div class="alert ok flash-message"><?php echo e($sucesso); ?></div><?php endif; ?>
                            <?php foreach ($erros as $erro): ?><div class="alert"><?php echo e($erro); ?></div><?php endforeach; ?>
                        </div>
                        <button class="submit" type="submit">Confirmar</button>
                    </div>
                </section>
            </form>
        </section>
    </main>
</div>
<script>
    const estoqueSelect = document.getElementById('estoqueSelect');
    const quantidadeSelect = document.getElementById('quantidadeSelect');
    const stockNote = document.getElementById('stockNote');
    const justificativa = document.getElementById('justificativa');
    const contador = document.getElementById('contador');
    const form = document.getElementById('formEntrega');

    function aplicarQuantidadeDisponivel(disponivel) {
        stockNote.textContent = `Disponível em estoque: ${disponivel}`;
        quantidadeSelect.querySelectorAll('option').forEach((option) => {
            option.disabled = Number(option.value) > disponivel;
        });

        if (Number(quantidadeSelect.value || 0) > disponivel) {
            quantidadeSelect.value = Math.max(1, Math.min(10, disponivel)).toString();
        }
    }

    async function atualizarEstoque() {
        const selected = estoqueSelect.options[estoqueSelect.selectedIndex];
        const estoqueId = Number(estoqueSelect.value || 0);
        aplicarQuantidadeDisponivel(Number(selected?.dataset.qtd || 0));

        if (!estoqueId) {
            return;
        }

        try {
            const response = await fetch(`dashboard.php?ajax=estoque&id=${estoqueId}`, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });
            const data = await response.json();
            const disponivel = Number(data.quantidade || 0);
            selected.dataset.qtd = disponivel;
            aplicarQuantidadeDisponivel(disponivel);
        } catch (error) {
            aplicarQuantidadeDisponivel(Number(selected?.dataset.qtd || 0));
        }
    }

    function atualizarContador() {
        contador.textContent = `${justificativa.value.length}/1000`;
    }

    estoqueSelect.addEventListener('change', atualizarEstoque);
    quantidadeSelect.addEventListener('change', atualizarEstoque);
    justificativa.addEventListener('input', atualizarContador);
    form.addEventListener('submit', (event) => {
        const selected = estoqueSelect.options[estoqueSelect.selectedIndex];
        const disponivel = Number(selected?.dataset.qtd || 0);
        const quantidade = Number(quantidadeSelect.value || 0);
        if (quantidade > disponivel) {
            event.preventDefault();
            alert('Quantidade indisponível em estoque.');
        }
    });
    document.querySelectorAll('.flash-message').forEach((message) => {
        setTimeout(() => {
            message.classList.add('hiding');
            setTimeout(() => message.remove(), 320);
        }, 3600);
    });
    document.addEventListener('click', (event) => {
        const link = event.target.closest('a[href]');
        if (!link || event.defaultPrevented || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
        if (link.target && link.target !== '_self') return;
        const href = link.getAttribute('href');
        if (!href || href.startsWith('#') || href.startsWith('javascript:')) return;
        event.preventDefault();
        document.body.classList.add('page-leaving');
        setTimeout(() => { window.location.href = link.href; }, 180);
    });
    atualizarEstoque();
    atualizarContador();
</script>
</body>
</html>
