<?php
require_once __DIR__ . '/admin_helpers.php';

$pdo = getConnection();
$acao = $_GET['acao'] ?? 'listar';
$mensagens = [];
$erros = [];

function perfilUsuario(?string $perfil): string
{
    $perfil = strtoupper(trim((string) $perfil));

    return $perfil === 'OPERADOR' ? 'TECNICO' : $perfil;
}

function perfilParaBanco(string $perfil): string
{
    return perfilUsuario($perfil) === 'ADMIN' ? 'admin' : 'operador';
}

function usuarioPorId(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT id, nome, usuario, nivel, ativo, data_criacao FROM usuarios WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $id]);
    $usuario = $stmt->fetch();

    return $usuario ?: null;
}

function loginDuplicado(PDO $pdo, string $login, int $idAtual = 0): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM usuarios WHERE usuario = :login AND id <> :id');
    $stmt->execute([
        ':login' => $login,
        ':id' => $idAtual,
    ]);

    return (int) $stmt->fetchColumn() > 0;
}

function badgeStatus(int $ativo): string
{
    return $ativo === 1 ? '<span class="badge ok">Ativo</span>' : '<span class="badge">Inativo</span>';
}

function dataBr(?string $data): string
{
    if (!$data) {
        return '-';
    }

    $timestamp = strtotime($data);
    return $timestamp ? date('d/m/Y H:i', $timestamp) : '-';
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        $acaoPost = $_POST['acao'] ?? '';

    if ($acaoPost === 'salvar') {
        $id = (int) ($_POST['id'] ?? 0);
        $nome = trim((string) ($_POST['nome'] ?? ''));
        $login = trim((string) ($_POST['login'] ?? ''));
        $perfil = perfilUsuario($_POST['perfil'] ?? '');
        $ativo = (int) ($_POST['ativo'] ?? 1);
        $senha = (string) ($_POST['senha'] ?? '');
        $confirmarSenha = (string) ($_POST['confirmar_senha'] ?? '');

        if ($nome === '') {
            $erros[] = 'Informe o nome do usuário.';
        }
        if ($login === '') {
            $erros[] = 'Informe o login do usuário.';
        }
        if (!in_array($perfil, ['ADMIN', 'TECNICO'], true)) {
            $erros[] = 'Selecione um perfil válido.';
        }
        if (!in_array($ativo, [0, 1], true)) {
            $erros[] = 'Selecione um status válido.';
        }
        if ($id === 0 && $senha === '') {
            $erros[] = 'Informe a senha do novo usuário.';
        }
        if ($senha !== '' && $senha !== $confirmarSenha) {
            $erros[] = 'As senhas informadas não coincidem.';
        }
        if ($login !== '' && loginDuplicado($pdo, $login, $id)) {
            $erros[] = 'Este login já está cadastrado.';
        }

        if (empty($erros)) {
            if ($id > 0) {
                $sql = 'UPDATE usuarios SET nome = :nome, usuario = :login, nivel = :perfil, ativo = :ativo';
                $params = [
                    ':nome' => $nome,
                    ':login' => $login,
                    ':perfil' => perfilParaBanco($perfil),
                    ':ativo' => $ativo,
                    ':id' => $id,
                ];

                if ($senha !== '') {
                    $sql .= ', senha = :senha';
                    $params[':senha'] = password_hash($senha, PASSWORD_DEFAULT);
                }

                $sql .= ' WHERE id = :id';
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                header('Location: usuarios.php?msg=alterado');
                exit;
            }

            $stmt = $pdo->prepare('
                INSERT INTO usuarios (nome, usuario, senha, nivel, ativo)
                VALUES (:nome, :login, :senha, :perfil, :ativo)
            ');
            $stmt->execute([
                ':nome' => $nome,
                ':login' => $login,
                ':senha' => password_hash($senha, PASSWORD_DEFAULT),
                ':perfil' => perfilParaBanco($perfil),
                ':ativo' => $ativo,
            ]);
            header('Location: usuarios.php?msg=cadastrado');
            exit;
        }

        $acao = $id > 0 ? 'editar' : 'novo';
    }

    if ($acaoPost === 'excluir') {
        $id = (int) ($_POST['id'] ?? 0);
        $usuario = usuarioPorId($pdo, $id);
        $idLogado = (int) ($_SESSION['id_usuario'] ?? 0);

        if (!$usuario) {
            $erros[] = 'Usuário não encontrado.';
        } elseif ((int) $usuario['id'] === $idLogado) {
            $erros[] = 'Não é permitido desativar o usuário atualmente logado.';
        } elseif (perfilUsuario($usuario['nivel'] ?? '') === 'ADMIN') {
            $erros[] = 'Não é permitido desativar administradores.';
        } else {
            $stmt = $pdo->prepare('UPDATE usuarios SET ativo = 0 WHERE id = :id');
            $stmt->execute([':id' => $id]);
            header('Location: usuarios.php?msg=desativado');
            exit;
        }
    }
    } catch (Throwable $e) {
        error_log('Erro no gerenciamento de usuários: ' . $e->getMessage());
        $erros[] = 'Não foi possível concluir a operação. Tente novamente.';
    }
}

