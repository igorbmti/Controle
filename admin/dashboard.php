<?php
require_once __DIR__ . '/../includes/auth.php';

verificarLogin('ADMIN');

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function fetchOneSafe(string $sql, array $fallback = []): array
{
    try {
        $stmt = getConnection()->query($sql);
        return $stmt->fetch() ?: $fallback;
    } catch (Throwable $e) {
        error_log('Dashboard query error: ' . $e->getMessage());
        return $fallback;
    }
}

function fetchAllSafe(string $sql): array
{
    try {
        $stmt = getConnection()->query($sql);
        return $stmt->fetchAll();
    } catch (Throwable $e) {
        error_log('Dashboard query error: ' . $e->getMessage());
        return [];
    }
}

function fetchValueSafe(string $sql): int
{
    try {
        $value = getConnection()->query($sql)->fetchColumn();
        return (int) $value;
    } catch (Throwable $e) {
        error_log('Dashboard query error: ' . $e->getMessage());
        return 0;
    }
}

function fetchOnePrepared(string $sql, array $params = [], array $fallback = []): array
{
    try {
        $stmt = getConnection()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch() ?: $fallback;
    } catch (Throwable $e) {
        error_log('Dashboard query error: ' . $e->getMessage());
        return $fallback;
    }
}

function fetchAllPrepared(string $sql, array $params = []): array
{
    try {
        $stmt = getConnection()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    } catch (Throwable $e) {
        error_log('Dashboard query error: ' . $e->getMessage());
        return [];
    }
}

function columnExists(string $table, string $column): bool
{
    try {
        $stmt = getConnection()->prepare("
            SELECT COUNT(*)
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = :table_name
              AND COLUMN_NAME = :column_name
        ");
        $stmt->execute([
            ':table_name' => $table,
            ':column_name' => $column,
        ]);
        return (int) $stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        error_log('Dashboard metadata error: ' . $e->getMessage());
        return false;
    }
}

function normalizeStatus(?string $status): string
{
    $value = strtoupper(trim((string) $status));

    return match ($value) {
        'CONCLUIDA' => 'Concluída',
        'EM AVALIACAO', 'EM_AVALIACAO' => 'Em avaliação',
        'PENDENTE' => 'Pendente',
        'CANCELADA' => 'Cancelada',
        default => (string) $status,
    };
}

function pluralAtendimentos(int $total): string
{
    return $total . ' ' . ($total === 1 ? 'atendimento' : 'atendimentos');
}

function textoMapaAtendimentos(int $total): string
{
    if ($total === 0) {
        return 'Sem movimentações';
    }

    return pluralAtendimentos($total);
}

$perfilColumn = columnExists('usuarios', 'perfil') ? 'perfil' : 'nivel';
$selectedMonth = max(1, min(12, (int) ($_GET['mes'] ?? date('n'))));
$currentYear = (int) date('Y');
$perPage = 5;
$selectedLojaIds = parseLojaIds($_GET['lojas'] ?? ($_GET['loja'] ?? ''));

function monthRange(int $month, int $year): array
{
    $start = sprintf('%04d-%02d-01 00:00:00', $year, $month);
    $end = date('Y-m-d H:i:s', strtotime($start . ' +1 month'));

    return [$start, $end];
}

function parseLojaIds(mixed $value): array
{
    if (is_array($value)) {
        $parts = $value;
    } else {
        $parts = preg_split('/,/', (string) $value) ?: [];
    }

    $ids = array_values(array_unique(array_filter(array_map('intval', $parts), static fn(int $id): bool => $id > 0)));

    return $ids;
}

function lojaClause(array $lojaIds, string $alias = 'm'): array
{
    if (empty($lojaIds)) {
        return ['', []];
    }

    $column = $alias === '' ? 'loja_id' : "{$alias}.loja_id";
    $placeholders = [];
    $params = [];

    foreach (array_values($lojaIds) as $index => $id) {
        $key = ":loja_id_{$index}";
        $placeholders[] = $key;
        $params[$key] = $id;
    }

    return [" AND {$column} IN (" . implode(', ', $placeholders) . ")", $params];
}

function normalizeLojaNome(string $nome): ?string
{
    $clean = trim($nome);
    $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $clean);
    $ascii = $ascii !== false ? $ascii : $clean;
    $ascii = strtoupper(preg_replace('/\s+/', ' ', $ascii));

    if (preg_match('/LOJA\s*0?(\d+)\b/', $ascii, $matches)) {
        $numero = (int) $matches[1];
        if (in_array($numero, [3, 7], true)) {
            return null;
        }
        return 'Loja ' . str_pad((string) $numero, 2, '0', STR_PAD_LEFT);
    }

    if (preg_match('/DEP\S*ITO\s*(73|77)\b/', $ascii, $matches) || preg_match('/DEPOSITO\s*(73|77)\b/', $ascii, $matches)) {
        return 'Depósito ' . $matches[1];
    }

    return $clean;
}

function lojaOrder(string $nome): int
{
    if (preg_match('/^Loja\s+0?(\d+)$/i', $nome, $matches)) {
        $numero = (int) $matches[1];
        if ($numero === 3 || $numero === 7) {
            return 500 + $numero;
        }
        if ($numero === 8) {
            return 8;
        }
        if ($numero === 9) {
            return 9;
        }
        return $numero <= 6 ? $numero : 100 + $numero;
    }

    $order = [
        'Loja 01' => 1,
        'Loja 02' => 2,
        'Depósito 73' => 3,
        'Loja 04' => 4,
        'Loja 05' => 5,
        'Loja 06' => 6,
        'Depósito 77' => 7,
        'Loja 08' => 8,
        'Loja 09' => 9,
    ];

    return $order[$nome] ?? 500;
}

function fetchLojasDashboard(): array
{
    $rows = fetchAllSafe("SELECT DISTINCT id, nome FROM lojas ORDER BY id, nome");
    $grouped = [];

    foreach ($rows as $row) {
        $nome = normalizeLojaNome((string) $row['nome']);
        if ($nome === null) {
            continue;
        }

        $grouped[$nome] ??= ['nome' => $nome, 'ids' => []];
        $grouped[$nome]['ids'][] = (int) $row['id'];
    }

    usort($grouped, static fn(array $a, array $b): int => lojaOrder($a['nome']) <=> lojaOrder($b['nome']));

    return array_values($grouped);
}

function fetchUsuariosDashboard(string $perfilColumn): array
{
    return fetchAllSafe("
        SELECT id, nome
        FROM usuarios
        WHERE ativo = 1
          AND UPPER(COALESCE({$perfilColumn}, '')) <> 'ADMIN'
        ORDER BY nome
    ");
}

function parseDashboardFilters(): array
{
    return [
        'data_inicial' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['data_inicial'] ?? ''))
            ? (string) $_GET['data_inicial']
            : date('Y-m-01'),
        'data_final' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['data_final'] ?? ''))
            ? (string) $_GET['data_final']
            : date('Y-m-t'),
        'loja_ids' => parseLojaIds($_GET['lojas'] ?? ($_GET['loja'] ?? '')),
        'usuario_id' => max(0, (int) ($_GET['usuario_id'] ?? 0)),
    ];
}

function buildMovimentacaoWhere(array $filters, string $alias = 'm'): array
{
    $where = [];
    $params = [];

    if (!empty($filters['data_inicial'])) {
        $where[] = "{$alias}.data_movimentacao >= :data_inicial";
        $params[':data_inicial'] = $filters['data_inicial'] . ' 00:00:00';
    }

    if (!empty($filters['data_final'])) {
        $where[] = "{$alias}.data_movimentacao < :data_final_next";
        $params[':data_final_next'] = date('Y-m-d 00:00:00', strtotime($filters['data_final'] . ' +1 day'));
    }

    [$lojaSql, $lojaParams] = lojaClause($filters['loja_ids'] ?? [], $alias);
    if ($lojaSql !== '') {
        $where[] = preg_replace('/^\s*AND\s+/', '', $lojaSql);
        $params = array_merge($params, $lojaParams);
    }

    if (!empty($filters['usuario_id'])) {
        $where[] = "{$alias}.usuario_id = :usuario_id";
        $params[':usuario_id'] = (int) $filters['usuario_id'];
    }

    return [$where ? 'WHERE ' . implode(' AND ', $where) : '', $params];
}

function buildEntregaWhere(array $filters, string $alias = 'e'): array
{
    $where = [];
    $params = [];

    if (!empty($filters['data_inicial'])) {
        $where[] = "{$alias}.data_entrega >= :data_inicial";
        $params[':data_inicial'] = $filters['data_inicial'] . ' 00:00:00';
    }

    if (!empty($filters['data_final'])) {
        $where[] = "{$alias}.data_entrega < :data_final_next";
        $params[':data_final_next'] = date('Y-m-d 00:00:00', strtotime($filters['data_final'] . ' +1 day'));
    }

    [$lojaSql, $lojaParams] = lojaClause($filters['loja_ids'] ?? [], $alias);
    if ($lojaSql !== '') {
        $where[] = preg_replace('/^\s*AND\s+/', '', $lojaSql);
        $params = array_merge($params, $lojaParams);
    }

    if (!empty($filters['usuario_id'])) {
        $where[] = "{$alias}.usuario_id = :usuario_id";
        $params[':usuario_id'] = (int) $filters['usuario_id'];
    }

    return [$where ? 'WHERE ' . implode(' AND ', $where) : '', $params];
}

function fetchChartData(int $month, int $year, array $lojas = [], array $lojaIds = []): array
{
    try {
        [$start, $end] = monthRange($month, $year);
        $allLojaIds = [];
        foreach ($lojas as $loja) {
            $allLojaIds = array_merge($allLojaIds, $loja['ids']);
        }
        $activeLojaIds = empty($lojaIds)
            ? array_values(array_unique($allLojaIds))
            : $lojaIds;
        [$lojaSql, $lojaParams] = lojaClause($activeLojaIds, 'm');
        $stmt = getConnection()->prepare("
            SELECT
                DAY(m.data_movimentacao) AS dia,
                m.loja_id,
                COUNT(*) AS total
            FROM movimentacoes m
            WHERE m.data_movimentacao >= :start
              AND m.data_movimentacao < :end
              {$lojaSql}
            GROUP BY DAY(m.data_movimentacao), m.loja_id
            ORDER BY dia, m.loja_id
        ");
        $stmt->execute(array_merge([
            ':start' => $start,
            ':end' => $end,
        ], $lojaParams));

        $rows = $stmt->fetchAll();
        $daysInMonth = cal_days_in_month(CAL_GREGORIAN, $month, $year);
        $labels = [];
        $datasets = [];
        $lojaById = [];

        for ($day = 1; $day <= $daysInMonth; $day++) {
            $labels[] = str_pad((string) $day, 2, '0', STR_PAD_LEFT);
        }

        foreach ($lojas as $index => $loja) {
            $datasets[$loja['nome']] = [
                'label' => $loja['nome'],
                'lojaIds' => $loja['ids'],
                'data' => array_fill(1, $daysInMonth, 0),
                'colorIndex' => $index,
            ];

            foreach ($loja['ids'] as $id) {
                $lojaById[(int) $id] = $loja['nome'];
            }
        }

        foreach ($rows as $row) {
            $day = (int) $row['dia'];
            $lojaNome = $lojaById[(int) $row['loja_id']] ?? null;

            if ($lojaNome !== null && isset($datasets[$lojaNome]['data'][$day])) {
                $datasets[$lojaNome]['data'][$day] += (int) $row['total'];
            }
        }

        return [
            'labels' => $labels,
            'datasets' => array_values(array_map(static function (array $dataset): array {
                $dataset['data'] = array_values($dataset['data']);
                return $dataset;
            }, $datasets)),
        ];
    } catch (Throwable $e) {
        error_log('Dashboard chart error: ' . $e->getMessage());
        return ['labels' => [], 'datasets' => []];
    }
}

function recentBaseSql(): string
{
    return "
        FROM (
            SELECT
                m.data_movimentacao AS data_entrega,
                COALESCE(u.nome, '-') AS usuario,
                COALESCE(m.solicitante_nome, f.nome, '-') AS solicitante,
                COALESCE(l.nome, '-') AS loja,
                m.loja_id,
                COALESCE(p.nome, '-') AS equipamento,
                m.tipo,
                m.status
            FROM movimentacoes m
            LEFT JOIN funcionarios f ON f.id = m.funcionario_id
            LEFT JOIN lojas l ON l.id = m.loja_id
            LEFT JOIN produtos p ON p.id = m.produto_id
            LEFT JOIN usuarios u ON u.id = m.usuario_id
        ) recentes
    ";
}

function fetchRecentPage(int $page, int $perPage, array $lojaIds = []): array
{
    $page = max(1, $page);
    $offset = ($page - 1) * $perPage;

    try {
        [$lojaSql, $lojaParams] = lojaClause($lojaIds, '');
        $where = $lojaSql !== '' ? ' WHERE ' . preg_replace('/^\s*AND\s+/', '', $lojaSql) : '';
        $totalStmt = getConnection()->prepare('SELECT COUNT(*) ' . recentBaseSql() . $where);
        foreach ($lojaParams as $key => $value) {
            $totalStmt->bindValue($key, $value, PDO::PARAM_INT);
        }
        $totalStmt->execute();
        $total = (int) $totalStmt->fetchColumn();

        $stmt = getConnection()->prepare('SELECT * ' . recentBaseSql() . $where . ' ORDER BY data_entrega DESC LIMIT :limit OFFSET :offset');
        foreach ($lojaParams as $key => $value) {
            $stmt->bindValue($key, $value, PDO::PARAM_INT);
        }
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $rows = array_map(static function (array $row): array {
            $row['status'] = normalizeStatus($row['status'] ?? '');
            return $row;
        }, $stmt->fetchAll());

        return [
            'rows' => $rows,
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
            'totalPages' => (int) ceil($total / $perPage),
        ];
    } catch (Throwable $e) {
        error_log('Dashboard table error: ' . $e->getMessage());
        return ['rows' => [], 'total' => 0, 'page' => 1, 'perPage' => $perPage, 'totalPages' => 0];
    }
}

function fetchPorTipo(array $lojaIds = []): array
{
    [$lojaSql, $lojaParams] = lojaClause($lojaIds);

    return fetchAllPrepared(
        "
        SELECT UPPER(tipo) AS tipo, COUNT(*) AS total
        FROM movimentacoes m
        WHERE 1 = 1
          {$lojaSql}
        GROUP BY UPPER(tipo)
        ORDER BY tipo
        ",
        $lojaParams
    );
}

function fetchAtividades(array $lojaIds = [], int $limit = 5): array
{
    [$lojaSql, $lojaParams] = lojaClause($lojaIds, 'm');
    $params = array_merge($lojaParams, [':limit' => $limit]);

    try {
        $stmt = getConnection()->prepare(
            "
            SELECT
                m.data_movimentacao AS data_entrega,
                COALESCE(u.nome, '-') AS usuario,
                COALESCE(p.nome, '-') AS equipamento,
                m.tipo
            FROM movimentacoes m
            LEFT JOIN usuarios u ON u.id = m.usuario_id
            LEFT JOIN produtos p ON p.id = m.produto_id
            WHERE 1 = 1
              {$lojaSql}
            ORDER BY m.data_movimentacao DESC
            LIMIT :limit
            "
        );

        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }

        $stmt->execute();
        return $stmt->fetchAll();
    } catch (Throwable $e) {
        error_log('Dashboard activity error: ' . $e->getMessage());
        return [];
    }
}

