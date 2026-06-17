<?php
/**
 * Arquivo: gerar_hash.php
 * Descrição: Script utilitário para gerar hash bcrypt para senhas.
 *
 * Como usar:
 * 1. Acesse http://localhost/Controle/config/gerar_hash.php
 * 2. Copie o hash gerado e atualize o usuário admin no banco.
 *
 * OU execute via linha de comando:
 *   php gerar_hash.php admin123
 */

$senha = $argv[1] ?? ($_GET['senha'] ?? 'admin123');
$hash = password_hash($senha, PASSWORD_DEFAULT);
echo "Senha: " . $senha . PHP_EOL;
echo "Hash:  " . $hash . PHP_EOL;
echo PHP_EOL;
echo "SQL para atualizar:" . PHP_EOL;
echo "UPDATE usuarios SET senha = '$hash' WHERE usuario = 'admin';" . PHP_EOL;
?>
