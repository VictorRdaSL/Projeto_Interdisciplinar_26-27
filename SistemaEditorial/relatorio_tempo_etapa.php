<?php
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/relatorios.php';
require_once __DIR__ . '/includes/relatorios_layout.php';

exigirPermissaoRelatorios();

[$de, $ate, $erroPeriodo] = lerPeriodoGet();

const SEGUNDOS_POR_DIA = 86400;

$etapasResumo = [];
if (!$erroPeriodo) {
    foreach ($conn->query('SELECT id, nome, ordem FROM etapas ORDER BY ordem, id') as $e) {
        $etapasResumo[(int)$e['id']] = [
            'nome' => $e['nome'], 'passagens' => [], 'abertas' => 0, 'soma' => 0, 'maior' => 0,
        ];
    }
    foreach (calcularPermanencias($conn, $de, $ate) as $p) {
        $id = $p['etapa_id'];
        $etapasResumo[$id]['passagens'][] = $p;
        $etapasResumo[$id]['soma'] += $p['segundos'];
        $etapasResumo[$id]['maior'] = max($etapasResumo[$id]['maior'], $p['segundos']);
        if ($p['em_aberto']) $etapasResumo[$id]['abertas']++;
    }
    foreach ($etapasResumo as &$er) {
        $n = count($er['passagens']);
        $er['media'] = $n > 0 ? $er['soma'] / $n : null;
    }
    unset($er);
}

$maiorMedia = null;
$etapaGargalo = null;
foreach ($etapasResumo as $id => $er) {
    if ($er['media'] !== null && ($maiorMedia === null || $er['media'] > $maiorMedia)) {
        $maiorMedia = $er['media'];
        $etapaGargalo = $id;
    }
}
$etapasComDados = count(array_filter($etapasResumo, fn($er) => $er['media'] !== null));

$exportar = $_GET['export'] ?? '';
if (!$erroPeriodo && $exportar === 'resumo') {
    $csv = [];
    foreach ($etapasResumo as $er) {
        $csv[] = [
            $er['nome'], count($er['passagens']), $er['abertas'],
            $er['media'] !== null ? formatarDecimalBr($er['media'] / SEGUNDOS_POR_DIA) : '',
            $er['media'] !== null ? formatarDecimalBr($er['maior'] / SEGUNDOS_POR_DIA) : '',
        ];
    }
    exportarCsv(nomeArquivoCsv('relatorio_tempo_por_etapa_resumo', $de, $ate),
        ['Etapa', 'Passagens', 'Em andamento', 'Tempo médio (dias)', 'Maior permanência (dias)'], $csv);
}
if (!$erroPeriodo && $exportar === 'detalhe') {
    $csv = [];
    foreach ($etapasResumo as $er) {
        foreach ($er['passagens'] as $p) {
            $csv[] = [
                $er['nome'], $p['titulo'], $p['rodada'], rotuloTipoMovimentacao($p['tipo']), $p['responsavel_nome'],
                formatarDataHoraBr($p['entrada']), formatarDataHoraBr($p['saida']),
                formatarDecimalBr($p['segundos'] / SEGUNDOS_POR_DIA), $p['em_aberto'] ? 'Sim' : 'Não',
            ];
        }
    }
    exportarCsv(nomeArquivoCsv('relatorio_tempo_por_etapa_detalhe', $de, $ate),
        ['Etapa', 'Livro', 'Rodada', 'Tipo de entrada', 'Mesa', 'Entrada', 'Saída', 'Dias na etapa', 'Em andamento'], $csv);
}

relatorioAbrir('Tempo por etapa', 'Relatórios');
?>
        <section class="ws-secao" aria-labelledby="filtros-titulo">
          <h2 class="ws-sr-only" id="filtros-titulo">Filtros</h2>
          <p class="ws-texto-suave">Para achar gargalos. Considera as permanências que <strong>começaram</strong> no período. Trocar de mesa dentro da etapa não conta como nova passagem. Permanências ainda em andamento contam até agora. Clique numa etapa para ver os livros.</p>
          <form class="ws-filtros" method="get">
            <?php relatorioCamposPeriodo($de, $ate); ?>
            <?php relatorioBotoes('relatorio_tempo_etapa.php'); ?>
          </form>
        </section>

        <section class="ws-secao" aria-labelledby="resultado-titulo">
