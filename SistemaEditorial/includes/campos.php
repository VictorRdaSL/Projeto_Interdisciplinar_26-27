<?php
// Erro de campo desenhado junto do campo (Passo 20.6). O mesmo padrão do formulário de livro:
// .ws-campo--erro no wrapper, aria-invalid no campo e <p class="ws-campo__erro" id="{id}-erro"> ligado por aria-describedby.
require_once __DIR__ . '/formatacao.php';

/** Classe extra do wrapper .ws-campo quando o campo tem erro. */
function classeCampoErro(?string $erro): string
{
    return $erro !== null && $erro !== '' ? ' ws-campo--erro' : '';
}

/** aria-invalid e aria-describedby do campo `$id`: o erro (se houver) vem antes da ajuda. */
function atributosCampo(string $id, ?string $erro, string $ajudaId = ''): string
{
    $temErro = $erro !== null && $erro !== '';
    $ids = [];
    if ($temErro) $ids[] = $id . '-erro';
    if ($ajudaId !== '') $ids[] = $ajudaId;
    return ($temErro ? ' aria-invalid="true"' : '')
        . ($ids ? ' aria-describedby="' . escaparHtml(implode(' ', $ids)) . '"' : '');
}

/** O parágrafo de erro do campo `$id` (vazio quando não há erro). */
function htmlErroCampo(string $id, ?string $erro): string
{
    if ($erro === null || $erro === '') return '';
    return '<p class="ws-campo__erro" id="' . escaparHtml($id) . '-erro"><i data-lucide="circle-alert"></i>' . escaparHtml($erro) . '</p>';
}