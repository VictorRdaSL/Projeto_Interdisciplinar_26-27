<?php
require_once __DIR__ . '/includes/sessao.php';

$_SESSION = [];
// Apaga também o cookie da sessão no navegador, não só os dados no servidor.
if (ini_get('session.use_cookies')) {
    $cookie = session_get_cookie_params();
    setcookie(session_name(), '', [
        'expires' => time() - 42000,
        'path' => $cookie['path'],
        'secure' => $cookie['secure'],
        'httponly' => $cookie['httponly'],
        'samesite' => $cookie['samesite'] ?: 'Lax',
    ]);
}
session_destroy();

header('Location: login.php');
exit;
