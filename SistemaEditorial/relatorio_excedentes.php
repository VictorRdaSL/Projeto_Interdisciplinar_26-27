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
               " . SQL_PESSOAS_PAPEIS . " AS pessoas_papeis
        FROM movimentacoes m
        JOIN livros l ON l.id = m.livro_id
        JOIN etapas e ON e.id = m.etapa_id
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
    <section class="painel">
        <div class="painel-topo">
            <div><h2>Rodadas excedentes</h2><p>Cada rodada cobrável que <strong>começou</strong> no período — base para a cobrança, feita fora do sistema. O limite mostrado é o atual da etapa; o que já foi gravado como excedente não é recalculado.</p></div>
        </div>
        <form method="get" class="form-filtro-log">
            <?php relatorioCamposPeriodo($de, $ate); ?>
            <?php relatorioBotoes('relatorio_excedentes.php', queryFiltros(['filtro_de' => $de, 'filtro_ate' => $ate]), (bool)$erroPeriodo); ?>
        </form>
        <?php if ($erroPeriodo): ?>
            <div class="flash erro"><?= htmlspecialchars($erroPeriodo) ?></div>
        <?php else: ?>
        <p style="font-size:13px;color:#8a9b91;margin:0 0 10px;"><?= count($linhas) ?> rodada(s) excedente(s)<?= count($linhas) > LIMITE_LINHAS_TELA ? ' — mostrando as ' . LIMITE_LINHAS_TELA . ' mais recentes; o CSV leva todas' : '' ?>.</p>
        <div class="tabela-container">
            <table>
                <thead><tr><th>Livro</th><th>Pessoas vinculadas</th><th>Etapa</th><th>Rodada</th><th>Limite da etapa</th><th>Data</th></tr></thead>
                <tbody>
                <?php if (!$linhas): ?>
                    <tr><td colspan="6" class="vazio">Nenhuma rodada excedente no período.</td></tr>
                <?php else: foreach (array_slice($linhas, 0, LIMITE_LINHAS_TELA) as $ln): ?>
                    <tr>
                        <td><?= htmlspecialchars($ln['livro']) ?></td>
                        <td><?= htmlspecialchars($ln['pessoas'] ?: '—') ?></td>
                        <td><?= htmlspecialchars($ln['etapa']) ?></td>
                        <td><?= (int)$ln['rodada'] ?> <span class="status baixo">Cobrável</span></td>
                        <td><?= $ln['limite'] === '' ? '—' : (int)$ln['limite'] ?></td>
                        <td><?= htmlspecialchars($ln['data']) ?></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </section>
<?php relatorioFechar();
