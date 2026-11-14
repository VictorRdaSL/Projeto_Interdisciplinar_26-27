<?php
/**
 * Paginação das listas com filtro (log de alterações e relatórios): 10 registros por página, com os filtros mantidos
 * na URL ao trocar de página. Só desenha a navegação e calcula o OFFSET: a consulta (COUNT + LIMIT/OFFSET, sempre por
 * prepared statement) fica em cada tela. A exportação CSV não usa nada daqui: continua levando tudo que o filtro retorna.
 *
 * Uso:
 *   $pg = dadosPaginacao($total, paginaSolicitada());          // pagina, paginas, offset, limite, total
 *   ... LIMIT ? OFFSET ?   com $pg['limite'] e $pg['offset']
 *   echo htmlPaginacao($pg, 'relatorio_livros.php', ['filtro_status' => $filtroStatus]);
 */
require_once __DIR__ . '/formatacao.php';
require_once __DIR__ . '/validacao.php';

const ITENS_POR_PAGINA = 10;

/** Número da página pedido na URL (?pagina=N): inteiro a partir de 1; qualquer outra coisa vira 1. */
function paginaSolicitada(string $chave = 'pagina'): int
{
    $texto = entradaTexto($_GET, $chave);
    return inteiroPositivo($texto, 1000000) ?? 1;
}

/**
 * Página pedida ajustada ao total (página além da última cai na última; sem registros, página 1).
 * Devolve ['pagina', 'paginas', 'offset', 'limite', 'total'].
 */
function dadosPaginacao(int $total, int $pagina, int $porPagina = ITENS_POR_PAGINA): array
{
    $paginas = max(1, (int)ceil($total / $porPagina));
    $pagina = min(max(1, $pagina), $paginas);
    return ['pagina' => $pagina, 'paginas' => $paginas, 'offset' => ($pagina - 1) * $porPagina, 'limite' => $porPagina, 'total' => $total];
}

/** "Mostrando 11 a 20 de 57" (vazio quando não há registros). */
function textoIntervaloPaginacao(array $pg): string
{
    if ($pg['total'] === 0) {
        return '';
    }
    $de = $pg['offset'] + 1;
    $ate = min($pg['offset'] + $pg['limite'], $pg['total']);
    return 'Mostrando ' . $de . ' a ' . $ate . ' de ' . $pg['total'];
}

/** Números a exibir: primeira, última e as vizinhas da atual; null = reticências. Ex.: [1, null, 4, 5, 6, null, 20]. */
function numerosPaginacao(int $pagina, int $paginas): array
{
    $conjunto = array_unique(array_filter([1, $pagina - 1, $pagina, $pagina + 1, $paginas], fn($n) => $n >= 1 && $n <= $paginas));
    sort($conjunto);
    $saida = [];
    $anterior = 0;
    foreach ($conjunto as $n) {
        if ($anterior && $n - $anterior > 1) {
            $saida[] = $n - $anterior === 2 ? $anterior + 1 : null; // um só número no meio aparece em vez de "…"
        }
        $saida[] = $n;
        $anterior = $n;
    }
    return $saida;
}

/**
 * Navegação Anterior / números / Próxima (.ws-paginacao). $filtros são os filtros ativos (vazios são omitidos);
 * $ancora leva de volta à seção (ex.: 'log'). Com uma página só, não mostra nada.
 */
function htmlPaginacao(array $pg, string $arquivo, array $filtros = [], string $chave = 'pagina', string $ancora = ''): string
{
    if ($pg['paginas'] <= 1) {
        return '';
    }
    $filtros = array_filter($filtros, fn($v) => $v !== '' && $v !== 0 && $v !== null);
    $link = function (int $n) use ($arquivo, $filtros, $chave, $ancora): string {
        $q = $filtros + ($n > 1 ? [$chave => $n] : []);
        return escaparHtml($arquivo . ($q ? '?' . http_build_query($q) : '') . ($ancora !== '' ? '#' . $ancora : ''));
    };

    $h = '<nav class="ws-paginacao" aria-label="Paginação">';
    if ($pg['pagina'] > 1) {
        $h .= '<a class="ws-paginacao__item" href="' . $link($pg['pagina'] - 1) . '" rel="prev">Anterior</a>';
    } else {
        $h .= '<span class="ws-paginacao__item" aria-disabled="true">Anterior</span>';
    }
    foreach (numerosPaginacao($pg['pagina'], $pg['paginas']) as $n) {
        if ($n === null) {
            $h .= '<span class="ws-paginacao__reticencias" aria-hidden="true">…</span>';
        } elseif ($n === $pg['pagina']) {
            $h .= '<a class="ws-paginacao__item" href="' . $link($n) . '" aria-current="page" aria-label="Página ' . $n . ', atual">' . $n . '</a>';
        } else {
            $h .= '<a class="ws-paginacao__item" href="' . $link($n) . '" aria-label="Página ' . $n . '">' . $n . '</a>';
        }
    }
    if ($pg['pagina'] < $pg['paginas']) {
        $h .= '<a class="ws-paginacao__item" href="' . $link($pg['pagina'] + 1) . '" rel="next">Próxima</a>';
    } else {
        $h .= '<span class="ws-paginacao__item" aria-disabled="true">Próxima</span>';
    }
    return $h . '</nav>';
}

/** Rodapé da lista: intervalo ("Mostrando 11 a 20 de 57") à esquerda e a navegação à direita. */
function htmlRodapePaginacao(array $pg, string $arquivo, array $filtros = [], string $chave = 'pagina', string $ancora = ''): string
{
    if ($pg['total'] === 0) {
        return '';
    }
    return '<div class="ws-tabela-rodape"><span>' . escaparHtml(textoIntervaloPaginacao($pg)) . '</span>' . htmlPaginacao($pg, $arquivo, $filtros, $chave, $ancora) . '</div>';
}