function summarizeTipos(array $porTipo): array
{
    $total = array_sum(array_map(static fn($row) => (int) $row['total'], $porTipo));
    $entregas = 0;
    $trocas = 0;

    foreach ($porTipo as $tipo) {
        if (strtoupper((string) $tipo['tipo']) === 'ENTREGA') {
            $entregas = (int) $tipo['total'];
        }
        if (strtoupper((string) $tipo['tipo']) === 'TROCA') {
            $trocas = (int) $tipo['total'];
        }
    }

    return [
        'total' => $total,
        'entregas' => $entregas,
        'trocas' => $trocas,
        'entregasPercent' => $total > 0 ? round(($entregas / $total) * 100, 1) : 0,
        'trocasPercent' => $total > 0 ? round(($trocas / $total) * 100, 1) : 0,
    ];
}

function fetchTopEntregaMes(array $lojaIds = []): array
{
    [$start, $end] = monthRange((int) date('n'), (int) date('Y'));
    [$lojaSql, $lojaParams] = lojaClause($lojaIds, 'm');

    return fetchOnePrepared(
        "
        SELECT COALESCE(p.nome, 'Sem equipamento') AS equipamento, SUM(m.quantidade) AS total
        FROM movimentacoes m
        LEFT JOIN produtos p ON p.id = m.produto_id
        WHERE UPPER(m.tipo) = 'ENTREGA'
          AND m.data_movimentacao >= :start
          AND m.data_movimentacao < :end
          {$lojaSql}
        GROUP BY p.id, p.nome
        ORDER BY total DESC, equipamento
        LIMIT 1
        ",
        array_merge([':start' => $start, ':end' => $end], $lojaParams),
        ['equipamento' => '', 'total' => 0]
    );
}

function fetchTopTrocaMes(array $lojaIds = []): array
{
    [$start, $end] = monthRange((int) date('n'), (int) date('Y'));
    [$lojaSql, $lojaParams] = lojaClause($lojaIds, 'm');

    return fetchOnePrepared(
        "
        SELECT COALESCE(p.nome, 'Sem equipamento') AS equipamento, SUM(m.quantidade) AS total
        FROM movimentacoes m
        LEFT JOIN produtos p ON p.id = m.produto_id
        WHERE UPPER(m.tipo) = 'TROCA'
          AND m.data_movimentacao >= :start
          AND m.data_movimentacao < :end
          {$lojaSql}
        GROUP BY p.id, p.nome
        ORDER BY total DESC, equipamento
        LIMIT 1
        ",
        array_merge([':start' => $start, ':end' => $end], $lojaParams),
        ['equipamento' => '', 'total' => 0]
    );
}

function fetchTopProdutoPorTipo(array $filters, string $tipo): array
{
    [$whereSql, $params] = buildMovimentacaoWhere($filters);
    $params[':tipo'] = $tipo;

    return fetchOnePrepared(
        "
        SELECT COALESCE(p.nome, 'Sem equipamento') AS equipamento, COALESCE(SUM(m.quantidade), 0) AS total
        FROM movimentacoes m
        LEFT JOIN produtos p ON p.id = m.produto_id
        {$whereSql}
          " . ($whereSql ? 'AND' : 'WHERE') . " UPPER(m.tipo) = UPPER(:tipo)
        GROUP BY p.id, p.nome
        ORDER BY total DESC, equipamento
        LIMIT 1
        ",
        $params,
        ['equipamento' => '', 'total' => 0]
    );
}

function fetchLojaMaiorDemanda(array $filters): array
{
    [$whereSql, $params] = buildMovimentacaoWhere($filters);

    $rows = fetchAllPrepared(
        "
        SELECT
            COALESCE(l.nome, 'Loja nao informada') AS loja,
            COUNT(*) AS total,
            SUM(CASE WHEN UPPER(m.tipo) = 'ENTREGA' THEN 1 ELSE 0 END) AS entregas,
            SUM(CASE WHEN UPPER(m.tipo) = 'TROCA' THEN 1 ELSE 0 END) AS trocas
        FROM movimentacoes m
        LEFT JOIN lojas l ON l.id = m.loja_id
        {$whereSql}
        GROUP BY m.loja_id, l.nome
        ORDER BY total DESC, entregas DESC, trocas DESC, loja
        ",
        $params
    );

    $grouped = [];
    foreach ($rows as $row) {
        $nome = normalizeLojaNome((string) $row['loja']) ?? (string) $row['loja'];
        $grouped[$nome] ??= ['loja' => $nome, 'total' => 0, 'entregas' => 0, 'trocas' => 0];
        $grouped[$nome]['total'] += (int) $row['total'];
        $grouped[$nome]['entregas'] += (int) $row['entregas'];
        $grouped[$nome]['trocas'] += (int) $row['trocas'];
    }

    if (empty($grouped)) {
        return ['loja' => '', 'total' => 0, 'entregas' => 0, 'trocas' => 0];
    }

    usort($grouped, static function (array $a, array $b): int {
        $byTotal = $b['total'] <=> $a['total'];
        return $byTotal !== 0 ? $byTotal : lojaOrder($a['loja']) <=> lojaOrder($b['loja']);
    });

    return $grouped[0];
}

function fetchSetorMaiorOperacao(array $filters): array
{
    [$whereSql, $params] = buildMovimentacaoWhere($filters);

    return fetchOnePrepared(
        "
        SELECT
            COALESCE(s.nome, 'Sem setor') AS setor,
            COUNT(m.id) AS total
        FROM movimentacoes m
        LEFT JOIN setores s ON s.id = m.setor_id
        {$whereSql}
        GROUP BY s.id, s.nome
        ORDER BY total DESC, setor
        LIMIT 1
        ",
        $params,
        ['setor' => '', 'total' => 0]
    );
}

function fetchEstoqueCritico(array $filters = [], int $minimo = 2): array
{
    [$lojaSql, $lojaParams] = lojaClause($filters['loja_ids'] ?? [], 'ee');

    return fetchOnePrepared(
        "
        SELECT COUNT(*) AS itens, COALESCE(SUM(quantidade), 0) AS quantidade
        FROM estoque_equipamentos ee
        WHERE ee.quantidade <= :minimo
        {$lojaSql}
        ",
        array_merge([':minimo' => $minimo], $lojaParams),
        ['itens' => 0, 'quantidade' => 0]
    );
}

function fetchCardsGerenciais(array $filters, string $perfilColumn): array
{
    $demandaFilters = $filters;
    $demandaFilters['loja_ids'] = [];
    $demandaFilters['usuario_id'] = 0;

    $estoqueFilters = $filters;
    $estoqueFilters['loja_ids'] = [];
    $estoqueFilters['usuario_id'] = 0;

    return [
        'lojaMaiorDemanda' => fetchLojaMaiorDemanda($demandaFilters),
        'setorMaiorOperacao' => fetchSetorMaiorOperacao($filters),
        'topTroca' => fetchTopProdutoPorTipo($filters, 'TROCA'),
        'estoqueCritico' => fetchEstoqueCritico($estoqueFilters),
    ];
}

function fetchMapaCalorLojas(array $filters, array $lojas): array
{
    $join = [];
    $params = [];

    if (!empty($filters['data_inicial'])) {
        $join[] = 'm.data_movimentacao >= :data_inicial';
        $params[':data_inicial'] = $filters['data_inicial'] . ' 00:00:00';
    }

    if (!empty($filters['data_final'])) {
        $join[] = 'm.data_movimentacao < :data_final_next';
        $params[':data_final_next'] = date('Y-m-d 00:00:00', strtotime($filters['data_final'] . ' +1 day'));
    }

    if (!empty($filters['usuario_id'])) {
        $join[] = 'm.usuario_id = :usuario_id';
        $params[':usuario_id'] = (int) $filters['usuario_id'];
    }

    $joinSql = $join ? ' AND ' . implode(' AND ', $join) : '';
    $rows = fetchAllPrepared(
        "
        SELECT
            l.id,
            l.nome,
            COUNT(m.id) AS total
        FROM lojas l
        LEFT JOIN movimentacoes m
            ON m.loja_id = l.id
            {$joinSql}
        GROUP BY l.id, l.nome
        ORDER BY l.id
        ",
        $params
    );

    $totals = [];
    foreach ($rows as $row) {
        $totals[(int) $row['id']] = (int) $row['total'];
    }

    $mapa = [];
    foreach ($lojas as $loja) {
        $total = 0;
        foreach ($loja['ids'] as $id) {
            $total += $totals[(int) $id] ?? 0;
        }
        $mapa[] = [
            'nome' => $loja['nome'],
            'ids' => $loja['ids'],
            'total' => $total,
        ];
    }

    usort($mapa, static fn(array $a, array $b): int => lojaOrder($a['nome']) <=> lojaOrder($b['nome']));

    return $mapa;
}

function findLojaByIds(array $lojas, array $lojaIds): ?array
{
    $selected = array_values(array_unique(array_map('intval', $lojaIds)));
    sort($selected);

    foreach ($lojas as $loja) {
        $ids = array_values(array_unique(array_map('intval', $loja['ids'] ?? [])));
        sort($ids);

        if ($ids === $selected) {
            return $loja;
        }
    }

    return null;
}

function fetchResumoLoja(array $filters, array $lojas): array
{
    $tipo = fetchTipoGerencial($filters);
    $loja = findLojaByIds($lojas, $filters['loja_ids'] ?? []);

    return [
        'nome' => $loja['nome'] ?? 'Loja selecionada',
        'ids' => $filters['loja_ids'] ?? [],
        'entregas' => (int) ($tipo['entregas'] ?? 0),
        'trocas' => (int) ($tipo['trocas'] ?? 0),
        'total' => (int) ($tipo['total'] ?? 0),
    ];
}

function fetchGraficoPrincipal(array $filters, array $lojas): array
{
    return [
        'titulo' => 'Mapa de Calor das Lojas',
        'subtitulo' => 'Volume por loja no período',
        'lojas' => fetchMapaCalorLojas($filters, $lojas),
        'loja_ids' => $filters['loja_ids'] ?? [],
    ];

    if (!empty($filters['loja_ids'])) {
        return [
            'modo' => 'loja',
            'titulo' => 'Movimentações da Loja',
            'subtitulo' => 'Clique no gráfico para voltar para todas as lojas',
            'loja' => fetchResumoLoja($filters, $lojas),
        ];
    }

    return [
        'modo' => 'ranking',
        'titulo' => 'Mapa de Calor das Lojas',
        'subtitulo' => 'Clique em uma barra para filtrar o painel',
        'ranking' => fetchMapaCalorLojas($filters, $lojas),
    ];
}

function fetchTipoGerencial(array $filters): array
{
    [$whereSql, $params] = buildMovimentacaoWhere($filters);
    $rows = fetchAllPrepared(
        "
        SELECT UPPER(m.tipo) AS tipo, COALESCE(SUM(m.quantidade), 0) AS total
        FROM movimentacoes m
        {$whereSql}
        GROUP BY UPPER(m.tipo)
        ORDER BY tipo
        ",
        $params
    );

    return summarizeTipos($rows);
}