<?php if ($erroPeriodo): ?>
          <h2 class="ws-sr-only" id="resultado-titulo">Resultado</h2>
          <?php relatorioErroPeriodo($erroPeriodo); ?>
<?php else: ?>
          <div class="ws-secao__cabecalho">
            <div>
              <h2 id="resultado-titulo">Tempo médio por etapa</h2>
              <p><?= escaparHtml(count($etapasResumo) . ' etapa(s)') ?>.</p>
            </div>
            <?php relatorioExportar('relatorio_tempo_etapa.php', queryFiltros(['filtro_de' => $de, 'filtro_ate' => $ate]), false, ['Exportar resumo (CSV)' => 'resumo', 'Exportar detalhe (CSV)' => 'detalhe']); ?>
          </div>
<?php if (!$etapasResumo): ?>
          <?php relatorioVazio('Nenhuma etapa cadastrada.'); ?>
<?php else: ?>
          <div class="ws-pilha">
<?php foreach ($etapasResumo as $id => $er):
    $n = count($er['passagens']);
?>
            <details class="ws-card ws-card--superficie">
              <summary>
                <span class="ws-linha" style="display:inline-flex;vertical-align:middle">
                <strong><?= escaparHtml($er['nome']) ?></strong>
<?php if ($n === 0): ?>
                <span class="ws-texto-suave">sem passagens no período</span>
<?php else: ?>
                <span><?= $n ?> passagem(ns)<?= $er['abertas'] > 0 ? ' (' . $er['abertas'] . ' em andamento)' : '' ?></span>
                <span>média <strong><?= escaparHtml(formatarDecimalBr($er['media'] / SEGUNDOS_POR_DIA)) ?> dia(s)</strong></span>
                <span>maior <?= escaparHtml(formatarDecimalBr($er['maior'] / SEGUNDOS_POR_DIA)) ?> dia(s)</span>
<?php if ($id === $etapaGargalo && $etapasComDados > 1): ?>
                <span class="ws-tag ws-tag--neutra ws-tag--peq">Maior média</span>
<?php endif; ?>
<?php endif; ?>
                </span>
              </summary>
<?php if ($n > 0): ?>
              <div class="ws-tabela-wrap" tabindex="0" role="region" aria-label="<?= escaparHtml('Passagens por ' . $er['nome']) ?>">
                <table class="ws-tabela ws-tabela--empilhar">
                  <thead>
                    <tr>
                      <th scope="col">Livro</th>
                      <th scope="col">Rodada</th>
                      <th scope="col">Entrada por</th>
                      <th scope="col">Mesa</th>
                      <th scope="col">Entrada</th>
                      <th scope="col">Saída</th>
                      <th scope="col" class="ws-tabela__num">Dias na etapa</th>
                    </tr>
                  </thead>
                  <tbody>
<?php foreach ($er['passagens'] as $p): ?>
                    <tr>
                      <td class="ws-tabela__titulo" data-rotulo="Livro"><a href="livro_detalhes.php?id=<?= (int)$p['livro_id'] ?>"><?= escaparHtml($p['titulo']) ?></a></td>
                      <td data-rotulo="Rodada"><?= (int)$p['rodada'] ?><?= $p['excedente'] ? ' ' . htmlChipCobrado() : '' ?></td>
                      <td data-rotulo="Entrada por"><?= escaparHtml(rotuloTipoMovimentacao($p['tipo'])) ?></td>
                      <td data-rotulo="Mesa"><?= escaparHtml($p['responsavel_nome']) ?></td>
                      <td class="ws-tabela__codigo" data-rotulo="Entrada"><?= escaparHtml(formatarDataHoraBr($p['entrada'])) ?></td>
                      <td class="ws-tabela__codigo" data-rotulo="Saída"><?= $p['em_aberto'] ? '<span class="ws-tabela__suave">em andamento</span>' : escaparHtml(formatarDataHoraBr($p['saida'])) ?></td>
                      <td class="ws-tabela__num" data-rotulo="Dias na etapa"><?= escaparHtml(formatarDecimalBr($p['segundos'] / SEGUNDOS_POR_DIA)) ?></td>
                    </tr>
<?php endforeach; ?>
                  </tbody>
                </table>
              </div>
<?php endif; ?>
            </details>
<?php endforeach; ?>
          </div>
<?php endif; ?>
<?php endif; ?>
        </section>
<?php relatorioFechar();
