<?php
/**
 * Autenticacao e protecao de rotas.
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/conexao.php';

function authColumnExists(PDO $pdo, string $column): bool
{
    static $columns = null;

    if ($columns === null) {
        $columns = [];
        $stmt = $pdo->query('SHOW COLUMNS FROM usuarios');
        foreach ($stmt->fetchAll() as $row) {
            $columns[strtolower((string) $row['Field'])] = true;
        }
    }

    return isset($columns[strtolower($column)]);
}

function authSetError(string $message): void
{
    $GLOBALS['login_error_message'] = $message;
}

function authGetError(): string
{
    return (string) ($GLOBALS['login_error_message'] ?? 'Usuário ou senha inválidos.');
}

function normalizePerfil(string $perfil): string
{
    $perfil = strtoupper(trim($perfil));

    $perfisAdmin = ['ADMIN', 'ADMINISTRADOR'];
    $perfisTecnicos = ['OPERADOR', 'TECNICO', 'TÉCNICO', 'USUARIO', 'USUÁRIO', 'USER'];

    if (in_array($perfil, $perfisAdmin, true)) {
        return 'ADMIN';
    }

    if (in_array($perfil, $perfisTecnicos, true)) {
        return 'TECNICO';
    }

    return $perfil;
}

function login(string $usuario, string $senha): string|false
{
    try {
        $pdo = getConnection();
        $idColumn = authColumnExists($pdo, 'id_usuario') ? 'id_usuario' : 'id';
        $loginColumn = authColumnExists($pdo, 'login') ? 'login' : 'usuario';
        $perfilColumn = authColumnExists($pdo, 'perfil') ? 'perfil' : 'nivel';

        $sql = "
            SELECT
                {$idColumn} AS id_usuario,
                nome,
                {$loginColumn} AS login,
                senha,
                UPPER(COALESCE({$perfilColumn}, '')) AS perfil,
                ativo
            FROM usuarios
            WHERE LOWER({$loginColumn}) = LOWER(:usuario)
              AND ativo = 1
            LIMIT 1
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':usuario', $usuario, PDO::PARAM_STR);
        $stmt->execute();
        $user = $stmt->fetch();

        if (!$user) {
            $inactiveSql = "
                SELECT COUNT(*)
                FROM usuarios
                WHERE LOWER({$loginColumn}) = LOWER(:usuario)
                  AND ativo <> 1
            ";
            $inactiveStmt = $pdo->prepare($inactiveSql);
            $inactiveStmt->bindValue(':usuario', $usuario, PDO::PARAM_STR);
            $inactiveStmt->execute();

            if ((int) $inactiveStmt->fetchColumn() > 0) {
                authSetError('Usuário desativado. Entre em contato com o administrador.');
            } else {
                authSetError('Usuário ou senha inválidos.');
            }

            return false;
        }
    } catch (Throwable $e) {
        error_log('Erro ao autenticar: ' . $e->getMessage());
        authSetError('Usuário ou senha inválidos.');
        return false;
    }

    $senhaArmazenada = (string) $user['senha'];
    $hashInfo = password_get_info($senhaArmazenada);
    $hashValido = $hashInfo['algo'] !== 0 && password_verify($senha, $senhaArmazenada);
    $textoPuroValido = $hashInfo['algo'] === 0 && hash_equals($senhaArmazenada, $senha);

    if (!$hashValido && !$textoPuroValido) {
        authSetError('Usuário ou senha inválidos.');
        return false;
    }

    try {
        if ($textoPuroValido || password_needs_rehash($senhaArmazenada, PASSWORD_DEFAULT)) {
            $novoHash = password_hash($senha, PASSWORD_DEFAULT);
            $stmt = getConnection()->prepare("UPDATE usuarios SET senha = :senha WHERE {$idColumn} = :id");
            $stmt->execute([
                ':senha' => $novoHash,
                ':id' => (int) $user['id_usuario'],
            ]);
        }
    } catch (Throwable $e) {
        error_log('Erro ao migrar senha: ' . $e->getMessage());
    }

    $perfil = normalizePerfil((string) $user['perfil']);

    session_regenerate_id(true);

    $_SESSION['id_usuario'] = (int) $user['id_usuario'];
    $_SESSION['nome'] = $user['nome'];
    $_SESSION['perfil'] = $perfil;

    if ($perfil === 'ADMIN') {
        return 'admin/dashboard.php';
    }

    if ($perfil === 'TECNICO') {
        return 'tecnico/dashboard.php';
    }

    session_unset();
    session_destroy();
    authSetError('Usuário ou senha inválidos.');
    return false;
}

function usuarioLogado(): bool
{
    return isset($_SESSION['id_usuario'], $_SESSION['nome'], $_SESSION['perfil']);
}

function verificarLogin(?string $perfilObrigatorio = null): void
{
    if (!usuarioLogado()) {
        header('Location: ../index.php');
        exit;
    }

    if ($perfilObrigatorio !== null && $_SESSION['perfil'] !== strtoupper($perfilObrigatorio)) {
        header('Location: ../index.php');
        exit;
    }
}

function logout(): void
{
    session_unset();
    session_destroy();
    header('Location: index.php');
    exit;
}