function fetchProdutosMovimentados(array $filters, int $limit = 5): array
{
    [$whereSql, $params] = buildMovimentacaoWhere($filters);
    $params[':limit'] = $limit;

    try {
        $stmt = getConnection()->prepare(
            "
            SELECT
                COALESCE(p.nome, 'Sem equipamento') AS produto,
                COALESCE(SUM(m.quantidade), 0) AS total,
                COALESCE(SUM(CASE WHEN UPPER(m.tipo) = 'ENTREGA' THEN m.quantidade ELSE 0 END), 0) AS entregas,
                COALESCE(SUM(CASE WHEN UPPER(m.tipo) = 'TROCA' THEN m.quantidade ELSE 0 END), 0) AS trocas
            FROM movimentacoes m
            LEFT JOIN produtos p ON p.id = m.produto_id
            {$whereSql}
            GROUP BY p.id, p.nome
            ORDER BY total DESC, produto
            LIMIT :limit
            "
        );

        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }

        $stmt->execute();
        return $stmt->fetchAll();
    } catch (Throwable $e) {
        error_log('Dashboard produtos ranking error: ' . $e->getMessage());
        return [];
    }
}

function fetchAlertasSistema(array $filters): array
{
    $estoqueNomeColumn = columnExists('estoque_equipamentos', 'nome_equipamento') ? 'ee.nome_equipamento' : "''";
    $rows = fetchAllPrepared(
        "
        SELECT
            COALESCE(NULLIF(p.nome, ''), NULLIF({$estoqueNomeColumn}, ''), 'Item não informado') AS produto,
            ee.quantidade
        FROM estoque_equipamentos ee
        LEFT JOIN produtos p ON p.id = ee.produto_id
        WHERE ee.quantidade <= 2
        ORDER BY ee.quantidade ASC, produto
        LIMIT 5
        "
    );

    $alertas = [];
    foreach ($rows as $row) {
        $quantidade = (int) $row['quantidade'];
        $alertas[] = [
            'tipo' => 'estoque',
            'produto' => (string) $row['produto'],
            'detalhe' => sprintf('Estoque: %d unidade%s', $quantidade, $quantidade === 1 ? '' : 's'),
        ];
    }

    return $alertas;
}

function fetchRecentesGerencial(array $filters, int $limit = 5): array
{
    [$whereSql, $params] = buildMovimentacaoWhere($filters);
    $params[':limit'] = $limit;

    try {
        $stmt = getConnection()->prepare(
            "
            SELECT
                m.data_movimentacao AS data_entrega,
                COALESCE(u.nome, '-') AS usuario,
                COALESCE(l.nome, '-') AS loja,
                COALESCE(p.nome, '-') AS equipamento,
                COALESCE(m.solicitante_nome, f.nome, '-') AS solicitante,
                m.tipo,
                m.status,
                COALESCE(m.justificativa, m.descricao, '') AS justificativa
            FROM movimentacoes m
            LEFT JOIN usuarios u ON u.id = m.usuario_id
            LEFT JOIN lojas l ON l.id = m.loja_id
            LEFT JOIN produtos p ON p.id = m.produto_id
            LEFT JOIN funcionarios f ON f.id = m.funcionario_id
            {$whereSql}
            ORDER BY m.data_movimentacao DESC, m.id DESC
            LIMIT :limit
            "
        );
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->execute();

        return array_map(static function (array $row): array {
            $row['status'] = normalizeStatus($row['status'] ?? '');
            return $row;
        }, $stmt->fetchAll());
    } catch (Throwable $e) {
        error_log('Dashboard recentes error: ' . $e->getMessage());
        return [];
    }
}

function fetchAtividadesGerencial(array $filters, int $limit = 5): array
{
    [$whereSql, $params] = buildMovimentacaoWhere($filters);
    $params[':limit'] = $limit;

    try {
        $stmt = getConnection()->prepare(
            "
            SELECT
                m.data_movimentacao AS data_entrega,
                COALESCE(u.nome, '-') AS usuario,
                COALESCE(p.nome, '-') AS equipamento,
                m.tipo
            FROM movimentacoes m
            LEFT JOIN usuarios u ON u.id = m.usuario_id
            LEFT JOIN produtos p ON p.id = m.produto_id
            {$whereSql}
            ORDER BY m.data_movimentacao DESC, m.id DESC
            LIMIT :limit
            "
        );
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->execute();
        return $stmt->fetchAll();
    } catch (Throwable $e) {
        error_log('Dashboard atividades error: ' . $e->getMessage());
        return [];
    }
}

function fetchDashboardPayload(array $filters, array $lojas, string $perfilColumn): array
{
    return [
        'cards' => fetchCardsGerenciais($filters, $perfilColumn),
        'mapaCalorLojas' => fetchMapaCalorLojas($filters, $lojas),
        'graficoPrincipal' => fetchGraficoPrincipal($filters, $lojas),
        'tipo' => fetchTipoGerencial($filters),
        'produtosRanking' => fetchProdutosMovimentados($filters),
        'alertas' => fetchAlertasSistema($filters),
        'recentes' => [
            'rows' => fetchRecentesGerencial($filters),
            'total' => 0,
            'page' => 1,
            'perPage' => 5,
            'totalPages' => 0,
        ],
        'atividades' => fetchAtividadesGerencial($filters),
    ];
}

$monthNames = [
    1 => 'Janeiro',
    2 => 'Fevereiro',
    3 => 'Março',
    4 => 'Abril',
    5 => 'Maio',
    6 => 'Junho',
    7 => 'Julho',
    8 => 'Agosto',
    9 => 'Setembro',
    10 => 'Outubro',
    11 => 'Novembro',
    12 => 'Dezembro',
];

