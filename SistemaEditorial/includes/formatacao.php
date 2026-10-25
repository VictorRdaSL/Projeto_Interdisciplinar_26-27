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
