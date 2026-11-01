<?php
// Ponto único de início de sessão, usado por toda página do sistema (inclusive
// login.php) — garante que os atributos do cookie de sessão
// sejam sempre os mesmos, em vez de cada arquivo chamar session_start() direto.

// Sessão sem uso por este tempo expira: o usuário volta ao login (Passo 21).
const SESSAO_INATIVIDADE_SEGUNDOS = 1800; // 30 minutos

if (session_status() === PHP_SESSION_NONE) {
    require_once __DIR__ . '/configuracao.php';
    $config = carregarConfiguracao();
    $producao = is_array($config) && ($config['ambiente'] ?? '') === 'producao';
    // Atrás do proxy da hospedagem o HTTPS pode chegar como cabeçalho; só vale quando o config.php diz que o proxy é confiável.
    $proxyConfiavel = is_array($config) && !empty($config['confiar_proxy']);
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ($_SERVER['SERVER_PORT'] ?? '') === '443'
        || ($proxyConfiavel && strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https');

    // Em produção nenhum erro vai para a tela, nem os que acontecem antes de config/database.php ser carregado
    // (este arquivo é o primeiro a rodar em toda página). O registro no log continua sendo feito pelo database.php.
    if ($producao) {
        ini_set('display_errors', '0');
    }

    // O PHP só aceita id de sessão que ele mesmo criou (impede "fixar" um id de sessão escolhido por outra pessoa) e
    // só lê o id pelo cookie (nunca pela URL).
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    // O coletor de lixo do servidor também descarta sessões velhas (com folga sobre o prazo de inatividade).
    ini_set('session.gc_maxlifetime', (string)(SESSAO_INATIVIDADE_SEGUNDOS * 2));

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => $producao && $https,
    ]);
    session_start();

    // Expiração por inatividade: cada requisição de quem está logado renova o relógio; passou do prazo, a sessão é
    // apagada aqui mesmo e auth_check.php manda para o login com o aviso (SESSAO_EXPIROU).
    if (!empty($_SESSION['usuario_id'])) {
        $agoraSessao = time();
        if (isset($_SESSION['ultimo_acesso']) && $agoraSessao - (int)$_SESSION['ultimo_acesso'] > SESSAO_INATIVIDADE_SEGUNDOS) {
            $_SESSION = [];
            session_regenerate_id(true);
            define('SESSAO_EXPIROU', true);
        } else {
            $_SESSION['ultimo_acesso'] = $agoraSessao;
        }
    }
}
