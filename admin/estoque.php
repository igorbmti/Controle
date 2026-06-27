<?php
require_once __DIR__ . '/admin_helpers.php';

$pdo = getConnection();
$mensagem = null;
$erro = null;

function buscarProdutoPorNome(PDO $pdo, string $nome): ?array
{
    $stmt = $pdo->prepare('SELECT id, nome FROM produtos WHERE nome = :nome LIMIT 1');
    $stmt->execute([':nome' => $nome]);
    $produto = $stmt->fetch();

    return $produto ?: null;
}

function parseLojaIdsEstoque(mixed $value): array
{
    $parts = is_array($value) ? $value : (preg_split('/,/', (string) $value) ?: []);

    return array_values(array_unique(array_filter(array_map('intval', $parts), static fn(int $id): bool => $id > 0)));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = $_POST['acao'] ?? '';

    try {
        if ($acao === 'salvar') {
            $estoqueId = (int) ($_POST['estoque_id'] ?? 0);
            $nome = trim((string) ($_POST['nome'] ?? ''));
            $quantidade = max(0, (int) ($_POST['quantidade'] ?? 0));

            if ($nome === '') {
                throw new RuntimeException('Informe o nome do equipamento.');
            }

            $pdo->beginTransaction();

            if ($estoqueId > 0) {
                $stmt = $pdo->prepare("
                    SELECT e.id, e.produto_id
                    FROM estoque_equipamentos e
                    WHERE e.id = :id
                    LIMIT 1
                ");
                $stmt->execute([':id' => $estoqueId]);
                $estoque = $stmt->fetch();

                if (!$estoque) {
                    throw new RuntimeException('Registro de estoque não encontrado.');
                }

                $stmtProduto = $pdo->prepare('UPDATE produtos SET nome = :nome WHERE id = :id');
                $stmtProduto->execute([
                    ':nome' => $nome,
                    ':id' => (int) $estoque['produto_id'],
                ]);

                $stmtEstoque = $pdo->prepare('UPDATE estoque_equipamentos SET quantidade = :quantidade WHERE id = :id');
                $stmtEstoque->execute([
                    ':quantidade' => $quantidade,
                    ':id' => $estoqueId,
                ]);
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

                $stmt = $pdo->prepare('SELECT id FROM estoque_equipamentos WHERE produto_id = :produto_id AND loja_id IS NULL LIMIT 1');
                $stmt->execute([':produto_id' => $produtoId]);
                $estoqueExistente = $stmt->fetch();

                if ($estoqueExistente) {
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
        $erro = 'Não foi possível concluir a operação. Tente novamente.';
    }
}

if (isset($_GET['salvo'])) {
    $mensagem = 'Estoque salvo com sucesso.';
}
if (isset($_GET['excluido'])) {
    $mensagem = 'Registro excluído com sucesso.';
}

$editando = null;
if (!empty($_GET['editar'])) {
    $stmt = $pdo->prepare("
        SELECT e.id, e.quantidade, p.nome
        FROM estoque_equipamentos e
        INNER JOIN produtos p ON p.id = e.produto_id
        WHERE e.id = :id
        LIMIT 1
    ");
    $stmt->execute([':id' => (int) $_GET['editar']]);
    $editando = $stmt->fetch() ?: null;
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
        p.nome,
        e.quantidade,
        COALESCE(NULLIF(MAX(i.serial), ''), 'N/A') AS serial,
        e.data_atualizacao
    FROM estoque_equipamentos e
    INNER JOIN produtos p ON p.id = e.produto_id
    LEFT JOIN itens i ON i.produto_id = e.produto_id
    {$where}
    GROUP BY e.id, p.nome, e.quantidade, e.data_atualizacao
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
</section>

<?php if ($mensagem): ?>
    <section class="panel"><div class="empty"><?php echo e($mensagem); ?></div></section>
<?php endif; ?>
<?php if ($erro): ?>
    <section class="panel"><div class="empty"><?php echo e($erro); ?></div></section>
<?php endif; ?>

<form class="panel filters stock-form" method="POST">
    <input type="hidden" name="acao" value="salvar">
    <input type="hidden" name="estoque_id" value="<?php echo (int) ($editando['id'] ?? 0); ?>">
    <label>Nome do equipamento
        <input type="text" name="nome" required value="<?php echo e($editando['nome'] ?? ''); ?>">
    </label>
    <label>Quantidade
        <input type="number" name="quantidade" min="0" required value="<?php echo e((string) ($editando['quantidade'] ?? 0)); ?>">
    </label>
    <div class="form-actions">
        <button class="btn primary" type="submit">Salvar</button>
        <?php if ($editando): ?>
            <a class="btn" href="estoque.php">Cancelar edição</a>
        <?php endif; ?>
    </div>
</form>

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
                            <td><?php echo e($row['serial'] ?: 'N/A'); ?></td>
                            <td><?php echo e(date('d/m/Y H:i', strtotime((string) $row['data_atualizacao']))); ?></td>
                            <td>
                                <div class="stock-actions">
                                <a class="btn" href="estoque.php?editar=<?php echo (int) $row['id']; ?><?php echo $busca !== '' ? '&busca=' . urlencode($busca) : ''; ?><?php echo $somenteCritico ? '&critico=1' : ''; ?>">Editar</a>
                                <form method="POST" style="display:inline;" onsubmit="return confirm('Confirmar exclusão deste item do estoque?');">
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
<?php adminPageEnd(); ?>
