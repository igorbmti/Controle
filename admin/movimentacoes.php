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
    {$whereSql}
";

$countStmt = $pdo->prepare("SELECT COUNT(*) {$baseSql}");
$countStmt->execute($params);
$total = (int) $countStmt->fetchColumn();
$totalPages = (int) ceil($total / $perPage);

$stmt = $pdo->prepare("
    SELECT
        m.data_movimentacao,
        COALESCE(u.nome, '-') AS usuario,
        m.tipo,
        COALESCE(p.nome, '-') AS equipamento,
        COALESCE(l.nome, '-') AS loja,
        COALESCE(m.solicitante_nome, '-') AS solicitante,
        m.status,
        COALESCE(m.justificativa, '') AS justificativa
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
    .mov-filters { grid-template-columns: repeat(4, minmax(170px, 1fr)); align-items: end; }
    .filter-actions { display: flex; gap: 10px; align-items: center; justify-content: flex-end; grid-column: 1 / -1; }
    .mov-table table { table-layout: fixed; }
    .mov-table th:nth-child(1), .mov-table td:nth-child(1) { width: 132px; }
    .mov-table th:nth-child(3), .mov-table td:nth-child(3) { width: 95px; }
    .mov-table th:nth-child(7), .mov-table td:nth-child(7) { width: 148px; }
    .mov-table th:nth-child(8), .mov-table td:nth-child(8) { width: 76px; text-align: center; }
    .reason-eye { width: 34px; height: 34px; border: 1px solid var(--line); border-radius: 8px; display: inline-grid; place-items: center; color: #fff; background: rgba(255,255,255,.035); cursor: help; position: relative; transition: border-color .18s ease, background .18s ease, transform .18s ease; }
    .reason-eye:hover, .reason-eye:focus-visible { border-color: rgba(229,9,20,.58); background: rgba(229,9,20,.1); transform: translateY(-1px); outline: none; }
    .reason-eye svg { width: 18px; height: 18px; }
    .reason-eye::after { content: attr(data-reason); position: absolute; right: 0; bottom: calc(100% + 10px); width: min(320px, 70vw); padding: 10px 12px; border: 1px solid var(--line); border-radius: 8px; background: #10151c; color: #fff; box-shadow: 0 16px 32px rgba(0,0,0,.36); font-size: 12px; line-height: 1.35; text-align: left; white-space: normal; opacity: 0; pointer-events: none; transform: translateY(4px); transition: opacity .16s ease, transform .16s ease; z-index: 20; }
    .reason-eye:hover::after, .reason-eye:focus-visible::after { opacity: 1; transform: translateY(0); }
    @media (max-width: 980px) { .mov-filters { grid-template-columns: repeat(2, minmax(0, 1fr)); } .filter-actions { justify-content: flex-start; } .mov-table table { table-layout: auto; } }
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
    <label class="limit-control" aria-label="Quantidade de registros"><span class="filter-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 5h18l-7 8v5l-4 2v-7L3 5Z"/></svg></span>
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
                        <th>Usuário</th>
                        <th>Tipo</th>
                        <th>Equipamento</th>
                        <th>Loja</th>
                        <th>Solicitante</th>
                        <th>Status</th>
                        <th>Motivo</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($movimentacoes as $row): ?>
                        <tr>
                            <td><?php echo e(date('d/m/Y H:i', strtotime((string) $row['data_movimentacao']))); ?></td>
                            <td><?php echo e($row['usuario']); ?></td>
                            <td><?php echo e($row['tipo']); ?></td>
                            <td><?php echo e($row['equipamento']); ?></td>
                            <td><?php echo e($row['loja']); ?></td>
                            <td><?php echo e($row['solicitante']); ?></td>
                            <td><span class="badge"><?php echo e(normalizeStatus($row['status'])); ?></span></td>
                            <td>
                                <span class="reason-eye" tabindex="0" data-reason="<?php echo e($row['justificativa'] !== '' ? $row['justificativa'] : 'Motivo não informado.'); ?>" aria-label="Ver descrição do problema">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <?php if ($totalPages > 1): ?>
        <nav class="pagination" aria-label="Paginação">
            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                <?php if ($i === $page): ?>
                    <span class="active"><?php echo $i; ?></span>
                <?php else: ?>
                    <a href="<?php echo e(pageUrl(['pagina' => $i])); ?>"><?php echo $i; ?></a>
                <?php endif; ?>
            <?php endfor; ?>
        </nav>
    <?php endif; ?>
</section>
<?php adminPageEnd(); ?>
