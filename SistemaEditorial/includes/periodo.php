<?php
/**
 * Fragmento reaproveitável do filtro de período (data inicial/final), usado
 * nos relatórios editoriais.
 *
 * Nome de coluna e alias não podem ser parâmetros de prepared statement, então
 * são validados aqui (whitelist de colunas + formato do alias) antes de entrar
 * no SQL — nunca interpolados crus.
 */
const COLUNAS_PERIODO_PERMITIDAS = ['criado_em', 'data_entrada', 'data_saida', 'data_abertura', 'data_conclusao'];

function condicaoPeriodo(string $alias, string $dataDe, string $dataAte, array &$parametros, string &$tipos, string $coluna = 'criado_em'): array
{
    if (!in_array($coluna, COLUNAS_PERIODO_PERMITIDAS, true)) {
        throw new InvalidArgumentException('Coluna de período não permitida.');
    }
    if (!preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $alias)) {
        throw new InvalidArgumentException('Alias de período inválido.');
    }
    $condicoes = [];
    if ($dataDe !== '') { $condicoes[] = "DATE($alias.$coluna) >= ?"; $parametros[] = $dataDe; $tipos .= 's'; }
    if ($dataAte !== '') { $condicoes[] = "DATE($alias.$coluna) <= ?"; $parametros[] = $dataAte; $tipos .= 's'; }
    return $condicoes;
}
