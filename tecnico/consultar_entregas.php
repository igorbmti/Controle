<?php
require_once __DIR__ . '/../includes/auth.php';

verificarLogin('TECNICO');

$pdo = getConnection();
$idUsuario = (int) ($_SESSION['id_usuario'] ?? 0);
$nomeUsuario = (string) ($_SESSION['nome'] ?? 'Usuário');

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function dataBr(?string $data): string
{
    if (!$data) {
        return '-';
    }
    $timestamp = strtotime($data);
    return $timestamp ? date('d/m/Y H:i', $timestamp) : '-';
}

function normalizeLojaNomeTecnico(string $nome): string
{
    $clean = trim($nome);
    $ascii = function_exists('iconv') ? iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $clean) : false;
    $ascii = $ascii !== false ? $ascii : $clean;
    $ascii = strtoupper(preg_replace('/\s+/', ' ', $ascii));

    if (preg_match('/LOJA\s*0?(\d+)\b/', $ascii, $matches)) {
        return 'Loja ' . str_pad((string) (int) $matches[1], 2, '0', STR_PAD_LEFT);
    }

    if (preg_match('/DEP\S*ITO\s*(73|77)\b/', $ascii, $matches) || preg_match('/DEPOSITO\s*(73|77)\b/', $ascii, $matches)) {
        return 'Depósito ' . $matches[1];
    }

    return $clean;
}

function lojaOrderTecnico(string $nome): int
{
    return match ($nome) {
        'Loja 01' => 1,
        'Loja 02' => 2,
        'Depósito 73' => 3,
        'Loja 04' => 4,
        'Loja 05' => 5,
        'Loja 06' => 6,
        'Depósito 77' => 7,
        'Loja 08' => 8,
        'Loja 09' => 9,
        default => 500,
    };
}

function fetchLojasTecnico(PDO $pdo): array
{
    $rows = $pdo->query('
        SELECT id, nome
        FROM lojas
        WHERE ativo = 1
        ORDER BY FIELD(id, 1, 2, 10, 4, 5, 6, 11, 8, 9), id, nome
    ')->fetchAll();
    $grouped = [];

    foreach ($rows as $row) {
        $nome = normalizeLojaNomeTecnico((string) $row['nome']);
        $grouped[$nome] ??= ['nome' => $nome, 'ids' => []];
        $grouped[$nome]['ids'][] = (int) $row['id'];
    }

    usort($grouped, static fn(array $a, array $b): int => lojaOrderTecnico($a['nome']) <=> lojaOrderTecnico($b['nome']));

    return array_map(static function (array $loja): array {
        $ids = array_values(array_unique(array_filter(array_map('intval', $loja['ids']))));

        return [
            'nome' => $loja['nome'],
            'ids' => $ids,
            'valor' => implode(',', $ids),
        ];
    }, $grouped);
}

function parseLojaIdsTecnico(string $value): array
{
    $ids = preg_split('/,/', $value) ?: [];

    return array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $id): bool => $id > 0)));
}

$lojas = fetchLojasTecnico($pdo);
$stmtStatus = $pdo->prepare("SELECT DISTINCT status FROM movimentacoes WHERE usuario_id = :usuario_id AND status IS NOT NULL AND status <> '' ORDER BY status");
$stmtStatus->execute([':usuario_id' => $idUsuario]);
$statusOptions = $stmtStatus->fetchAll();
$dataInicial = trim((string) ($_GET['data_inicial'] ?? ''));
$dataFinal = trim((string) ($_GET['data_final'] ?? ''));
$lojaIds = parseLojaIdsTecnico((string) ($_GET['loja_id'] ?? ''));
$lojaValue = implode(',', $lojaIds);
$tipo = strtoupper(trim((string) ($_GET['tipo'] ?? '')));
$status = strtoupper(trim((string) ($_GET['status'] ?? '')));
$detalheId = (int) ($_GET['detalhe'] ?? 0);

$where = ['m.usuario_id = :usuario_id'];
$params = [':usuario_id' => $idUsuario];

if ($dataInicial !== '') {
    $where[] = 'DATE(m.data_movimentacao) >= :data_inicial';
    $params[':data_inicial'] = $dataInicial;
}
if ($dataFinal !== '') {
    $where[] = 'DATE(m.data_movimentacao) <= :data_final';
    $params[':data_final'] = $dataFinal;
}
if (!empty($lojaIds)) {
    $placeholders = [];
    foreach ($lojaIds as $index => $id) {
        $key = ":loja_id_{$index}";
        $placeholders[] = $key;
        $params[$key] = $id;
    }
    $where[] = 'm.loja_id IN (' . implode(', ', $placeholders) . ')';
}
if (in_array($tipo, ['ENTREGA', 'TROCA'], true)) {
    $where[] = 'UPPER(m.tipo) = :tipo';
    $params[':tipo'] = $tipo;
}
if ($status !== '') {
    $where[] = 'UPPER(m.status) = :status';
    $params[':status'] = $status;
}

