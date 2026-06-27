<?php
require_once __DIR__ . '/admin_helpers.php';

$pdo = getConnection();
$allowedLimits = [5, 10, 20, 30, 50];
$perPage = (int) ($_GET['limite'] ?? 5);
$perPage = in_array($perPage, $allowedLimits, true) ? $perPage : 5;
$page = max(1, (int) ($_GET['pagina'] ?? 1));
$offset = ($page - 1) * $perPage;
$params = [];
$where = [];

if (!empty($_GET['data_inicial'])) {
    $where[] = 'DATE(m.data_movimentacao) >= :data_inicial';
    $params[':data_inicial'] = $_GET['data_inicial'];
}
if (!empty($_GET['data_final'])) {
    $where[] = 'DATE(m.data_movimentacao) <= :data_final';
    $params[':data_final'] = $_GET['data_final'];
}
if (!empty($_GET['loja_id'])) {
    $where[] = 'm.loja_id = :loja_id';
    $params[':loja_id'] = (int) $_GET['loja_id'];
}
if (!empty($_GET['usuario_id'])) {
    $where[] = 'm.usuario_id = :usuario_id';
    $params[':usuario_id'] = (int) $_GET['usuario_id'];
}
if (!empty($_GET['produto_id'])) {
    $where[] = 'COALESCE(i.produto_id, m.produto_id) = :produto_id';
    $params[':produto_id'] = (int) $_GET['produto_id'];
}
if (!empty($_GET['tipo'])) {
    $where[] = 'm.tipo = :tipo';
    $params[':tipo'] = $_GET['tipo'];
}
if (!empty($_GET['status'])) {
    $where[] = 'm.status = :status';
    $params[':status'] = $_GET['status'];
}
if (!empty($_GET['solicitante'])) {
    $where[] = 'm.solicitante_nome LIKE :solicitante';
    $params[':solicitante'] = '%' . $_GET['solicitante'] . '%';
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$baseSql = "
    FROM movimentacoes m
    LEFT JOIN usuarios u ON u.id = m.usuario_id
    LEFT JOIN itens i ON i.id = m.item_id
    LEFT JOIN produtos p ON p.id = COALESCE(i.produto_id, m.produto_id)
    LEFT JOIN lojas l ON l.id = m.loja_id
    LEFT JOIN setores s ON s.id = m.setor_id
    {$whereSql}
";

$countStmt = $pdo->prepare("SELECT COUNT(*) {$baseSql}");
$countStmt->execute($params);
$total = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($total / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$stmt = $pdo->prepare("
    SELECT
        m.data_movimentacao,
        m.tipo,
        COALESCE(l.nome, '-') AS loja,
        COALESCE(s.nome, '-') AS setor,
        COALESCE(p.nome, '-') AS equipamento,
        COALESCE(m.quantidade, 0) AS quantidade,
        COALESCE(NULLIF(TRIM(i.serial), ''), 'N/A') AS serial,
        COALESCE(u.nome, '-') AS usuario
    {$baseSql}
    ORDER BY m.data_movimentacao DESC
    LIMIT :limit OFFSET :offset
");
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$movimentacoes = $stmt->fetchAll();

$lojas = $pdo->query('SELECT id, nome FROM lojas WHERE ativo = 1 ORDER BY nome')->fetchAll();
$usuarios = $pdo->query('SELECT id, nome FROM usuarios WHERE ativo = 1 ORDER BY nome')->fetchAll();
$produtos = $pdo->query('SELECT DISTINCT p.id, p.nome FROM estoque_equipamentos e INNER JOIN produtos p ON p.id = e.produto_id WHERE p.ativo = 1 ORDER BY p.nome')->fetchAll();
$statusList = $pdo->query("SELECT DISTINCT status FROM movimentacoes WHERE status IS NOT NULL AND status <> '' ORDER BY status")->fetchAll();

adminPageStart('Movimentações');
?>
<style>
    .mov-filters {
        grid-template-columns: repeat(4, minmax(170px, 1fr));
        gap: 12px;
        padding: 20px;
        align-items: end;
    }
    .top { margin-bottom: 24px; }
    .panel { margin-bottom: 18px; }
    .mov-filters > * { min-width: 0; }
    .mov-filters input,
    .mov-filters select {
        width: 100%;
        height: 38px;
    }
    .filter-actions {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        align-items: center;
        gap: 10px;
        grid-column: span 3;
    }
    .filter-actions .btn {
        width: 100%;
        min-height: 38px;
    }
    .mov-table .table-wrap { overflow: visible; }
    .mov-table table {
        width: 100%;
        min-width: 0;
        table-layout: auto;
    }
    .mov-table th {
        text-align: center;
        vertical-align: middle;
        white-space: normal;
    }
    .mov-table td {
        vertical-align: middle;
        white-space: normal;
        overflow-wrap: anywhere;
    }
    .mov-table th:nth-child(1),
    .mov-table td:nth-child(1),
    .mov-table th:nth-child(2),
    .mov-table td:nth-child(2),
    .mov-table th:nth-child(3),
    .mov-table td:nth-child(3),
    .mov-table th:nth-child(7),
    .mov-table td:nth-child(7),
    .mov-table th:nth-child(8),
    .mov-table td:nth-child(8) {
        text-align: center;
    }
    .mov-table .serial {
        color: #dce2ea;
        font-variant-numeric: tabular-nums;
    }
    @media (max-width: 980px) {
        .mov-filters { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        .filter-actions { grid-column: 1 / -1; }
    }
    @media (max-width: 720px) {
        .mov-table td { text-align: left !important; }
    }
    .mov-table .table-wrap,
    .mov-table table { width: 100%; }
    .mov-table th,
    .mov-table td { padding: 14px 18px; vertical-align: middle; }
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
    @media (max-width: 720px) {
        .filter-actions { grid-template-columns: 1fr; }
        .pagination { justify-content: flex-end; flex-wrap: wrap; }
    }
</style>

<section class="top">
    <div>
        <h1>Movimentações</h1>
        <p>Consulta completa de entregas e trocas registradas.</p>
    </div>
</section>

<form class="panel filters mov-filters" method="GET">
    <label>Data inicial<input type="date" name="data_inicial" value="<?php echo e($_GET['data_inicial'] ?? ''); ?>"></label>
    <label>Data final<input type="date" name="data_final" value="<?php echo e($_GET['data_final'] ?? ''); ?>"></label>
    <label>Loja
        <select name="loja_id">
            <option value="">Todas</option>
            <?php foreach ($lojas as $loja): ?>
                <option value="<?php echo (int) $loja['id']; ?>" <?php echo (string) ($_GET['loja_id'] ?? '') === (string) $loja['id'] ? 'selected' : ''; ?>>
                    <?php echo e($loja['nome']); ?>
                </option>
            <?php endforeach; ?>
        </select>
    </label>
    <label>Usuário
        <select name="usuario_id">
            <option value="">Todos</option>
            <?php foreach ($usuarios as $usuario): ?>
                <option value="<?php echo (int) $usuario['id']; ?>" <?php echo (string) ($_GET['usuario_id'] ?? '') === (string) $usuario['id'] ? 'selected' : ''; ?>>
                    <?php echo e($usuario['nome']); ?>
                </option>
            <?php endforeach; ?>
        </select>
    </label>
    <label>Equipamento
        <select name="produto_id">
            <option value="">Todos</option>
            <?php foreach ($produtos as $produto): ?>
                <option value="<?php echo (int) $produto['id']; ?>" <?php echo (string) ($_GET['produto_id'] ?? '') === (string) $produto['id'] ? 'selected' : ''; ?>>
                    <?php echo e($produto['nome']); ?>
                </option>
            <?php endforeach; ?>
        </select>
    </label>
    <label>Tipo
        <select name="tipo">
            <option value="">Todos</option>
            <option value="Entrega" <?php echo ($_GET['tipo'] ?? '') === 'Entrega' ? 'selected' : ''; ?>>Entrega</option>
            <option value="Troca" <?php echo ($_GET['tipo'] ?? '') === 'Troca' ? 'selected' : ''; ?>>Troca</option>
        </select>
    </label>
    <label>Status
        <select name="status">
            <option value="">Todos</option>
            <?php foreach ($statusList as $status): ?>
                <option value="<?php echo e($status['status']); ?>" <?php echo ($_GET['status'] ?? '') === $status['status'] ? 'selected' : ''; ?>>
                    <?php echo e(normalizeStatus($status['status'])); ?>
                </option>
            <?php endforeach; ?>
        </select>
    </label>
    <label>Solicitante<input type="text" name="solicitante" value="<?php echo e($_GET['solicitante'] ?? ''); ?>" placeholder="Nome"></label>
    <label class="limit-control" aria-label="Quantidade de registros">
        <span class="filter-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 5h18l-7 8v5l-4 2v-7L3 5Z"/></svg>
        </span>
        <select name="limite" onchange="this.form.submit()">
            <?php foreach ($allowedLimits as $limit): ?>
                <option value="<?php echo $limit; ?>" <?php echo $perPage === $limit ? 'selected' : ''; ?>><?php echo $limit; ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <div class="filter-actions">
        <button class="btn primary" type="submit">Pesquisar</button>
        <a class="btn" href="movimentacoes.php">Limpar</a>
    </div>
</form>

<section class="panel mov-table">
    <?php if (empty($movimentacoes)): ?>
        <div class="empty">Nenhuma movimentação registrada.</div>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Data</th>
                        <th>Hora</th>
                        <th>Tipo</th>
                        <th>Loja</th>
                        <th>Setor</th>
                        <th>Equipamento</th>
                        <th>Quantidade</th>
                        <th>Serial</th>
                        <th>Usuário responsável</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($movimentacoes as $row): ?>
                        <?php $timestamp = strtotime((string) $row['data_movimentacao']); ?>
                        <tr>
                            <td><?php echo e($timestamp ? date('d/m/Y', $timestamp) : '-'); ?></td>
                            <td><?php echo e($timestamp ? date('H:i', $timestamp) : '-'); ?></td>
                            <td><?php echo e(ucfirst(strtolower((string) $row['tipo']))); ?></td>
                            <td><?php echo e($row['loja']); ?></td>
                            <td><?php echo e($row['setor']); ?></td>
                            <td><?php echo e($row['equipamento']); ?></td>
                            <td><?php echo (int) $row['quantidade']; ?></td>
                            <td class="serial"><?php echo e($row['serial'] ?: 'N/A'); ?></td>
                            <td><?php echo e($row['usuario']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <?php if ($totalPages > 1): ?>
        <?php
        if ($totalPages <= 7) {
            $paginasVisiveis = range(1, $totalPages);
        } elseif ($page <= 4) {
            $paginasVisiveis = [1, 2, 3, 4, 5, '...', $totalPages];
        } elseif ($page >= $totalPages - 3) {
            $paginasVisiveis = [1, '...', $totalPages - 4, $totalPages - 3, $totalPages - 2, $totalPages - 1, $totalPages];
        } else {
            $paginasVisiveis = [1, '...', $page - 1, $page, $page + 1, '...', $totalPages];
        }
        ?>
        <nav class="pagination" aria-label="Paginação">
            <?php if ($page > 1): ?>
                <a href="<?php echo e(pageUrl(['pagina' => $page - 1])); ?>" rel="prev">◀ Anterior</a>
            <?php else: ?>
                <span class="disabled" aria-disabled="true">◀ Anterior</span>
            <?php endif; ?>
            <?php foreach ($paginasVisiveis as $itemPagina): ?>
                <?php if ($itemPagina === '...'): ?>
                    <span class="ellipsis" aria-hidden="true">...</span>
                <?php elseif ($itemPagina === $page): ?>
                    <span class="active" aria-current="page"><?php echo $itemPagina; ?></span>
                <?php else: ?>
                    <a href="<?php echo e(pageUrl(['pagina' => $itemPagina])); ?>"><?php echo $itemPagina; ?></a>
                <?php endif; ?>
            <?php endforeach; ?>
            <?php if ($page < $totalPages): ?>
                <a href="<?php echo e(pageUrl(['pagina' => $page + 1])); ?>" rel="next">Próximo ▶</a>
            <?php else: ?>
                <span class="disabled" aria-disabled="true">Próximo ▶</span>
            <?php endif; ?>
        </nav>
    <?php endif; ?>
</section>
<?php adminPageEnd(); ?>
