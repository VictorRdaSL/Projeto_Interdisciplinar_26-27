<?php
require_once __DIR__ . '/sessao.php';

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token()) . '">';
}

function csrf_validar(mixed $token): bool
{
    return is_string($token) && !empty($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

// Envio único: protege formulários que CRIAM registro contra envio duplicado (duplo clique, reenvio
// pelo botão "voltar"). Cada exibição do formulário gera um código guardado na sessão; o primeiro
// envio com aquele código o consome, e qualquer outro envio com o mesmo código é recusado.
// Livros não podem ser excluídos (só cancelados), então um duplicado ficaria para sempre.
const ENVIOS_UNICOS_MAX = 100;
const MSG_ENVIO_DUPLICADO = 'Este formulário já foi enviado (ou a página ficou aberta por muito tempo). Confira a lista antes de cadastrar de novo.';

function envio_unico_field(): string
{
    if (!isset($_SESSION['envios_unicos']) || !is_array($_SESSION['envios_unicos'])) {
        $_SESSION['envios_unicos'] = [];
    }
    $codigo = bin2hex(random_bytes(16));
    $_SESSION['envios_unicos'][$codigo] = time();
    while (count($_SESSION['envios_unicos']) > ENVIOS_UNICOS_MAX) {
        array_shift($_SESSION['envios_unicos']); // descarta o mais antigo
    }
    return '<input type="hidden" name="envio_id" value="' . $codigo . '">';
}

/** true na primeira vez que o código é apresentado; false se já foi usado, expirou ou não existe. */
function envio_unico_consumir(mixed $codigo): bool
{
    if (!is_string($codigo) || !isset($_SESSION['envios_unicos'][$codigo])) {
        return false;
    }
    unset($_SESSION['envios_unicos'][$codigo]);
    return true;
}
