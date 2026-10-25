<?php
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/relatorios.php';
require_once __DIR__ . '/includes/relatorios_layout.php';

exigirPermissaoRelatorios();

[$de, $ate, $erroPeriodo] = lerPeriodoGet();

$linhas = [];
if (!$erroPeriodo) {
    $parametros = [];
    $tipos = '';
    $condicoes = condicaoPeriodo('m', $de, $ate, $parametros, $tipos, 'data_entrada');
    // Só as linhas que de fato abriram a rodada (avanço/repetição/retrocesso).
    // Troca de mesa copia rodada/excedente da movimentação anterior e resgate
    // mantém a rodada — incluí-las contaria a mesma cobrança duas vezes.
    $condicoes[] = 'm.excedente = 1';
    $condicoes[] = "m.tipo IN ('avanco', 'repeticao', 'retrocesso')";
    $whereSql = 'WHERE ' . implode(' AND ', $condicoes);

    $stmt = $conn->prepare("
        SELECT l.titulo, e.nome AS etapa_nome, e.rodadas_incluidas, m.rodada, m.data_entrada,
               c.pessoas_papeis
        FROM movimentacoes m
        JOIN livros l ON l.id = m.livro_id
        JOIN etapas e ON e.id = m.etapa_id
        LEFT JOIN vw_livros_contribuidores c ON c.livro_id = m.livro_id
        $whereSql
        ORDER BY m.data_entrada DESC, m.id DESC
    ");
    if ($parametros) $stmt->bind_param($tipos, ...$parametros);
    $stmt->execute();
    foreach ($stmt->get_result() as $m) {
        $linhas[] = [
            'livro' => $m['titulo'],
            'pessoas' => $m['pessoas_papeis'] ?? '',
            'etapa' => $m['etapa_nome'],
            'rodada' => (int)$m['rodada'],
            'limite' => $m['rodadas_incluidas'] !== null ? (int)$m['rodadas_incluidas'] : '',
            'data' => formatarDataHoraBr($m['data_entrada']),
        ];
    }
}

if (!$erroPeriodo && ($_GET['export'] ?? '') === 'csv') {
    exportarCsv(
        nomeArquivoCsv('relatorio_rodadas_excedentes', $de, $ate),
        ['Livro', 'Pessoas vinculadas', 'Etapa', 'Número da rodada', 'Limite da etapa', 'Data'],
        array_map('array_values', $linhas)
    );
}

relatorioAbrir('Rodadas excedentes por período', 'Relatórios');
?>
        <section class="ws-secao" aria-labelledby="filtros-titulo">
          <h2 class="ws-sr-only" id="filtros-titulo">Filtros</h2>
          <p class="ws-texto-suave">Cada rodada cobrável que <strong>começou</strong> no período — base para a cobrança, feita fora do sistema. O limite mostrado é o atual da etapa; o que já foi gravado como excedente não é recalculado.</p>
          <form class="ws-filtros" method="get">
            <?php relatorioCamposPeriodo($de, $ate); ?>
            <?php relatorioBotoes('relatorio_excedentes.php'); ?>
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
              <p><?= escaparHtml(count($linhas) . ' rodada(s) excedente(s)' . (count($linhas) > LIMITE_LINHAS_TELA ? ' — mostrando as ' . LIMITE_LINHAS_TELA . ' mais recentes; o CSV leva todas' : '')) ?>.</p>
            </div>
            <?php relatorioExportar('relatorio_excedentes.php', queryFiltros(['filtro_de' => $de, 'filtro_ate' => $ate])); ?>
          </div>
<?php if (!$linhas): ?>
          <?php relatorioVazio('Nenhuma rodada excedente no período.', 'Mude as datas e filtre de novo.'); ?>
<?php else: ?>
          <!-- No celular as linhas viram cartões (.ws-tabela--empilhar + data-rotulo) -->
          <div class="ws-tabela-wrap" tabindex="0" role="region" aria-labelledby="resultado-titulo">
            <table class="ws-tabela ws-tabela--empilhar">
              <thead>
                <tr>
                  <th scope="col">Livro</th>
                  <th scope="col">Pessoas vinculadas</th>
                  <th scope="col">Etapa</th>
                  <th scope="col">Rodada</th>
                  <th scope="col" class="ws-tabela__num">Limite da etapa</th>
                  <th scope="col">Data</th>
                </tr>
              </thead>
              <tbody>
<?php foreach (array_slice($linhas, 0, LIMITE_LINHAS_TELA) as $ln): ?>
                <tr>
                  <td class="ws-tabela__titulo" data-rotulo="Livro"><?= escaparHtml($ln['livro']) ?></td>
                  <td data-rotulo="Pessoas vinculadas"><?= escaparHtml($ln['pessoas'] ?: '—') ?></td>
                  <td data-rotulo="Etapa"><?= escaparHtml($ln['etapa']) ?></td>
                  <td data-rotulo="Rodada"><?= (int)$ln['rodada'] ?> <?= htmlChipCobrado() ?></td>
                  <td class="ws-tabela__num" data-rotulo="Limite da etapa"><?= $ln['limite'] === '' ? '—' : (int)$ln['limite'] ?></td>
                  <td class="ws-tabela__codigo" data-rotulo="Data"><?= escaparHtml($ln['data']) ?></td>
                </tr>
<?php endforeach; ?>
              </tbody>
            </table>
          </div>
<?php endif; ?>
<?php endif; ?>
        </section>
<?php relatorioFechar();
