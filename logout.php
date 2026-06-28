<?php
/**
 * Arquivo: logout.php
 * Descrição: Encerra a sessão do usuário.
 */

require_once 'includes/auth.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('M?todo n?o permitido.');
}

csrfRequireValid();
logout();
