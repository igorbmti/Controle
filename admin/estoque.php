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
        e.data_atualizacao
    FROM estoque_equipamentos e
    INNER JOIN produtos p ON p.id = e.produto_id
    {$where}
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
        padding: 16px 18px;
        border-bottom: 1px solid rgba(255, 255, 255, .08);
    }
    .stock-summary strong { display: block; font-size: 15px; }
    .stock-summary span { color: var(--muted); font-size: 13px; }
    .stock-form { grid-template-columns: minmax(260px, 1fr) minmax(120px, 180px) auto auto; align-items: end; }
    .stock-search { grid-template-columns: minmax(260px, 1fr) auto auto; align-items: end; }
    .stock-actions { display: inline-flex; align-items: center; gap: 8px; flex-wrap: wrap; }
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
    @media (max-width: 820px) {
        .stock-form,
        .stock-search { grid-template-columns: 1fr; }
    }
</style>
<section class="top">
    <div>
        <h1>Controle de Estoque</h1>
        <p>Cadastro e consulta de equipamentos em estoque.</p>
    </div>
    <a class="btn" href="dashboard.php">Voltar</a>
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
    <button class="btn primary" type="submit">Salvar</button>
    <?php if ($editando): ?>
        <a class="btn" href="estoque.php">Cancelar edição</a>
    <?php endif; ?>
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
        <select name="limite">
            <?php foreach ($limitesPermitidos as $limite): ?>
                <option value="<?php echo $limite; ?>" <?php echo $porPagina === $limite ? 'selected' : ''; ?>><?php echo $limite; ?></option>
            <?php endforeach; ?>
        </select>
    </label>    <button class="btn primary" type="submit">Pesquisar</button>
    <a class="btn" href="<?php echo $somenteCritico ? 'estoque.php?critico=1' : 'estoque.php'; ?>">Limpar</a>
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
                        <th>ID</th>
                        <th>Nome do equipamento</th>
                        <th>Quantidade em estoque</th>
                        <th>Situação</th>
                        <th>Data de cadastro</th>
                        <th>Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($estoques as $row): ?>
                        <tr>
                            <td><?php echo (int) $row['id']; ?></td>
                            <td><?php echo e($row['nome']); ?></td>
                            <td><?php echo (int) $row['quantidade']; ?></td>
                            <td><span class="stock-status <?php echo (int) $row['quantidade'] <= 3 ? 'critical' : ''; ?>"><?php echo (int) $row['quantidade'] <= 3 ? 'Crítico' : 'Regular'; ?></span></td>
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
        <nav class="pagination" aria-label="Paginação">
            <?php for ($i = 1; $i <= $totalPaginas; $i++): ?>
                <?php if ($i === $pagina): ?><span class="active"><?php echo $i; ?></span>
                <?php else: ?><a href="<?php echo e(pageUrl(['pagina' => $i])); ?>"><?php echo $i; ?></a>
                <?php endif; ?>
            <?php endfor; ?>
        </nav>
    <?php endif; ?></section>
<?php adminPageEnd(); ?>