$sqlBase = '
    FROM movimentacoes m
    LEFT JOIN lojas l ON l.id = m.loja_id
    LEFT JOIN setores s ON s.id = m.setor_id
    LEFT JOIN produtos p ON p.id = m.produto_id
    WHERE ' . implode(' AND ', $where);

$stmt = $pdo->prepare('
    SELECT
        m.id,
        m.data_movimentacao,
        m.solicitante_nome,
        m.quantidade,
        m.tipo,
        m.status,
        m.justificativa,
        m.descricao,
        COALESCE(l.nome, "-") AS loja,
        COALESCE(s.nome, "-") AS setor,
        COALESCE(p.nome, "-") AS equipamento
    ' . $sqlBase . '
    ORDER BY m.data_movimentacao DESC, m.id DESC
');
$stmt->execute($params);
$registros = $stmt->fetchAll();

$detalhe = null;
if ($detalheId > 0) {
    $stmt = $pdo->prepare('
        SELECT
            m.*,
            COALESCE(l.nome, "-") AS loja,
            COALESCE(s.nome, "-") AS setor,
            COALESCE(p.nome, "-") AS equipamento
        FROM movimentacoes m
        LEFT JOIN lojas l ON l.id = m.loja_id
        LEFT JOIN setores s ON s.id = m.setor_id
        LEFT JOIN produtos p ON p.id = m.produto_id
        WHERE m.id = :id
          AND m.usuario_id = :usuario_id
        LIMIT 1
    ');
    $stmt->execute([
        ':id' => $detalheId,
        ':usuario_id' => $idUsuario,
    ]);
    $detalhe = $stmt->fetch() ?: null;
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Consultar Entregas - Controle Big TI</title>
    <style>
        :root { --bg:#05080c; --panel:#10151c; --line:#242c37; --text:#f7f8fb; --muted:#a7b0be; --red:#e50914; --green:#27b84d; --radius:8px; --soft:#717b8b; }
        * { box-sizing: border-box; }
        body { margin:0; min-height:100vh; color:var(--text); font-family:"Inter","Segoe UI",Arial,sans-serif; background:radial-gradient(circle at 70% 0%, rgba(40,48,62,.24), transparent 35%),linear-gradient(135deg,#040609 0%,#081018 52%,#05070b 100%); animation:pageFadeIn .24s ease both; transition:opacity .22s ease,transform .22s ease; }
        @media (min-width:1024px){ body{ zoom:.82; min-height:122vh; } }
        body.page-leaving { opacity:0; transform:translateY(4px); }
        @keyframes pageFadeIn { from{opacity:0;transform:translateY(4px)} to{opacity:1;transform:translateY(0)} }
        a { color:inherit; text-decoration:none; }
        .app { display:grid; grid-template-columns:280px minmax(0,1fr); min-height:100vh; }
        .sidebar { position:sticky; top:0; height:100vh; border-right:1px solid var(--line); background:rgba(4,8,13,.94); display:flex; flex-direction:column; padding:30px 22px 22px; }
        @media (min-width:1024px){ .app{min-height:122vh}.sidebar{height:122vh} }
        .brand { display:flex; align-items:flex-start; height:70px; font-size:42px; font-weight:800; line-height:1; }
        .brand span:nth-child(2) { color:var(--red); }
        .brand .plus { color:#fff; background:var(--red); width:18px; height:18px; border-radius:50%; font-size:15px; line-height:17px; display:inline-flex; align-items:center; justify-content:center; margin-left:4px; }
        .nav { display:grid; gap:14px; margin-top:22px; }
        .nav-item { min-height:50px; border-radius:var(--radius); display:flex; align-items:center; gap:12px; padding:0 14px; font-size:14px; font-weight:700; border:1px solid transparent; transition:background .2s ease,border-color .2s ease,transform .2s ease,box-shadow .2s ease; }
        .nav-item:hover { background:rgba(255,255,255,.045); border-color:rgba(255,255,255,.075); transform:translateX(2px); }
        .nav-item.active { background:linear-gradient(135deg,var(--red),#f01520); box-shadow:0 14px 30px rgba(229,9,20,.24); }
        .nav-item svg,.logout svg { width:21px; height:21px; flex:0 0 auto; }
        .sidebar-footer { margin-top:auto; border-top:1px solid var(--line); padding-top:18px; display:grid; gap:14px; }
        .profile { display:flex; align-items:center; gap:9px; }
        .avatar { width:32px; height:32px; border:1px solid rgba(255,255,255,.86); border-radius:50%; display:grid; place-items:center; color:#fff; }
        .avatar svg { width:18px; height:18px; }
        .profile strong { display:block; font-size:12px; line-height:1.2; }
        .profile span { display:inline-flex; align-items:center; gap:6px; color:#dce1ea; font-size:10.5px; margin-top:2px; }
        .online-dot { width:7px; height:7px; border-radius:50%; background:var(--green); box-shadow:0 0 0 3px rgba(39,184,77,.12); }
        .logout { display:inline-flex; align-items:center; gap:10px; min-height:44px; width:100%; padding:0 12px; font-size:14px; font-weight:700; border:1px solid transparent; border-radius:var(--radius); transition:background .2s ease,border-color .2s ease,transform .2s ease; }
        .logout:hover { background:rgba(255,255,255,.045); border-color:rgba(255,255,255,.075); transform:translateY(-1px); }
        .topbar { height:84px; border-bottom:1px solid var(--line); display:flex; justify-content:space-between; align-items:center; padding:0 32px; background:rgba(5,8,12,.48); }
        .mobile-menu { color:#fff; font-size:14px; font-weight:800; }
        .ti-user { margin-left:auto; color:#f4f6fa; font-size:15px; font-weight:800; letter-spacing:.2px; }
        .content { padding:28px 32px 36px; }
        .page-title { display:flex; align-items:center; gap:18px; margin-bottom:22px; }
        .title-icon { width:64px; height:64px; border-radius:var(--radius); background:linear-gradient(135deg,#8d1119,#4a090e); display:grid; place-items:center; }
        .title-icon svg { width:31px; height:31px; }
        .page-title h1 { margin:0 0 5px; font-size:26px; }
        .page-title p { margin:0; color:#d6dbe5; font-size:14px; }
        .panel { background:linear-gradient(150deg,rgba(255,255,255,.045),transparent 40%),rgba(17,22,30,.88); border:1px solid var(--line); border-radius:var(--radius); margin-bottom:18px; overflow:hidden; }
        .filters { display:grid; grid-template-columns:repeat(5,minmax(150px,1fr)); gap:14px; padding:20px 22px; align-items:end; }
        label { display:grid; gap:7px; font-size:13px; font-weight:700; }
        input,select { height:46px; border:1px solid var(--line); border-radius:var(--radius); background:#10151c; color:#fff; padding:0 14px; font:inherit; font-size:14px; }
        .filter-actions { display:flex; gap:10px; align-items:center; grid-column:1 / -1; justify-content:flex-end; }
        .btn { min-height:42px; border:1px solid var(--line); border-radius:var(--radius); background:transparent; color:#fff; padding:0 14px; display:inline-flex; align-items:center; justify-content:center; font-weight:800; cursor:pointer; }
        .btn.primary { background:var(--red); border-color:var(--red); }
        .table-wrap { overflow-x:auto; }
        table { width:100%; border-collapse:collapse; min-width:980px; }
        th,td { text-align:left; padding:16px 18px; border-bottom:1px solid rgba(255,255,255,.08); white-space:nowrap; }
        th { font-size:13px; color:#f1f4f8; }
        td { font-size:14px; }
        .badge { display:inline-flex; min-height:26px; align-items:center; padding:0 10px; border-radius:6px; background:rgba(24,98,161,.48); font-weight:800; }
        .badge.ok { background:rgba(36,160,71,.35); }
        .empty { padding:22px; color:var(--muted); }
        .detail { padding:22px; display:grid; gap:12px; }
        .detail strong { color:#fff; }
        @media (max-width:1050px) { .app{grid-template-columns:1fr}.sidebar{position:static;height:auto}.filters{grid-template-columns:repeat(2,minmax(0,1fr))} }
        @media (max-width:640px) { .content{padding:22px}.filters{grid-template-columns:1fr}.filter-actions{justify-content:flex-start;flex-direction:column;align-items:stretch} }
    </style>
</head>
<body>
<div class="app">
    <aside class="sidebar">
        <div class="brand"><span>Big</span><span>mais</span><span class="plus">+</span></div>
        <nav class="nav">
            <a class="nav-item" href="dashboard.php">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M12 18v-6"/><path d="M9 15h6"/></svg>
                Nova Entrega
            </a>
            <a class="nav-item active" href="consultar_entregas.php">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M8 2v4"/><path d="M16 2v4"/><path d="M3 10h18"/><path d="M8 14h.01"/><path d="M12 14h.01"/><path d="M16 14h.01"/><path d="M8 18h.01"/><path d="M12 18h.01"/></svg>
                Consultar Entregas
            </a>
        </nav>
        <div class="sidebar-footer">
            <div class="profile"><div class="avatar"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="5"/><path d="M20 21a8 8 0 0 0-16 0"/></svg></div><div><strong><?php echo e($nomeUsuario); ?></strong><span><i class="online-dot"></i>Online</span></div></div>
            <a class="logout" href="../logout.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5"/><path d="M21 12H9"/></svg>Sair</a>
        </div>
    </aside>
    <main>
        <header class="topbar"><div class="ti-user">TI <?php echo e($nomeUsuario); ?></div></header>
        <section class="content">
            <div class="page-title">
                <div class="title-icon"><svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M8 2v4"/><path d="M16 2v4"/><path d="M3 10h18"/></svg></div>
                <div><h1>Consultar Entregas</h1><p>Pesquise e acompanhe as entregas ou trocas de equipamentos realizadas.</p></div>
            </div>
            <section class="panel">
                <form class="filters" method="get" action="consultar_entregas.php">
                    <label>Data inicial<input type="date" name="data_inicial" value="<?php echo e($dataInicial); ?>"></label>
                    <label>Data final<input type="date" name="data_final" value="<?php echo e($dataFinal); ?>"></label>
                    <label>Loja
                        <select name="loja_id">
                            <option value="">Todas</option>
                            <?php foreach ($lojas as $loja): ?>
                                <option value="<?php echo e($loja['valor']); ?>" <?php echo $lojaValue === $loja['valor'] ? 'selected' : ''; ?>><?php echo e($loja['nome']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>Tipo
                        <select name="tipo">
                            <option value="">Todos</option>
                            <option value="ENTREGA" <?php echo $tipo === 'ENTREGA' ? 'selected' : ''; ?>>Entrega</option>
                            <option value="TROCA" <?php echo $tipo === 'TROCA' ? 'selected' : ''; ?>>Troca</option>
                        </select>
                    </label>
                    <label>Status
                        <select name="status">
                            <option value="">Todos</option>
                            <?php foreach ($statusOptions as $statusItem): ?>
                                <?php $statusValue = strtoupper((string) $statusItem['status']); ?>
                                <option value="<?php echo e($statusValue); ?>" <?php echo $status === $statusValue ? 'selected' : ''; ?>><?php echo e($statusItem['status']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <div class="filter-actions">
                        <button class="btn primary" type="submit">Pesquisar</button>
                        <a class="btn" href="consultar_entregas.php">Limpar</a>
                    </div>
                </form>
            </section>
            <?php if ($detalhe): ?>
                <section class="panel">
                    <div class="detail">
                        <strong>Detalhes da movimentação #<?php echo (int) $detalhe['id']; ?></strong>
                        <span>Data: <?php echo e(dataBr($detalhe['data_movimentacao'])); ?></span>
                        <span>Equipamento: <?php echo e($detalhe['equipamento']); ?></span>
                        <span>Loja: <?php echo e($detalhe['loja']); ?></span>
                        <span>Setor: <?php echo e($detalhe['setor']); ?></span>
                        <span>Quantidade: <?php echo (int) $detalhe['quantidade']; ?></span>
                        <span>Tipo: <?php echo e($detalhe['tipo']); ?></span>
                        <span>Status: <?php echo e($detalhe['status']); ?></span>
                        <span>Justificativa: <?php echo e($detalhe['justificativa']); ?></span>
                    </div>
                </section>
            <?php endif; ?>
            <section class="panel">
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Data</th>
                                <th>Solicitante</th>
                                <th>Loja</th>
                                <th>Setor</th>
                                <th>Equipamento</th>
                                <th>Quantidade</th>
                                <th>Tipo</th>
                                <th>Status</th>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($registros as $registro): ?>
                                <tr>
                                    <td><?php echo e(dataBr($registro['data_movimentacao'])); ?></td>
                                    <td><?php echo e($registro['solicitante_nome']); ?></td>
                                    <td><?php echo e($registro['loja']); ?></td>
                                    <td><?php echo e($registro['setor']); ?></td>
                                    <td><?php echo e($registro['equipamento']); ?></td>
                                    <td><?php echo (int) $registro['quantidade']; ?></td>
                                    <td><?php echo e($registro['tipo']); ?></td>
                                    <td><span class="badge ok"><?php echo e($registro['status']); ?></span></td>
                                    <td><a class="btn" href="consultar_entregas.php?<?php echo e(http_build_query(array_merge($_GET, ['detalhe' => (int) $registro['id']]))); ?>">Visualizar</a></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (empty($registros)): ?>
                                <tr><td colspan="9">Nenhuma movimentação registrada.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        </section>
    </main>
</div>
<script>
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
</script>
</body>
</html>
