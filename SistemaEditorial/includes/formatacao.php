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

/**
 * "Etapa atual" de um livro para listas e CSV (Passo 20.11). Livro concluído ou cancelado não está em etapa nenhuma:
 * a coluna mostra o status. No banco etapa_atual_id continua apontando para a última etapa (é o histórico), então
 * só a EXIBIÇÃO muda.
 */
function textoEtapaAtual(string $status, ?string $etapaNome): string
{
    if ($status === 'concluido') return 'Concluído';
    if ($status === 'cancelado') return 'Cancelado';
    return ($etapaNome !== null && $etapaNome !== '') ? $etapaNome : '—';
}

/**
 * "Mesa" de um livro para listas e CSV: a última mesa, marcada como "(concluído)" ou "(cancelado)" quando o livro já
 * terminou (a pessoa com quem ele ficou no fim, não uma mesa de hoje). Sem mesa gravada: "—".
 */
function textoMesaAtual(string $status, ?string $mesaNome): string
{
    if ($mesaNome === null || $mesaNome === '') return '—';
    if ($status === 'concluido') return $mesaNome . ' (concluído)';
    if ($status === 'cancelado') return $mesaNome . ' (cancelado)';
    return $mesaNome;
}

/** O mesmo, em HTML: concluído e cancelado como etiqueta neutra (sem cor de status), o resto como texto escapado. */
function htmlEtapaAtual(string $status, ?string $etapaNome): string
{
    $texto = escaparHtml(textoEtapaAtual($status, $etapaNome));
    return ($status === 'concluido' || $status === 'cancelado') ? '<span class="ws-tag ws-tag--neutra">' . $texto . '</span>' : $texto;
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

/**
 * Contador de rodadas do design system: um bloco por rodada de 1 até limite+1 (o último é "R4+"). O PHP marca cada
 * bloco (.is-atual, .is-limite, .is-cobrada, .is-futura) porque o CSS só calcula as cores pelos data-* até limite 6
 * e rodada 12. Com $atual = null mostra só a regra da etapa (sem rodada atual): limite em amarelo, acima dele cobrado.
 */
function htmlContadorRodadas(string $nomeEtapa, int $limite, ?int $atual, string $legenda = ''): string
{
    $limite = max(1, $limite);
    if ($atual === null) {
        $aria = $nomeEtapa . ': ' . $limite . ' rodadas incluídas; a rodada ' . $limite . ' acende o alerta e a ' . ($limite + 1) . ' é cobrada';
    } else {
        $atual = max(0, $atual);
        $situacao = $atual === 0 ? 'ainda sem rodada' : ($atual > $limite ? 'acima do limite, cobrada' : ($atual === $limite ? 'no limite' : 'no prazo'));
        $aria = $nomeEtapa . ': rodada ' . $atual . ' de ' . $limite . ' incluídas, ' . $situacao;
    }
    $blocos = '';
    for ($n = 1; $n <= $limite + 1; $n++) {
        $classes = ['ws-rodada'];
        if ($atual !== null && $n === min($atual, $limite + 1)) $classes[] = 'is-atual';
        if ($n === $limite) $classes[] = 'is-limite';
        if ($n > $limite) $classes[] = 'is-cobrada';
        if ($atual !== null && $n > $atual) $classes[] = 'is-futura';
        $blocos .= '<li class="' . implode(' ', $classes) . '">R' . $n . ($n > $limite ? '+' : '') . '</li>';
    }
    return '<div class="ws-rodadas" data-limite="' . $limite . '" data-atual="' . ($atual === null ? 'regra' : $atual) . '" role="group" aria-label="' . escaparHtml($aria) . '">'
        . '<span class="ws-rodadas__etapa">' . escaparHtml($nomeEtapa) . '</span>'
        . '<ol class="ws-rodadas__blocos">' . $blocos . '</ol>'
        . ($legenda !== '' ? '<p class="ws-rodadas__legenda">' . escaparHtml($legenda) . '</p>' : '')
        . '</div>';
}

/** Iniciais para o avatar: 1ª letra do primeiro e do último nome; nome único = 2 primeiras letras; vazio = "?". */
function iniciaisDoNome(string $nome): string
{
    $partes = preg_split('/\s+/u', trim($nome), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    if (count($partes) >= 2) {
        $iniciais = mb_substr($partes[0], 0, 1, 'UTF-8') . mb_substr($partes[count($partes) - 1], 0, 1, 'UTF-8');
    } elseif (count($partes) === 1) {
        $iniciais = mb_substr($partes[0], 0, 2, 'UTF-8');
    } else {
        return '?';
    }
    return mb_strtoupper($iniciais, 'UTF-8');
}
