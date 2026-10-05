<?php
// Ponto único de início de sessão, usado por toda página do sistema (inclusive
// login.php e register.php) — garante que os atributos do cookie de sessão
// sejam sempre os mesmos, em vez de cada arquivo chamar session_start() direto.
if (session_status() === PHP_SESSION_NONE) {
    $config = @include __DIR__ . '/../config/config.php';
    $producao = is_array($config) && ($config['ambiente'] ?? '') === 'producao';
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ($_SERVER['SERVER_PORT'] ?? '') === '443';

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => $producao && $https,
    ]);
    session_start();
}