if (isset($_GET['msg'])) {
    $mensagens[] = match ($_GET['msg']) {
        'cadastrado' => 'Usuário cadastrado com sucesso.',
        'alterado' => 'Usuário atualizado com sucesso.',
        'desativado' => 'Usuário desativado com sucesso.',
        default => '',
    };
    $mensagens = array_filter($mensagens);
}

adminPageStart('Usuários');
?>
<style>
    .messages { display: grid; gap: 10px; margin-bottom: 18px; }
    .message {
        border: 1px solid rgba(39, 184, 77, .42);
        background: rgba(39, 184, 77, .12);
        color: #dff9e6;
        border-radius: var(--radius);
        padding: 12px 14px;
        font-weight: 700;
    }
    .message.error {
        border-color: rgba(229, 9, 20, .5);
        background: rgba(229, 9, 20, .12);
        color: #ffdfe2;
    }
    .form-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 16px;
        padding: 18px;
    }
    .form-grid .full { grid-column: 1 / -1; }
    .form-actions {
        display: flex;
        justify-content: flex-end;
        gap: 10px;
        padding: 0 18px 18px;
    }
    .action-row {
        display: flex;
        align-items: center;
        gap: 8px;
    }
    .action-row form { margin: 0; }
    .btn.small {
        min-height: 32px;
        padding: 0 10px;
        font-size: 13px;
    }
    .btn.danger {
        border-color: rgba(229, 9, 20, .6);
        color: #fff;
    }
    .detail-grid {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 14px;
        padding: 18px;
    }
    .detail-card {
        border: 1px solid var(--line);
        border-radius: var(--radius);
        padding: 16px;
        background: rgba(255, 255, 255, .03);
    }
    .detail-card span {
        display: block;
        color: var(--muted);
        font-size: 13px;
        margin-bottom: 8px;
    }
    .detail-card strong {
        font-size: 22px;
    }
    .section-title {
        padding: 18px 18px 0;
        margin: 0;
        font-size: 18px;
    }
    .users-toolbar {
        display: grid;
        grid-template-columns: minmax(260px, 1fr) auto auto;
        gap: 12px;
        align-items: end;
        padding: 16px 18px;
    }
    .search-box {
        position: relative;
        display: block;
    }
    .search-box input {
        width: 100%;
        padding-left: 42px;
        height: 42px;
    }
    .search-box svg {
        position: absolute;
        left: 14px;
        top: 50%;
        width: 18px;
        height: 18px;
        transform: translateY(-50%);
        color: var(--muted);
        pointer-events: none;
    }
    .users-table table {
        table-layout: fixed;
    }
    .users-table th:nth-child(1),
    .users-table td:nth-child(1) { width: 70px; }
    .users-table th:nth-child(7),
    .users-table td:nth-child(7) { width: 260px; }
    @media (max-width: 760px) {
        .form-grid,
        .detail-grid { grid-template-columns: 1fr; }
        .users-toolbar { grid-template-columns: 1fr; }
        .users-table table { table-layout: auto; }
    }
</style>

<div class="top">
    <div>
        <h1>Usuários</h1>
        <p>Gerencie os usuários administrativos e técnicos do sistema.</p>
    </div>
    <div class="action-row">
        <a class="btn" href="dashboard.php">Voltar</a>
        <?php if ($acao === 'listar'): ?>
            <a class="btn primary" href="usuarios.php?acao=novo">Novo Usuário</a>
        <?php endif; ?>
    </div>
</div>

