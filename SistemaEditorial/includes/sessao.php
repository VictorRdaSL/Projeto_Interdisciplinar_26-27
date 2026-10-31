<?php
// Ponto único de início de sessão, usado por toda página do sistema (inclusive
// login.php) — garante que os atributos do cookie de sessão
// sejam sempre os mesmos, em vez de cada arquivo chamar session_start() direto.
if (session_status() === PHP_SESSION_NONE) {
    $config = @include __DIR__ . '/../config/config.php';
    $producao = is_array($config) && ($config['ambiente'] ?? '') === 'producao';
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ($_SERVER['SERVER_PORT'] ?? '') === '443';

    // Em produção nenhum erro vai para a tela, nem os que acontecem antes de config/database.php ser carregado
    // (este arquivo é o primeiro a rodar em toda página). O registro em logs/app.log continua sendo feito pelo database.php.
    if ($producao) {
        ini_set('display_errors', '0');
    }

    // O PHP só aceita id de sessão que ele mesmo criou (impede "fixar" um id de sessão escolhido por outra pessoa) e
    // só lê o id pelo cookie (nunca pela URL).
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => $producao && $https,
    ]);
    session_start();
}
