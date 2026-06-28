<?php
require_once __DIR__ . '/admin_helpers.php';
require_once __DIR__ . '/../includes/serial_control.php';

$pdo = getConnection();
$allowedLimits = [5, 10, 20, 30, 50];
$perPage = (int) ($_GET['limite'] ?? 5);
$perPage = in_array($perPage, $allowedLimits, true) ? $perPage : 5;
$page = max(1, (int) ($_GET['pagina'] ?? 1));
$offset = ($page - 1) * $perPage;
$params = [];
$where = [];
$busca = trim((string) ($_GET['busca'] ?? ''));

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
    $where[] = 'COALESCE(m.produto_id, i.produto_id) = :produto_id';
    $params[':produto_id'] = (int) $_GET['produto_id'];
}
if (!empty($_GET['tipo'])) {
    $where[] = 'm.tipo = :tipo';
    $params[':tipo'] = $_GET['tipo'];
}
if ($busca !== '') {
    $where[] = '(p.nome LIKE :busca OR es.serial LIKE :busca)';
    $params[':busca'] = '%' . $busca . '%';
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$baseSql = "
    FROM movimentacoes m
    LEFT JOIN usuarios u ON u.id = m.usuario_id
    LEFT JOIN itens i ON i.id = m.item_id
    LEFT JOIN produtos p ON p.id = COALESCE(m.produto_id, i.produto_id)
    LEFT JOIN equipamento_seriais es
        ON es.id_serial = m.id_serial
       AND es.id_equipamento = p.id
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
        CASE
            WHEN COALESCE(p.controla_serial, 0) = 1 THEN
                COALESCE(
                    NULLIF(TRIM(es.serial), ''),
                    NULLIF(TRIM(CASE WHEN i.produto_id = p.id THEN i.serial END), ''),
                    'N/A'
                )
            ELSE 'N/A'
        END AS serial,
        COALESCE(es.status, '') AS status_serial
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

$serialDetalhe = null;
$historicoSerial = [];
if ($busca !== '') {
    $stmtSerial = $pdo->prepare("
        SELECT
            es.id_serial,
            es.serial,
            es.status,
            p.nome AS equipamento,
            COALESCE(l.nome, 'Estoque central') AS loja_atual,
            (
                SELECT MIN(m1.data_movimentacao)
                FROM movimentacoes m1
                WHERE m1.id_serial = es.id_serial
                  AND UPPER(m1.tipo) = 'ENTREGA'
            ) AS data_entrega,
            (
                SELECT MAX(m2.data_movimentacao)
                FROM movimentacoes m2
                WHERE m2.id_serial = es.id_serial
                  AND UPPER(m2.tipo) = 'TROCA'
            ) AS data_troca,
            (
                SELECT MAX(mt.data_registro)
                FROM manutencoes mt
                WHERE mt.id_serial = es.id_serial
                  AND COALESCE(mt.ativo, 1) = 1
            ) AS data_manutencao
        FROM equipamento_seriais es
        INNER JOIN produtos p ON p.id = es.id_equipamento
        LEFT JOIN lojas l ON l.id = es.loja_atual
        WHERE es.serial = :serial
        LIMIT 1
    ");
    $stmtSerial->execute([':serial' => $busca]);
    $serialDetalhe = $stmtSerial->fetch() ?: null;

    if ($serialDetalhe) {
        $stmtHistorico = $pdo->prepare("
            SELECT
                m.data_movimentacao AS data_evento,
                m.tipo,
                COALESCE(l.nome, '-') AS loja,
                COALESCE(u.nome, '-') AS usuario,
                m.status
            FROM movimentacoes m
            LEFT JOIN lojas l ON l.id = m.loja_id
            LEFT JOIN usuarios u ON u.id = m.usuario_id
            WHERE m.id_serial = :id_serial_mov
            UNION ALL
            SELECT
                mt.data_registro AS data_evento,
                'Manutenção' AS tipo,
                COALESCE(lm.nome, '-') AS loja,
                COALESCE(um.nome, '-') AS usuario,
                mt.status
            FROM manutencoes mt
            LEFT JOIN lojas lm ON lm.id = mt.id_loja
            LEFT JOIN usuarios um ON um.id = mt.id_usuario
            WHERE mt.id_serial = :id_serial_man
              AND COALESCE(mt.ativo, 1) = 1
            ORDER BY data_evento DESC
        ");
        $stmtHistorico->execute([
            ':id_serial_mov' => (int) $serialDetalhe['id_serial'],
            ':id_serial_man' => (int) $serialDetalhe['id_serial'],
        ]);
        $historicoSerial = $stmtHistorico->fetchAll();
    }
}

$lojas = $pdo->query('SELECT id, nome FROM lojas WHERE ativo = 1 ORDER BY nome')->fetchAll();
$usuarios = $pdo->query('SELECT id, nome FROM usuarios WHERE ativo = 1 ORDER BY nome')->fetchAll();
$produtos = $pdo->query('SELECT DISTINCT p.id, p.nome FROM estoque_equipamentos e INNER JOIN produtos p ON p.id = e.produto_id WHERE p.ativo = 1 ORDER BY p.nome')->fetchAll();
$dataInicial = trim((string) ($_GET['data_inicial'] ?? ''));
$dataFinal = trim((string) ($_GET['data_final'] ?? ''));
$formatarDataPeriodo = static function (string $data): string {
    $valor = DateTime::createFromFormat('Y-m-d', $data);
    return $valor ? $valor->format('d/m/Y') : $data;
};
if ($dataInicial !== '' && $dataFinal !== '') {
    $periodoSelecionado = $formatarDataPeriodo($dataInicial) . ' até ' . $formatarDataPeriodo($dataFinal);
} elseif ($dataInicial !== '') {
    $periodoSelecionado = 'A partir de ' . $formatarDataPeriodo($dataInicial);
} elseif ($dataFinal !== '') {
    $periodoSelecionado = 'Até ' . $formatarDataPeriodo($dataFinal);
} else {
    $periodoSelecionado = 'Selecionar período';
}

adminPageStart('Movimentações');
?>
<style>
    .top { margin-bottom: 24px; }
    .panel { margin-bottom: 18px; }
    .mov-filters { position: relative; overflow: visible; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 18px 16px; padding: 22px; align-items: end; }
    .mov-filters > * { min-width: 0; }
    .mov-filters label, .period-field { display: grid; gap: 8px; }
    .mov-filters select { width: 100%; height: 42px; }
    .period-picker { position: relative; }
    .period-picker summary { display: flex; align-items: center; gap: 10px; width: 100%; height: 42px; padding: 0 12px; border: 1px solid var(--line); border-radius: var(--radius); background: #10151c; color: #fff; cursor: pointer; list-style: none; font-size: 13px; font-weight: 600; transition: border-color .2s ease, background .2s ease; }
    .period-picker summary::-webkit-details-marker { display: none; }
    .period-picker summary:hover, .period-picker[open] summary { border-color: rgba(255, 255, 255, .2); background: #141b24; }
    .calendar-icon { width: 17px; height: 17px; flex: 0 0 17px; color: var(--muted); }
    .period-label { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .period-popover { position: absolute; z-index: 30; top: calc(100% + 8px); left: 0; width: min(420px, calc(100vw - 64px)); padding: 16px; border: 1px solid rgba(255, 255, 255, .14); border-radius: 12px; background: #11171f; box-shadow: 0 22px 54px rgba(0, 0, 0, .48); }
    .period-dates { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
    .period-dates input { width: 100%; height: 42px; }
    .period-clear { width: 100%; margin-top: 14px; min-height: 38px; }
    .mov-limit-control { display: flex; align-items: center; gap: 8px; }
    .mov-limit-control .filter-icon { width: 42px; height: 42px; flex: 0 0 42px; display: grid; place-items: center; border: 1px solid var(--line); border-radius: var(--radius); background: rgba(255, 255, 255, .035); color: var(--muted); }
    .mov-limit-control .filter-icon svg { width: 17px; height: 17px; }
    .mov-limit-control select { min-width: 0; }
    .filter-actions { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; grid-column: span 2; }
    .filter-actions .btn { width: 100%; min-height: 42px; }
    .mov-table .table-wrap { overflow: visible; width: 100%; }
    .mov-table table { width: 100%; min-width: 0; table-layout: auto; }
    .mov-table th { text-align: center; vertical-align: middle; white-space: normal; }
    .mov-table td { vertical-align: middle; white-space: normal; overflow-wrap: anywhere; }
    .mov-table th:nth-child(1), .mov-table td:nth-child(1), .mov-table th:nth-child(2), .mov-table td:nth-child(2), .mov-table th:nth-child(3), .mov-table td:nth-child(3), .mov-table th:nth-child(7), .mov-table td:nth-child(7), .mov-table th:nth-child(8), .mov-table td:nth-child(8) { text-align: center; }
    .mov-table .serial { color: #dce2ea; font-variant-numeric: tabular-nums; }
    .mov-table th, .mov-table td { padding: 14px 18px; vertical-align: middle; }
    .empty { padding: 18px; }
    .serial-summary { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 12px; padding: 18px; margin-bottom: 18px; }
    .serial-summary div { padding: 12px; border: 1px solid var(--line); border-radius: 8px; background: rgba(255,255,255,.03); }
    .serial-summary span { display: block; margin-bottom: 5px; color: var(--muted); font-size: 11px; text-transform: uppercase; }
    .serial-history { margin-bottom: 18px; }
    @media (max-width: 720px) { .serial-summary { grid-template-columns: 1fr; } }
    .pagination { display: flex; align-items: center; justify-content: flex-end; gap: 6px; min-height: 72px; padding: 16px 18px; border-top: 1px solid rgba(255, 255, 255, .08); }
    .pagination a, .pagination span { display: inline-flex; align-items: center; justify-content: center; min-width: 36px; height: 36px; padding: 0 11px; border: 1px solid var(--line); border-radius: 8px; color: var(--muted); font-size: 13px; font-weight: 700; line-height: 1; white-space: nowrap; transition: background .2s ease, border-color .2s ease, color .2s ease; }
    .pagination a:hover { background: rgba(255, 255, 255, .06); border-color: rgba(255, 255, 255, .18); color: #fff; }
    .pagination .active { background: var(--red); border-color: var(--red); color: #fff; }
    .pagination .disabled { cursor: not-allowed; opacity: .38; }
    .pagination .ellipsis { min-width: 28px; padding: 0 4px; border-color: transparent; }
    @media (max-width: 980px) { .mov-filters { grid-template-columns: repeat(2, minmax(0, 1fr)); } .filter-actions { grid-column: 1 / -1; } }
    @media (max-width: 720px) { .mov-filters { grid-template-columns: 1fr; padding: 18px; } .filter-actions { grid-column: 1; grid-template-columns: 1fr; } .period-dates { grid-template-columns: 1fr; } .period-popover { width: min(100%, calc(100vw - 68px)); } .mov-table td { text-align: left !important; } .pagination { justify-content: flex-end; flex-wrap: wrap; } }
</style>

<section class="top">
    <div>
        <h1>Movimentações</h1>
        <p>Consulta completa de entregas e trocas registradas.</p>
    </div>
</section>

<form class="panel filters mov-filters" method="GET">
    <label>Equipamento ou Serial<input type="search" name="busca" value="<?php echo e($busca); ?>" placeholder="Nome ou número de série"></label>
    <div class="period-field">
        <span>Período</span>
        <details class="period-picker" id="periodPicker">
            <summary><svg class="calendar-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18"/></svg><span class="period-label" id="periodLabel"><?php echo e($periodoSelecionado); ?></span></summary>
            <div class="period-popover">
                <div class="period-dates">
                    <label>Data inicial<input type="date" id="periodStart" name="data_inicial" value="<?php echo e($dataInicial); ?>"></label>
                    <label>Data final<input type="date" id="periodEnd" name="data_final" value="<?php echo e($dataFinal); ?>"></label>
                </div>
                <button class="btn period-clear" id="clearPeriod" type="button">Limpar período</button>
            </div>
        </details>
    </div>
    <label>Loja<select name="loja_id"><option value="">Todas</option><?php foreach ($lojas as $loja): ?><option value="<?php echo (int) $loja['id']; ?>" <?php echo (string) ($_GET['loja_id'] ?? '') === (string) $loja['id'] ? 'selected' : ''; ?>><?php echo e($loja['nome']); ?></option><?php endforeach; ?></select></label>
    <label>Usuário<select name="usuario_id"><option value="">Todos</option><?php foreach ($usuarios as $usuario): ?><option value="<?php echo (int) $usuario['id']; ?>" <?php echo (string) ($_GET['usuario_id'] ?? '') === (string) $usuario['id'] ? 'selected' : ''; ?>><?php echo e($usuario['nome']); ?></option><?php endforeach; ?></select></label>
    <label>Equipamento<select name="produto_id"><option value="">Todos</option><?php foreach ($produtos as $produto): ?><option value="<?php echo (int) $produto['id']; ?>" <?php echo (string) ($_GET['produto_id'] ?? '') === (string) $produto['id'] ? 'selected' : ''; ?>><?php echo e($produto['nome']); ?></option><?php endforeach; ?></select></label>
    <label>Tipo<select name="tipo"><option value="">Todos</option><option value="Entrega" <?php echo ($_GET['tipo'] ?? '') === 'Entrega' ? 'selected' : ''; ?>>Entrega</option><option value="Troca" <?php echo ($_GET['tipo'] ?? '') === 'Troca' ? 'selected' : ''; ?>>Troca</option></select></label>
    <label>Quantidade por página<span class="mov-limit-control"><span class="filter-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 5h18l-7 8v5l-4 2v-7L3 5Z"/></svg></span><select name="limite" onchange="this.form.submit()"><?php foreach ($allowedLimits as $limit): ?><option value="<?php echo $limit; ?>" <?php echo $perPage === $limit ? 'selected' : ''; ?>><?php echo $limit; ?></option><?php endforeach; ?></select></span></label>
    <div class="filter-actions"><button class="btn primary" type="submit">Pesquisar</button><a class="btn" href="movimentacoes.php">Limpar</a></div>
</form>

<?php if ($serialDetalhe): ?>
    <section class="panel serial-summary">
        <div><span>Serial</span><strong><?php echo e($serialDetalhe['serial']); ?></strong></div>
        <div><span>Equipamento</span><strong><?php echo e($serialDetalhe['equipamento']); ?></strong></div>
        <div><span>Loja atual</span><strong><?php echo e($serialDetalhe['loja_atual']); ?></strong></div>
        <div><span>Status atual</span><strong><?php echo e(serialControlStatusLabel((string) $serialDetalhe['status'])); ?></strong></div>
        <div><span>Data da entrega</span><strong><?php echo e($serialDetalhe['data_entrega'] ? date('d/m/Y H:i', strtotime((string) $serialDetalhe['data_entrega'])) : '-'); ?></strong></div>
        <div><span>Data da troca</span><strong><?php echo e($serialDetalhe['data_troca'] ? date('d/m/Y H:i', strtotime((string) $serialDetalhe['data_troca'])) : '-'); ?></strong></div>
        <div><span>Data da manutenção</span><strong><?php echo e($serialDetalhe['data_manutencao'] ? date('d/m/Y H:i', strtotime((string) $serialDetalhe['data_manutencao'])) : '-'); ?></strong></div>
    </section>
    <section class="panel serial-history">
        <div class="stock-summary"><strong>Histórico completo do serial</strong></div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Data</th><th>Evento</th><th>Loja</th><th>Usuário</th><th>Status</th></tr></thead>
                <tbody>
                    <?php foreach ($historicoSerial as $evento): ?>
                        <tr>
                            <td><?php echo e(date('d/m/Y H:i', strtotime((string) $evento['data_evento']))); ?></td>
                            <td><?php echo e($evento['tipo']); ?></td>
                            <td><?php echo e($evento['loja']); ?></td>
                            <td><?php echo e($evento['usuario']); ?></td>
                            <td><?php echo e($evento['status']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php endif; ?>
<script>
(() => {
    const picker = document.getElementById('periodPicker');
    const start = document.getElementById('periodStart');
    const end = document.getElementById('periodEnd');
    const label = document.getElementById('periodLabel');
    const clear = document.getElementById('clearPeriod');
    if (!picker || !start || !end || !label || !clear) return;
    const formatDate = (value) => { if (!value) return ''; const [year, month, day] = value.split('-'); return `${day}/${month}/${year}`; };
    const updateLabel = () => {
        if (start.value && end.value) label.textContent = `${formatDate(start.value)} até ${formatDate(end.value)}`;
        else if (start.value) label.textContent = `A partir de ${formatDate(start.value)}`;
        else if (end.value) label.textContent = `Até ${formatDate(end.value)}`;
        else label.textContent = 'Selecionar período';
    };
    start.addEventListener('change', updateLabel);
    end.addEventListener('change', updateLabel);
    clear.addEventListener('click', () => { start.value = ''; end.value = ''; updateLabel(); });
    document.addEventListener('click', (event) => { if (picker.open && !picker.contains(event.target)) picker.removeAttribute('open'); });
})();
</script>

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
