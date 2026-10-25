<?php
/**
 * Tema do usuário logado, para <html data-theme="...">.
 *
 * Os valores gravados em usuarios.tema (e em $_SESSION['usuario_tema']) são 'claro' e 'escuro', os mesmos que o
 * design system usa. Qualquer outra coisa — chave ausente, nulo, texto desconhecido — vira 'claro', sem aviso do PHP.
 */
function temaDoUsuario(): string
{
    return ($_SESSION['usuario_tema'] ?? null) === 'escuro' ? 'escuro' : 'claro';
}