<?php if (!empty($mensagens) || !empty($erros)): ?>
    <div class="messages">
        <?php foreach ($mensagens as $mensagem): ?>
            <div class="message"><?php echo e($mensagem); ?></div>
        <?php endforeach; ?>
        <?php foreach ($erros as $erro): ?>
            <div class="message error"><?php echo e($erro); ?></div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php
if ($acao === 'novo' || $acao === 'editar'):
    $id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
    $usuario = $acao === 'editar' ? usuarioPorId($pdo, $id) : null;

    if ($acao === 'editar' && !$usuario) {
        echo '<section class="panel"><div class="empty">Usuário não encontrado.</div></section>';
        adminPageEnd();
        exit;
    }

    $form = [
        'id' => $usuario['id'] ?? ($_POST['id'] ?? ''),
        'nome' => $_POST['nome'] ?? ($usuario['nome'] ?? ''),
        'login' => $_POST['login'] ?? ($usuario['usuario'] ?? ''),
        'perfil' => perfilUsuario($_POST['perfil'] ?? ($usuario['nivel'] ?? 'TECNICO')),
        'ativo' => (string) ($_POST['ativo'] ?? ($usuario['ativo'] ?? '1')),
    ];
    ?>
    <section class="panel">
        <form method="post" action="usuarios.php">
            <input type="hidden" name="acao" value="salvar">
            <input type="hidden" name="id" value="<?php echo e((string) $form['id']); ?>">
            <div class="form-grid">
                <label>
                    Nome
                    <input type="text" name="nome" value="<?php echo e((string) $form['nome']); ?>" required>
                </label>
                <label>
                    Login
                    <input type="text" name="login" value="<?php echo e((string) $form['login']); ?>" required>
                </label>
                <label>
                    Senha <?php echo $acao === 'editar' ? '(opcional)' : ''; ?>
                    <input type="password" name="senha" <?php echo $acao === 'novo' ? 'required' : ''; ?>>
                </label>
                <label>
                    Confirmar senha <?php echo $acao === 'editar' ? '(opcional)' : ''; ?>
                    <input type="password" name="confirmar_senha" <?php echo $acao === 'novo' ? 'required' : ''; ?>>
                </label>
                <label>
                    Perfil
                    <select name="perfil" required>
                        <option value="ADMIN" <?php echo $form['perfil'] === 'ADMIN' ? 'selected' : ''; ?>>ADMIN</option>
                        <option value="TECNICO" <?php echo $form['perfil'] === 'TECNICO' ? 'selected' : ''; ?>>TECNICO</option>
                    </select>
                </label>
                <label>
                    Status
                    <select name="ativo" required>
                        <option value="1" <?php echo $form['ativo'] === '1' ? 'selected' : ''; ?>>Ativo</option>
                        <option value="0" <?php echo $form['ativo'] === '0' ? 'selected' : ''; ?>>Inativo</option>
                    </select>
                </label>
            </div>
            <div class="form-actions">
                <a class="btn" href="usuarios.php">Cancelar</a>
                <button class="btn primary" type="submit">Salvar</button>
            </div>
        </form>
    </section>
