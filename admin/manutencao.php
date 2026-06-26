<?php
require_once __DIR__ . '/admin_helpers.php';

$pdo = getConnection();
$idUsuario = (int) ($_SESSION['id_usuario'] ?? 0);
$mensagem = null;
$erro = null;

function buscarEquipamentosManutencao(PDO $pdo): array
{
    $stmt = $pdo->query("
        SELECT DISTINCT p.id, p.nome
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = (string) ($_POST['acao'] ?? '');

    try {
        if ($acao === 'salvar') {
            $idManutencao = (int) ($_POST['id_manutencao'] ?? 0);
            $equipamentoId = (int) ($_POST['equipamento_id'] ?? 0);
            $lojaId = (int) ($_POST['loja_id'] ?? 0);
            $descricao = trim((string) ($_POST['descricao'] ?? ''));
            $status = strtoupper((string) ($_POST['status'] ?? 'EM_MANUTENCAO'));
            $status = $status === 'CONCLUIDO' ? 'CONCLUIDO' : 'EM_MANUTENCAO';

            if ($equipamentoId <= 0 || $lojaId <= 0 || $descricao === '') {
                throw new RuntimeException('Preencha equipamento, loja e descrição do problema.');
            }

            if ($idManutencao > 0) {
                $stmt = $pdo->prepare("
                    UPDATE manutencoes
                    SET id_item = :id_item,
                        id_loja = :id_loja,
                        descricao = :descricao,
                        status = :status,
                        data_conclusao = CASE WHEN :status_conclusao = 'CONCLUIDO' THEN COALESCE(data_conclusao, NOW()) ELSE NULL END
                    WHERE id_manutencao = :id_manutencao
                ");
                $stmt->execute([
                    ':id_item' => $equipamentoId,
                    ':id_loja' => $lojaId,
                    ':descricao' => $descricao,
                    ':status' => $status,
                    ':status_conclusao' => $status,
                    ':id_manutencao' => $idManutencao,
                ]);
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO manutencoes (id_item, id_loja, descricao, status, id_usuario, data_registro, data_conclusao, ativo)
                    VALUES (:id_item, :id_loja, :descricao, :status, :id_usuario, NOW(), CASE WHEN :status_conclusao = 'CONCLUIDO' THEN NOW() ELSE NULL END, 1)
                ");
                $stmt->execute([
                    ':id_item' => $equipamentoId,
                    ':id_loja' => $lojaId,
                    ':descricao' => $descricao,
                    ':status' => $status,
                    ':id_usuario' => $idUsuario,
                    ':status_conclusao' => $status,
                ]);
            }

            header('Location: manutencao.php?salvo=1');
            exit;
        }

        if ($acao === 'concluir') {
            $stmt = $pdo->prepare("
                UPDATE manutencoes
                SET status = 'CONCLUIDO',
                    data_conclusao = COALESCE(data_conclusao, NOW())
                WHERE id_manutencao = :id_manutencao
            ");
            $stmt->execute([':id_manutencao' => (int) ($_POST['id_manutencao'] ?? 0)]);
            header('Location: manutencao.php?concluido=1');
            exit;
        }

        if ($acao === 'excluir') {
            $stmt = $pdo->prepare('UPDATE manutencoes SET ativo = 0 WHERE id_manutencao = :id_manutencao');
            $stmt->execute([':id_manutencao' => (int) ($_POST['id_manutencao'] ?? 0)]);
            header('Location: manutencao.php?excluido=1');
            exit;
        }
    } catch (Throwable $e) {
        error_log('Erro na manutenção: ' . $e->getMessage());
        $erro = 'Não foi possível concluir a operação. Tente novamente.';
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
        SELECT *
        FROM manutencoes
        WHERE id_manutencao = :id_manutencao
          AND COALESCE(ativo, 1) = 1
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
 m.data_registro, m.data_conclusao, COALESCE(NULLIF(i.serial, ''), 'N/A') AS serial " . $baseSql . "
 ORDER BY m.data_registro DESC, m.id_manutencao DESC LIMIT :limite OFFSET :offset");
foreach ($params as $chave => $valor) $stmt->bindValue($chave, $valor);
$stmt->bindValue(':limite', $porPagina, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$manutencoes = $stmt->fetchAll();

adminPageStart('Manutenção');
?>
<style>
    .maintenance-form { grid-template-columns: minmax(220px, 1fr) minmax(180px, 240px) minmax(180px, 220px) auto auto; align-items: end; }
    .maintenance-filter { grid-template-columns: minmax(260px, 1fr) auto auto; align-items: end; }
    .maintenance-filter .limit-control { grid-column: 1 / -1; width: fit-content; }
    .maintenance-form label.description { grid-column: 1 / -1; }
    .maintenance-form textarea {
        min-height: 88px;
        border: 1px solid var(--line);
        border-radius: var(--radius);
        background: #10151c;
        color: #fff;
        padding: 10px;
        font: inherit;
        resize: vertical;
    }
    .maintenance-actions { display: inline-flex; gap: 8px; flex-wrap: wrap; }
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
    .maintenance-modal-footer { border-top:1px solid var(--line); justify-content:flex-end; }    @media (max-width: 980px) {
        .maintenance-form { grid-template-columns: 1fr; }
        .maintenance-form label.description { grid-column: auto; }
    }
</style>

<section class="top">
    <div>
        <h1>Manutenção</h1>
        <p>Registre equipamentos enviados para manutenção.</p>
    </div>
</section>

<?php if ($mensagem): ?>
    <section class="panel"><div class="empty"><?php echo e($mensagem); ?></div></section>
<?php endif; ?>
<?php if ($erro): ?>
    <section class="panel"><div class="empty"><?php echo e($erro); ?></div></section>
<?php endif; ?>

<form class="panel filters maintenance-form" method="POST">
    <input type="hidden" name="acao" value="salvar">
    <input type="hidden" name="id_manutencao" value="<?php echo (int) ($editando['id_manutencao'] ?? 0); ?>">

    <label>Equipamento
        <select name="equipamento_id" required>
            <option value="">Selecione</option>
            <?php foreach ($equipamentos as $equipamento): ?>
                <option value="<?php echo (int) $equipamento['id']; ?>" <?php echo (int) ($editando['id_item'] ?? 0) === (int) $equipamento['id'] ? 'selected' : ''; ?>>
                    <?php echo e($equipamento['nome']); ?>
                </option>
            <?php endforeach; ?>
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

    <button class="btn primary" type="submit"><?php echo $editando ? 'Salvar alterações' : 'Adicionar equipamento em manutenção'; ?></button>
    <?php if ($editando): ?>
        <a class="btn" href="manutencao.php">Cancelar edição</a>
    <?php endif; ?>

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
    <button class="btn primary" type="submit">Pesquisar</button>
    <a class="btn" href="manutencao.php">Limpar</a>
</form>

<section class="panel">
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
                            <td><?php echo e($row['equipamento']); ?></td>
                            <td><?php echo e($row['loja']); ?></td>

                            <td><?php echo e($row['serial'] ?: 'N/A'); ?></td>
                            <td><?php echo e(date('d/m/Y H:i', strtotime((string) $row['data_registro']))); ?></td>
                            <td><?php echo e($row['usuario']); ?></td>
                            <td>
                                <div class="maintenance-actions">
                                    <button class="btn js-view-description" type="button" data-description="<?php echo e($row['descricao']); ?>">Visualizar</button>
                                    <a class="btn" href="manutencao.php?editar=<?php echo (int) $row['id_manutencao']; ?>">Editar</a>
                                    <?php if (!$concluido): ?>
                                        <form method="POST" style="display:inline;">
                                            <input type="hidden" name="acao" value="concluir">
                                            <input type="hidden" name="id_manutencao" value="<?php echo (int) $row['id_manutencao']; ?>">
                                            <button class="btn" type="submit">Concluir</button>
                                        </form>
                                    <?php endif; ?>
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Confirmar remoção deste registro?');">
                                        <input type="hidden" name="acao" value="excluir">
                                        <input type="hidden" name="id_manutencao" value="<?php echo (int) $row['id_manutencao']; ?>">
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
        <nav class="pagination" aria-label="Paginação">
            <?php for ($i = 1; $i <= $totalPaginas; $i++): ?>
                <?php if ($i === $pagina): ?><span class="active"><?php echo $i; ?></span>
                <?php else: ?><a href="<?php echo e(pageUrl(['pagina' => $i])); ?>"><?php echo $i; ?></a>
                <?php endif; ?>
            <?php endfor; ?>
        </nav>
    <?php endif; ?></section>
<div class="maintenance-modal" id="descriptionModal" aria-hidden="true">
    <div class="maintenance-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="descriptionModalTitle">
        <div class="maintenance-modal-header"><h2 id="descriptionModalTitle">Descrição do Problema</h2><button class="btn js-close-description" type="button">Fechar</button></div>
        <div class="maintenance-modal-body" id="descriptionModalBody"></div>
        <div class="maintenance-modal-footer"><button class="btn primary js-close-description" type="button">Entendi</button></div>
    </div>
</div>
<script>
(() => {
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
