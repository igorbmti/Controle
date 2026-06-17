<?php
/**
 * Conexao PDO com o banco MySQL existente controle_big.
 * Este arquivo nao cria tabelas nem altera a estrutura do banco.
 */

declare(strict_types=1);

$dbHost = 'localhost';
$dbPort = 3306;
$dbName = 'controle_big';
$dbUser = 'root';
$dbPass = '';

$dsn = "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4";

try {
    $pdo = new PDO($dsn, $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (PDOException $e) {
    error_log('Erro de conexao PDO: ' . $e->getMessage());
    $pdo = null;
}

function getConnection(): PDO
{
    global $pdo;

    if (!$pdo instanceof PDO) {
        throw new RuntimeException('Nao foi possivel conectar ao banco de dados.');
    }

    return $pdo;
}