<?php
elseif ($acao === 'visualizar'):
    $id = (int) ($_GET['id'] ?? 0);
    $usuario = usuarioPorId($pdo, $id);

    if (!$usuario) {
        echo '<section class="panel"><div class="empty">Usuário não encontrado.</div></section>';
        adminPageEnd();
        exit;
    }

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM movimentacoes WHERE usuario_id = :id');
    $stmt->execute([':id' => $id]);
    $totalMovimentacoes = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM entregas WHERE usuario_id = :id');
    $stmt->execute([':id' => $id]);
    $totalEntregas = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM trocas WHERE usuario_id = :id');
    $stmt->execute([':id' => $id]);
    $totalTrocas = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT *
        FROM (
            SELECT
                m.data_movimentacao AS data_evento,
                'Movimentação' AS origem,
                m.tipo AS tipo,
                COALESCE(p.nome, '-') AS equipamento,
                COALESCE(l.nome, '-') AS loja,
                NULL AS solicitante,
                NULL AS motivo
            FROM movimentacoes m
            LEFT JOIN produtos p ON p.id = m.produto_id
            LEFT JOIN lojas l ON l.id = m.loja_id
            WHERE m.usuario_id = :id_mov

            UNION ALL

            SELECT
                e.data_entrega AS data_evento,
                'Entrega' AS origem,
                'Entrega' AS tipo,
                COALESCE(p.nome, '-') AS equipamento,
                COALESCE(l.nome, '-') AS loja,
                COALESCE(f.nome, '-') AS solicitante,
                e.observacao AS motivo
            FROM entregas e
            LEFT JOIN itens i ON i.id = e.item_id
            LEFT JOIN produtos p ON p.id = i.produto_id
            LEFT JOIN lojas l ON l.id = e.loja_id
            LEFT JOIN funcionarios f ON f.id = e.funcionario_id
            WHERE e.usuario_id = :id_ent

            UNION ALL

            SELECT
                t.data_troca AS data_evento,
                'Troca' AS origem,
                'Troca' AS tipo,
                COALESCE(p.nome, '-') AS equipamento,
                COALESCE(l.nome, '-') AS loja,
                NULL AS solicitante,
                t.motivo AS motivo
            FROM trocas t
            LEFT JOIN itens i ON i.id = t.item_novo_id
            LEFT JOIN produtos p ON p.id = i.produto_id
            LEFT JOIN lojas l ON l.id = t.loja_id
            WHERE t.usuario_id = :id_tro
        ) historico
        ORDER BY data_evento DESC
        LIMIT 100
    ");
    $stmt->execute([
        ':id_mov' => $id,
        ':id_ent' => $id,
        ':id_tro' => $id,
    ]);
    $historico = $stmt->fetchAll();
    ?>
    <section class="panel">
        <div class="detail-grid">
            <div class="detail-card"><span>ID</span><strong><?php echo (int) $usuario['id']; ?></strong></div>
            <div class="detail-card"><span>Nome</span><strong><?php echo e($usuario['nome']); ?></strong></div>
            <div class="detail-card"><span>Login</span><strong><?php echo e($usuario['usuario']); ?></strong></div>
            <div class="detail-card"><span>Perfil</span><strong><?php echo e(perfilUsuario($usuario['nivel'])); ?></strong></div>
            <div class="detail-card"><span>Status</span><strong><?php echo (int) $usuario['ativo'] === 1 ? 'Ativo' : 'Inativo'; ?></strong></div>
            <div class="detail-card"><span>Data de cadastro</span><strong><?php echo e(dataBr($usuario['data_criacao'])); ?></strong></div>
        </div>
        <div class="detail-grid">
            <div class="detail-card"><span>Movimentações realizadas</span><strong><?php echo $totalMovimentacoes; ?></strong></div>
            <div class="detail-card"><span>Entregas registradas</span><strong><?php echo $totalEntregas; ?></strong></div>
            <div class="detail-card"><span>Trocas registradas</span><strong><?php echo $totalTrocas; ?></strong></div>
        </div>
    </section>

    <section class="panel">
        <h2 class="section-title">Histórico do usuário</h2>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Data</th>
                        <th>Origem</th>
                        <th>Tipo</th>
                        <th>Equipamento</th>
                        <th>Loja</th>
                        <th>Solicitante</th>
                        <th>Motivo</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($historico as $item): ?>
                        <tr>
                            <td><?php echo e(dataBr($item['data_evento'])); ?></td>
                            <td><?php echo e($item['origem']); ?></td>
                            <td><?php echo e($item['tipo']); ?></td>
                            <td><?php echo e($item['equipamento']); ?></td>
                            <td><?php echo e($item['loja']); ?></td>
                            <td><?php echo e($item['solicitante'] ?? '-'); ?></td>
                            <td><?php echo e($item['motivo'] ?? '-'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($historico)): ?>
                        <tr><td colspan="7">Nenhuma atividade encontrada para este usuário.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <div class="form-actions">
            <a class="btn" href="usuarios.php">Voltar</a>
            <a class="btn primary" href="usuarios.php?acao=editar&id=<?php echo (int) $usuario['id']; ?>">Editar</a>
        </div>
    </section>
