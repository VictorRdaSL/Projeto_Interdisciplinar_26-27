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
    $condicoes = condicaoPeriodo('r', $de, $ate, $parametros, $tipos, 'data_abertura');
    $whereSql = $condicoes ? 'WHERE ' . implode(' AND ', $condicoes) : '';

    // A movimentação de "resgate" nasce no mesmo instante em que o resgate é
    // concluído (mesmo $agora em concluirResgate) — é por isso que dá pra
    // descobrir a etapa onde o livro foi encontrado. LIMIT 1 evita duplicar
    // a linha do relatório se houver empate de timestamp.
    $stmt = $conn->prepare("
        SELECT l.titulo, r.data_abertura, r.data_conclusao, r.local_encontrado,
               er.nome AS ultima_etapa_nome, ur.nome AS ultima_mesa_nome,
               ua.nome AS aberto_por,
               (SELECT e2.nome FROM movimentacoes mr JOIN etapas e2 ON e2.id = mr.etapa_id
                 WHERE mr.livro_id = r.livro_id AND mr.tipo = 'resgate' AND mr.data_entrada = r.data_conclusao
                 ORDER BY mr.id LIMIT 1) AS etapa_encontrado
        FROM resgates r
        JOIN livros l ON l.id = r.livro_id
        JOIN movimentacoes mref ON mref.id = r.movimentacao_referencia_id
        JOIN etapas er ON er.id = mref.etapa_id
        JOIN usuarios ur ON ur.id = mref.responsavel_id
        JOIN usuarios ua ON ua.id = r.registrado_por_id
        $whereSql
        ORDER BY r.data_abertura DESC, r.id DESC
    ");
    if ($parametros) $stmt->bind_param($tipos, ...$parametros);
    $stmt->execute();
    foreach ($stmt->get_result() as $r) {
        $concluido = $r['data_conclusao'] !== null;
        $linhas[] = [
            'livro' => $r['titulo'],
            'ultima_etapa' => $r['ultima_etapa_nome'],
            'ultima_mesa' => $r['ultima_mesa_nome'],
            'aberto_em' => formatarDataHoraBr($r['data_abertura']),
            'aberto_por' => $r['aberto_por'],
            'situacao' => $concluido ? 'Concluído' : 'Em aberto',
            'concluido_em' => formatarDataHoraBr($r['data_conclusao']),
            'dias' => diasEntre($r['data_abertura'], $r['data_conclusao']),
            'local' => $r['local_encontrado'] ?? '',
            'etapa_encontrado' => $r['etapa_encontrado'] ?? '',
        ];
    }
}

if (!$erroPeriodo && ($_GET['export'] ?? '') === 'csv') {
    exportarCsv(
        nomeArquivoCsv('relatorio_resgates', $de, $ate),
        ['Livro', 'Última etapa conhecida', 'Última mesa conhecida', 'Aberto em', 'Aberto por', 'Situação', 'Concluído em', 'Dias', 'Local encontrado', 'Etapa onde foi encontrado'],
        array_map('array_values', $linhas)
    );
}

relatorioAbrir('Resgates por período', 'Relatórios');
?>
    <section class="painel">
        <div class="painel-topo">
            <div><h2>Resgates</h2><p>Resgates <strong>abertos</strong> no período. "Dias" vai da abertura até a conclusão (ou até hoje, se ainda em aberto).</p></div>
        </div>
        <form method="get" class="form-filtro-log">
            <?php relatorioCamposPeriodo($de, $ate); ?>
            <?php relatorioBotoes('relatorio_resgates.php', queryFiltros(['filtro_de' => $de, 'filtro_ate' => $ate]), (bool)$erroPeriodo); ?>
        </form>
        <?php if ($erroPeriodo): ?>
            <div class="flash erro"><?= htmlspecialchars($erroPeriodo) ?></div>
        <?php else: ?>
        <p style="font-size:13px;color:#8a9b91;margin:0 0 10px;"><?= count($linhas) ?> resgate(s)<?= count($linhas) > LIMITE_LINHAS_TELA ? ' — mostrando os ' . LIMITE_LINHAS_TELA . ' mais recentes; o CSV leva todos' : '' ?>.</p>
        <div class="tabela-container">
            <table>
                <thead><tr><th>Livro</th><th>Última etapa / mesa conhecida</th><th>Aberto em</th><th>Situação</th><th>Concluído em</th><th>Dias</th><th>Local encontrado</th><th>Etapa onde foi encontrado</th></tr></thead>
                <tbody>
                <?php if (!$linhas): ?>
                    <tr><td colspan="8" class="vazio">Nenhum resgate no período.</td></tr>
                <?php else: foreach (array_slice($linhas, 0, LIMITE_LINHAS_TELA) as $ln): ?>
                    <tr>
                        <td><?= htmlspecialchars($ln['livro']) ?></td>
                        <td><?= htmlspecialchars($ln['ultima_etapa']) ?> — <?= htmlspecialchars($ln['ultima_mesa']) ?></td>
                        <td><?= htmlspecialchars($ln['aberto_em']) ?><br><small>por <?= htmlspecialchars($ln['aberto_por']) ?></small></td>
                        <td><span class="status <?= $ln['situacao'] === 'Concluído' ? 'disponivel' : 'baixo' ?>"><?= htmlspecialchars($ln['situacao']) ?></span></td>
                        <td><?= htmlspecialchars($ln['concluido_em'] ?: '—') ?></td>
                        <td><?= (int)$ln['dias'] ?></td>
                        <td style="max-width:220px;white-space:pre-line;"><?= htmlspecialchars($ln['local'] ?: '—') ?></td>
                        <td><?= htmlspecialchars($ln['etapa_encontrado'] ?: '—') ?></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </section>
<?php relatorioFechar();
