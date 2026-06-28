<?php
$target = $argv[1] ?? '';
$perfil = $argv[2] ?? 'ADMIN';

$_GET = [];
$_POST = [];
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTP_X_REQUESTED_WITH'] = '';

session_start();
$_SESSION['id_usuario'] = 1;
$_SESSION['nome'] = 'Teste';
$_SESSION['perfil'] = $perfil;
$_SESSION['auth_last_activity'] = time();
$_SESSION['auth_last_regeneration'] = time();

ob_start();
require __DIR__ . '/' . $target;
ob_end_clean();

echo $target . "-ok\n";
