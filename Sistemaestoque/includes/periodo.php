<?php
/**
 * Fragmento reaproveitável do filtro de período (data inicial/final), usado
 * tanto no histórico de Movimentações do dashboard quanto nos Relatórios.
 */
function condicaoPeriodo(string $alias, string $dataDe, string $dataAte, array &$parametros, string &$tipos): array
{
    $condicoes = [];
    if ($dataDe !== '') { $condicoes[] = "DATE($alias.criado_em) >= ?"; $parametros[] = $dataDe; $tipos .= 's'; }
    if ($dataAte !== '') { $condicoes[] = "DATE($alias.criado_em) <= ?"; $parametros[] = $dataAte; $tipos .= 's'; }
    return $condicoes;
}
