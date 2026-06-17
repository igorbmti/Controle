<?php
/**
 * Arquivo: index.php
 * Descrição: Tela de login do sistema Controle Big TI.
 */

require_once 'includes/auth.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $usuario = trim($_POST['usuario'] ?? '');
    $senha = trim($_POST['senha'] ?? '');

    $destino = login($usuario, $senha);

    if ($destino) {
        header("Location: {$destino}");
        exit;
    } else {
        $erro = authGetError();
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Controle Big TI</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <div class="login-container">
        <div class="login-box">
            <div class="logo-area">
                <img src="assets/img/logo.png" alt="Logo" class="logo" onerror="this.style.display='none'">
                <h1>Controle Big</h1>
            </div>
            <p class="subtitulo">Setor de Tecnologia da Informação</p>

            <?php if (isset($erro)): ?>
                <div class="mensagem mensagem-erro"><?php echo htmlspecialchars($erro, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>

            <form method="POST" class="form" autocomplete="off">
                <div class="grupo">
                    <label for="usuario">Usuário</label>
                    <input type="text" id="usuario" name="usuario" required autofocus>
                </div>
                <div class="grupo">
                    <label for="senha">Senha</label>
                    <input type="password" id="senha" name="senha" required>
                </div>
                <button type="submit" class="btn">Entrar</button>
            </form>
        </div>
    </div>
    <script>
        const mensagem = document.querySelector('.mensagem');
        if (mensagem) {
            setTimeout(() => {
                mensagem.classList.add('ocultando');
                setTimeout(() => mensagem.remove(), 320);
            }, 4200);
        }
    </script>
</body>
</html>
