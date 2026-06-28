<?php
require_once __DIR__ . '/admin_helpers.php';
require_once __DIR__ . '/../includes/serial_control.php';

$pdo = getConnection();
$mensagem = null;
$erro = null;

function buscarProdutoPorNome(PDO $pdo, string $nome): ?array
{
    $stmt = $pdo->prepare('SELECT id, nome, controla_serial FROM produtos WHERE nome = :nome LIMIT 1');
    $stmt->execute([':nome' => $nome]);
    $produto = $stmt->fetch();

    return $produto ?: null;
}

function parseLojaIdsEstoque(mixed $value): array
{
    $parts = is_array($value) ? $value : (preg_split('/,/', (string) $value) ?: []);

    return array_values(array_unique(array_filter(array_map('intval', $parts), static fn(int $id): bool => $id > 0)));
}

function registrarAtividadeEstoque(PDO $pdo, int $produtoId, string $equipamento, int $variacao): void
{
    if ($variacao === 0) {
        return;
    }

    $usuarioId = (int) ($_SESSION['id_usuario'] ?? 0);
    if ($usuarioId <= 0) {
        return;
    }

    $tipo = $variacao > 0 ? 'Entrada' : 'Saida';
    $quantidade = abs($variacao);
    $stmt = $pdo->prepare('
        INSERT INTO movimentacoes (tipo, produto_id, quantidade, status, usuario_id, descricao, data_movimentacao)
        VALUES (:tipo, :produto_id, :quantidade, :status, :usuario_id, :descricao, NOW())
    ');
    $stmt->execute([
        ':tipo' => $tipo,
        ':produto_id' => $produtoId,
        ':quantidade' => $quantidade,
        ':status' => 'Concluida',
        ':usuario_id' => $usuarioId,
        ':descricao' => ($variacao > 0 ? 'Entrada' : 'Saída') . ' de estoque: ' . $equipamento,
    ]);
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfRequireValid();
    $acao = $_POST['acao'] ?? '';

    try {
        if ($acao === 'salvar') {
            $estoqueId = (int) ($_POST['estoque_id'] ?? 0);
            $editandoEquipamento = $estoqueId > 0;
            $nome = trim((string) ($_POST['nome'] ?? ''));
            $quantidade = max(0, (int) ($_POST['quantidade'] ?? 0));
            $quantidadeAnterior = 0;

            if ($nome === '') {
                throw new RuntimeException('Informe o nome do equipamento.');
            }

            $pdo->beginTransaction();

            if ($estoqueId > 0) {
                $stmt = $pdo->prepare("
                    SELECT e.id, e.produto_id, e.quantidade, p.controla_serial
                    FROM estoque_equipamentos e
                    INNER JOIN produtos p ON p.id = e.produto_id
                    WHERE e.id = :id
                    LIMIT 1
                ");
                $stmt->execute([':id' => $estoqueId]);
                $estoque = $stmt->fetch();

                if (!$estoque) {
                    throw new RuntimeException('Registro de estoque não encontrado.');
                }
                $quantidadeAnterior = (int) $estoque['quantidade'];

                $stmtProduto = $pdo->prepare('UPDATE produtos SET nome = :nome WHERE id = :id');
                $stmtProduto->execute([
                    ':nome' => $nome,
                    ':id' => (int) $estoque['produto_id'],
                ]);

                if ((bool) $estoque['controla_serial']) {
                    $quantidade = serialControlSincronizarEstoque($pdo, (int) $estoque['produto_id']);
                } else {
                    $stmtEstoque = $pdo->prepare('UPDATE estoque_equipamentos SET quantidade = :quantidade WHERE id = :id');
                    $stmtEstoque->execute([
                        ':quantidade' => $quantidade,
                        ':id' => $estoqueId,
                    ]);
                }
            } else {
                $produto = buscarProdutoPorNome($pdo, $nome);

                if ($produto) {
                    $produtoId = (int) $produto['id'];
                } else {
                    $stmtProduto = $pdo->prepare('INSERT INTO produtos (nome, categoria, ativo) VALUES (:nome, :categoria, 1)');
                    $stmtProduto->execute([
                        ':nome' => $nome,
                        ':categoria' => 'Estoque',
                    ]);
                    $produtoId = (int) $pdo->lastInsertId();
                }

                $stmt = $pdo->prepare('SELECT id, quantidade FROM estoque_equipamentos WHERE produto_id = :produto_id AND loja_id IS NULL LIMIT 1');
                $stmt->execute([':produto_id' => $produtoId]);
                $estoqueExistente = $stmt->fetch();

                if ($estoqueExistente) {
                    $quantidadeAnterior = (int) $estoqueExistente['quantidade'];
                    $stmtEstoque = $pdo->prepare('UPDATE estoque_equipamentos SET quantidade = :quantidade WHERE id = :id');
                    $stmtEstoque->execute([
                        ':quantidade' => $quantidade,
                        ':id' => (int) $estoqueExistente['id'],
                    ]);
                } else {
                    $stmtEstoque = $pdo->prepare('INSERT INTO estoque_equipamentos (produto_id, loja_id, quantidade) VALUES (:produto_id, NULL, :quantidade)');
                    $stmtEstoque->execute([
                        ':produto_id' => $produtoId,
                        ':quantidade' => $quantidade,
                    ]);
                }
            }

            registrarAtividadeEstoque($pdo, $produtoId, $nome, $quantidade - $quantidadeAnterior);
            $pdo->commit();
            header('Location: estoque.php?salvo=1');
            exit;
        }

        if ($acao === 'novo_equipamento') {
            $estoqueId = (int) ($_POST['estoque_id'] ?? 0);
            $editandoEquipamento = $estoqueId > 0;
            $nome = trim((string) ($_POST['nome_equipamento'] ?? ''));
            $quantidade = max(0, (int) ($_POST['quantidade_equipamento'] ?? 0));
            $controlaSerial = (int) ($_POST['controla_serial'] ?? 0) === 1;
            $seriaisTexto = trim((string) ($_POST['seriais_equipamento'] ?? ''));

            if ($nome === '') {
                throw new RuntimeException('Informe o nome do equipamento.');
            }

            $pdo->beginTransaction();
            $estoque = null;
            $produto = null;
            if ($estoqueId > 0) {
                $stmt = $pdo->prepare("
                    SELECT e.id, e.produto_id, e.quantidade, p.nome, p.controla_serial
                    FROM estoque_equipamentos e
                    INNER JOIN produtos p ON p.id = e.produto_id
                    WHERE e.id = :id
                    LIMIT 1
                    FOR UPDATE
                ");
                $stmt->execute([':id' => $estoqueId]);
                $estoque = $stmt->fetch();
                if (!$estoque) {
                    throw new RuntimeException('Registro de estoque não encontrado.');
                }
                $produto = $estoque;
            } else {
                $produto = buscarProdutoPorNome($pdo, $nome);
            }

            $jaControlavaSerial = $produto ? (bool) $produto['controla_serial'] : false;
            if (!$editandoEquipamento && $jaControlavaSerial) {
                $controlaSerial = true;
            }
            if ($produto) {
                $produtoId = (int) ($produto['produto_id'] ?? $produto['id']);
                $stmtProduto = $pdo->prepare('UPDATE produtos SET nome = :nome, controla_serial = :controla_serial WHERE id = :id');
                $stmtProduto->execute([
                    ':nome' => $nome,
                    ':controla_serial' => $controlaSerial ? 1 : 0,
                    ':id' => $produtoId,
                ]);
            } else {
                $stmtProduto = $pdo->prepare('INSERT INTO produtos (nome, categoria, ativo, controla_serial) VALUES (:nome, :categoria, 1, :controla_serial)');
                $stmtProduto->execute([
                    ':nome' => $nome,
                    ':categoria' => 'Estoque',
                    ':controla_serial' => $controlaSerial ? 1 : 0,
                ]);
                $produtoId = (int) $pdo->lastInsertId();
            }

            if (!$estoque) {
                $stmtEstoque = $pdo->prepare('SELECT id, quantidade FROM estoque_equipamentos WHERE produto_id = :produto_id AND loja_id IS NULL LIMIT 1');
                $stmtEstoque->execute([':produto_id' => $produtoId]);
                $estoque = $stmtEstoque->fetch();
            }
            $quantidadeAnterior = (int) ($estoque['quantidade'] ?? 0);
            if ($estoque) {
                $quantidadeFinal = $quantidadeAnterior;
                if (!$controlaSerial) {
                    $quantidadeFinal = $editandoEquipamento ? $quantidade : $quantidadeAnterior + $quantidade;
                }
                $stmtAtualizar = $pdo->prepare('UPDATE estoque_equipamentos SET quantidade = :quantidade WHERE id = :id');
                $stmtAtualizar->execute([
                    ':quantidade' => $quantidadeFinal,
                    ':id' => (int) $estoque['id'],
                ]);
                $quantidade = $quantidadeFinal;
            } else {
                $stmtInserir = $pdo->prepare('INSERT INTO estoque_equipamentos (produto_id, loja_id, quantidade) VALUES (:produto_id, NULL, :quantidade)');
                $stmtInserir->execute([
                    ':produto_id' => $produtoId,
                    ':quantidade' => $controlaSerial ? 0 : $quantidade,
                ]);
                $estoqueId = (int) $pdo->lastInsertId();
            }

            if ($controlaSerial) {
                $seriaisDesejados = $seriaisTexto !== ''
                    ? serialControlNormalizarLista($seriaisTexto)
                    : ($estoqueId > 0 ? [] : array_fill(0, max(1, $quantidade), 'N/A'));
                $stmtExistentes = $pdo->prepare('
                    SELECT id_serial, id_item, serial, status
                    FROM equipamento_seriais
                    WHERE id_equipamento = :produto_id
                    ORDER BY data_cadastro, id_serial
                    FOR UPDATE
                ');
                $stmtExistentes->execute([':produto_id' => $produtoId]);
                $seriaisExistentes = $stmtExistentes->fetchAll();

                foreach ($seriaisDesejados as $indice => $serialDesejado) {
                    if (!isset($seriaisExistentes[$indice])) {
                        serialControlCadastrar($pdo, $produtoId, $nome, [$serialDesejado]);
                        continue;
                    }
                    $serialAtual = $seriaisExistentes[$indice];
                    if (mb_strtoupper(trim((string) $serialAtual['serial']), 'UTF-8') === mb_strtoupper(trim($serialDesejado), 'UTF-8')) {
                        continue;
                    }
                    if (strtoupper(trim($serialDesejado)) !== 'N/A') {
                        $stmtDuplicado = $pdo->prepare('SELECT id_serial FROM equipamento_seriais WHERE serial_unico = UPPER(TRIM(:serial)) AND id_serial <> :id_serial LIMIT 1');
                        $stmtDuplicado->execute([':serial' => $serialDesejado, ':id_serial' => (int) $serialAtual['id_serial']]);
                        if ($stmtDuplicado->fetchColumn()) {
                            throw new RuntimeException('O serial ' . $serialDesejado . ' já está cadastrado.');
                        }
                    }
                    $stmtSerial = $pdo->prepare('UPDATE equipamento_seriais SET serial = :serial WHERE id_serial = :id_serial');
                    $stmtSerial->execute([':serial' => $serialDesejado, ':id_serial' => (int) $serialAtual['id_serial']]);
                }

                if (count($seriaisDesejados) < count($seriaisExistentes)) {
                    $stmtUso = $pdo->prepare('
                        SELECT
                            (SELECT COUNT(*) FROM movimentacoes WHERE id_serial = :id_mov) +
                            (SELECT COUNT(*) FROM manutencoes WHERE id_serial = :id_man) AS total
                    ');
                    $stmtExcluirSerial = $pdo->prepare('DELETE FROM equipamento_seriais WHERE id_serial = :id_serial');
                    foreach (array_slice($seriaisExistentes, count($seriaisDesejados)) as $serialRemovido) {
                        $stmtUso->execute([
                            ':id_mov' => (int) $serialRemovido['id_serial'],
                            ':id_man' => (int) $serialRemovido['id_serial'],
                        ]);
                        if (strtoupper((string) $serialRemovido['status']) !== 'DISPONIVEL' || (int) $stmtUso->fetchColumn() > 0) {
                            throw new RuntimeException('Não é possível remover um serial indisponível ou com histórico de movimentação.');
                        }
                        $stmtExcluirSerial->execute([':id_serial' => (int) $serialRemovido['id_serial']]);
                    }
                }

                if (!$jaControlavaSerial && !$seriaisExistentes && !$seriaisDesejados) {
                    serialControlCadastrar($pdo, $produtoId, $nome, array_fill(0, max(1, $quantidadeAnterior), 'N/A'));
                }
                $quantidade = serialControlSincronizarEstoque($pdo, $produtoId);
            }

            registrarAtividadeEstoque($pdo, $produtoId, $nome, $quantidade - $quantidadeAnterior);
            $pdo->commit();
            header('Location: estoque.php?salvo=1');
            exit;
        }

        if ($acao === 'excluir') {
            $estoqueId = (int) ($_POST['estoque_id'] ?? 0);
            $stmt = $pdo->prepare('DELETE FROM estoque_equipamentos WHERE id = :id');
            $stmt->execute([':id' => $estoqueId]);
            header('Location: estoque.php?excluido=1');
            exit;
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Erro no controle de estoque: ' . $e->getMessage());
        $erro = $e instanceof RuntimeException
            ? $e->getMessage()
            : 'Não foi possível concluir a operação. Tente novamente.';
    }
}

if (isset($_GET['salvo'])) {
    $mensagem = 'Estoque salvo com sucesso.';
}
if (isset($_GET['excluido'])) {
    $mensagem = 'Registro excluído com sucesso.';
}

$busca = trim((string) ($_GET['busca'] ?? ''));
$somenteCritico = (string) ($_GET['critico'] ?? '') === '1';
$lojaIds = parseLojaIdsEstoque($_GET['lojas'] ?? '');
$params = [];
$whereParts = [];

if ($busca !== '') {
    $whereParts[] = 'p.nome LIKE :busca';
    $params[':busca'] = '%' . $busca . '%';
}

if ($somenteCritico && $busca === '') {
    $whereParts[] = 'e.quantidade <= :critico';
    $params[':critico'] = 3;
}

if (!empty($lojaIds)) {
    $placeholders = [];
    foreach ($lojaIds as $index => $id) {
        $key = ":loja_id_{$index}";
        $placeholders[] = $key;
        $params[$key] = $id;
    }
    $whereParts[] = 'e.loja_id IN (' . implode(', ', $placeholders) . ')';
}

$where = $whereParts ? 'WHERE ' . implode(' AND ', $whereParts) : '';
$limitesPermitidos = [5, 10, 20, 30, 50];
$porPagina = (int) ($_GET['limite'] ?? 5);
$porPagina = in_array($porPagina, $limitesPermitidos, true) ? $porPagina : 5;
$pagina = max(1, (int) ($_GET['pagina'] ?? 1));
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM estoque_equipamentos e INNER JOIN produtos p ON p.id = e.produto_id {$where}");
foreach ($params as $key => $value) {
    $countStmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
}
$countStmt->execute();
$totalRegistros = (int) $countStmt->fetchColumn();
$totalPaginas = max(1, (int) ceil($totalRegistros / $porPagina));
$pagina = min($pagina, $totalPaginas);
$offset = ($pagina - 1) * $porPagina;

$stmt = $pdo->prepare("
    SELECT
        e.id,
        e.produto_id,
        p.nome,
        p.controla_serial,
        CASE
            WHEN p.controla_serial = 1 THEN (
                SELECT COUNT(*)
                FROM equipamento_seriais esq
                WHERE esq.id_equipamento = p.id
                  AND esq.status = 'DISPONIVEL'
            )
            ELSE e.quantidade
        END AS quantidade,
        e.data_atualizacao
    FROM estoque_equipamentos e
    INNER JOIN produtos p ON p.id = e.produto_id
    {$where}
    ORDER BY p.nome
    LIMIT :limite OFFSET :offset
");
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
}
$stmt->bindValue(':limite', $porPagina, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$estoques = $stmt->fetchAll();

$seriaisPorProduto = [];
$stmtSeriais = $pdo->query("
    SELECT
        es.id_equipamento,
        es.serial,
        es.status
    FROM equipamento_seriais es
    ORDER BY es.id_equipamento, es.data_cadastro, es.id_serial
");
foreach ($stmtSeriais->fetchAll() as $serialRow) {
    $seriaisPorProduto[(int) $serialRow['id_equipamento']][] = $serialRow;
}

adminPageStart('Controle de Estoque');
?>
<style>
    .stock-summary {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 14px;
        padding: 20px;
        border-bottom: 1px solid rgba(255, 255, 255, .08);
    }
    .stock-summary strong { display: block; font-size: 15px; }
    .stock-summary span { color: var(--muted); font-size: 13px; }
    .top { margin-bottom: 24px; }
    .panel { margin-bottom: 18px; }
    .stock-form {
        grid-template-columns: repeat(12, minmax(0, 1fr));
        gap: 12px;
        padding: 20px;
        align-items: end;
    }
    .stock-form > label:first-of-type { grid-column: span 7; }
    .stock-form > label:nth-of-type(2) { grid-column: span 2; }
    .stock-form .form-actions { grid-column: span 3; }
    .stock-search {
        grid-template-columns: minmax(260px, 1fr) auto minmax(200px, auto);
        gap: 12px;
        padding: 20px;
        align-items: end;
    }
    .stock-form > *,
    .stock-search > * { min-width: 0; }
    .stock-form input,
    .stock-form select,
    .stock-search input,
    .stock-search select {
        width: 100%;
        height: 38px;
    }
    .form-actions,
    .filter-actions {
        display: grid;
        grid-auto-flow: column;
        grid-auto-columns: minmax(0, 1fr);
        gap: 10px;
        align-items: end;
    }
    .stock-form .btn,
    .stock-search .btn,
    .stock-actions .btn {
        width: 100%;
        min-height: 38px;
        white-space: nowrap;
    }
    .stock-actions {
        display: flex;
        align-items: center;
        gap: 10px;
        flex-wrap: nowrap;
    }
    .stock-actions form { margin: 0; }
    .stock-status {
        display: inline-flex;
        min-height: 28px;
        align-items: center;
        padding: 0 10px;
        border-radius: 6px;
        background: rgba(39, 184, 77, .16);
        color: #bdf5c9;
        font-weight: 800;
        font-size: 12px;
    }
    .stock-status.critical { background: rgba(245, 179, 1, .16); color: #ffd36a; }
    .stock-modal { position: fixed; inset: 0; z-index: 1200; display: none; place-items: center; padding: 24px; background: rgba(0, 0, 0, .72); backdrop-filter: blur(4px); }
    .stock-modal.open { display: grid; }
    .stock-modal-dialog { width: min(520px, 100%); overflow: hidden; border: 1px solid var(--line); border-radius: 10px; background: #11171f; box-shadow: 0 24px 70px rgba(0, 0, 0, .52); }
    .stock-modal-header, .stock-modal-footer { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 16px 18px; }
    .stock-modal-header { border-bottom: 1px solid var(--line); }
    .stock-modal-header h2 { margin: 0; font-size: 17px; }
    .stock-modal-body { display: grid; gap: 16px; padding: 20px 18px; }
    .stock-modal-body input { width: 100%; height: 40px; }
    .stock-modal-body textarea {
        width: 100%;
        min-height: 110px;
        padding: 10px 12px;
        border: 1px solid var(--line);
        border-radius: var(--radius);
        background: #10151c;
        color: #fff;
        font: inherit;
        resize: vertical;
    }
    .serial-choice { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; }
    .serial-choice label { display: flex; align-items: center; gap: 8px; min-height: 40px; padding: 0 12px; border: 1px solid var(--line); border-radius: var(--radius); }
    .serial-choice input { width: auto; height: auto; }
    .serial-list { margin: 0; padding: 0; list-style: none; display: grid; gap: 7px; }
    .serial-list li { display: flex; justify-content: space-between; gap: 12px; padding: 8px 10px; border-radius: 6px; background: rgba(255,255,255,.04); }
    .serial-list small { color: var(--muted); }
    .serial-details summary { cursor: pointer; list-style: none; }
    .serial-details summary::-webkit-details-marker { display: none; }
    .serial-details[open] summary { margin-bottom: 10px; }
    .stock-modal-footer { justify-content: flex-end; border-top: 1px solid var(--line); }
    .serial-modal-table th, .serial-modal-table td { text-align: left; }
    .table-wrap,
    table { width: 100%; }
    th,
    td { padding: 14px 18px; vertical-align: middle; }
    .empty { padding: 18px; }
    .pagination {
        display: flex;
        align-items: center;
        justify-content: flex-end;
        gap: 6px;
        min-height: 72px;
        padding: 16px 18px;
        border-top: 1px solid rgba(255, 255, 255, .08);
    }
    .pagination a,
    .pagination span {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 36px;
        height: 36px;
        padding: 0 11px;
        border: 1px solid var(--line);
        border-radius: 8px;
        color: var(--muted);
        font-size: 13px;
        font-weight: 700;
        line-height: 1;
        white-space: nowrap;
        transition: background .2s ease, border-color .2s ease, color .2s ease;
    }
    .pagination a:hover { background: rgba(255, 255, 255, .06); border-color: rgba(255, 255, 255, .18); color: #fff; }
    .pagination .active { background: var(--red); border-color: var(--red); color: #fff; }
    .pagination .disabled { cursor: not-allowed; opacity: .38; }
    .pagination .ellipsis { min-width: 28px; padding: 0 4px; border-color: transparent; }
    @media (max-width: 900px) {
        .stock-form > label:first-of-type { grid-column: span 7; }
        .stock-form > label:nth-of-type(2) { grid-column: span 5; }
        .stock-form .form-actions { grid-column: 1 / -1; }
    }
    @media (max-width: 720px) {
        .stock-form > label,
        .stock-form .form-actions { grid-column: 1 / -1; }
        .stock-search { grid-template-columns: 1fr; }
        .form-actions,
        .filter-actions { grid-auto-flow: row; grid-template-columns: 1fr; }
        .stock-actions { flex-direction: column; align-items: stretch; }
        .stock-summary { align-items: stretch; flex-direction: column; }
        .pagination { justify-content: flex-end; flex-wrap: wrap; }
    }
</style>
<section class="top">
    <div>
        <h1>Controle de Estoque</h1>
        <p>Cadastro e consulta de equipamentos em estoque.</p>
    </div>
    <button class="btn primary" id="openNewEquipmentModal" type="button">Novo Equipamento</button>
</section>

<?php if ($mensagem): ?>
    <section class="panel"><div class="empty"><?php echo e($mensagem); ?></div></section>
<?php endif; ?>
<?php if ($erro): ?>
    <section class="panel"><div class="empty"><?php echo e($erro); ?></div></section>
<?php endif; ?>

<form class="panel filters stock-search" method="GET">
    <?php if ($somenteCritico): ?>
        <input type="hidden" name="critico" value="1">
    <?php endif; ?>
    <?php if (!empty($lojaIds)): ?>
        <input type="hidden" name="lojas" value="<?php echo e(implode(',', $lojaIds)); ?>">
    <?php endif; ?>
    <label>Pesquisar equipamento
        <input type="text" name="busca" value="<?php echo e($busca); ?>" placeholder="Nome do equipamento">
    </label>
    <label class="limit-control" aria-label="Quantidade de registros"><span class="filter-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 5h18l-7 8v5l-4 2v-7L3 5Z"/></svg></span>
        <select name="limite" onchange="this.form.submit()">
            <?php foreach ($limitesPermitidos as $limite): ?>
                <option value="<?php echo $limite; ?>" <?php echo $porPagina === $limite ? 'selected' : ''; ?>><?php echo $limite; ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <div class="filter-actions">
        <button class="btn primary" type="submit">Pesquisar</button>
        <a class="btn" href="<?php echo $somenteCritico ? 'estoque.php?critico=1' : 'estoque.php'; ?>">Limpar</a>
    </div>
</form>

<section class="panel">
    <div class="stock-summary">
        <div>
            <strong><?php echo $somenteCritico && $busca === '' ? 'Itens com estoque crítico' : 'Consulta de estoque'; ?></strong>
            <span><?php echo $busca !== '' ? 'Resultado da pesquisa em todos os equipamentos cadastrados.' : 'Estoque crítico: quantidade menor ou igual a 3 unidades.'; ?></span>
        </div>
        <a class="btn" href="estoque.php?critico=1">Ver críticos</a>
    </div>
    <?php if (empty($estoques)): ?>
        <div class="empty">Nenhum equipamento encontrado.</div>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Nome do equipamento</th>
                        <th>Quantidade em estoque</th>
                        <th>Serial</th>
                        <th>Data de cadastro</th>
                        <th>Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($estoques as $row): ?>
                        <tr>
                            <td><?php echo e($row['nome']); ?></td>
                            <td><?php echo (int) $row['quantidade']; ?></td>
                            <td>
                                <?php if (!empty($row['controla_serial'])): ?>
                                    <?php
                                    $seriaisProduto = $seriaisPorProduto[(int) $row['produto_id']] ?? [];
                                    $seriaisModal = array_map(static fn(array $serial): array => [
                                        'serial' => (string) $serial['serial'],
                                        'status' => strtoupper((string) $serial['status']) === 'DISPONIVEL' ? 'Disponível' : 'Indisponível',
                                    ], $seriaisProduto);
                                    ?>
                                    <button
                                        class="btn js-view-serials"
                                        type="button"
                                        data-equipment="<?php echo e($row['nome']); ?>"
                                        data-serials="<?php echo e(json_encode($seriaisModal, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)); ?>"
                                    >Ver Seriais</button>
                                <?php else: ?>
                                    N/A
                                <?php endif; ?>
                            </td>
                            <td><?php echo e(date('d/m/Y H:i', strtotime((string) $row['data_atualizacao']))); ?></td>
                            <td>
                                <div class="stock-actions">
                                <button
                                    class="btn js-edit-equipment"
                                    type="button"
                                    data-stock-id="<?php echo (int) $row['id']; ?>"
                                    data-name="<?php echo e($row['nome']); ?>"
                                    data-controls-serial="<?php echo (int) $row['controla_serial']; ?>"
                                    data-quantity="<?php echo (int) $row['quantidade']; ?>"
                                    data-serials="<?php echo e(implode("\n", array_column($seriaisPorProduto[(int) $row['produto_id']] ?? [], 'serial'))); ?>"
                                >Editar</button>
                                <form method="POST" style="display:inline;" onsubmit="return confirm('Confirmar exclusão deste item do estoque?');">
                                    <?php echo csrfInput(); ?>
                                    <input type="hidden" name="acao" value="excluir">
                                    <input type="hidden" name="estoque_id" value="<?php echo (int) $row['id']; ?>">
                                    <button class="btn" type="submit">Excluir</button>
                                </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
    <?php if ($totalPaginas > 1): ?>
        <?php
        if ($totalPaginas <= 7) {
            $paginasVisiveis = range(1, $totalPaginas);
        } elseif ($pagina <= 4) {
            $paginasVisiveis = [1, 2, 3, 4, 5, '...', $totalPaginas];
        } elseif ($pagina >= $totalPaginas - 3) {
            $paginasVisiveis = [1, '...', $totalPaginas - 4, $totalPaginas - 3, $totalPaginas - 2, $totalPaginas - 1, $totalPaginas];
        } else {
            $paginasVisiveis = [1, '...', $pagina - 1, $pagina, $pagina + 1, '...', $totalPaginas];
        }
        ?>
        <nav class="pagination" aria-label="Paginação">
            <?php if ($pagina > 1): ?>
                <a href="<?php echo e(pageUrl(['pagina' => $pagina - 1])); ?>" rel="prev">◀ Anterior</a>
            <?php else: ?>
                <span class="disabled" aria-disabled="true">◀ Anterior</span>
            <?php endif; ?>
            <?php foreach ($paginasVisiveis as $itemPagina): ?>
                <?php if ($itemPagina === '...'): ?>
                    <span class="ellipsis" aria-hidden="true">...</span>
                <?php elseif ($itemPagina === $pagina): ?>
                    <span class="active" aria-current="page"><?php echo $itemPagina; ?></span>
                <?php else: ?>
                    <a href="<?php echo e(pageUrl(['pagina' => $itemPagina])); ?>"><?php echo $itemPagina; ?></a>
                <?php endif; ?>
            <?php endforeach; ?>
            <?php if ($pagina < $totalPaginas): ?>
                <a href="<?php echo e(pageUrl(['pagina' => $pagina + 1])); ?>" rel="next">Próximo ▶</a>
            <?php else: ?>
                <span class="disabled" aria-disabled="true">Próximo ▶</span>
            <?php endif; ?>
        </nav>
    <?php endif; ?>
</section>
<div class="stock-modal" id="newEquipmentModal" aria-hidden="true">
    <form class="stock-modal-dialog" method="POST" role="dialog" aria-modal="true" aria-labelledby="newEquipmentModalTitle">
        <?php echo csrfInput(); ?>
        <input type="hidden" name="acao" value="novo_equipamento">
        <input type="hidden" name="estoque_id" value="0">
        <div class="stock-modal-header"><h2 id="newEquipmentModalTitle">Novo Equipamento</h2><button class="btn js-close-equipment-modal" type="button">Fechar</button></div>
        <div class="stock-modal-body">
            <label>Nome do equipamento<input type="text" name="nome_equipamento" required autocomplete="off"></label>
            <div>
                <span>Controla Serial?</span>
                <div class="serial-choice">
                    <label><input type="radio" name="controla_serial" value="1"> Sim</label>
                    <label><input type="radio" name="controla_serial" value="0" checked> Não</label>
                </div>
            </div>
            <label id="quantityEquipmentField">Quantidade<input type="number" name="quantidade_equipamento" min="0" required value="1"></label>
            <label id="serialsEquipmentField" hidden>Seriais
                <textarea name="seriais_equipamento" placeholder="Um serial por linha. Se ficar vazio, será gravado N/A."></textarea>
                <small>A quantidade será calculada automaticamente pelos seriais cadastrados.</small>
            </label>
        </div>
        <div class="stock-modal-footer"><button class="btn js-close-equipment-modal" type="button">Cancelar</button><button class="btn primary" type="submit">Salvar</button></div>
    </form>
</div>
<div class="stock-modal" id="serialsModal" aria-hidden="true">
    <div class="stock-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="serialsModalTitle">
        <div class="stock-modal-header"><h2 id="serialsModalTitle">Seriais</h2><button class="btn js-close-serials-modal" type="button">Fechar</button></div>
        <div class="stock-modal-body">
            <div class="table-wrap">
                <table class="serial-modal-table">
                    <thead><tr><th>Serial</th><th>Status</th></tr></thead>
                    <tbody id="serialsModalBody"></tbody>
                </table>
            </div>
        </div>
        <div class="stock-modal-footer"><button class="btn primary js-close-serials-modal" type="button">Fechar</button></div>
    </div>
</div>
<script>
(() => {
    const escapeHtml = (value) => {
        const node = document.createElement('div');
        node.textContent = String(value ?? '');
        return node.innerHTML;
    };
    const modal = document.getElementById('newEquipmentModal');
    const openButton = document.getElementById('openNewEquipmentModal');
    if (!modal || !openButton) return;

    const title = document.getElementById('newEquipmentModalTitle');
    const stockIdInput = modal.querySelector('input[name="estoque_id"]');
    const nameInput = modal.querySelector('input[name="nome_equipamento"]');
    const quantityField = document.getElementById('quantityEquipmentField');
    const serialsField = document.getElementById('serialsEquipmentField');
    const quantityInput = quantityField?.querySelector('input');
    const serialsInput = serialsField?.querySelector('textarea');

    const updateSerialMode = () => {
        const controlsSerial = modal.querySelector('input[name="controla_serial"]:checked')?.value === '1';
        serialsField.hidden = !controlsSerial;
        quantityInput.readOnly = controlsSerial;
        if (controlsSerial) {
            const informed = (serialsInput.value || '').split(/[\n,;]+/).map((value) => value.trim()).filter(Boolean);
            quantityInput.value = informed.length;
        }
    };
    const closeModal = () => {
        modal.classList.remove('open');
        modal.setAttribute('aria-hidden', 'true');
    };
    const openModal = (values = null) => {
        const editing = values !== null;
        title.textContent = editing ? 'Editar Equipamento' : 'Novo Equipamento';
        stockIdInput.value = editing ? values.stockId : '0';
        nameInput.value = editing ? values.name : '';
        quantityInput.value = editing ? values.quantity : '1';
        serialsInput.value = editing ? values.serials : '';
        const serialValue = editing && values.controlsSerial ? '1' : '0';
        modal.querySelector(`input[name="controla_serial"][value="${serialValue}"]`).checked = true;
        updateSerialMode();
        modal.classList.add('open');
        modal.setAttribute('aria-hidden', 'false');
        nameInput.focus();
    };

    openButton.addEventListener('click', () => openModal());
    document.querySelectorAll('.js-edit-equipment').forEach((button) => button.addEventListener('click', () => openModal({
        stockId: button.dataset.stockId || '0',
        name: button.dataset.name || '',
        controlsSerial: button.dataset.controlsSerial === '1',
        quantity: button.dataset.quantity || '0',
        serials: button.dataset.serials || '',
    })));
    modal.querySelectorAll('.js-close-equipment-modal').forEach((button) => button.addEventListener('click', closeModal));
    modal.querySelectorAll('input[name="controla_serial"]').forEach((input) => input.addEventListener('change', updateSerialMode));
    serialsInput?.addEventListener('input', updateSerialMode);
    modal.addEventListener('click', (event) => { if (event.target === modal) closeModal(); });

    const serialsModal = document.getElementById('serialsModal');
    const serialsBody = document.getElementById('serialsModalBody');
    const serialsTitle = document.getElementById('serialsModalTitle');
    const closeSerialsModal = () => {
        serialsModal.classList.remove('open');
        serialsModal.setAttribute('aria-hidden', 'true');
    };
    document.querySelectorAll('.js-view-serials').forEach((button) => button.addEventListener('click', () => {
        let rows = [];
        try { rows = JSON.parse(button.dataset.serials || '[]'); } catch (error) { rows = []; }
        serialsTitle.textContent = `Seriais — ${button.dataset.equipment || 'Equipamento'}`;
        serialsBody.innerHTML = rows.length
            ? rows.map((row) => `<tr><td>${escapeHtml(row.serial || 'N/A')}</td><td>${escapeHtml(row.status || 'Indisponível')}</td></tr>`).join('')
            : '<tr><td colspan="2">Nenhum serial cadastrado.</td></tr>';
        serialsModal.classList.add('open');
        serialsModal.setAttribute('aria-hidden', 'false');
    }));
    serialsModal.querySelectorAll('.js-close-serials-modal').forEach((button) => button.addEventListener('click', closeSerialsModal));
    serialsModal.addEventListener('click', (event) => { if (event.target === serialsModal) closeSerialsModal(); });
    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape') return;
        if (serialsModal.classList.contains('open')) closeSerialsModal();
        else if (modal.classList.contains('open')) closeModal();
    });
})();
</script>
<?php adminPageEnd(); ?>
