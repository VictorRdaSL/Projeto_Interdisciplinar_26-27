<?php
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/relatorios.php';
require_once __DIR__ . '/includes/relatorios_layout.php';
require_once __DIR__ . '/includes/paginacao.php';

exigirPermissaoRelatorios();

[$de, $ate, $erroPeriodo] = lerPeriodoGet();

$exportandoCsv = ($_GET['export'] ?? '') === 'csv';
$linhas = [];
$pg = dadosPaginacao(0, 1);
if (!$erroPeriodo) {
    $parametros = [];
    $tipos = '';
    $condicoes = condicaoPeriodo('m', $de, $ate, $parametros, $tipos, 'data_entrada');
    $whereSql = $condicoes ? 'WHERE ' . implode(' AND ', $condicoes) : '';
    $juncoes = '
        FROM movimentacoes m
        JOIN livros l ON l.id = m.livro_id
        JOIN etapas e ON e.id = m.etapa_id
        JOIN usuarios u ON u.id = m.responsavel_id
    ';

    // Tela: 10 por página (COUNT + LIMIT/OFFSET). CSV: tudo que o filtro retorna, sem paginação.
    $sql = "
        SELECT l.titulo, e.nome AS etapa_nome, m.rodada, m.tipo, u.nome AS responsavel_nome,
               m.data_entrada, m.data_saida, m.excedente
        $juncoes
        $whereSql
        ORDER BY m.data_entrada DESC, m.id DESC
    ";
    $stmtTotal = $conn->prepare("SELECT COUNT(*) AS total $juncoes $whereSql");
    if ($parametros) $stmtTotal->bind_param($tipos, ...$parametros);
    $stmtTotal->execute();
    $pg = dadosPaginacao((int)$stmtTotal->get_result()->fetch_assoc()['total'], paginaSolicitada());

    if ($exportandoCsv) {
        $stmt = $conn->prepare($sql);
        if ($parametros) $stmt->bind_param($tipos, ...$parametros);
    } else {
        $stmt = $conn->prepare($sql . ' LIMIT ? OFFSET ?');
        $stmt->bind_param($tipos . 'ii', ...[...$parametros, $pg['limite'], $pg['offset']]);
    }
    $stmt->execute();
    foreach ($stmt->get_result() as $m) {
        $linhas[] = [
            'livro' => $m['titulo'],
            'etapa' => $m['etapa_nome'],
            'rodada' => (int)$m['rodada'],
            'tipo' => rotuloTipoMovimentacao($m['tipo']),
            'responsavel' => $m['responsavel_nome'],
            'entrada' => formatarDataHoraBr($m['data_entrada']),
            'saida' => formatarDataHoraBr($m['data_saida']),
            'excedente' => (int)$m['excedente'] === 1 ? 'Sim' : 'Não',
        ];
    }
}

if (!$erroPeriodo && $exportandoCsv) {
    exportarCsv(
        nomeArquivoCsv('relatorio_movimentacoes', $de, $ate),
        ['Livro', 'Etapa', 'Rodada', 'Tipo', 'Responsável', 'Entrada', 'Saída', 'Excedente'],
        array_map('array_values', $linhas)
    );
}

relatorioAbrir('Movimentações por período', 'Relatórios');
?>
        <section class="ws-secao" aria-labelledby="filtros-titulo">
          <h2 class="ws-sr-only" id="filtros-titulo">Filtros</h2>
          <p class="ws-texto-suave">Todas as movimentações de livros entre etapas e mesas que <strong>começaram</strong> no período (pela data de entrada). Saída vazia = ainda aberta.</p>
          <form class="ws-filtros" method="get">
            <?php relatorioCamposPeriodo($de, $ate); ?>
            <?php relatorioBotoes('relatorio_movimentacoes.php'); ?>
          </form>
        </section>

        <section class="ws-secao" aria-labelledby="resultado-titulo">
<?php if ($erroPeriodo): ?>
          <h2 class="ws-sr-only" id="resultado-titulo">Resultado</h2>
          <?php relatorioErroPeriodo($erroPeriodo); ?>
<?php else: ?>
          <div class="ws-secao__cabecalho">
            <div>
              <h2 id="resultado-titulo">Resultado</h2>
              <p><?= escaparHtml($pg['total'] . ' movimentação(ões)') ?>. O CSV leva todas.</p>
            </div>
            <?php relatorioExportar('relatorio_movimentacoes.php', queryFiltros(['filtro_de' => $de, 'filtro_ate' => $ate])); ?>
          </div>
<?php if (!$linhas): ?>
          <?php relatorioVazio('Nenhuma movimentação no período.', 'Mude as datas e filtre de novo.'); ?>
<?php else: ?>
          <!-- No celular as linhas viram cartões (.ws-tabela--empilhar + data-rotulo) -->
          <div class="ws-tabela-wrap" tabindex="0" role="region" aria-labelledby="resultado-titulo">
            <table class="ws-tabela ws-tabela--empilhar">
              <thead>
                <tr>
                  <th scope="col">Livro</th>
                  <th scope="col">Etapa</th>
                  <th scope="col" class="ws-tabela__num">Rodada</th>
                  <th scope="col">Tipo</th>
                  <th scope="col">Responsável</th>
                  <th scope="col">Entrada</th>
                  <th scope="col">Saída</th>
                  <th scope="col">Excedente</th>
                </tr>
              </thead>
              <tbody>
<?php foreach ($linhas as $ln): ?>
                <tr>
                  <td class="ws-tabela__titulo" data-rotulo="Livro"><?= escaparHtml($ln['livro']) ?></td>
                  <td data-rotulo="Etapa"><?= escaparHtml($ln['etapa']) ?></td>
                  <td class="ws-tabela__num" data-rotulo="Rodada"><?= (int)$ln['rodada'] ?></td>
                  <td data-rotulo="Tipo"><?= escaparHtml($ln['tipo']) ?></td>
                  <td data-rotulo="Responsável"><?= escaparHtml($ln['responsavel']) ?></td>
                  <td class="ws-tabela__codigo" data-rotulo="Entrada"><?= escaparHtml($ln['entrada']) ?></td>
                  <td class="ws-tabela__codigo" data-rotulo="Saída"><?= escaparHtml($ln['saida'] ?: '—') ?></td>
                  <td data-rotulo="Excedente"><?= $ln['excedente'] === 'Sim' ? htmlChipCobrado() : '<span class="ws-tabela__suave">—</span>' ?></td>
                </tr>
<?php endforeach; ?>
              </tbody>
            </table>
          </div>
<?= htmlRodapePaginacao($pg, 'relatorio_movimentacoes.php', ['filtro_de' => $de, 'filtro_ate' => $ate]) ?>
<?php endif; ?>
<?php endif; ?>
        </section>
<?php relatorioFechar();
