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
    $whereSql = $condicoes ? 'WHERE ' . implode(' AND ', $condicoes) : '';

    $stmt = $conn->prepare("
        SELECT l.titulo, e.nome AS etapa_nome, m.rodada, m.tipo, u.nome AS responsavel_nome,
               m.data_entrada, m.data_saida, m.excedente
        FROM movimentacoes m
        JOIN livros l ON l.id = m.livro_id
        JOIN etapas e ON e.id = m.etapa_id
        JOIN usuarios u ON u.id = m.responsavel_id
        $whereSql
        ORDER BY m.data_entrada DESC, m.id DESC
    ");
    if ($parametros) $stmt->bind_param($tipos, ...$parametros);
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

if (!$erroPeriodo && ($_GET['export'] ?? '') === 'csv') {
    exportarCsv(
        nomeArquivoCsv('relatorio_movimentacoes', $de, $ate),
        ['Livro', 'Etapa', 'Rodada', 'Tipo', 'Responsável', 'Entrada', 'Saída', 'Excedente'],
        array_map('array_values', $linhas)
    );
}

relatorioAbrir('Movimentações por período', 'Relatórios');
?>
    <section class="painel">
        <div class="painel-topo">
            <div><h2>Movimentações</h2><p>Todas as movimentações de livros entre etapas e mesas que <strong>começaram</strong> no período (pela data de entrada). Saída vazia = ainda aberta.</p></div>
        </div>
        <form method="get" class="form-filtro-log">
            <?php relatorioCamposPeriodo($de, $ate); ?>
            <?php relatorioBotoes('relatorio_movimentacoes.php', queryFiltros(['filtro_de' => $de, 'filtro_ate' => $ate]), (bool)$erroPeriodo); ?>
        </form>
        <?php if ($erroPeriodo): ?>
            <div class="flash erro"><?= htmlspecialchars($erroPeriodo) ?></div>
        <?php else: ?>
        <p style="font-size:13px;color:#8a9b91;margin:0 0 10px;"><?= count($linhas) ?> movimentação(ões)<?= count($linhas) > LIMITE_LINHAS_TELA ? ' — mostrando as ' . LIMITE_LINHAS_TELA . ' mais recentes; o CSV leva todas' : '' ?>.</p>
        <div class="tabela-container">
            <table>
                <thead><tr><th>Livro</th><th>Etapa</th><th>Rodada</th><th>Tipo</th><th>Responsável</th><th>Entrada</th><th>Saída</th><th>Excedente</th></tr></thead>
                <tbody>
                <?php if (!$linhas): ?>
                    <tr><td colspan="8" class="vazio">Nenhuma movimentação no período.</td></tr>
                <?php else: foreach (array_slice($linhas, 0, LIMITE_LINHAS_TELA) as $ln): ?>
                    <tr>
                        <td><?= htmlspecialchars($ln['livro']) ?></td>
                        <td><?= htmlspecialchars($ln['etapa']) ?></td>
                        <td><?= (int)$ln['rodada'] ?></td>
                        <td><?= htmlspecialchars($ln['tipo']) ?></td>
                        <td><?= htmlspecialchars($ln['responsavel']) ?></td>
                        <td><?= htmlspecialchars($ln['entrada']) ?></td>
                        <td><?= htmlspecialchars($ln['saida'] ?: '—') ?></td>
                        <td><?= $ln['excedente'] === 'Sim' ? '<span class="status baixo">Sim</span>' : 'Não' ?></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </section>
<?php relatorioFechar();
