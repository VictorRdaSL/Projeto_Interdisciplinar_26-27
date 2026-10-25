<?php
/**
 * Moldura HTML (cabeçalho do app, topo, rodapé) das páginas de relatório, no design system. Cada relatorio_*.php chama
 * relatorioAbrir() antes do conteúdo e relatorioFechar() depois. Nada pode ser impresso antes de um exportarCsv().
 *
 * O flash da sessão é lido e apagado pelo includes/rodape.php (toast), então as páginas não o exibem por conta própria.
 * Todas as funções aqui só desenham HTML: nenhuma consulta, nenhum filtro e nenhum dado de exportação passam por elas.
 */
require_once __DIR__ . '/formatacao.php';

/**
 * Abre a página: cabeçalho do app (menu), topo e o <div class="ws-conteudo">.
 * Índice dos relatórios ($indice = true): H1 = $titulo e subtítulo = $subtitulo.
 * Relatório: link de volta com o texto $subtitulo (hoje "Relatórios") e H1 = $titulo.
 */
function relatorioAbrir(string $titulo, string $subtitulo, bool $indice = false): void
{
    $tituloPagina = $titulo;
    $paginaAtual = 'relatorios';
    require __DIR__ . '/cabecalho.php';
    ?>
      <header class="ws-topo">
        <div class="ws-topo__titulos">
<?php if (!$indice): ?>
          <a class="ws-topo__voltar ws-link-limpo" href="relatorios.php"><i data-lucide="arrow-left" class="ws-icone--peq"></i><?= escaparHtml($subtitulo) ?></a>
<?php endif; ?>
          <h1 class="ws-topo__titulo"><?= escaparHtml($titulo) ?></h1>
<?php if ($indice): ?>
          <p class="ws-topo__subtitulo"><?= escaparHtml($subtitulo) ?></p>
<?php endif; ?>
        </div>
      </header>

      <div class="ws-conteudo">
<?php
}

/** Fecha o conteúdo e a página (rodapé do app, toasts, scripts). */
function relatorioFechar(): void
{
    ?>
      </div>
<?php
    require __DIR__ . '/rodape.php';
}

/** Campos De/Até do filtro de período, para dentro de um <form class="ws-filtros" method="get">. */
function relatorioCamposPeriodo(string $de, string $ate): void
{
    ?>
            <div class="ws-campo">
              <label class="ws-campo__rotulo" for="filtro_de">De</label>
              <input class="ws-input" type="date" id="filtro_de" name="filtro_de" value="<?= escaparHtml($de) ?>">
            </div>
            <div class="ws-campo">
              <label class="ws-campo__rotulo" for="filtro_ate">Até</label>
              <input class="ws-input" type="date" id="filtro_ate" name="filtro_ate" value="<?= escaparHtml($ate) ?>">
            </div>
<?php
}

/** Botões Filtrar / Limpar, para dentro do <form class="ws-filtros">. */
function relatorioBotoes(string $arquivo): void
{
    ?>
            <div class="ws-filtros__acoes">
              <button class="ws-btn ws-btn--primario" type="submit">Filtrar</button>
              <a class="ws-btn ws-btn--link" href="<?= escaparHtml($arquivo) ?>">Limpar</a>
            </div>
<?php
}

/**
 * Botões de exportação CSV (o CSV leva os mesmos filtros da tela). Ficam fora do formulário de filtros, acima da
 * tabela. Sem $extrasCsv: um botão "Exportar CSV" (export=csv); com $extrasCsv: um botão por [rótulo => valor de export].
 * Com erro de período não há exportação, então nada é mostrado.
 */
function relatorioExportar(string $arquivo, string $queryFiltros, bool $haErro = false, array $extrasCsv = []): void
{
    if ($haErro) {
        return;
    }
    $botoes = $extrasCsv ?: ['Exportar CSV' => 'csv'];
    ?>
          <div class="ws-btn-grupo">
<?php foreach ($botoes as $rotulo => $valor): ?>
            <a class="ws-btn ws-btn--secundario" href="<?= escaparHtml($arquivo . '?' . ($queryFiltros !== '' ? $queryFiltros . '&' : '') . 'export=' . $valor) ?>"><i data-lucide="download"></i><?= escaparHtml($rotulo) ?></a>
<?php endforeach; ?>
          </div>
<?php
}

/** Erro de período (data inválida, De maior que Até): alerta no lugar do resultado; nada é exportado. */
function relatorioErroPeriodo(string $mensagem): void
{
    ?>
        <div class="ws-alerta ws-alerta--cobrado" role="alert">
          <i class="ws-alerta__icone" data-lucide="circle-alert"></i>
          <div class="ws-alerta__corpo">
            <p class="ws-alerta__titulo"><?= escaparHtml($mensagem) ?></p>
          </div>
        </div>
<?php
}

/** Estado vazio de um relatório. */
function relatorioVazio(string $titulo, string $texto = ''): void
{
    ?>
          <div class="ws-vazio">
            <i class="ws-vazio__icone" data-lucide="file-text"></i>
            <h3 class="ws-h2"><?= escaparHtml($titulo) ?></h3>
<?php if ($texto !== ''): ?>
            <p class="ws-vazio__texto"><?= escaparHtml($texto) ?></p>
<?php endif; ?>
          </div>
<?php
}

/** Chip de status (ponto + texto) de livro a partir do rótulo já traduzido; concluído e cancelado são etiquetas neutras. */
function htmlStatusLivroRelatorio(string $rotulo): string
{
    $r = escaparHtml($rotulo);
    if ($rotulo === ROTULOS_STATUS_LIVRO['extraviado']) {
        return '<span class="ws-status ws-status--extraviado">' . $r . '</span>';
    }
    if ($rotulo === ROTULOS_STATUS_LIVRO['concluido'] || $rotulo === ROTULOS_STATUS_LIVRO['cancelado']) {
        return '<span class="ws-tag ws-tag--neutra">' . $r . '</span>';
    }
    return $r;
}

/** Chip de rodada cobrada (excedente). */
function htmlChipCobrado(): string
{
    return '<span class="ws-status ws-status--cobrado">Cobrado</span>';
}
