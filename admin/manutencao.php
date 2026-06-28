<?php
require_once __DIR__ . '/admin_helpers.php';
require_once __DIR__ . '/../includes/serial_control.php';

$pdo = getConnection();
$idUsuario = (int) ($_SESSION['id_usuario'] ?? 0);
$mensagem = null;
$erro = null;

function buscarEquipamentosManutencao(PDO $pdo): array
{
    $stmt = $pdo->query("
        SELECT DISTINCT p.id, p.nome, p.controla_serial
        FROM estoque_equipamentos e
        INNER JOIN produtos p ON p.id = e.produto_id
        WHERE COALESCE(p.ativo, 1) = 1
        ORDER BY p.nome
    ");

    return $stmt->fetchAll();
}

function buscarLojasManutencao(PDO $pdo): array
{
    $stmt = $pdo->query("
        SELECT id, nome
        FROM lojas
        WHERE ativo = 1
        ORDER BY FIELD(id, 1, 2, 10, 4, 5, 6, 11, 8, 9), id, nome
    ");

    return $stmt->fetchAll();
}

function statusManutencaoLabel(string $status): string
{
    return strtoupper($status) === 'CONCLUIDO' ? 'Concluído' : 'Em manutenção';
}

if (isset($_GET['ajax']) && $_GET['ajax'] === 'seriais') {
    header('Content-Type: application/json; charset=utf-8');
    $produtoId = (int) ($_GET['produto_id'] ?? 0);
    $idSerialAtual = (int) ($_GET['id_serial'] ?? 0);
    $stmt = $pdo->prepare("
        SELECT id_serial, serial, status
        FROM equipamento_seriais
        WHERE id_equipamento = :produto_id
          AND (status = 'DISPONIVEL' OR id_serial = :id_serial_atual)
        ORDER BY serial, id_serial
    ");
    $stmt->execute([
        ':produto_id' => $produtoId,
        ':id_serial_atual' => $idSerialAtual,
    ]);
    echo json_encode($stmt->fetchAll(), JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfRequireValid();
    $acao = (string) ($_POST['acao'] ?? '');

    try {
        if ($acao === 'salvar') {
            $idManutencao = (int) ($_POST['id_manutencao'] ?? 0);
            $equipamentoId = (int) ($_POST['equipamento_id'] ?? 0);
            $idSerial = (int) ($_POST['id_serial'] ?? 0);
            $lojaId = (int) ($_POST['loja_id'] ?? 0);
            $descricao = trim((string) ($_POST['descricao'] ?? ''));
            $status = strtoupper((string) ($_POST['status'] ?? 'EM_MANUTENCAO'));
            $status = $status === 'CONCLUIDO' ? 'CONCLUIDO' : 'EM_MANUTENCAO';

            if ($equipamentoId <= 0 || $lojaId <= 0 || $descricao === '') {
                throw new RuntimeException('Preencha equipamento, loja e descrição do problema.');
            }

            $pdo->beginTransaction();
            $stmtProduto = $pdo->prepare('SELECT nome, controla_serial FROM produtos WHERE id = :id LIMIT 1 FOR UPDATE');
            $stmtProduto->execute([':id' => $equipamentoId]);
            $produto = $stmtProduto->fetch();
            if (!$produto) {
                throw new RuntimeException('Equipamento não encontrado.');
            }

            $controlaSerial = (bool) $produto['controla_serial'];
            $serialSelecionado = null;
            $idItemManutencao = $equipamentoId;
            if ($controlaSerial) {
                if ($idSerial <= 0) {
                    throw new RuntimeException('Selecione o serial do equipamento.');
                }
                $serialSelecionado = serialControlBuscar($pdo, $idSerial, $equipamentoId, true);
                $mesmoRegistro = $idManutencao > 0
                    && strtoupper((string) ($serialSelecionado['status'] ?? '')) === 'EM_MANUTENCAO';
                if (!$mesmoRegistro) {
                    serialControlValidarDisponivel($serialSelecionado);
                }
                $idItemManutencao = (int) ($serialSelecionado['id_item'] ?? 0);
                if ($idItemManutencao <= 0) {
                    $idItemManutencao = serialControlCriarItem($pdo, $equipamentoId, (string) $produto['nome'], (string) $serialSelecionado['serial']);
                    $stmtVincular = $pdo->prepare('UPDATE equipamento_seriais SET id_item = :id_item WHERE id_serial = :id_serial');
                    $stmtVincular->execute([':id_item' => $idItemManutencao, ':id_serial' => $idSerial]);
                }
            }

            if ($idManutencao > 0) {
                $stmt = $pdo->prepare("
                    UPDATE manutencoes
                    SET id_item = :id_item,
                        id_serial = :id_serial,
                        id_loja = :id_loja,
                        descricao = :descricao,
                        status = :status,
                        data_conclusao = CASE WHEN :status_conclusao = 'CONCLUIDO' THEN COALESCE(data_conclusao, NOW()) ELSE NULL END
                    WHERE id_manutencao = :id_manutencao
                ");
                $stmt->execute([
                    ':id_item' => $idItemManutencao,
                    ':id_serial' => $controlaSerial ? $idSerial : null,
                    ':id_loja' => $lojaId,
                    ':descricao' => $descricao,
                    ':status' => $status,
                    ':status_conclusao' => $status,
                    ':id_manutencao' => $idManutencao,
                ]);
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO manutencoes (id_item, id_serial, id_loja, descricao, status, id_usuario, data_registro, data_conclusao, ativo)
                    VALUES (:id_item, :id_serial, :id_loja, :descricao, :status, :id_usuario, NOW(), CASE WHEN :status_conclusao = 'CONCLUIDO' THEN NOW() ELSE NULL END, 1)
                ");
                $stmt->execute([
                    ':id_item' => $idItemManutencao,
                    ':id_serial' => $controlaSerial ? $idSerial : null,
                    ':id_loja' => $lojaId,
                    ':descricao' => $descricao,
                    ':status' => $status,
                    ':id_usuario' => $idUsuario,
                    ':status_conclusao' => $status,
                ]);
            }

            if ($controlaSerial) {
                $novoStatusSerial = $status === 'CONCLUIDO' ? 'DISPONIVEL' : 'EM_MANUTENCAO';
                $stmtSerial = $pdo->prepare('
                    UPDATE equipamento_seriais
                    SET status = :status,
                        loja_atual = :loja_id
                    WHERE id_serial = :id_serial
                ');
                $stmtSerial->execute([
                    ':status' => $novoStatusSerial,
                    ':loja_id' => $novoStatusSerial === 'DISPONIVEL' ? null : $lojaId,
                    ':id_serial' => $idSerial,
                ]);
                $stmtItem = $pdo->prepare('UPDATE itens SET status = :status WHERE id = :id_item');
                $stmtItem->execute([
                    ':status' => $novoStatusSerial === 'DISPONIVEL' ? 'Estoque' : 'Manutenção',
                    ':id_item' => $idItemManutencao,
                ]);
                serialControlSincronizarEstoque($pdo, $equipamentoId);
            }

            $pdo->commit();

            header('Location: manutencao.php?salvo=1');
            exit;
        }

        if ($acao === 'concluir') {
            $pdo->beginTransaction();
            $idManutencao = (int) ($_POST['id_manutencao'] ?? 0);
            $stmtSerialAtual = $pdo->prepare('
                SELECT m.id_serial, es.id_equipamento, es.id_item
                FROM manutencoes m
                LEFT JOIN equipamento_seriais es ON es.id_serial = m.id_serial
                WHERE m.id_manutencao = :id_manutencao
                LIMIT 1
                FOR UPDATE
            ');
            $stmtSerialAtual->execute([':id_manutencao' => $idManutencao]);
            $serialAtual = $stmtSerialAtual->fetch();
            $stmt = $pdo->prepare("
                UPDATE manutencoes
                SET status = 'CONCLUIDO',
                    data_conclusao = COALESCE(data_conclusao, NOW())
                WHERE id_manutencao = :id_manutencao
            ");
            $stmt->execute([':id_manutencao' => $idManutencao]);
            if (!empty($serialAtual['id_serial'])) {
                $stmtSerial = $pdo->prepare("
                    UPDATE equipamento_seriais
                    SET status = 'DISPONIVEL', loja_atual = NULL
                    WHERE id_serial = :id_serial
                ");
                $stmtSerial->execute([':id_serial' => (int) $serialAtual['id_serial']]);
                $stmtItem = $pdo->prepare("UPDATE itens SET status = 'Estoque' WHERE id = :id_item");
                $stmtItem->execute([':id_item' => (int) $serialAtual['id_item']]);
                serialControlSincronizarEstoque($pdo, (int) $serialAtual['id_equipamento']);
            }
            $pdo->commit();
            header('Location: manutencao.php?concluido=1');
            exit;
        }

        if ($acao === 'excluir') {
            $pdo->beginTransaction();
            $idManutencao = (int) ($_POST['id_manutencao'] ?? 0);
            $stmtAtual = $pdo->prepare('
                SELECT m.id_serial, es.id_equipamento, es.id_item
                FROM manutencoes m
                LEFT JOIN equipamento_seriais es ON es.id_serial = m.id_serial
                WHERE m.id_manutencao = :id_manutencao
                LIMIT 1
                FOR UPDATE
            ');
            $stmtAtual->execute([':id_manutencao' => $idManutencao]);
            $serialAtual = $stmtAtual->fetch();
            $stmt = $pdo->prepare('UPDATE manutencoes SET ativo = 0 WHERE id_manutencao = :id_manutencao');
            $stmt->execute([':id_manutencao' => $idManutencao]);
            if (!empty($serialAtual['id_serial'])) {
                $stmtSerial = $pdo->prepare("
                    UPDATE equipamento_seriais
                    SET status = 'DISPONIVEL', loja_atual = NULL
                    WHERE id_serial = :id_serial
                      AND status = 'EM_MANUTENCAO'
                ");
                $stmtSerial->execute([':id_serial' => (int) $serialAtual['id_serial']]);
                $stmtItem = $pdo->prepare("UPDATE itens SET status = 'Estoque' WHERE id = :id_item");
                $stmtItem->execute([':id_item' => (int) $serialAtual['id_item']]);
                serialControlSincronizarEstoque($pdo, (int) $serialAtual['id_equipamento']);
            }
            $pdo->commit();
            header('Location: manutencao.php?excluido=1');
            exit;
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Erro na manutenção: ' . $e->getMessage());
        $erro = $e instanceof RuntimeException
            ? $e->getMessage()
            : 'Não foi possível concluir a operação. Tente novamente.';
    }
}

if (isset($_GET['salvo'])) {
    $mensagem = 'Registro de manutenção salvo com sucesso.';
}
if (isset($_GET['concluido'])) {
    $mensagem = 'Manutenção marcada como concluída.';
}
if (isset($_GET['excluido'])) {
    $mensagem = 'Registro removido da listagem.';
}

$editando = null;
if (!empty($_GET['editar'])) {
    $stmt = $pdo->prepare("
        SELECT m.*, COALESCE(es.id_equipamento, m.id_item) AS equipamento_id
        FROM manutencoes m
        LEFT JOIN equipamento_seriais es ON es.id_serial = m.id_serial
        WHERE m.id_manutencao = :id_manutencao
          AND COALESCE(m.ativo, 1) = 1
        LIMIT 1
    ");
    $stmt->execute([':id_manutencao' => (int) $_GET['editar']]);
    $editando = $stmt->fetch() ?: null;
}

$equipamentos = buscarEquipamentosManutencao($pdo);
$lojas = buscarLojasManutencao($pdo);

$filtroStatus = strtoupper(trim((string) ($_GET['filtro'] ?? 'RECENTES')));
$filtroStatus = in_array($filtroStatus, ['RECENTES', 'CONCLUIDAS', 'TODAS'], true) ? $filtroStatus : 'RECENTES';
$busca = trim((string) ($_GET['busca'] ?? ''));
$limitesPermitidos = [5, 10, 20, 30, 50];
$porPagina = (int) ($_GET['limite'] ?? 5);
$porPagina = in_array($porPagina, $limitesPermitidos, true) ? $porPagina : 5;
$pagina = max(1, (int) ($_GET['pagina'] ?? 1));
$where = ['COALESCE(m.ativo, 1) = 1'];
$params = [];
if ($filtroStatus === 'RECENTES' && $busca === '') $where[] = "UPPER(m.status) = 'EM_MANUTENCAO'";
elseif ($filtroStatus === 'CONCLUIDAS') $where[] = "UPPER(m.status) = 'CONCLUIDO'";
if ($busca !== '') {
    $where[] = '(p.nome LIKE :busca_produto OR pi.nome LIKE :busca_item OR l.nome LIKE :busca_loja)';
    $params[':busca_produto'] = '%' . $busca . '%';
    $params[':busca_item'] = '%' . $busca . '%';
    $params[':busca_loja'] = '%' . $busca . '%';
}
$baseSql = " FROM manutencoes m
 LEFT JOIN produtos p ON p.id = m.id_item
 LEFT JOIN itens i ON i.id = m.id_item
 LEFT JOIN equipamento_seriais es ON es.id_serial = m.id_serial
 LEFT JOIN produtos pi ON pi.id = i.produto_id
 LEFT JOIN lojas l ON l.id = m.id_loja
 LEFT JOIN usuarios u ON u.id = m.id_usuario
 WHERE " . implode(' AND ', $where);
$countStmt = $pdo->prepare('SELECT COUNT(*) ' . $baseSql);
$countStmt->execute($params);
$totalRegistros = (int) $countStmt->fetchColumn();
$totalPaginas = max(1, (int) ceil($totalRegistros / $porPagina));
$pagina = min($pagina, $totalPaginas);
$offset = ($pagina - 1) * $porPagina;
$stmt = $pdo->prepare("SELECT m.id_manutencao, COALESCE(p.nome, pi.nome, '-') AS equipamento,
 COALESCE(l.nome, '-') AS loja, COALESCE(u.nome, '-') AS usuario, m.descricao, m.status,
 m.data_registro, m.data_conclusao, COALESCE(NULLIF(es.serial, ''), NULLIF(i.serial, ''), 'N/A') AS serial, m.id_serial " . $baseSql . "
 ORDER BY m.data_registro DESC, m.id_manutencao DESC LIMIT :limite OFFSET :offset");
foreach ($params as $chave => $valor) $stmt->bindValue($chave, $valor);
$stmt->bindValue(':limite', $porPagina, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$manutencoes = $stmt->fetchAll();

$stmtHistorico = $pdo->query("
    SELECT
        m.id_manutencao,
        COALESCE(p.nome, pi.nome, '-') AS equipamento,
        COALESCE(l.nome, '-') AS loja,
        COALESCE(NULLIF(es.serial, ''), NULLIF(i.serial, ''), 'N/A') AS serial,
        COALESCE(u.nome, '-') AS usuario,
        m.status,
        m.data_registro,
        m.data_conclusao
    FROM manutencoes m
    LEFT JOIN produtos p ON p.id = m.id_item
    LEFT JOIN itens i ON i.id = m.id_item
    LEFT JOIN equipamento_seriais es ON es.id_serial = m.id_serial
    LEFT JOIN produtos pi ON pi.id = i.produto_id
    LEFT JOIN lojas l ON l.id = m.id_loja
    LEFT JOIN usuarios u ON u.id = m.id_usuario
    ORDER BY m.data_registro DESC, m.id_manutencao DESC
");
$historicoManutencoes = $stmtHistorico->fetchAll();

adminPageStart('Manutenção');
?>
<style>
    .main, .page { max-width: 100%; min-width: 0; }
    .top { margin-bottom: 24px; }
    .panel { width: 100%; max-width: 100%; min-width: 0; margin-bottom: 18px; }
    .maintenance-form {
        grid-template-columns: repeat(12, minmax(0, 1fr));
        gap: 12px;
        padding: 20px;
        align-items: end;
    }
    .maintenance-form > label:nth-of-type(1) { grid-column: span 3; }
    .maintenance-form > label:nth-of-type(2) { grid-column: span 3; }
    .maintenance-form > label:nth-of-type(3),
    .maintenance-form > label:nth-of-type(4) { grid-column: span 2; }
    .maintenance-form .form-actions { grid-column: span 2; }
    .maintenance-filter {
        grid-template-columns: minmax(260px, 1fr) auto minmax(200px, auto);
        gap: 12px;
        padding: 20px;
        align-items: end;
    }
    .maintenance-filter .limit-control { width: auto; }
    .maintenance-form label.description { grid-column: 1 / -1; }
    .maintenance-form > *,
    .maintenance-filter > * { min-width: 0; }
    .maintenance-form input,
    .maintenance-form select,
    .maintenance-filter input,
    .maintenance-filter select {
        width: 100%;
        height: 38px;
    }
    .maintenance-form textarea {
        width: 100%;
        min-height: 88px;
        border: 1px solid var(--line);
        border-radius: var(--radius);
        background: #10151c;
        color: #fff;
        padding: 10px;
        font: inherit;
        resize: vertical;
    }
    .form-actions,
    .filter-actions {
        display: grid;
        grid-auto-flow: column;
        grid-auto-columns: minmax(0, 1fr);
        gap: 10px;
        align-items: end;
    }
    .maintenance-form .btn,
    .maintenance-filter .btn {
        width: 100%;
        min-height: 38px;
        white-space: normal;
        overflow-wrap: anywhere;
    }
    .maintenance-actions {
        display: grid;
        grid-template-columns: repeat(3, minmax(88px, 1fr));
        align-items: stretch;
        gap: 10px;
        width: 100%;
    }
    .maintenance-actions .maintenance-action-slot {
        display: block;
        min-width: 0;
        width: 100%;
        height: 100%;
        margin: 0;
    }
    .maintenance-action-button {
        display: flex;
        align-items: center;
        justify-content: center;
        box-sizing: border-box;
        width: 100%;
        min-width: 88px;
        height: 40px;
        min-height: 40px;
        padding: 0 12px;
        border-radius: 6px;
        font-family: inherit;
        font-size: 13px;
        font-weight: 700;
        line-height: 1;
        text-align: center;
        white-space: nowrap;
        overflow-wrap: normal;
    }
    .maintenance-action-button:disabled { cursor: not-allowed; opacity: .45; transform: none; }
    .maintenance-badge {
        display: inline-flex;
        min-height: 28px;
        align-items: center;
        padding: 0 10px;
        border-radius: 6px;
        background: rgba(245, 179, 1, .16);
        color: #ffd36a;
        font-weight: 800;
        font-size: 12px;
    }
    .maintenance-badge.done { background: rgba(39, 184, 77, .16); color: #bdf5c9; }
    .description-cell {
        max-width: 360px;
        white-space: normal;
        color: var(--muted);
    }
    .maintenance-modal { position: fixed; inset: 0; z-index: 1200; display: none; place-items: center; padding: 24px; background: rgba(0,0,0,.72); backdrop-filter: blur(4px); }
    .maintenance-modal.open { display: grid; }
    .maintenance-modal-dialog { width: min(560px, 100%); border: 1px solid var(--line); border-radius: 8px; background: #11171f; box-shadow: 0 24px 70px rgba(0,0,0,.52); overflow: hidden; }
    .maintenance-modal-header, .maintenance-modal-footer { display:flex; align-items:center; justify-content:space-between; gap:16px; padding:16px 18px; }
    .maintenance-modal-header { border-bottom:1px solid var(--line); }
    .maintenance-modal-header h2 { margin:0; font-size:16px; }
    .maintenance-modal-body { padding:18px; color:#dce2ea; line-height:1.65; white-space:pre-wrap; overflow-wrap:anywhere; }
    .maintenance-modal-footer { border-top:1px solid var(--line); justify-content:flex-end; }
    .maintenance-history-dialog { width: min(1180px, 100%); max-height: calc(100vh - 48px); display: flex; flex-direction: column; }
    .maintenance-history-body { min-height: 0; padding: 18px; overflow: auto; white-space: normal; }
    .maintenance-history-filters { display: grid; grid-template-columns: repeat(5, minmax(150px, 1fr)); gap: 12px; margin-bottom: 16px; }
    .maintenance-history-filters label { display: grid; gap: 7px; color: var(--muted); font-size: 12px; font-weight: 700; }
    .maintenance-history-filters input { width: 100%; height: 40px; }
    .maintenance-history-summary { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 12px; color: var(--muted); font-size: 12px; }
    .maintenance-history-table-wrap { max-height: 52vh; overflow: auto; border: 1px solid var(--line); border-radius: 8px; }
    .maintenance-history-table { width: 100%; min-width: 820px; table-layout: auto; }
    .maintenance-history-table th { position: sticky; z-index: 1; top: 0; background: #151c25; }
    .maintenance-history-table th,
    .maintenance-history-table td { padding: 12px 14px; text-align: left; white-space: nowrap; }
    .maintenance-history-empty td { padding: 24px; text-align: center; color: var(--muted); }
    .maintenance-table .table-wrap { width: 100%; max-width: 100%; overflow: hidden; }
    .maintenance-table table { width: 100%; min-width: 0; table-layout: fixed; }
    .maintenance-table th,
    .maintenance-table td {
        padding: 14px 12px;
        vertical-align: middle;
        white-space: normal;
        overflow-wrap: anywhere;
    }
    .maintenance-table th:last-child,
    .maintenance-table td:last-child { width: 300px; }
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
    @media (max-width: 1100px) {
        .maintenance-form > label:nth-of-type(1),
        .maintenance-form > label:nth-of-type(2) { grid-column: span 6; }
        .maintenance-form > label:nth-of-type(3) { grid-column: span 4; }
        .maintenance-form .form-actions { grid-column: span 8; }
        .maintenance-history-filters { grid-template-columns: repeat(2, minmax(180px, 1fr)); }
    }
    @media (max-width: 720px) {
        .maintenance-form > label,
        .maintenance-form .form-actions,
        .maintenance-form label.description { grid-column: 1 / -1; }
        .maintenance-filter { grid-template-columns: 1fr; }
        .form-actions,
        .filter-actions { grid-auto-flow: row; grid-template-columns: 1fr; }
        .maintenance-actions { gap: 8px; }
        .maintenance-table th:last-child,
        .maintenance-table td:last-child { width: auto; }
        .pagination { justify-content: flex-end; flex-wrap: wrap; }
        .maintenance-history-filters { grid-template-columns: 1fr; }
        .maintenance-history-dialog { max-height: calc(100vh - 24px); }
    }
</style>

<section class="top">
    <div>
        <h1>Manutenção</h1>
        <p>Registre equipamentos enviados para manutenção.</p>
    </div>
    <button class="btn" id="openMaintenanceHistory" type="button">Histórico de Manutenções</button>
</section>

<?php if ($mensagem): ?>
    <section class="panel"><div class="empty"><?php echo e($mensagem); ?></div></section>
<?php endif; ?>
<?php if ($erro): ?>
    <section class="panel"><div class="empty"><?php echo e($erro); ?></div></section>
<?php endif; ?>

<form class="panel filters maintenance-form" method="POST">
    <?php echo csrfInput(); ?>
    <input type="hidden" name="acao" value="salvar">
    <input type="hidden" name="id_manutencao" value="<?php echo (int) ($editando['id_manutencao'] ?? 0); ?>">

    <label>Equipamento
        <select name="equipamento_id" required>
            <option value="">Selecione</option>
            <?php foreach ($equipamentos as $equipamento): ?>
                <option value="<?php echo (int) $equipamento['id']; ?>" data-controla-serial="<?php echo (int) $equipamento['controla_serial']; ?>" <?php echo (int) ($editando['equipamento_id'] ?? $editando['id_item'] ?? 0) === (int) $equipamento['id'] ? 'selected' : ''; ?>>
                    <?php echo e($equipamento['nome']); ?>
                </option>
            <?php endforeach; ?>
        </select>
    </label>

    <label id="maintenanceSerialField" hidden>Serial
        <select name="id_serial" id="maintenanceSerialSelect" data-current="<?php echo (int) ($editando['id_serial'] ?? 0); ?>">
            <option value="">Selecione o serial</option>
        </select>
    </label>

    <label>Loja
        <select name="loja_id" required>
            <option value="">Selecione</option>
            <?php foreach ($lojas as $loja): ?>
                <option value="<?php echo (int) $loja['id']; ?>" <?php echo (int) ($editando['id_loja'] ?? 0) === (int) $loja['id'] ? 'selected' : ''; ?>>
                    <?php echo e($loja['nome']); ?>
                </option>
            <?php endforeach; ?>
        </select>
    </label>

    <label>Status
        <select name="status">
            <option value="EM_MANUTENCAO" <?php echo strtoupper((string) ($editando['status'] ?? '')) !== 'CONCLUIDO' ? 'selected' : ''; ?>>Em manutenção</option>
            <option value="CONCLUIDO" <?php echo strtoupper((string) ($editando['status'] ?? '')) === 'CONCLUIDO' ? 'selected' : ''; ?>>Concluído</option>
        </select>
    </label>

    <div class="form-actions">
        <button class="btn primary" type="submit"><?php echo $editando ? 'Salvar alterações' : 'Adicionar equipamento em manutenção'; ?></button>
        <?php if ($editando): ?>
            <a class="btn" href="manutencao.php">Cancelar edição</a>
        <?php endif; ?>
    </div>

    <label class="description">Descrição do problema
        <textarea name="descricao" required><?php echo e($editando['descricao'] ?? ''); ?></textarea>
    </label>
</form>
<form class="panel filters maintenance-filter" method="GET">
    <label>Pesquisar<input type="search" name="busca" value="<?php echo e($busca); ?>" placeholder="Equipamento ou loja"></label>
    <label class="limit-control" aria-label="Quantidade de registros"><span class="filter-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 5h18l-7 8v5l-4 2v-7L3 5Z"/></svg></span><select name="limite" onchange="this.form.submit()">
        <?php foreach ($limitesPermitidos as $limite): ?>
            <option value="<?php echo $limite; ?>" <?php echo $porPagina === $limite ? 'selected' : ''; ?>><?php echo $limite; ?></option>
        <?php endforeach; ?>
    </select></label>
    <div class="filter-actions">
        <button class="btn primary" type="submit">Pesquisar</button>
        <a class="btn" href="manutencao.php">Limpar</a>
    </div>
</form>

<section class="panel maintenance-table">
    <?php if (empty($manutencoes)): ?>
        <div class="empty">Nenhum equipamento em manutenção.</div>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Equipamento</th>
                        <th>Loja</th>

                        <th>Serial</th>
                        <th>Data de envio</th>
                        <th>Usuário</th>
                        <th>Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($manutencoes as $row): ?>
                        <?php $concluido = strtoupper((string) $row['status']) === 'CONCLUIDO'; ?>
                        <tr>
                            <td data-label="Equipamento"><?php echo e($row['equipamento']); ?></td>
                            <td data-label="Loja"><?php echo e($row['loja']); ?></td>

                            <td data-label="Serial"><?php echo e($row['serial'] ?: 'N/A'); ?></td>
                            <td data-label="Data de envio"><?php echo e(date('d/m/Y H:i', strtotime((string) $row['data_registro']))); ?></td>
                            <td data-label="Usuário"><?php echo e($row['usuario']); ?></td>
                            <td data-label="Ações">
                                <div class="maintenance-actions">
                                    <div class="maintenance-action-slot">
                                        <button class="btn maintenance-action-button js-view-description" type="button" data-description="<?php echo e($row['descricao']); ?>">Visualizar</button>
                                    </div>
                                    <?php if (!$concluido): ?>
                                        <form class="maintenance-action-slot" method="POST">
                                            <?php echo csrfInput(); ?>
                                            <input type="hidden" name="acao" value="concluir">
                                            <input type="hidden" name="id_manutencao" value="<?php echo (int) $row['id_manutencao']; ?>">
                                            <button class="btn maintenance-action-button" type="submit">Concluir</button>
                                        </form>
                                    <?php else: ?>
                                        <div class="maintenance-action-slot">
                                            <button class="btn maintenance-action-button" type="button" disabled>Concluir</button>
                                        </div>
                                    <?php endif; ?>
                                    <form class="maintenance-action-slot" method="POST" onsubmit="return confirm('Confirmar remoção deste registro?');">
                                        <?php echo csrfInput(); ?>
                                        <input type="hidden" name="acao" value="excluir">
                                        <input type="hidden" name="id_manutencao" value="<?php echo (int) $row['id_manutencao']; ?>">
                                        <button class="btn maintenance-action-button" type="submit">Excluir</button>
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
<div class="maintenance-modal" id="maintenanceHistoryModal" aria-hidden="true">
    <div class="maintenance-modal-dialog maintenance-history-dialog" role="dialog" aria-modal="true" aria-labelledby="maintenanceHistoryTitle">
        <div class="maintenance-modal-header">
            <h2 id="maintenanceHistoryTitle">Histórico de Manutenções</h2>
            <button class="btn js-close-maintenance-history" type="button">Fechar</button>
        </div>
        <div class="maintenance-history-body">
            <div class="maintenance-history-filters">
                <label>Equipamento<input type="search" id="historyEquipment" placeholder="Pesquisar equipamento"></label>
                <label>Loja<input type="search" id="historyStore" placeholder="Pesquisar loja"></label>
                <label>Serial<input type="search" id="historySerial" placeholder="Pesquisar serial"></label>
                <label>Data<input type="date" id="historyDate"></label>
                <label>Usuário<input type="search" id="historyUser" placeholder="Pesquisar usuário"></label>
            </div>
            <div class="maintenance-history-summary">
                <span id="maintenanceHistoryCount"><?php echo count($historicoManutencoes); ?> registro(s)</span>
                <button class="btn" id="clearMaintenanceHistory" type="button">Limpar pesquisa</button>
            </div>
            <div class="maintenance-history-table-wrap">
                <table class="maintenance-history-table">
                    <thead><tr><th>Equipamento</th><th>Loja</th><th>Serial</th><th>Data</th><th>Usuário</th><th>Status</th></tr></thead>
                    <tbody id="maintenanceHistoryBody">
                        <?php foreach ($historicoManutencoes as $historico): ?>
                            <tr class="maintenance-history-row" data-date="<?php echo e(date('Y-m-d', strtotime((string) $historico['data_registro']))); ?>">
                                <td data-history-field="equipment"><?php echo e($historico['equipamento']); ?></td>
                                <td data-history-field="store"><?php echo e($historico['loja']); ?></td>
                                <td data-history-field="serial"><?php echo e($historico['serial'] ?: 'N/A'); ?></td>
                                <td><?php echo e(date('d/m/Y H:i', strtotime((string) $historico['data_registro']))); ?></td>
                                <td data-history-field="user"><?php echo e($historico['usuario']); ?></td>
                                <td><span class="maintenance-badge <?php echo strtoupper((string) $historico['status']) === 'CONCLUIDO' ? 'done' : ''; ?>"><?php echo e(statusManutencaoLabel((string) $historico['status'])); ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                        <tr class="maintenance-history-empty" id="maintenanceHistoryEmpty" <?php echo $historicoManutencoes ? 'hidden' : ''; ?>><td colspan="6">Nenhuma manutenção encontrada.</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="maintenance-modal-footer"><button class="btn primary js-close-maintenance-history" type="button">Fechar</button></div>
    </div>
</div>
<div class="maintenance-modal" id="descriptionModal" aria-hidden="true">
    <div class="maintenance-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="descriptionModalTitle">
        <div class="maintenance-modal-header"><h2 id="descriptionModalTitle">Descrição do Problema</h2><button class="btn js-close-description" type="button">Fechar</button></div>
        <div class="maintenance-modal-body" id="descriptionModalBody"></div>
        <div class="maintenance-modal-footer"><button class="btn primary js-close-description" type="button">Entendi</button></div>
    </div>
</div>
<script>
(() => {
    const equipmentSelect = document.querySelector('select[name="equipamento_id"]');
    const serialField = document.getElementById('maintenanceSerialField');
    const serialSelect = document.getElementById('maintenanceSerialSelect');
    const loadSerials = async () => {
        const option = equipmentSelect?.options[equipmentSelect.selectedIndex];
        const controlsSerial = Boolean(equipmentSelect?.value) && option?.dataset.controlaSerial === '1';
        serialField.hidden = !controlsSerial;
        serialSelect.required = controlsSerial;
        serialSelect.disabled = !controlsSerial;
        serialSelect.innerHTML = '<option value="">Selecione o serial</option>';
        if (!controlsSerial || !equipmentSelect.value) return;
        const current = Number(serialSelect.dataset.current || 0);
        const response = await fetch(`manutencao.php?ajax=seriais&produto_id=${encodeURIComponent(equipmentSelect.value)}&id_serial=${current}`);
        const rows = await response.json();
        rows.forEach((row) => {
            const item = document.createElement('option');
            item.value = row.id_serial;
            item.textContent = row.serial;
            item.selected = Number(row.id_serial) === current;
            serialSelect.appendChild(item);
        });
    };
    equipmentSelect?.addEventListener('change', () => {
        serialSelect.dataset.current = '0';
        loadSerials();
    });
    loadSerials();

    const historyModal = document.getElementById('maintenanceHistoryModal');
    const historyOpenButton = document.getElementById('openMaintenanceHistory');
    const historyRows = Array.from(document.querySelectorAll('.maintenance-history-row'));
    const historyEmpty = document.getElementById('maintenanceHistoryEmpty');
    const historyCount = document.getElementById('maintenanceHistoryCount');
    const historyFilters = {
        equipment: document.getElementById('historyEquipment'),
        store: document.getElementById('historyStore'),
        serial: document.getElementById('historySerial'),
        date: document.getElementById('historyDate'),
        user: document.getElementById('historyUser'),
    };
    const normalizeHistoryText = (value) => String(value || '')
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .toLocaleLowerCase('pt-BR')
        .trim();
    const filterMaintenanceHistory = () => {
        const values = {
            equipment: normalizeHistoryText(historyFilters.equipment?.value),
            store: normalizeHistoryText(historyFilters.store?.value),
            serial: normalizeHistoryText(historyFilters.serial?.value),
            date: historyFilters.date?.value || '',
            user: normalizeHistoryText(historyFilters.user?.value),
        };
        let visible = 0;
        historyRows.forEach((row) => {
            const matches = ['equipment', 'store', 'serial', 'user'].every((field) => {
                const cell = row.querySelector(`[data-history-field="${field}"]`);
                return !values[field] || normalizeHistoryText(cell?.textContent).includes(values[field]);
            }) && (!values.date || row.dataset.date === values.date);
            row.hidden = !matches;
            if (matches) visible++;
        });
        if (historyEmpty) historyEmpty.hidden = visible !== 0;
        if (historyCount) historyCount.textContent = `${visible} ${visible === 1 ? 'registro' : 'registros'}`;
    };
    const closeMaintenanceHistory = () => {
        historyModal?.classList.remove('open');
        historyModal?.setAttribute('aria-hidden', 'true');
        historyOpenButton?.focus();
    };
    const openMaintenanceHistory = () => {
        filterMaintenanceHistory();
        historyModal?.classList.add('open');
        historyModal?.setAttribute('aria-hidden', 'false');
        historyFilters.equipment?.focus();
    };
    historyOpenButton?.addEventListener('click', openMaintenanceHistory);
    if (new URLSearchParams(window.location.search).get('filtro')?.toUpperCase() === 'TODAS') {
        openMaintenanceHistory();
    }
    Object.values(historyFilters).forEach((input) => {
        input?.addEventListener(input.type === 'date' ? 'change' : 'input', filterMaintenanceHistory);
    });
    document.getElementById('clearMaintenanceHistory')?.addEventListener('click', () => {
        Object.values(historyFilters).forEach((input) => { if (input) input.value = ''; });
        filterMaintenanceHistory();
        historyFilters.equipment?.focus();
    });
    historyModal?.querySelectorAll('.js-close-maintenance-history').forEach((button) => button.addEventListener('click', closeMaintenanceHistory));
    historyModal?.addEventListener('click', (event) => { if (event.target === historyModal) closeMaintenanceHistory(); });
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && historyModal?.classList.contains('open')) closeMaintenanceHistory();
    });

    const modal = document.getElementById('descriptionModal');
    const body = document.getElementById('descriptionModalBody');
    if (!modal || !body) return;
    const closeModal = () => { modal.classList.remove('open'); modal.setAttribute('aria-hidden', 'true'); };
    document.querySelectorAll('.js-view-description').forEach((button) => button.addEventListener('click', () => {
        body.textContent = button.dataset.description || 'Nenhuma descrição registrada.';
        modal.classList.add('open');
        modal.setAttribute('aria-hidden', 'false');
    }));
    modal.querySelectorAll('.js-close-description').forEach((button) => button.addEventListener('click', closeModal));
    modal.addEventListener('click', (event) => { if (event.target === modal) closeModal(); });
    document.addEventListener('keydown', (event) => { if (event.key === 'Escape' && modal.classList.contains('open')) closeModal(); });
})();
</script><?php adminPageEnd(); ?>
