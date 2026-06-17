<?php
require_once __DIR__ . '/admin_helpers.php';

$pdo = getConnection();
$perPage = 10;
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
    $where[] = 'm.produto_id = :produto_id';
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
    LEFT JOIN produtos p ON p.id = m.produto_id
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
        m.status
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
$produtos = $pdo->query('SELECT id, nome FROM produtos WHERE ativo = 1 ORDER BY nome')->fetchAll();
$statusList = $pdo->query("SELECT DISTINCT status FROM movimentacoes WHERE status IS NOT NULL AND status <> '' ORDER BY status")->fetchAll();

adminPageStart('Movimentações');
?>
<section class="top">
    <div>
        <h1>Movimentações</h1>
        <p>Consulta completa de entregas e trocas registradas.</p>
    </div>
    <a class="btn" href="dashboard.php">Voltar</a>
</section>

<form class="panel filters" method="GET">
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
    <button class="btn primary" type="submit">Filtrar</button>
    <a class="btn" href="movimentacoes.php">Limpar</a>
</form>

<section class="panel">
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
