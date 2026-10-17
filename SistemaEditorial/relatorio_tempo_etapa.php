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
    <section class="painel">
        <div class="painel-topo">
            <div><h2>Tempo médio por etapa</h2><p>Para achar gargalos. Considera as permanências que <strong>começaram</strong> no período. Trocar de mesa dentro da etapa não conta como nova passagem. Permanências ainda em andamento contam até agora. Clique numa etapa para ver os livros.</p></div>
        </div>
        <form method="get" class="form-filtro-log">
            <?php relatorioCamposPeriodo($de, $ate); ?>
            <?php relatorioBotoes('relatorio_tempo_etapa.php', queryFiltros(['filtro_de' => $de, 'filtro_ate' => $ate]), (bool)$erroPeriodo, ['Exportar resumo (CSV)' => 'resumo', 'Exportar detalhe (CSV)' => 'detalhe']); ?>
        </form>

        <?php if ($erroPeriodo): ?>
            <div class="flash erro"><?= htmlspecialchars($erroPeriodo) ?></div>
        <?php else: foreach ($etapasResumo as $id => $er):
            $n = count($er['passagens']);
        ?>
        <details style="border:1px solid #e3ebe6;border-radius:10px;margin-bottom:10px;padding:10px 14px;">
            <summary style="cursor:pointer;display:flex;gap:14px;align-items:center;flex-wrap:wrap;">
                <strong style="flex:1;min-width:200px;"><?= htmlspecialchars($er['nome']) ?></strong>
                <?php if ($n === 0): ?>
                    <span style="color:#8a9b91;">sem passagens no período</span>
                <?php else: ?>
                    <span><?= $n ?> passagem(ns)<?= $er['abertas'] > 0 ? ' (' . $er['abertas'] . ' em andamento)' : '' ?></span>
                    <span>média <strong><?= formatarDecimalBr($er['media'] / SEGUNDOS_POR_DIA) ?> dia(s)</strong></span>
                    <span>maior <?= formatarDecimalBr($er['maior'] / SEGUNDOS_POR_DIA) ?> dia(s)</span>
                    <?php if ($id === $etapaGargalo && $etapasComDados > 1): ?><span class="status baixo">maior média</span><?php endif; ?>
                <?php endif; ?>
            </summary>
            <?php if ($n > 0): ?>
            <div class="tabela-container" style="margin-top:12px;">
                <table>
                    <thead><tr><th>Livro</th><th>Rodada</th><th>Entrada por</th><th>Mesa</th><th>Entrada</th><th>Saída</th><th>Dias na etapa</th></tr></thead>
                    <tbody>
                    <?php foreach ($er['passagens'] as $p): ?>
                        <tr>
                            <td><a href="livro_detalhes.php?id=<?= (int)$p['livro_id'] ?>"><?= htmlspecialchars($p['titulo']) ?></a></td>
                            <td><?= (int)$p['rodada'] ?><?= $p['excedente'] ? ' <span class="status baixo">Cobrável</span>' : '' ?></td>
                            <td><?= htmlspecialchars(rotuloTipoMovimentacao($p['tipo'])) ?></td>
                            <td><?= htmlspecialchars($p['responsavel_nome']) ?></td>
                            <td><?= htmlspecialchars(formatarDataHoraBr($p['entrada'])) ?></td>
                            <td><?= $p['em_aberto'] ? '<em>em andamento</em>' : htmlspecialchars(formatarDataHoraBr($p['saida'])) ?></td>
                            <td><?= formatarDecimalBr($p['segundos'] / SEGUNDOS_POR_DIA) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </details>
        <?php endforeach; endif; ?>
    </section>
<?php relatorioFechar();
