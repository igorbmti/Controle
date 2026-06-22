<?php
require_once __DIR__ . '/admin_helpers.php';

$pdo = getConnection();
$allowedLimits = [5, 10, 20, 30, 50];
$perPage = (int) ($_GET['limite'] ?? 5);
$perPage = in_array($perPage, $allowedLimits, true) ? $perPage : 5;
$page = max(1, (int) ($_GET['pagina'] ?? 1));
$offset = ($page - 1) * $perPage;

$baseSql = "
    FROM movimentacoes m
    LEFT JOIN usuarios u ON u.id = m.usuario_id
    LEFT JOIN produtos p ON p.id = m.produto_id
    LEFT JOIN lojas l ON l.id = m.loja_id
";

$total = (int) $pdo->query("SELECT COUNT(*) {$baseSql}")->fetchColumn();
$totalPages = (int) ceil($total / $perPage);

$stmt = $pdo->prepare("
    SELECT
        m.data_movimentacao,
        COALESCE(u.nome, '-') AS usuario,
        m.tipo,
        COALESCE(p.nome, '-') AS equipamento,
        COALESCE(l.nome, '-') AS loja
    {$baseSql}
    ORDER BY m.data_movimentacao DESC
    LIMIT :limit OFFSET :offset
");
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$atividades = $stmt->fetchAll();

adminPageStart('Atividades');
?>
<section class="top">
    <div>
        <h1>Atividades</h1>
        <p>Histórico completo das ações geradas pelos usuários.</p>
    </div>
    <a class="btn" href="dashboard.php">Voltar</a>
</section>
<form class="panel filters" method="GET">
    <label class="limit-control" aria-label="Quantidade de registros"><span class="filter-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 5h18l-7 8v5l-4 2v-7L3 5Z"/></svg></span>
        <select name="limite">
            <?php foreach ($allowedLimits as $limit): ?>
                <option value="<?php echo $limit; ?>" <?php echo $perPage === $limit ? 'selected' : ''; ?>><?php echo $limit; ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <button class="btn primary" type="submit">Aplicar</button>
</form>

<section class="panel">
    <?php if (empty($atividades)): ?>
        <div class="empty">Nenhuma atividade registrada.</div>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Data</th>
                        <th>Usuário</th>
                        <th>Ação realizada</th>
                        <th>Equipamento</th>
                        <th>Loja</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($atividades as $row): ?>
                        <tr>
                            <td><?php echo e(date('d/m/Y H:i', strtotime((string) $row['data_movimentacao']))); ?></td>
                            <td><?php echo e($row['usuario']); ?></td>
                            <td><?php echo e($row['tipo']); ?></td>
                            <td><?php echo e($row['equipamento']); ?></td>
                            <td><?php echo e($row['loja']); ?></td>
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