<?php
else:
    $busca = trim((string) ($_GET['busca'] ?? ''));
    $pagina = max(1, (int) ($_GET['pagina'] ?? 1));
    $limitesPermitidos = [5, 10, 20, 30, 50];
    $porPagina = (int) ($_GET['limite'] ?? 5);
    $porPagina = in_array($porPagina, $limitesPermitidos, true) ? $porPagina : 5;
    $offset = ($pagina - 1) * $porPagina;
    $where = 'WHERE ativo = 1';
    $params = [];

    if ($busca !== '') {
        $where .= ' AND (nome LIKE :busca_nome OR usuario LIKE :busca_login OR nivel LIKE :busca_perfil)';
        $params[':busca_nome'] = '%' . $busca . '%';
        $params[':busca_login'] = '%' . $busca . '%';
        $params[':busca_perfil'] = '%' . $busca . '%';
    }

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM usuarios {$where}");
    $stmt->execute($params);
    $total = (int) $stmt->fetchColumn();
    $totalPaginas = (int) ceil($total / $porPagina);

    $stmt = $pdo->prepare("
        SELECT id, nome, usuario, nivel, ativo, data_criacao
        FROM usuarios
        {$where}
        ORDER BY id DESC
        LIMIT :limite OFFSET :offset
    ");
    foreach ($params as $chave => $valor) {
        $stmt->bindValue($chave, $valor);
    }
    $stmt->bindValue(':limite', $porPagina, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $usuarios = $stmt->fetchAll();
    ?>
    <section class="panel">
        <form class="users-toolbar" method="get" action="usuarios.php" id="usuariosBuscaForm">
            <label class="search-box" aria-label="Buscar usuários">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
                <input type="search" name="busca" value="<?php echo e($busca); ?>" placeholder="Buscar por nome, login ou perfil" autocomplete="off">
            </label>
            <label class="limit-control" aria-label="Quantidade de registros"><span class="filter-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 5h18l-7 8v5l-4 2v-7L3 5Z"/></svg></span>
                <select name="limite" onchange="this.form.submit()">
                    <?php foreach ($limitesPermitidos as $limite): ?>
                        <option value="<?php echo $limite; ?>" <?php echo $porPagina === $limite ? 'selected' : ''; ?>><?php echo $limite; ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <?php if ($busca !== ''): ?>
                <a class="btn" href="usuarios.php">Limpar</a>
            <?php endif; ?>
        </form>
    </section>

    <section class="panel users-table">
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Nome</th>
                        <th>Login</th>
                        <th>Perfil</th>
                        <th>Status</th>
                        <th>Data de cadastro</th>
                        <th>Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($usuarios as $item): ?>
                        <tr>
                            <td><?php echo (int) $item['id']; ?></td>
                            <td><?php echo e($item['nome']); ?></td>
                            <td><?php echo e($item['usuario']); ?></td>
                            <td><?php echo e(perfilUsuario($item['nivel'])); ?></td>
                            <td><?php echo badgeStatus((int) $item['ativo']); ?></td>
                            <td><?php echo e(dataBr($item['data_criacao'])); ?></td>
                            <td>
                                <div class="action-row">
                                    <a class="btn small" href="usuarios.php?acao=visualizar&id=<?php echo (int) $item['id']; ?>">Visualizar</a>
                                    <a class="btn small" href="usuarios.php?acao=editar&id=<?php echo (int) $item['id']; ?>">Editar</a>
                                    <form method="post" action="usuarios.php" onsubmit="return confirm('Deseja realmente excluir este usuário?');">
                                        <input type="hidden" name="acao" value="excluir">
                                        <input type="hidden" name="id" value="<?php echo (int) $item['id']; ?>">
                                        <button class="btn small danger" type="submit">Excluir</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($usuarios)): ?>
                        <tr><td colspan="7">Nenhum usuário encontrado.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php if ($totalPaginas > 1): ?>
            <div class="pagination">
                <?php for ($i = 1; $i <= $totalPaginas; $i++): ?>
                    <?php if ($i === $pagina): ?>
                        <span class="active"><?php echo $i; ?></span>
                    <?php else: ?>
                        <a href="<?php echo e(pageUrl(['pagina' => $i])); ?>"><?php echo $i; ?></a>
                    <?php endif; ?>
                <?php endfor; ?>
            </div>
        <?php endif; ?>
    </section>
<?php endif; ?>

<script>
    const usuariosBuscaForm = document.getElementById('usuariosBuscaForm');
    const usuariosBuscaInput = usuariosBuscaForm?.querySelector('input[name="busca"]');
    let usuariosBuscaTimer = null;
    usuariosBuscaInput?.addEventListener('input', () => {
        clearTimeout(usuariosBuscaTimer);
        usuariosBuscaTimer = setTimeout(() => usuariosBuscaForm.submit(), 450);
    });
</script>
<?php adminPageEnd(); ?>
