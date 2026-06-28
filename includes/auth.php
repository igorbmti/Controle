<?php
/**
 * Autenticacao e protecao de rotas.
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    $sessionSecure = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
        || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;

    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.cookie_secure', $sessionSecure ? '1' : '0');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $sessionSecure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

require_once __DIR__ . '/../config/conexao.php';

const AUTH_REGENERATE_INTERVAL = 900;

function authDestroySession(): void
{
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $params['path'],
            'domain' => $params['domain'],
            'secure' => (bool) $params['secure'],
            'httponly' => (bool) $params['httponly'],
            'samesite' => $params['samesite'] ?? 'Lax',
        ]);
    }

    session_destroy();
}

function authTouchSession(): bool
{
    if (!usuarioLogado()) {
        return true;
    }

    $now = time();
    $lastRegeneration = (int) ($_SESSION['auth_last_regeneration'] ?? 0);
    if ($lastRegeneration === 0 || ($now - $lastRegeneration) >= AUTH_REGENERATE_INTERVAL) {
        session_regenerate_id(true);
        $_SESSION['auth_last_regeneration'] = $now;
    }

    return true;
}

function csrfToken(): string
{
    if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function csrfInput(): string
{
    return '<input type="hidden" name="_csrf" value="'
        . htmlspecialchars(csrfToken(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
        . '">';
}

function csrfRegenerate(): void
{
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function csrfIsValid(mixed $token): bool
{
    return is_string($token)
        && $token !== ''
        && isset($_SESSION['csrf_token'])
        && is_string($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $token);
}

function csrfRequireValid(): void
{
    if (!csrfIsValid($_POST['_csrf'] ?? null)) {
        http_response_code(403);
        exit('N?o foi poss?vel validar esta solicita??o. Atualize a p?gina e tente novamente.');
    }
}

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
    csrfRegenerate();

    $_SESSION['id_usuario'] = (int) $user['id_usuario'];
    $_SESSION['nome'] = $user['nome'];
    $_SESSION['perfil'] = $perfil;
    $_SESSION['auth_last_regeneration'] = time();

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
    if (!usuarioLogado() || !authTouchSession()) {
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
    authDestroySession();
    header('Location: index.php');
    exit;
}