if (isset($_GET['ajax'])) {
    header('Content-Type: application/json; charset=utf-8');
    $ajaxLojaIds = parseLojaIds($_GET['lojas'] ?? ($_GET['loja'] ?? ''));
    $ajaxLojas = fetchLojasDashboard();
    $ajaxFilters = parseDashboardFilters();
    $ajaxFilters['loja_ids'] = $ajaxLojaIds;

    if ($_GET['ajax'] === 'dashboard') {
        echo json_encode(fetchDashboardPayload($ajaxFilters, $ajaxLojas, $perfilColumn), JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK);
        exit;
    }

    if ($_GET['ajax'] === 'grafico') {
        echo json_encode(fetchChartData(max(1, min(12, (int) ($_GET['mes'] ?? date('n')))), $currentYear, $ajaxLojas, $ajaxLojaIds), JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($_GET['ajax'] === 'recentes') {
        echo json_encode([
            'rows' => fetchRecentesGerencial($ajaxFilters),
            'total' => 0,
            'page' => 1,
            'perPage' => 5,
            'totalPages' => 0,
        ], JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK);
        exit;
    }

    if ($_GET['ajax'] === 'tipo') {
        echo json_encode(fetchTipoGerencial($ajaxFilters), JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK);
        exit;
    }

    if ($_GET['ajax'] === 'atividades') {
        echo json_encode(fetchAtividadesGerencial($ajaxFilters), JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK);
        exit;
    }

    if ($_GET['ajax'] === 'cards') {
        echo json_encode(fetchCardsGerenciais($ajaxFilters, $perfilColumn), JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK);
        exit;
    }

    echo json_encode(['erro' => 'Requisição inválida'], JSON_UNESCAPED_UNICODE);
    exit;
}

$filters = parseDashboardFilters();
$lojas = fetchLojasDashboard();
$usuariosFiltro = fetchUsuariosDashboard($perfilColumn);
$dashboardPayload = fetchDashboardPayload($filters, $lojas, $perfilColumn);
$cardsGerenciais = $dashboardPayload['cards'];
$lojaMaiorDemanda = $cardsGerenciais['lojaMaiorDemanda'];
$setorMaiorOperacao = $cardsGerenciais['setorMaiorOperacao'];
$topTrocaMes = $cardsGerenciais['topTroca'];
$estoqueCritico = $cardsGerenciais['estoqueCritico'];
$mapaCalorLojas = $dashboardPayload['mapaCalorLojas'];
$tipoResumo = $dashboardPayload['tipo'];
$produtosRanking = $dashboardPayload['produtosRanking'];
$alertasSistema = $dashboardPayload['alertas'];
$recentesPage = $dashboardPayload['recentes'];
$recentes = $recentesPage['rows'];
$atividades = $dashboardPayload['atividades'];

$totalTipos = $tipoResumo['total'];
$entregasTipo = $tipoResumo['entregas'];
$trocasTipo = $tipoResumo['trocas'];
$entregasPercent = $tipoResumo['entregasPercent'];
$trocasPercent = $tipoResumo['trocasPercent'];

$nomeUsuario = $_SESSION['nome'] ?? 'Administrador';
$estoqueCriticoUrl = 'estoque.php?critico=1';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Painel de Controle - Controle Big TI</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root {
            --bg: #05080c;
            --panel: #10151c;
            --panel-2: #131922;
            --line: #242c37;
            --text: #f7f8fb;
            --muted: #a7b0be;
            --soft: #717b8b;
            --red: #e50914;
            --red-2: #a10610;
            --green: #27b84d;
            --blue: #2276d2;
            --purple: #8b5bd6;
            --yellow: #f5b301;
            --radius: 8px;
        }

        * {
            box-sizing: border-box;
        }

        html {
            background: var(--bg);
        }

        body {
            margin: 0;
            min-height: 100vh;
            color: var(--text);
            font-family: "Inter", "Segoe UI", Arial, sans-serif;
            background:
                radial-gradient(circle at 70% 0%, rgba(40, 48, 62, .24), transparent 35%),
                linear-gradient(135deg, #040609 0%, #081018 52%, #05070b 100%);
            animation: pageFadeIn .24s ease both;
            transition: opacity .22s ease, transform .22s ease;
        }

        @media (min-width: 1024px) {
            body {
                zoom: .82;
                min-height: 122vh;
            }
        }

        body.page-leaving {
            opacity: 0;
            transform: translateY(4px);
        }

        @keyframes pageFadeIn {
            from { opacity: 0; transform: translateY(4px); }
            to { opacity: 1; transform: translateY(0); }
        }

        a {
            color: inherit;
            text-decoration: none;
        }

        .app {
            display: grid;
            grid-template-columns: 280px minmax(0, 1fr);
            min-height: 100vh;
        }

        .sidebar {
            position: sticky;
            top: 0;
            height: 100vh;
            border-right: 1px solid var(--line);
            background: rgba(4, 8, 13, .94);
            display: flex;
            flex-direction: column;
            padding: 30px 22px 22px;
        }

        @media (min-width: 1024px) {
            .app {
                min-height: 122vh;
            }

            .sidebar {
                height: 122vh;
            }
        }

        .brand {
            display: flex;
            align-items: flex-start;
            height: 70px;
            font-size: 42px;
            font-weight: 800;
            letter-spacing: 0;
            line-height: 1;
        }

        .brand span:first-child {
            color: #fff;
        }

        .brand span:nth-child(2) {
            color: var(--red);
        }

        .brand .plus {
            color: #fff;
            background: var(--red);
            width: 18px;
            height: 18px;
            border-radius: 50%;
            font-size: 15px;
            line-height: 17px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-left: 4px;
            margin-top: 1px;
        }

        .nav {
            display: grid;
            gap: 14px;
            margin-top: 22px;
        }

        .nav-group {
            display: grid;
            gap: 10px;
        }

        .nav-label {
            color: var(--muted);
            font-size: 13px;
            font-weight: 700;
            text-transform: uppercase;
            margin: 18px 14px 4px;
        }

        .nav-item {
            min-height: 50px;
            border-radius: var(--radius);
            color: #fff;
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 0 14px;
            font-weight: 700;
            border: 1px solid transparent;
            transition: background .2s ease, border-color .2s ease, transform .2s ease, box-shadow .2s ease;
        }

        .nav-item svg {
            width: 21px;
            height: 21px;
            flex: 0 0 auto;
        }

        .nav-item:hover {
            background: rgba(255, 255, 255, .045);
            border-color: rgba(255, 255, 255, .075);
            transform: translateX(2px);
        }

        .nav-item.active {
            background: linear-gradient(135deg, var(--red), #f01520);
            box-shadow: 0 10px 24px rgba(229, 9, 20, .22);
        }

        .nav-item.muted {
            color: #e8ecf3;
        }

        .nav-item.compact {
            background: rgba(255, 255, 255, .04);
            border-color: rgba(255, 255, 255, .04);
            align-items: flex-start;
            min-height: 88px;
            padding-top: 16px;
        }

        .nav-item small {
            display: block;
            color: var(--muted);
            font-size: 13px;
            font-weight: 500;
            line-height: 1.35;
            margin-top: 5px;
        }

        .sidebar-footer {
            margin-top: auto;
            border-top: 1px solid var(--line);
            padding-top: 18px;
            display: grid;
            gap: 14px;
        }

        .profile {
            display: flex;
            align-items: center;
            gap: 9px;
        }

        .avatar {
            width: 32px;
            height: 32px;
            border: 1px solid rgba(255, 255, 255, .86);
            border-radius: 50%;
            display: grid;
            place-items: center;
            color: #fff;
        }

        .avatar svg {
            width: 18px;
            height: 18px;
        }

        .profile strong {
            display: block;
            font-size: 12px;
            line-height: 1.2;
        }

        .profile span {
            display: block;
            color: var(--red);
            font-size: 10.5px;
            margin-top: 2px;
        }

        .profile .online-status {
            color: #dce1ea;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .online-dot {
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: var(--green);
            box-shadow: 0 0 0 3px rgba(39, 184, 77, .12);
        }

        .logout {
            display: inline-flex;
            align-items: center;
            justify-content: flex-start;
            gap: 10px;
            color: #fff;
            font-weight: 700;
            min-height: 44px;
            width: 100%;
            padding: 0 12px;
            border: 1px solid transparent;
            border-radius: var(--radius);
            transition: background .2s ease, border-color .2s ease, transform .2s ease;
        }

        .logout:hover {
            background: rgba(255, 255, 255, .045);
            border-color: rgba(255, 255, 255, .075);
            transform: translateY(-1px);
        }

        .main {
            min-width: 0;
        }

        .topbar {
            height: 84px;
            border-bottom: 1px solid var(--line);
            display: flex;
            justify-content: flex-end;
            align-items: center;
            padding: 0 32px;
            background: rgba(5, 8, 12, .48);
        }

        .hello {
            display: flex;
            align-items: center;
            gap: 16px;
            font-size: 17px;
        }

        .hello strong {
            font-weight: 800;
        }

        .hello span {
            color: var(--red);
            display: block;
            text-align: right;
            font-size: 14px;
        }

        .dashboard-scope {
            width: 38px;
            height: 38px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: 1px solid var(--line);
            border-radius: var(--radius);
            background: rgba(255, 255, 255, .035);
            color: #f4f6fa;
            padding: 0;
            font: inherit;
            cursor: pointer;
            transition: border-color .18s ease, background .18s ease, transform .18s ease;
        }

        .dashboard-scope:hover {
            border-color: rgba(255, 255, 255, .16);
            background: rgba(255, 255, 255, .055);
            transform: translateY(-1px);
        }

        .dashboard-scope svg {
            width: 17px;
            height: 17px;
        }

        .content {
            padding: 32px;
        }

        .page-title {
            display: flex;
            align-items: center;
            gap: 22px;
            margin-bottom: 22px;
        }

        .title-icon {
            width: 76px;
            height: 76px;
            border-radius: var(--radius);
            background: linear-gradient(135deg, #17662d, #0b3318);
            color: #9ff3b1;
            display: grid;
            place-items: center;
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, .08);
        }

        .title-icon svg {
            width: 38px;
            height: 38px;
        }

        .page-title h1 {
            margin: 0 0 8px;
            font-size: 30px;
            line-height: 1.15;
        }

        .page-title p {
            margin: 0;
            color: #d6dbe5;
        }

        .cards {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 18px;
            margin-bottom: 18px;
        }

        .dashboard-filters {
            display: grid;
            grid-template-columns: repeat(3, minmax(180px, 1fr));
            gap: 12px;
            margin-bottom: 18px;
            padding: 16px;
        }

        .dashboard-filters label {
            display: grid;
            gap: 7px;
            color: #f4f6fa;
            font-size: 12px;
            font-weight: 800;
        }

        .dashboard-filters input,
        .dashboard-filters select {
            height: 38px;
            border: 1px solid var(--line);
            border-radius: var(--radius);
            background: #10151c;
            color: #fff;
            padding: 0 10px;
            font: inherit;
            min-width: 0;
        }

        .metric-card,
        .panel {
            background:
                linear-gradient(150deg, rgba(255, 255, 255, .045), transparent 40%),
                rgba(17, 22, 30, .88);
            border: 1px solid var(--line);
            border-radius: var(--radius);
            box-shadow: 0 18px 44px rgba(0, 0, 0, .18);
        }

        .metric-card {
            height: 148px;
            min-height: 148px;
            padding: 18px 20px;
            display: grid;
            grid-template-columns: 50px minmax(0, 1fr);
            gap: 16px;
            align-items: center;
            overflow: hidden;
            transition: transform .2s ease, box-shadow .2s ease, border-color .2s ease;
        }

        .metric-card,
        .metric-card-link {
            cursor: pointer;
        }

        .metric-card:hover,
        .metric-card-link:hover {
            border-color: rgba(229, 9, 20, .48);
            transform: translateY(-4px);
            box-shadow: 0 22px 48px rgba(0, 0, 0, .28);
        }

        .metric-card.stock-alert {
            border-color: rgba(245, 179, 1, .48);
            box-shadow: 0 18px 44px rgba(245, 179, 1, .08), 0 18px 44px rgba(0, 0, 0, .18);
        }

        .metric-card.stock-alert:hover {
            border-color: rgba(245, 179, 1, .7);
            box-shadow: 0 22px 48px rgba(245, 179, 1, .11), 0 22px 48px rgba(0, 0, 0, .24);
        }

        .metric-icon {
            width: 48px;
            height: 48px;
            border-radius: var(--radius);
            display: grid;
            place-items: center;
            font-weight: 900;
            color: #fff;
        }

        .metric-icon svg {
            width: 24px;
            height: 24px;
        }

        .metric-icon.red { background: linear-gradient(135deg, var(--red), var(--red-2)); }
        .metric-icon.danger { background: linear-gradient(135deg, rgba(245, 179, 1, .26), rgba(143, 96, 0, .34)); color: var(--yellow); }
        .metric-icon.yellow { background: linear-gradient(135deg, rgba(245, 179, 1, .26), rgba(143, 96, 0, .34)); color: var(--yellow); }
        .metric-icon.ok { background: linear-gradient(135deg, #17662d, #0b3318); color: #9ff3b1; }

        .metric-title {
            color: rgba(255, 255, 255, .65);
            font-size: 12px;
            line-height: 1.2;
            margin: 0 0 10px;
            font-weight: 700;
            letter-spacing: .8px;
            text-transform: uppercase;
            overflow-wrap: anywhere;
        }

        .metric-card > div:last-child {
            min-width: 0;
            align-self: center;
            display: flex;
            flex-direction: column;
            justify-content: center;
            overflow: hidden;
            min-height: 100%;
        }

        .metric-value {
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 8px;
            line-height: 1.15;
            color: #fff;
            max-width: 100%;
            min-width: 0;
            white-space: normal;
            overflow: hidden;
            overflow-wrap: anywhere;
            max-height: 2.25em;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            text-overflow: ellipsis;
        }

        .metric-value.fit-small {
            font-size: 21px;
            line-height: 1.12;
        }

        .metric-value.fit-tiny {
            font-size: 18px;
            line-height: 1.14;
        }

        .metric-meta {
            color: #a7b0c0;
            font-size: 13px;
            font-weight: 500;
            line-height: 1.25;
            margin-bottom: 0;
            white-space: normal;
            overflow-wrap: anywhere;
        }

        .metric-footer {
            display: flex;
            align-items: center;
            gap: 10px;
            color: var(--muted);
            font-size: 12px;
            font-weight: 700;
            line-height: 1.25;
            min-width: 0;
            white-space: normal;
            overflow: hidden;
            text-overflow: ellipsis;
            max-height: 2.55em;
        }

        .metric-footer span {
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .metric-footer .up { color: #54df70; }
        .metric-footer .down { color: #f5b301; }

        .metric-note {
            color: var(--muted);
            font-size: 12px;
            line-height: 1.3;
            max-width: 100%;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
            overflow-wrap: anywhere;
        }

        .metric-subnote {
            color: var(--soft);
            font-size: 11px;
            line-height: 1.25;
            margin-top: 4px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .top-products {
            display: grid;
            gap: 8px;
            padding: 18px 22px 22px;
        }

        .top-product-row {
            min-height: 44px;
            display: grid;
            grid-template-columns: 42px minmax(0, 1fr);
            align-items: center;
            gap: 12px;
            padding: 0 12px;
            border: 1px solid rgba(255, 255, 255, .07);
            border-radius: var(--radius);
            background: rgba(255, 255, 255, .035);
            transition: transform .2s ease, background .2s ease, border-color .2s ease;
        }

        .top-product-row:hover {
            transform: translateY(-2px);
            border-color: rgba(255, 255, 255, .12);
            background: rgba(255, 255, 255, .055);
        }

        .top-product-rank {
            width: 34px;
            height: 28px;
            display: grid;
            place-items: center;
            border-radius: 7px;
            background: rgba(229, 9, 20, .16);
            color: #fff;
            font-weight: 900;
            font-size: 12px;
        }

        .top-product-name {
            min-width: 0;
            color: #f4f6fa;
            font-size: 14px;
            font-weight: 800;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .heatmap-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(142px, 1fr));
            gap: 10px;
            padding: 16px 20px 20px;
        }

        .heatmap-tile {
            min-width: 0;
            height: 96px;
            min-height: 96px;
            padding: 13px 14px;
            border: 1px solid rgba(255, 255, 255, .075);
            border-radius: var(--radius);
            background: rgba(255, 255, 255, .035);
            display: flex;
            flex-direction: column;
            justify-content: center;
            gap: 11px;
            overflow: hidden;
            cursor: pointer;
            transition: border-color .18s ease, background .18s ease, transform .18s ease;
        }

        .heatmap-tile:hover,
        .heatmap-tile:focus-visible {
            border-color: rgba(229, 9, 20, .55);
            background: rgba(255, 255, 255, .055);
            transform: translateY(-1px);
            outline: none;
        }

        .heatmap-tile.active {
            border-color: rgba(39, 184, 77, .75);
            box-shadow: inset 0 0 0 1px rgba(39, 184, 77, .25);
        }

        .heatmap-name {
            color: #f4f6fa;
            font-size: 13px;
            font-weight: 900;
            line-height: 1.2;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .heatmap-total {
            color: var(--muted);
            font-size: 12px;
            font-weight: 800;
            margin-top: 4px;
        }

        .heatmap-bar {
            height: 8px;
            flex: 0 0 8px;
            border-radius: 999px;
            background: rgba(255, 255, 255, .08);
            overflow: hidden;
        }

        .heatmap-fill {
            display: block;
            height: 100%;
            min-width: 8px;
            border-radius: inherit;
            background: linear-gradient(90deg, rgba(229, 9, 20, .35), rgba(229, 9, 20, .95));
        }

        .heatmap-tile.empty .heatmap-total {
            color: #7f8896;
        }

        .heatmap-tile.empty .heatmap-fill {
            width: 100% !important;
            min-width: 0;
            background: rgba(255, 255, 255, .16);
        }

        .metric-link {
            margin-top: 12px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: #47a7ff;
            font-size: 13px;
            font-weight: 700;
            width: fit-content;
        }

        .metric-trend {
            color: #54df70;
            margin-top: 18px;
            font-size: 13px;
            font-weight: 700;
        }

        .metric-trend.down {
            color: #c78cff;
        }

        .metric-link.yellow {
            color: var(--yellow);
        }

        .dashboard-grid {
            display: grid;
            grid-template-columns: minmax(0, 1.35fr) minmax(360px, .95fr);
            gap: 18px;
        }

        .panel {
            overflow: hidden;
            position: relative;
        }

        .chart-panel {
            overflow: visible;
            z-index: 5;
        }

        .panel-header {
            min-height: 58px;
            padding: 18px 22px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 1px solid var(--line);
        }

        .panel-header h2 {
            margin: 0;
            font-size: 17px;
        }

        .ghost-button {
            min-width: 82px;
            height: 32px;
            border: 1px solid var(--line);
            background: transparent;
            color: rgba(232, 237, 245, .84);
            border-radius: var(--radius);
            padding: 0 12px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font: inherit;
            font-size: 12px;
            font-weight: 800;
            line-height: 1;
            transition: border-color .18s ease, color .18s ease, background .18s ease, transform .18s ease;
        }

        .ghost-button:hover {
            border-color: rgba(255, 255, 255, .16);
            color: #fff;
            background: rgba(255, 255, 255, .035);
            transform: translateY(-1px);
        }

        .month-select {
            height: 36px;
            border: 1px solid var(--line);
            background: #10151c;
            color: #e8edf5;
            border-radius: var(--radius);
            padding: 0 34px 0 12px;
            font: inherit;
            outline: none;
        }

        .chart {
            padding: 18px 24px 22px;
        }

        .chart-canvas {
            position: relative;
            height: 238px;
            z-index: 10;
        }

        .ranking-canvas,
        .sector-canvas {
            position: relative;
            height: 280px;
            z-index: 10;
        }

        .ranking-canvas {
            height: 300px;
        }

        .donut-canvas {
            position: relative;
            width: 160px;
            height: 160px;
            z-index: 10;
            justify-self: center;
        }

        .chartjs-tooltip {
            position: fixed;
            z-index: 99999;
            pointer-events: none;
            background: #10151c;
            border: 1px solid var(--line);
            border-radius: var(--radius);
            color: #fff;
            padding: 10px 12px;
            box-shadow: 0 16px 32px rgba(0, 0, 0, .35);
            opacity: 0;
            transform: none;
            transition: opacity .12s ease, left .12s ease, top .12s ease;
            max-width: min(280px, calc(100vw - 24px));
            white-space: normal;
            font-size: 13px;
        }

        .chartjs-tooltip strong {
            display: block;
            margin-bottom: 6px;
        }

        .chartjs-tooltip span {
            display: block;
            color: #dce1ea;
        }

        .legend {
            display: flex;
            justify-content: center;
            gap: 28px;
            color: #d7dce6;
            font-size: 13px;
            margin-bottom: 16px;
        }

        .dot {
            display: inline-block;
            width: 13px;
            height: 13px;
            border-radius: 3px;
            margin-right: 8px;
            vertical-align: -2px;
        }

        .dot.green { background: var(--green); }
        .dot.red { background: var(--red); }

        .bar-chart {
            height: 205px;
            display: grid;
            grid-template-columns: 34px repeat(6, minmax(54px, 1fr));
            gap: 14px;
            align-items: end;
            border-bottom: 1px solid #333b47;
            position: relative;
        }

        .bar-chart::before,
        .bar-chart::after {
            content: "";
            position: absolute;
            left: 34px;
            right: 0;
            height: 1px;
            background: rgba(255, 255, 255, .08);
        }

        .bar-chart::before { top: 33%; }
        .bar-chart::after { top: 66%; }

        .axis {
            align-self: stretch;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            color: var(--muted);
            font-size: 12px;
            padding-bottom: 6px;
        }

        .month {
            height: 100%;
            display: flex;
            flex-direction: column;
            justify-content: flex-end;
            align-items: center;
            gap: 8px;
            min-width: 0;
        }

        .bars {
            height: 170px;
            width: 46px;
            display: flex;
            justify-content: center;
            align-items: flex-end;
            gap: 8px;
        }

        .bar {
            width: 20px;
            border-radius: 4px 4px 0 0;
            min-height: 4px;
        }

        .bar.green { background: linear-gradient(#33c85b, #1a8f37); }
        .bar.red { background: linear-gradient(#f42730, #b50610); }

        .month-label {
            color: #dce1ea;
            font-size: 13px;
            white-space: nowrap;
        }

        .donut-wrap {
            display: grid;
            grid-template-columns: 166px minmax(0, 1fr);
            align-items: center;
            gap: 10px;
            padding: 20px 22px;
        }

        .donut-center {
            position: absolute;
            inset: 35px;
            border-radius: 50%;
            background: #10151c;
            display: grid;
            place-items: center;
            text-align: center;
            box-shadow: inset 0 0 0 1px rgba(255, 255, 255, .06);
        }

        .donut-center strong {
            display: block;
            font-size: 22px;
            line-height: 1.05;
        }

        .donut-center span {
            color: #dfe4ec;
            font-size: 12px;
            line-height: 1.25;
        }

        .type-list {
            display: grid;
            gap: 8px;
            align-self: center;
        }

        .type-row {
            display: grid;
            grid-template-columns: 16px minmax(0, 1fr);
            gap: 10px;
            align-items: center;
            color: #e7ebf2;
            font-size: 14px;
            min-height: 38px;
            padding: 8px 10px;
            border: 1px solid rgba(255, 255, 255, .07);
            border-radius: var(--radius);
            background: rgba(255, 255, 255, .035);
        }

        .type-row span {
            display: block;
            color: var(--muted);
            font-size: 12px;
            font-weight: 800;
            text-transform: uppercase;
        }

        .type-row strong {
            display: block;
            color: #fff;
            font-size: 14px;
            margin-top: 2px;
        }

        .sector-list,
        .activity-list,
        .alert-list {
            padding: 0 22px 16px;
        }

        .sector-row,
        .activity-row,
        .alert-row {
            display: grid;
            align-items: center;
            border-bottom: 1px solid rgba(255, 255, 255, .08);
        }

        .sector-row {
            grid-template-columns: 28px 1fr auto;
            min-height: 36px;
            font-size: 14px;
        }

        .sector-row:last-child,
        .activity-row:last-child,
        .alert-row:last-child {
            border-bottom: 0;
        }

        .sector-icon {
            width: 18px;
            height: 18px;
            border-radius: 4px;
            display: grid;
            place-items: center;
            color: #fff;
            font-size: 11px;
        }

        .sector-icon.blue { background: #125fad; }
        .sector-icon.purple { background: #7b4fd0; }
        .sector-icon.green { background: #168a3a; }
        .sector-icon.yellow { background: #b57900; }
        .sector-icon.gray { background: #647083; }

        .recent-panel {
            margin-top: 18px;
            overflow: visible;
        }

        .table-wrap {
            overflow-x: auto;
            overflow-y: visible;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 0;
            table-layout: fixed;
        }

        .recent-panel table th:nth-child(1),
        .recent-panel table td:nth-child(1) { width: 17%; }
        .recent-panel table th:nth-child(2),
        .recent-panel table td:nth-child(2) { width: 104px; }
        .recent-panel table th:nth-child(3),
        .recent-panel table td:nth-child(3) { width: 23%; }
        .recent-panel table th:nth-child(4),
        .recent-panel table td:nth-child(4) { width: 15%; }
        .recent-panel table th:nth-child(5),
        .recent-panel table td:nth-child(5) { width: 18%; }
        .recent-panel table th:nth-child(6),
        .recent-panel table td:nth-child(6) { width: 148px; }

        th,
        td {
            text-align: left;
            padding: 13px 18px;
            border-bottom: 1px solid rgba(255, 255, 255, .08);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        th {
            color: #f1f4f8;
            font-size: 12px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .03em;
        }

        td {
            color: #f4f6fa;
            font-size: 13px;
            line-height: 1.35;
        }

        td:last-child {
            overflow: visible;
        }

        .kind {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            width: 86px;
            min-height: 25px;
            padding: 0 9px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 700;
            border: 1px solid rgba(255, 255, 255, .08);
            white-space: nowrap;
            overflow: visible;
            text-overflow: clip;
        }

        .kind svg {
            width: 15px;
            height: 15px;
        }

        .kind.entrega svg {
            color: #57d720;
        }

        .kind.entrega {
            color: #dbffe2;
            background: rgba(39, 184, 77, .12);
        }

        .kind.troca svg {
            color: var(--red);
        }

        .kind.troca {
            color: #ffe1e4;
            background: rgba(229, 9, 20, .13);
        }

        .badge {
            display: inline-flex;
            align-items: center;
            min-height: 28px;
            padding: 0 12px;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 800;
        }

        .badge.ok {
            background: rgba(36, 160, 71, .35);
            color: #eaffef;
        }

        .badge.wait {
            background: rgba(24, 98, 161, .48);
            color: #eaf5ff;
        }

        .status-with-reason {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            position: relative;
        }

        .reason-eye {
            width: 24px;
            height: 24px;
            display: inline-grid;
            place-items: center;
            border: 1px solid rgba(255, 255, 255, .12);
            border-radius: 6px;
            color: #dce1ea;
            background: rgba(255, 255, 255, .035);
            cursor: help;
        }

        .reason-eye svg {
            width: 15px;
            height: 15px;
        }

        .reason-eye::after {
            content: "Motivo: " attr(data-reason);
            position: absolute;
            right: 0;
            bottom: calc(100% + 10px);
            width: min(320px, 70vw);
            padding: 10px 12px;
            border: 1px solid var(--line);
            border-radius: var(--radius);
            background: #10151c;
            color: #fff;
            box-shadow: 0 16px 32px rgba(0, 0, 0, .35);
            font-size: 12px;
            line-height: 1.35;
            white-space: normal;
            opacity: 0;
            transform: translateY(4px);
            pointer-events: none;
            transition: opacity .14s ease, transform .14s ease;
            z-index: 50;
        }

        .reason-eye:hover::after,
        .reason-eye:focus-visible::after {
            opacity: 1;
            transform: translateY(0);
        }

        .action {
            color: #fff;
            width: 25px;
            height: 25px;
            display: inline-grid;
            place-items: center;
        }

        .pagination {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            padding: 18px 20px;
            color: #e4e8ef;
            font-size: 14px;
        }

        .pages {
            display: flex;
            gap: 8px;
        }

        .page {
            min-width: 40px;
            height: 40px;
            border: 1px solid var(--line);
            border-radius: 7px;
            display: grid;
            place-items: center;
            color: #fff;
            background: transparent;
            cursor: pointer;
            font: inherit;
        }

        .page.active {
            background: var(--red);
            border-color: var(--red);
            font-weight: 800;
        }

        .activity-row {
            grid-template-columns: 38px minmax(0, 1fr);
            gap: 12px;
            min-height: 62px;
            padding: 8px 0;
        }

        .alert-row {
            grid-template-columns: 34px minmax(0, 1fr);
            gap: 12px;
            min-height: 54px;
            color: #f3f6fb;
            font-size: 13px;
            line-height: 1.35;
        }

        .alert-panel.has-alert:hover {
            border-color: rgba(245, 179, 1, .55);
            box-shadow: 0 18px 44px rgba(245, 179, 1, .08), 0 18px 44px rgba(0, 0, 0, .18);
        }

        .alert-panel.has-alert:hover .alert-row.active .alert-dot {
            animation: alertPulse 2.4s ease-in-out infinite;
        }

        .alert-dot {
            width: 28px;
            height: 28px;
            border-radius: 7px;
            display: grid;
            place-items: center;
            background: rgba(245, 179, 1, .16);
            color: var(--yellow);
            font-weight: 900;
        }

        .alert-dot svg {
            width: 15px;
            height: 15px;
        }

        .alert-dot.ok {
            background: rgba(245, 179, 1, .14);
            color: var(--yellow);
        }

        .alert-dot svg {
            display: block;
        }

        .alert-text strong {
            display: block;
            color: #fff;
            font-size: 13px;
            line-height: 1.25;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .alert-text span {
            display: block;
            color: var(--muted);
            font-size: 12px;
            margin-top: 2px;
        }

        @keyframes alertPulse {
            0%, 100% {
                box-shadow: 0 0 0 0 rgba(245, 179, 1, .18);
                transform: scale(1);
            }
            50% {
                box-shadow: 0 0 0 5px rgba(245, 179, 1, 0);
                transform: scale(1.04);
            }
        }

        .ti-signature {
            min-height: 178px;
            display: grid;
            place-items: center;
            color: rgba(255, 255, 255, .88);
            font-family: "Sora", "Poppins", "Inter", "Segoe UI", Arial, sans-serif;
            font-size: 22px;
            font-weight: 600;
            letter-spacing: 1px;
            text-align: center;
            position: relative;
            top: 14px;
        }

        .activity-dot {
            width: 34px;
            height: 34px;
            border-radius: 50%;
            display: grid;
            place-items: center;
        }

        .activity-dot.green { background: var(--green); }
        .activity-dot.purple { background: var(--purple); }
        .activity-dot.red { background: var(--red); }

        .activity-list.activity-reveal .activity-row {
            opacity: 0;
            transform: translateX(24px);
        }

        .activity-list.activity-reveal.is-visible .activity-row {
            animation: activityReveal .46s ease forwards;
            animation-delay: var(--activity-delay, 0ms);
        }

        @keyframes activityReveal {
            from {
                opacity: 0;
                transform: translateX(24px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        .activity-row p {
            margin: 3px 0 0;
            font-size: 13px;
            line-height: 1.35;
            color: #f4f6fa;
            overflow-wrap: anywhere;
        }

        .activity-row time {
            color: var(--muted);
            font-size: 12px;
            display: block;
            font-weight: 600;
        }

        .mobile-menu {
            display: none;
        }

        @media (max-width: 1180px) {
            .cards {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .dashboard-filters {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .dashboard-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 860px) {
            .app {
                grid-template-columns: 1fr;
            }

            .sidebar {
                position: relative;
                height: auto;
                padding: 22px;
            }

            .brand {
                height: auto;
            }

            .nav {
                grid-template-columns: 1fr 1fr;
            }

            .sidebar-footer {
                margin-top: 18px;
            }

            .topbar {
                justify-content: space-between;
                padding: 0 20px;
            }

            .mobile-menu {
                display: inline-flex;
                font-weight: 800;
                color: #fff;
            }

            .content {
                padding: 22px;
            }

            .page-title {
                align-items: flex-start;
            }
        }

        @media (max-width: 640px) {
            .cards,
            .nav,
            .dashboard-filters {
                grid-template-columns: 1fr;
            }

            .metric-card {
                grid-template-columns: 54px 1fr;
                padding: 18px;
            }

            .title-icon {
                width: 58px;
                height: 58px;
            }

            .page-title h1 {
                font-size: 24px;
            }

            .bar-chart {
                overflow-x: auto;
                grid-template-columns: 30px repeat(6, 70px);
            }

            .donut-wrap {
                grid-template-columns: 1fr;
                justify-items: center;
            }

            .pagination {
                align-items: flex-start;
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <div class="app">
        <aside class="sidebar">
            <div class="brand" aria-label="Bigmais">
                <span>Big</span><span>mais</span><span class="plus">+</span>
            </div>

            <nav class="nav" aria-label="Navegação principal">
                <div class="nav-group">
                    <a class="nav-item active" href="dashboard.php">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><path d="m3.3 7 8.7 5 8.7-5"/><path d="M12 22V12"/></svg>
                        <span>Painel de Controle</span>
                    </a>
                </div>

                <div class="nav-group">
                    <div class="nav-label">Gestão</div>
                    <a class="nav-item muted" href="usuarios.php">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21a8 8 0 0 0-16 0"/><circle cx="12" cy="7" r="4"/></svg>
                        <span>Usuários</span>
                    </a>
                </div>

                <div class="nav-group">
                    <div class="nav-label">Configurações</div>
                    <a class="nav-item muted" href="estoque.php">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .34 1.88l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06A1.7 1.7 0 0 0 15 19.4a1.7 1.7 0 0 0-1 .6 1.7 1.7 0 0 0-.4 1.1V21a2 2 0 1 1-4 0v-.09A1.7 1.7 0 0 0 8.6 19.4a1.7 1.7 0 0 0-1.88.34l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.7 1.7 0 0 0 4.6 15a1.7 1.7 0 0 0-.6-1 1.7 1.7 0 0 0-1.1-.4H3a2 2 0 1 1 0-4h.09A1.7 1.7 0 0 0 4.6 8.6a1.7 1.7 0 0 0-.34-1.88l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.7 1.7 0 0 0 9 4.6a1.7 1.7 0 0 0 1-.6 1.7 1.7 0 0 0 .4-1.1V3a2 2 0 1 1 4 0v.09A1.7 1.7 0 0 0 15.4 4.6a1.7 1.7 0 0 0 1.88-.34l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.7 1.7 0 0 0 19.4 9c.4.1.75.3 1 .6.3.3.5.68.5 1.1V11a2 2 0 1 1 0 4h-.09a1.7 1.7 0 0 0-1.41 0z"/></svg>
                        <span>Controle de Estoque</span>
                    </a>
                </div>
            </nav>

            <div class="sidebar-footer">
                <div class="profile">
                    <div class="avatar">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/></svg>
                    </div>
                    <div>
                        <strong><?php echo e($nomeUsuario); ?></strong>
                        <span class="online-status"><i class="online-dot"></i>Online</span>
                    </div>
                </div>
                <a class="logout" href="../logout.php">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5"/><path d="M21 12H9"/></svg>
                    <span>Sair</span>
                </a>
            </div>
        </aside>

        <main class="main">
            <header class="topbar">
                <span class="mobile-menu">Painel</span>
                <button class="dashboard-scope" id="homeReset" type="button" aria-label="Início" title="Início">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m3 10.5 9-7 9 7"/><path d="M5 10v10h14V10"/><path d="M9 20v-6h6v6"/></svg>
                </button>
            </header>

            <section class="content">
                <div class="page-title">
                    <div class="title-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg>
                    </div>
                    <div>
                        <h1>Painel de Controle</h1>
                        <p>Visão geral do controle de equipamentos e movimentações.</p>
                    </div>
                </div>

                <form class="panel dashboard-filters" id="dashboardFilters">
                    <input type="hidden" name="lojas" id="globalLoja" value="<?php echo e(implode(',', array_map('intval', $filters['loja_ids']))); ?>">
                    <label>Data inicial
                        <input type="date" name="data_inicial" value="<?php echo e($filters['data_inicial']); ?>">
                    </label>
                    <label>Data final
                        <input type="date" name="data_final" value="<?php echo e($filters['data_final']); ?>">
                    </label>
                    <label>Usuário
                        <select name="usuario_id">
                            <option value="0">Todos os usuários</option>
                            <?php foreach ($usuariosFiltro as $usuarioFiltro): ?>
                                <option value="<?php echo (int) $usuarioFiltro['id']; ?>" <?php echo (int) $filters['usuario_id'] === (int) $usuarioFiltro['id'] ? 'selected' : ''; ?>>
                                    <?php echo e($usuarioFiltro['nome']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </form>

                <div class="cards">
                    <article class="metric-card">
                        <div class="metric-icon red">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 21h18"/><path d="M5 21V7l8-4v18"/><path d="M19 21V11l-6-4"/><path d="M9 9h.01"/><path d="M9 13h.01"/><path d="M9 17h.01"/></svg>
                        </div>
                        <div>
                            <div class="metric-title">LOJA COM MAIOR DEMANDA</div>
                            <?php if ((int) $lojaMaiorDemanda['total'] > 0): ?>
                                <div class="metric-value" data-fit-text id="cardLojaValue" title="<?php echo e($lojaMaiorDemanda['loja']); ?>"><?php echo e($lojaMaiorDemanda['loja']); ?></div>
                                <div class="metric-meta" id="cardLojaMeta"><?php echo e(pluralAtendimentos((int) $lojaMaiorDemanda['total'])); ?></div>
                            <?php else: ?>
                                <div class="metric-value" data-fit-text id="cardLojaValue" title="Sem atendimentos">Sem atendimentos</div>
                                <div class="metric-meta" id="cardLojaMeta"></div>
                            <?php endif; ?>
                        </div>
                    </article>

                    <article class="metric-card">
                        <div class="metric-icon red">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="3" width="6" height="6" rx="1"/><rect x="3" y="15" width="6" height="6" rx="1"/><rect x="15" y="15" width="6" height="6" rx="1"/><path d="M12 9v3"/><path d="M6 15v-3h12v3"/></svg>
                        </div>
                        <div>
                            <div class="metric-title">SETOR COM MAIOR DEMANDA</div>
                            <?php if ((int) $setorMaiorOperacao['total'] > 0): ?>
                                <div class="metric-value" data-fit-text id="cardEntregaValue" title="<?php echo e($setorMaiorOperacao['setor']); ?>"><?php echo e($setorMaiorOperacao['setor']); ?></div>
                                <div class="metric-meta" id="cardEntregaMeta"><?php echo e(pluralAtendimentos((int) $setorMaiorOperacao['total'])); ?></div>
                            <?php else: ?>
                                <div class="metric-value" data-fit-text id="cardEntregaValue" title="Nenhuma">Nenhuma</div>
                                <div class="metric-meta" id="cardEntregaMeta"></div>
                            <?php endif; ?>
                        </div>
                    </article>

                    <article class="metric-card">
                        <div class="metric-icon red">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12a9 9 0 0 0-15-6.7L3 8"/><path d="M3 3v5h5"/><path d="M3 12a9 9 0 0 0 15 6.7l3-2.7"/><path d="M16 16h5v5"/></svg>
                        </div>
                        <div>
                            <div class="metric-title">ITEM MAIS TROCADO</div>
                            <?php if ((int) $topTrocaMes['total'] > 0): ?>
                                <div class="metric-value" data-fit-text id="cardTrocaValue" title="<?php echo e($topTrocaMes['equipamento']); ?>"><?php echo e($topTrocaMes['equipamento']); ?></div>
                                <div class="metric-meta" id="cardTrocaMeta"><?php echo (int) $topTrocaMes['total']; ?> trocas</div>
                            <?php else: ?>
                                <div class="metric-value" data-fit-text id="cardTrocaValue" title="Nenhuma">Nenhuma</div>
                                <div class="metric-meta" id="cardTrocaMeta"></div>
                            <?php endif; ?>
                        </div>
                    </article>

                    <a class="metric-card metric-card-link <?php echo (int) $estoqueCritico['itens'] > 0 ? 'stock-alert' : ''; ?>" id="cardEstoqueLink" href="<?php echo e($estoqueCriticoUrl); ?>">
                        <div class="metric-icon <?php echo (int) $estoqueCritico['itens'] > 0 ? 'danger' : 'ok'; ?>" id="cardEstoqueIcon">
                            <?php if ((int) $estoqueCritico['itens'] > 0): ?>
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m21.73 18-8-14a2 2 0 0 0-3.46 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>
                            <?php else: ?>
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 6 9 17l-5-5"/></svg>
                            <?php endif; ?>
                        </div>
                        <div>
                            <div class="metric-title">SITUAÇÃO DO ESTOQUE</div>
                            <?php if ((int) $estoqueCritico['itens'] > 0): ?>
                                <div class="metric-value" data-fit-text id="cardEstoqueValue" title="Estoque Irregular">Estoque Irregular</div>
                                <div class="metric-meta" id="cardEstoqueMeta"></div>
                            <?php else: ?>
                                <div class="metric-value" data-fit-text id="cardEstoqueValue" title="Estoque Regular">Estoque Regular</div>
                                <div class="metric-meta" id="cardEstoqueMeta"></div>
                            <?php endif; ?>
                        </div>
                    </a>
                </div>

                <div class="dashboard-grid">
                    <div>
                        <section class="panel chart-panel">
                            <div class="panel-header">
                                <h2>Mapa de Calor das Lojas</h2>
                                <span class="metric-note">Atendimentos por loja</span>
                            </div>
                            <div class="heatmap-grid" id="heatmapLojas">
                                <?php $maxSolicitacoes = max(1, ...array_map(static fn(array $loja): int => (int) $loja['total'], $mapaCalorLojas)); ?>
                                <?php foreach ($mapaCalorLojas as $lojaMapa): ?>
                                    <?php
                                        $totalLojaMapa = (int) $lojaMapa['total'];
                                        $textoLojaMapa = textoMapaAtendimentos($totalLojaMapa);
                                        $intensidade = $totalLojaMapa > 0 ? max(8, (int) round(($totalLojaMapa / $maxSolicitacoes) * 100)) : 100;
                                        $ativa = !empty(array_intersect(array_map('intval', $lojaMapa['ids']), array_map('intval', $filters['loja_ids'])));
                                    ?>
                                    <div class="heatmap-tile<?php echo $ativa ? ' active' : ''; ?><?php echo $totalLojaMapa === 0 ? ' empty' : ''; ?>" role="button" tabindex="0" data-loja-ids="<?php echo e(implode(',', array_map('intval', $lojaMapa['ids']))); ?>" title="<?php echo e($lojaMapa['nome'] . ' - ' . $textoLojaMapa); ?>">
                                        <div>
                                            <div class="heatmap-name"><?php echo e($lojaMapa['nome']); ?></div>
                                            <div class="heatmap-total"><?php echo e($textoLojaMapa); ?></div>
                                        </div>
                                        <div class="heatmap-bar"><span class="heatmap-fill" style="width: <?php echo $intensidade; ?>%;"></span></div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </section>

                        <section class="panel recent-panel">
                            <div class="panel-header">
                                <h2>Movimentações Recentes</h2>
                                <a class="ghost-button" href="movimentacoes.php">Ver todas</a>
                            </div>
                            <div class="table-wrap">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Usuário</th>
                                            <th>Tipo</th>
                                            <th>Item</th>
                                            <th>Loja</th>
                                            <th>Solicitante</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody id="recentesBody">
                                        <?php foreach ($recentes as $row): ?>
                                            <?php
                                                $tipo = strtoupper((string) $row['tipo']);
                                                $status = strtoupper((string) $row['status']);
                                                $statusOk = in_array($status, ['CONCLUIDA', 'CONCLUÍDA'], true);
                                            ?>
                                            <tr>
                                                <td><?php echo e($row['usuario']); ?></td>
                                                <td>
                                                    <span class="kind <?php echo $tipo === 'TROCA' ? 'troca' : 'entrega'; ?>">
                                                        <?php if ($tipo === 'TROCA'): ?>
                                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12a9 9 0 0 0-15-6.7L3 8"/><path d="M3 3v5h5"/><path d="M3 12a9 9 0 0 0 15 6.7l3-2.7"/><path d="M16 16h5v5"/></svg>
                                                        <?php else: ?>
                                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg>
                                                        <?php endif; ?>
                                                        <?php echo $tipo === 'TROCA' ? 'Troca' : 'Entrega'; ?>
                                                    </span>
                                                </td>
                                                <td><?php echo e($row['equipamento']); ?></td>
                                                <td><?php echo e($row['loja']); ?></td>
                                                <td><?php echo e($row['solicitante']); ?></td>
                                                <td>
                                                    <span class="status-with-reason">
                                                        <span class="badge <?php echo $statusOk ? 'ok' : 'wait'; ?>"><?php echo e($row['status']); ?></span>
                                                        <span class="reason-eye" tabindex="0" data-reason="<?php echo e($row['justificativa'] ?: 'Motivo nao informado.'); ?>" aria-label="Ver motivo">
                                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
                                                        </span>
                                                    </span>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                        <?php if (empty($recentes)): ?>
                                            <tr><td colspan="6">Nenhuma movimentação registrada.</td></tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                            <div class="pagination" id="pagination" <?php echo $recentesPage['totalPages'] <= 1 ? 'style="display:none;"' : ''; ?>>
                                <span id="paginationInfo"></span>
                                <div class="pages" id="paginationPages"></div>
                            </div>
                        </section>

                        <div class="ti-signature">Manutenção TI</div>
                    </div>

                    <div>
                        <section class="panel">
                            <div class="panel-header">
                                <h2>Movimentações por Tipo</h2>
                            </div>
                            <div class="donut-wrap">
                                <div class="donut-canvas">
                                    <canvas id="tipoChart"></canvas>
                                    <div class="donut-center">
                                        <div><strong id="tipoTotal"><?php echo (int) $totalTipos; ?></strong><span>movimentações</span></div>
                                    </div>
                                </div>
                                <div class="type-list">
                                    <div class="type-row"><i class="dot green"></i><div><span>ENTREGAS</span><strong id="tipoEntregas"><?php echo (int) $entregasTipo; ?> registros • <?php echo e(number_format((float) $entregasPercent, 1, ',', '.')); ?>%</strong></div></div>
                                    <div class="type-row"><i class="dot red"></i><div><span>TROCAS</span><strong id="tipoTrocas"><?php echo (int) $trocasTipo; ?> registros • <?php echo e(number_format((float) $trocasPercent, 1, ',', '.')); ?>%</strong></div></div>
                                </div>
                            </div>
                        </section>

                        <section class="panel recent-panel">
                            <div class="panel-header">
                                <h2>Top 5 Itens Mais Movimentados</h2>
                            </div>
                            <div class="top-products" id="produtosRankingList">
                                <?php
                                    $rankIcons = ['1º', '2º', '3º', '4º', '5º'];
                                ?>
                                <?php foreach (array_slice($produtosRanking, 0, 5) as $index => $produto): ?>
                                    <div class="top-product-row">
                                        <span class="top-product-rank"><?php echo e($rankIcons[$index]); ?></span>
                                        <span class="top-product-name" title="<?php echo e($produto['produto']); ?>"><?php echo e($produto['produto']); ?></span>
                                    </div>
                                <?php endforeach; ?>
                                <?php if (empty($produtosRanking)): ?>
                                    <div class="top-product-row">
                                        <span class="top-product-rank">-</span>
                                        <span class="top-product-name">Nenhuma movimentação registrada.</span>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </section>

                        <section class="panel recent-panel alert-panel <?php echo empty($alertasSistema) ? '' : 'has-alert'; ?>">
                            <div class="panel-header">
                                <h2>Alertas do Sistema</h2>
                            </div>
                            <div class="alert-list" id="alertasList">
                                <?php foreach ($alertasSistema as $alerta): ?>
                                    <div class="alert-row active">
                                        <span class="alert-dot" aria-hidden="true">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m21.73 18-8-14a2 2 0 0 0-3.46 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>
                                        </span>
                                        <span class="alert-text"><strong><?php echo e($alerta['produto'] ?? 'Alerta'); ?></strong><span><?php echo e($alerta['detalhe'] ?? ($alerta['texto'] ?? '')); ?></span></span>
                                    </div>
                                <?php endforeach; ?>
                                <?php if (empty($alertasSistema)): ?>
                                    <div class="alert-row">
                                        <span class="alert-dot ok">✓</span>
                                        <span class="alert-text"><strong>Sem alertas</strong></span>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </section>

                        <section class="panel recent-panel">
                            <div class="panel-header">
                                <h2>Atividades Recentes</h2>
                                <a class="ghost-button" href="atividades.php">Ver todas</a>
                            </div>
                            <div class="activity-list" id="atividadesList">
                                <?php $activityColors = ['purple', 'green', 'red']; ?>
                                <?php foreach ($atividades as $index => $atividade): ?>
                                    <div class="activity-row">
                                        <span class="activity-dot <?php echo $activityColors[$index % count($activityColors)]; ?>">
                                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg>
                                        </span>
                                        <div>
                                            <p><?php echo e($atividade['usuario'] . ' registrou uma ' . strtolower((string) $atividade['tipo']) . ' de ' . $atividade['equipamento'] . '.'); ?></p>
                                            <?php $atividadeTs = strtotime((string) $atividade['data_entrega']); ?>
                                            <time><?php echo e(date('d/m/y', $atividadeTs)); ?> &bull; <?php echo e(date('H:i', $atividadeTs)); ?></time>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                                <?php if (empty($atividades)): ?>
                                    <div class="activity-row">
                                        <span class="activity-dot purple">
                                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5v14"/><path d="M5 12h14"/></svg>
                                        </span>
                                        <div>
                                            <p>Nenhuma atividade registrada.</p>
                                            <time>0</time>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </section>
                    </div>
                </div>
            </section>
        </main>
    </div>
    <script>
        const chartTextColor = '#dce1ea';
        const chartGridColor = 'rgba(255, 255, 255, .08)';
        const initialDashboard = <?php echo json_encode($dashboardPayload, JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK); ?>;
        const initialRecentes = <?php echo json_encode($recentesPage, JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK); ?>;
        const typeData = [<?php echo (int) $entregasTipo; ?>, <?php echo (int) $trocasTipo; ?>];
        const chartColors = ['#e50914', '#c90812', '#a10610', '#f03a43', '#7d0710', '#b70b16', '#d61f28', '#8f0911'];
        let selectedLojaIds = <?php echo json_encode(array_values($filters['loja_ids']), JSON_NUMERIC_CHECK); ?>;

        Chart.defaults.color = chartTextColor;
        Chart.defaults.font.family = '"Inter", "Segoe UI", Arial, sans-serif';

        document.getElementById('homeReset')?.addEventListener('click', () => {
            const form = document.getElementById('dashboardFilters');
            const params = new URLSearchParams(new FormData(form));
            params.delete('lojas');
            const query = params.toString();
            window.location.href = query ? `dashboard.php?${query}` : 'dashboard.php';
        });

        function externalTooltip(context) {
            const { chart, tooltip } = context;
            let tooltipEl = document.getElementById('chartjsTooltip');

            if (!tooltipEl) {
                tooltipEl = document.createElement('div');
                tooltipEl.id = 'chartjsTooltip';
                tooltipEl.className = 'chartjs-tooltip';
                document.body.appendChild(tooltipEl);
            }

            if (tooltip.opacity === 0) {
                tooltipEl.style.opacity = 0;
                return;
            }

            const title = tooltip.title?.[0] || '';
            const rows = tooltip.body
                .map((body, index) => {
                    const color = tooltip.labelColors[index]?.backgroundColor || '#fff';
                    return `<span><i style="display:inline-block;width:10px;height:10px;border-radius:2px;background:${color};margin-right:6px;"></i>${body.lines[0]}</span>`;
                })
                .join('');

            tooltipEl.innerHTML = `<strong>${title}</strong>${rows}`;

            const rect = chart.canvas.getBoundingClientRect();
            const panelRect = chart.canvas.closest('.panel')?.getBoundingClientRect() || rect;
            const tooltipWidth = tooltipEl.offsetWidth || 180;
            const tooltipHeight = tooltipEl.offsetHeight || 60;
            const padding = 10;
            const activePoint = tooltip.dataPoints?.[0]?.element;
            const point = activePoint?.tooltipPosition ? activePoint.tooltipPosition() : { x: tooltip.caretX, y: tooltip.caretY };
            const anchorLeft = rect.left + point.x;
            const anchorTop = rect.top + point.y;
            const chartCenterX = rect.left + rect.width / 2;
            const offset = 10;
            let left = anchorLeft >= chartCenterX ? anchorLeft + offset : anchorLeft - tooltipWidth - offset;
            let top = anchorTop - (tooltipHeight / 2);
            const minLeft = Math.max(padding, panelRect.left + padding);
            const maxLeft = Math.max(minLeft, Math.min(window.innerWidth - tooltipWidth - padding, panelRect.right - tooltipWidth - padding));
            const minTop = Math.max(padding, panelRect.top + padding);
            const maxTop = Math.max(minTop, Math.min(window.innerHeight - tooltipHeight - padding, panelRect.bottom - tooltipHeight - padding));

            if (left < minLeft || left > maxLeft) {
                left = anchorLeft >= chartCenterX ? anchorLeft - tooltipWidth - offset : anchorLeft + offset;
            }

            left = Math.min(maxLeft, Math.max(minLeft, left));
            top = Math.min(maxTop, Math.max(minTop, top));

            tooltipEl.style.opacity = 1;
            tooltipEl.style.left = `${left}px`;
            tooltipEl.style.top = `${top}px`;
        }

        function hexToRgba(hex, alpha) {
            const clean = hex.replace('#', '');
            const value = parseInt(clean.length === 3 ? clean.split('').map((char) => char + char).join('') : clean, 16);
            const red = (value >> 16) & 255;
            const green = (value >> 8) & 255;
            const blue = value & 255;

            return `rgba(${red}, ${green}, ${blue}, ${alpha})`;
        }

        function rankingLabels(rows) {
            return rows.map((row) => row.nome);
        }

        function rankingValues(rows) {
            return rows.map((row) => Number(row.total || 0));
        }

        function lojaResumoLabels(data) {
            return ['Entregas', 'Trocas', 'Total'];
        }

        function lojaResumoValues(data) {
            return [
                Number(data.entregas || 0),
                Number(data.trocas || 0),
                Number(data.total || 0)
            ];
        }

        function updateSelectedStoreFromBar(index) {
            const item = currentRanking[index];
            if (!item) return;
            const ids = item.ids || [];
            const sameSelection = selectedLojaIds.length === ids.length && ids.every((id) => selectedLojaIds.includes(id));
            selectedLojaIds = sameSelection ? [] : ids;
            document.getElementById('globalLoja').value = sameSelection ? '' : ids.join(',');
        }

        function lojaQuery() {
            return selectedLojaIds.length ? `&lojas=${selectedLojaIds.join(',')}` : '';
        }

        function pluralAtendimentosJs(total) {
            const value = Number(total || 0);
            return `${value} ${value === 1 ? 'atendimento' : 'atendimentos'}`;
        }

        function heatmapAtendimentoLabel(total) {
            const value = Number(total || 0);
            return value === 0 ? 'Sem movimentações' : pluralAtendimentosJs(value);
        }

        function renderCards(data) {
            const loja = data.lojaMaiorDemanda || {};
            const entrega = data.setorMaiorOperacao || {};
            const troca = data.topTroca || {};
            const estoque = data.estoqueCritico || {};

            const lojaValue = document.getElementById('cardLojaValue');
            const lojaMeta = document.getElementById('cardLojaMeta');
            const entregaValue = document.getElementById('cardEntregaValue');
            const entregaMeta = document.getElementById('cardEntregaMeta');
            const trocaValue = document.getElementById('cardTrocaValue');
            const trocaMeta = document.getElementById('cardTrocaMeta');
            const estoqueIcon = document.getElementById('cardEstoqueIcon');
            const estoqueValue = document.getElementById('cardEstoqueValue');
            const estoqueMeta = document.getElementById('cardEstoqueMeta');
            const estoqueLink = document.getElementById('cardEstoqueLink');
            const totalLoja = Number(loja.total || 0);

            if (totalLoja > 0) {
                lojaValue.textContent = String(loja.loja || 'Loja não informada');
                lojaMeta.textContent = pluralAtendimentosJs(totalLoja);
            } else {
                lojaValue.textContent = 'Sem atendimentos';
                lojaMeta.textContent = '';
            }
            lojaValue.title = lojaValue.textContent;

            entregaValue.textContent = Number(entrega.total || 0) > 0 ? entrega.setor : 'Nenhuma';
            entregaValue.title = entregaValue.textContent;
            entregaMeta.textContent = Number(entrega.total || 0) > 0 ? pluralAtendimentosJs(entrega.total) : '';

            trocaValue.textContent = Number(troca.total || 0) > 0 ? troca.equipamento : 'Nenhuma';
            trocaValue.title = trocaValue.textContent;
            trocaMeta.textContent = Number(troca.total || 0) > 0 ? `${Number(troca.total)} trocas` : '';

            const itensCriticos = Number(estoque.itens || 0);
            estoqueIcon.className = `metric-icon ${itensCriticos > 0 ? 'danger' : 'ok'}`;
            estoqueLink?.classList.toggle('stock-alert', itensCriticos > 0);
            estoqueIcon.innerHTML = itensCriticos > 0
                ? '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m21.73 18-8-14a2 2 0 0 0-3.46 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>'
                : '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 6 9 17l-5-5"/></svg>';
            estoqueValue.textContent = itensCriticos > 0 ? 'Estoque Irregular' : 'Estoque Regular';
            estoqueValue.title = estoqueValue.textContent;
            estoqueMeta.textContent = '';

            if (estoqueLink) {
                const params = new URLSearchParams();
                params.set('critico', '1');
                estoqueLink.href = `estoque.php?${params.toString()}`;
            }

            fitMetricText();
        }

        function fitMetricText() {
            document.querySelectorAll('[data-fit-text]').forEach((element) => {
                element.classList.remove('fit-small', 'fit-tiny');
                if (element.scrollHeight > element.clientHeight || element.scrollWidth > element.clientWidth) {
                    element.classList.add('fit-small');
                }
                if (element.scrollHeight > element.clientHeight || element.scrollWidth > element.clientWidth) {
                    element.classList.add('fit-tiny');
                }
            });
        }

        let currentRanking = initialDashboard.rankingLojas || [];
        let currentPrincipalChart = initialDashboard.graficoPrincipal || {
            modo: 'ranking',
            titulo: 'Mapa de Calor das Lojas',
            subtitulo: 'Clique em uma barra para filtrar o painel',
            ranking: currentRanking
        };
        let currentChartMode = currentPrincipalChart.modo || 'ranking';
        const initialLojaResumo = currentPrincipalChart.loja || {};
        const initialRankingRows = currentPrincipalChart.ranking || currentRanking;
        const rankingCanvas = null;
        const rankingLojasChart = null;
        /*
        const rankingLojasChartLegacy = new Chart(rankingCanvas, {
            type: 'bar',
            data: {
                labels: currentChartMode === 'loja' ? lojaResumoLabels(initialLojaResumo) : rankingLabels(initialRankingRows),
                datasets: [{
                    label: 'Movimentações',
                    data: currentChartMode === 'loja' ? lojaResumoValues(initialLojaResumo) : rankingValues(initialRankingRows),
                    lojaIds: currentChartMode === 'loja' ? [] : initialRankingRows.map((row) => row.ids || []),
                    backgroundColor: currentChartMode === 'loja'
                        ? ['rgba(39, 184, 77, .82)', 'rgba(229, 9, 20, .82)', 'rgba(34, 118, 210, .82)']
                        : initialRankingRows.map((row, index) => hexToRgba(chartColors[index % chartColors.length], .78)),
                    borderColor: currentChartMode === 'loja'
                        ? ['#27b84d', '#e50914', '#2276d2']
                        : initialRankingRows.map((row, index) => chartColors[index % chartColors.length]),
                    borderWidth: 1,
                    borderRadius: 9,
                    barPercentage: .68,
                    categoryPercentage: .72
                }]
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: false,
                interaction: {
                    mode: 'nearest',
                    intersect: false
                },
                animation: {
                    duration: 650,
                    easing: 'easeOutQuart'
                },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        enabled: false,
                        external: externalTooltip,
                        callbacks: {
                            label: (context) => pluralAtendimentosJs(Number(context.parsed.x || 0))
                        }
                    }
                },
                scales: {
                    x: {
                        beginAtZero: true,
                        grid: { color: 'rgba(255, 255, 255, .055)' },
                        border: { display: false },
                        ticks: {
                            color: chartTextColor,
                            precision: 0
                        }
                    },
                    y: {
                        grid: { display: false },
                        border: { display: false },
                        ticks: {
                            color: chartTextColor,
                            font: { size: 12, weight: 700 }
                        }
                    }
                },
                onClick: null
            }
        });
        */

        function renderMapaCalor(rows, lojaIds = selectedLojaIds) {
            const grid = document.getElementById('heatmapLojas');
            const items = rows || [];
            const maxTotal = Math.max(1, ...items.map((row) => Number(row.total || 0)));
            updateDashboardScope(items, lojaIds);

            if (!items.length) {
                grid.innerHTML = '<div class="heatmap-tile empty"><div><div class="heatmap-name">Sem lojas</div><div class="heatmap-total">Sem movimentações</div></div><div class="heatmap-bar"><span class="heatmap-fill" style="width:100%;"></span></div></div>';
                return;
            }

            grid.innerHTML = items.map((row) => {
                const total = Number(row.total || 0);
                const label = heatmapAtendimentoLabel(total);
                const width = total > 0 ? Math.max(8, Math.round((total / maxTotal) * 100)) : 100;
                const ids = row.ids || [];
                const active = lojaIds.length && ids.some((id) => lojaIds.includes(Number(id)));
                const title = `${row.nome} - ${label}`;
                const idValue = ids.map(Number).filter(Boolean).join(',');

                return `
                    <div class="heatmap-tile${active ? ' active' : ''}${total === 0 ? ' empty' : ''}" role="button" tabindex="0" data-loja-ids="${escapeHtml(idValue)}" title="${escapeHtml(title)}">
                        <div>
                            <div class="heatmap-name">${escapeHtml(row.nome)}</div>
                            <div class="heatmap-total">${escapeHtml(label)}</div>
                        </div>
                        <div class="heatmap-bar"><span class="heatmap-fill" style="width:${width}%;"></span></div>
                    </div>
                `;
            }).join('');

            grid.querySelectorAll('.heatmap-tile[data-loja-ids]').forEach((tile) => {
                const selectLoja = () => {
                    const ids = tile.dataset.lojaIds
                        ? tile.dataset.lojaIds.split(',').map(Number).filter(Boolean)
                        : [];
                    const sameSelection = selectedLojaIds.length === ids.length && ids.every((id) => selectedLojaIds.includes(id));
                    selectedLojaIds = sameSelection ? [] : ids;
                    document.getElementById('globalLoja').value = sameSelection ? '' : ids.join(',');
                    applyDashboardFilters();
                };

                tile.addEventListener('click', selectLoja);
                tile.addEventListener('keydown', (event) => {
                    if (event.key === 'Enter' || event.key === ' ') {
                        event.preventDefault();
                        selectLoja();
                    }
                });
            });
        }

        function updateDashboardScope() {}

        const tipoChart = new Chart(document.getElementById('tipoChart'), {
            type: 'doughnut',
            data: {
                labels: ['Entregas', 'Trocas'],
                datasets: [{
                    data: typeData,
                    backgroundColor: ['#27b84d', '#e50914'],
                    borderColor: '#10151c',
                    borderWidth: 4,
                    hoverOffset: 2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '62%',
                animation: {
                    animateRotate: true,
                    animateScale: true,
                    duration: 700,
                    easing: 'easeOutQuart'
                },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        enabled: false,
                        external: externalTooltip
                    }
                }
            }
        });

        function escapeHtml(value) {
            return String(value ?? '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        function formatDate(value) {
            const date = new Date(String(value).replace(' ', 'T'));
            if (Number.isNaN(date.getTime())) return '-';
            return new Intl.DateTimeFormat('pt-BR', {
                day: '2-digit',
                month: '2-digit',
                year: 'numeric',
                hour: '2-digit',
                minute: '2-digit'
            }).format(date);
        }

        function formatDateOnly(value) {
            const date = new Date(String(value).replace(' ', 'T'));
            if (Number.isNaN(date.getTime())) return '-';
            return new Intl.DateTimeFormat('pt-BR', {
                day: '2-digit',
                month: '2-digit'
            }).format(date);
        }

        function formatShortDateTime(value) {
            const date = new Date(String(value).replace(' ', 'T'));
            if (Number.isNaN(date.getTime())) return '-';
            const day = new Intl.DateTimeFormat('pt-BR', {
                day: '2-digit',
                month: '2-digit',
                year: '2-digit'
            }).format(date);
            const time = new Intl.DateTimeFormat('pt-BR', {
                hour: '2-digit',
                minute: '2-digit'
            }).format(date);
            return `${day} &bull; ${time}`;
        }

        function renderRecentes(data) {
            const body = document.getElementById('recentesBody');
            const pagination = document.getElementById('pagination');
            const paginationInfo = document.getElementById('paginationInfo');
            const paginationPages = document.getElementById('paginationPages');

            body.innerHTML = '';
            paginationPages.innerHTML = '';
            paginationInfo.textContent = '';

            if (!data.rows.length) {
                body.innerHTML = '<tr><td colspan="6">Nenhuma movimentação registrada.</td></tr>';
                pagination.style.display = 'none';
                return;
            }

            body.innerHTML = data.rows.map((row) => {
                const tipo = String(row.tipo || '').toUpperCase();
                const status = String(row.status || '').toUpperCase();
                const isTroca = tipo === 'TROCA';
                const isOk = status === 'CONCLUIDA' || status === 'CONCLUÍDA';
                const tipoLabel = isTroca ? 'Troca' : 'Entrega';
                const icon = isTroca
                    ? '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12a9 9 0 0 0-15-6.7L3 8"/><path d="M3 3v5h5"/><path d="M3 12a9 9 0 0 0 15 6.7l3-2.7"/><path d="M16 16h5v5"/></svg>'
                    : '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg>';

                return `
                    <tr>
                        <td>${escapeHtml(row.usuario)}</td>
                        <td><span class="kind ${isTroca ? 'troca' : 'entrega'}">${icon}${tipoLabel}</span></td>
                        <td>${escapeHtml(row.equipamento)}</td>
                        <td>${escapeHtml(row.loja)}</td>
                        <td>${escapeHtml(row.solicitante || '-')}</td>
                        <td>
                            <span class="status-with-reason">
                                <span class="badge ${isOk ? 'ok' : 'wait'}">${escapeHtml(row.status)}</span>
                                <span class="reason-eye" tabindex="0" data-reason="${escapeHtml(row.justificativa || 'Motivo nao informado.')}" aria-label="Ver motivo">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
                                </span>
                            </span>
                        </td>
                    </tr>
                `;
            }).join('');

            if (data.totalPages <= 1) {
                pagination.style.display = 'none';
                return;
            }

            pagination.style.display = '';
            const start = ((data.page - 1) * data.perPage) + 1;
            const end = Math.min(data.page * data.perPage, data.total);
            paginationInfo.textContent = `Mostrando ${start} a ${end} de ${data.total} registros`;

            for (let page = 1; page <= data.totalPages; page++) {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = `page${page === data.page ? ' active' : ''}`;
                button.textContent = page;
                button.addEventListener('click', () => loadRecentes(page));
                paginationPages.appendChild(button);
            }
        }

        async function loadRecentes(page) {
            const response = await fetch(`dashboard.php?ajax=recentes&pagina=${page}${filterQuery()}`, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });
            renderRecentes(await response.json());
        }

        function renderTipo(data) {
            const entregas = Number(data.entregas || 0);
            const trocas = Number(data.trocas || 0);
            const total = Number(data.total || 0);
            const entregasPercent = Number(data.entregasPercent || 0);
            const trocasPercent = Number(data.trocasPercent || 0);
            const formatPercent = (value) => value.toLocaleString('pt-BR', {
                minimumFractionDigits: 1,
                maximumFractionDigits: 1
            });
            const currentData = tipoChart.data.datasets[0].data.map(Number);
            if (currentData[0] !== entregas || currentData[1] !== trocas) {
                tipoChart.data.datasets[0].data = [entregas, trocas];
                tipoChart.update();
            }

            document.getElementById('tipoTotal').textContent = total;
            document.getElementById('tipoEntregas').textContent = `${entregas} registros • ${formatPercent(entregasPercent)}%`;
            document.getElementById('tipoTrocas').textContent = `${trocas} registros • ${formatPercent(trocasPercent)}%`;
        }

        async function loadTipo() {
            const response = await fetch(`dashboard.php?ajax=tipo${filterQuery()}`, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });
            renderTipo(await response.json());
        }

        function renderAtividades(rows) {
            const list = document.getElementById('atividadesList');
            const colors = ['purple', 'green', 'red'];

            if (!rows.length) {
                list.innerHTML = `
                    <div class="activity-row">
                        <span class="activity-dot purple">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5v14"/><path d="M5 12h14"/></svg>
                        </span>
                        <div>
                            <p>Nenhuma atividade registrada.</p>
                            <time>0</time>
                        </div>
                    </div>
                `;
                refreshActivityRevealRows();
                return;
            }

            list.innerHTML = rows.map((atividade, index) => `
                <div class="activity-row">
                    <span class="activity-dot ${colors[index % colors.length]}">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg>
                    </span>
                    <div>
                        <p>${escapeHtml(atividade.usuario)} registrou uma ${escapeHtml(String(atividade.tipo || '').toLowerCase())} de ${escapeHtml(atividade.equipamento)}.</p>
                        <time>${formatShortDateTime(atividade.data_entrega)}</time>
                    </div>
                </div>
            `).join('');
            refreshActivityRevealRows();
        }

        let atividadesRevealDone = false;

        function refreshActivityRevealRows() {
            const list = document.getElementById('atividadesList');
            if (!list) return;
            list.classList.add('activity-reveal');
            list.querySelectorAll('.activity-row').forEach((row, index) => {
                row.style.setProperty('--activity-delay', `${index * 120}ms`);
            });
            if (atividadesRevealDone) {
                list.classList.add('is-visible');
            }
        }

        function setupActivityReveal() {
            const list = document.getElementById('atividadesList');
            if (!list) return;
            refreshActivityRevealRows();

            const target = list.closest('.recent-panel') || list;
            const reveal = () => {
                atividadesRevealDone = true;
                list.classList.add('is-visible');
            };

            if (!('IntersectionObserver' in window)) {
                reveal();
                return;
            }

            const observer = new IntersectionObserver((entries) => {
                if (entries.some((entry) => entry.isIntersecting)) {
                    reveal();
                    observer.disconnect();
                }
            }, { threshold: 0.24, rootMargin: '0px 0px -8% 0px' });

            observer.observe(target);
        }

        async function loadAtividades() {
            const response = await fetch(`dashboard.php?ajax=atividades${filterQuery()}`, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });
            renderAtividades(await response.json());
        }

        async function loadCards() {
            const response = await fetch(`dashboard.php?ajax=cards${filterQuery()}`, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });
            renderCards(await response.json());
        }

        function filterQuery() {
            const params = new URLSearchParams(new FormData(document.getElementById('dashboardFilters')));
            if (selectedLojaIds.length) {
                params.set('lojas', selectedLojaIds.join(','));
            }
            const query = params.toString();
            return query ? `&${query}` : '';
        }

        function renderRanking(rows) {
            renderMapaCalor(rows || [], selectedLojaIds);
        }

        function renderPrincipalChart(data) {
            const payload = data || {};
            renderMapaCalor(payload.lojas || [], payload.loja_ids || selectedLojaIds);
        }

        function renderProdutosRanking(rows) {
            const list = document.getElementById('produtosRankingList');
            const rankIcons = ['1º', '2º', '3º', '4º', '5º'];
            const items = (rows || []).slice(0, 5);

            if (!items.length) {
                list.innerHTML = `
                    <div class="top-product-row">
                        <span class="top-product-rank">-</span>
                        <span class="top-product-name">Nenhuma movimentação registrada.</span>
                    </div>
                `;
                return;
            }

            list.innerHTML = items.map((row, index) => `
                <div class="top-product-row">
                    <span class="top-product-rank">${rankIcons[index]}</span>
                    <span class="top-product-name" title="${escapeHtml(row.produto)}">${escapeHtml(row.produto)}</span>
                </div>
            `).join('');
        }

        function renderAlertas(rows) {
            const list = document.getElementById('alertasList');
            if (!rows || !rows.length) {
                list.innerHTML = `
                    <div class="alert-row">
                        <span class="alert-dot ok">✓</span>
                        <span class="alert-text"><strong>Sem alertas</strong></span>
                    </div>
                `;
                list.closest('.alert-panel')?.classList.remove('has-alert');
                return;
            }
            list.closest('.alert-panel')?.classList.add('has-alert');

            list.innerHTML = rows.map((row) => `
                <div class="alert-row active">
                    <span class="alert-dot" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m21.73 18-8-14a2 2 0 0 0-3.46 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>
                    </span>
                    <span class="alert-text"><strong>${escapeHtml(row.produto || 'Alerta')}</strong><span>${escapeHtml(row.detalhe || row.texto || '')}</span></span>
                </div>
            `).join('');
        }

        async function applyDashboardFilters() {
            const response = await fetch(`dashboard.php?ajax=dashboard${filterQuery()}`, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });
            const data = await response.json();
            renderCards(data.cards);
            renderPrincipalChart(data.graficoPrincipal);
            renderTipo(data.tipo);
            renderProdutosRanking(data.produtosRanking);
            renderAlertas(data.alertas);
            renderRecentes(data.recentes);
            renderAtividades(data.atividades);
        }

        document.getElementById('dashboardFilters').addEventListener('change', () => {
            const lojaValue = document.getElementById('globalLoja').value;
            selectedLojaIds = lojaValue ? lojaValue.split(',').map(Number).filter(Boolean) : [];
            applyDashboardFilters();
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

        renderRecentes(initialRecentes);
        renderMapaCalor(initialDashboard.graficoPrincipal?.lojas || [], initialDashboard.graficoPrincipal?.loja_ids || selectedLojaIds);
        setupActivityReveal();
        fitMetricText();
        window.addEventListener('resize', fitMetricText);
    </script>
</body>
</html>
