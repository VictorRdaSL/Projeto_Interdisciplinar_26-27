<?php
/**
 * Limite de tentativas de login (Passo 21).
 *
 * Cada tentativa fica registrada em `login_tentativas`. Depois de LOGIN_MAX_FALHAS falhas seguidas para o mesmo e-mail,
 * OU de LOGIN_MAX_FALHAS falhas seguidas vindas do mesmo IP (qualquer e-mail), o e-mail (ou o IP) fica bloqueado por
 * LOGIN_BLOQUEIO_MINUTOS. Durante o bloqueio nem a senha certa entra, e a tela mostra uma mensagem que serve para qualquer
 * e-mail (existente ou não): ela não diz se o erro foi no e-mail ou na senha. Entrar com sucesso zera a contagem do e-mail.
 *
 * `resultado`: 'falha' (senha ou e-mail errado), 'sucesso', 'bloqueada' (tentativa feita durante um bloqueio; não conta e
 * não prolonga o bloqueio), 'bloqueio_email' / 'bloqueio_ip' (a linha que marca o início de um bloqueio).
 * `contada`: 1 enquanto a falha ainda conta para o próximo bloqueio; vira 0 quando o bloqueio é marcado ou o login acerta.
 *
 * Toda data vem do PHP (nunca do relógio do MySQL) e todo valor entra por prepared statement.
 */

const LOGIN_MAX_FALHAS = 5;
const LOGIN_BLOQUEIO_MINUTOS = 15;
const LOGIN_RETENCAO_DIAS = 30; // tentativas mais velhas que isso são apagadas na próxima falha

const MSG_LOGIN_INVALIDO = 'E-mail ou senha inválidos.';
const MSG_LOGIN_BLOQUEADO = 'Muitas tentativas sem sucesso. Aguarde 15 minutos para tentar de novo.';

/** IP de quem fez a requisição. Só confia em cabeçalho de proxy se o config.php disser ('confiar_proxy' => true). */
function ipDoCliente(): string
{
    require_once __DIR__ . '/configuracao.php';
    $config = carregarConfiguracao();
    if (is_array($config) && !empty($config['confiar_proxy'])) {
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR'] as $cabecalho) {
            $valor = trim(explode(',', (string)($_SERVER[$cabecalho] ?? ''))[0]);
            if ($valor !== '' && filter_var($valor, FILTER_VALIDATE_IP) !== false) {
                return $valor;
            }
        }
    }
    $remoto = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    return filter_var($remoto, FILTER_VALIDATE_IP) !== false ? $remoto : '0.0.0.0';
}

/** E-mail como é guardado na tentativa: minúsculo, sem espaços nas pontas e dentro do limite da coluna. */
function emailDaTentativa(string $email): string
{
    return mb_substr(mb_strtolower(trim($email), 'UTF-8'), 0, 150, 'UTF-8');
}

function inicioJanelaLogin(string $agora): string
{
    return date('Y-m-d H:i:s', strtotime($agora) - LOGIN_BLOQUEIO_MINUTOS * 60);
}

/** True se este e-mail OU este IP está em período de bloqueio. */
function loginBloqueado(mysqli $conn, string $email, string $ip, string $agora): bool
{
    $inicio = inicioJanelaLogin($agora);
    $stmt = $conn->prepare("SELECT 1 FROM login_tentativas
        WHERE criado_em >= ? AND ((resultado = 'bloqueio_email' AND email = ?) OR (resultado = 'bloqueio_ip' AND ip = ?))
        LIMIT 1");
    $stmt->bind_param('sss', $inicio, $email, $ip);
    $stmt->execute();
    return $stmt->get_result()->fetch_row() !== null;
}

function gravarTentativaLogin(mysqli $conn, ?string $email, string $ip, string $resultado, int $contada, string $agora): void
{
    $stmt = $conn->prepare('INSERT INTO login_tentativas (email, ip, resultado, contada, criado_em) VALUES (?, ?, ?, ?, ?)');
    $stmt->bind_param('sssis', $email, $ip, $resultado, $contada, $agora);
    $stmt->execute();
}

/** Tentativa feita durante um bloqueio: fica registrada, mas não conta e não estende o bloqueio. */
function registrarLoginBloqueado(mysqli $conn, string $email, string $ip, string $agora): void
{
    gravarTentativaLogin($conn, $email, $ip, 'bloqueada', 0, $agora);
}

/** Entrou: registra e zera a contagem de falhas desse e-mail ("falhas seguidas"). */
function registrarLoginSucesso(mysqli $conn, string $email, string $ip, string $agora): void
{
    $stmt = $conn->prepare("UPDATE login_tentativas SET contada = 0 WHERE resultado = 'falha' AND contada = 1 AND email = ?");
    $stmt->bind_param('s', $email);
    $stmt->execute();
    gravarTentativaLogin($conn, $email, $ip, 'sucesso', 0, $agora);
}

/**
 * Registra uma falha e, se ela fechar LOGIN_MAX_FALHAS seguidas para o e-mail ou para o IP, marca o bloqueio.
 * Devolve true se esta falha iniciou um bloqueio.
 */
function registrarLoginFalha(mysqli $conn, string $email, string $ip, string $agora): bool
{
    gravarTentativaLogin($conn, $email, $ip, 'falha', 1, $agora);
    $inicio = inicioJanelaLogin($agora);
    $bloqueou = false;

    foreach ([['email', $email, 'bloqueio_email'], ['ip', $ip, 'bloqueio_ip']] as [$coluna, $valor, $tipoBloqueio]) {
        // $coluna vem desta lista fechada (nunca de entrada do usuário), por isso entra no SQL; o valor vai por parâmetro.
        $stmt = $conn->prepare("SELECT COUNT(*) FROM login_tentativas WHERE resultado = 'falha' AND contada = 1 AND $coluna = ? AND criado_em >= ?");
        $stmt->bind_param('ss', $valor, $inicio);
        $stmt->execute();
        $total = (int)$stmt->get_result()->fetch_row()[0];
        if ($total < LOGIN_MAX_FALHAS) {
            continue;
        }
        $stmtZera = $conn->prepare("UPDATE login_tentativas SET contada = 0 WHERE resultado = 'falha' AND contada = 1 AND $coluna = ?");
        $stmtZera->bind_param('s', $valor);
        $stmtZera->execute();
        gravarTentativaLogin($conn, $email, $ip, $tipoBloqueio, 0, $agora);
        $bloqueou = true;
    }

    // Faxina: tentativas antigas não servem para mais nada.
    $limite = date('Y-m-d H:i:s', strtotime($agora) - LOGIN_RETENCAO_DIAS * 86400);
    $stmtLimpa = $conn->prepare('DELETE FROM login_tentativas WHERE criado_em < ?');
    $stmtLimpa->bind_param('s', $limite);
    $stmtLimpa->execute();
    return $bloqueou;
}

/**
 * Conferência de senha em tempo constante em relação à existência do e-mail: sem usuário, confere contra um hash
 * descartável, para a resposta não demorar menos quando o e-mail não existe.
 */
function conferirSenhaDoUsuario(?array $usuario, string $senha): bool
{
    // Hash de uma senha aleatória qualquer, que ninguém conhece: serve só para gastar o mesmo tempo do password_verify.
    static $hashFicticio = '$2y$10$lFoGfMkwQFH.EBMhw.nBneqmxBY8OuTTV9W1Ft1lSLyvLg5FiAkLO';
    return password_verify($senha, $usuario['senha_hash'] ?? $hashFicticio) && $usuario !== null;
}
