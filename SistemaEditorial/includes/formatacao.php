<?php
/**
 * Formatação de datas para a tela. As datas do banco são DATETIME gravados pelo PHP já em horário de
 * Brasília (fuso fixado em config/database.php), então basta formatar — nada de converter fuso aqui.
 */

/** 'Y-m-d H:i:s' (ou null) -> 'dd/mm/aaaa hh:mm'; devolve o $vazio quando não há data. */
function formatarDataHora(?string $dataHora, string $vazio = '—'): string
{
    if ($dataHora === null || $dataHora === '') {
        return $vazio;
    }
    $momento = strtotime($dataHora);
    return $momento === false ? $vazio : date('d/m/Y H:i', $momento);
}

/** Escapa texto para HTML (sempre com aspas e UTF-8). Toda saída dinâmica das telas novas passa por aqui. */
function escaparHtml(?string $texto): string
{
    return htmlspecialchars($texto ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Código do livro com 4 dígitos ("0112"), a partir do id. Passa de 4 dígitos só se o id passar de 9999. */
function codigoLivro(int $id): string
{
    return str_pad((string)$id, 4, '0', STR_PAD_LEFT);
}

/** "1 livro" / "3 livros". */
function qtdTexto(int $n, string $singular, string $plural): string
{
    return $n . ' ' . ($n === 1 ? $singular : $plural);
}

/** Rodada no formato do design system: "R3/3" (etapa com limite de rodadas) ou "R2" (sem limite). */
function rotuloRodada(int $rodada, $limite): string
{
    return 'R' . $rodada . ($limite !== null ? '/' . (int)$limite : '');
}
