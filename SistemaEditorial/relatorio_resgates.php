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
        <section class="ws-secao" aria-labelledby="filtros-titulo">
          <h2 class="ws-sr-only" id="filtros-titulo">Filtros</h2>
          <p class="ws-texto-suave">Resgates <strong>abertos</strong> no período. "Dias" vai da abertura até a conclusão (ou até hoje, se ainda em aberto).</p>
          <form class="ws-filtros" method="get">
            <?php relatorioCamposPeriodo($de, $ate); ?>
            <?php relatorioBotoes('relatorio_resgates.php'); ?>
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
              <p><?= escaparHtml(count($linhas) . ' resgate(s)' . (count($linhas) > LIMITE_LINHAS_TELA ? ' — mostrando os ' . LIMITE_LINHAS_TELA . ' mais recentes; o CSV leva todos' : '')) ?>.</p>
            </div>
            <?php relatorioExportar('relatorio_resgates.php', queryFiltros(['filtro_de' => $de, 'filtro_ate' => $ate])); ?>
          </div>
<?php if (!$linhas): ?>
          <?php relatorioVazio('Nenhum resgate no período.', 'Mude as datas e filtre de novo.'); ?>
<?php else: ?>
          <!-- No celular as linhas viram cartões (.ws-tabela--empilhar + data-rotulo) -->
          <div class="ws-tabela-wrap" tabindex="0" role="region" aria-labelledby="resultado-titulo">
            <table class="ws-tabela ws-tabela--empilhar">
              <thead>
                <tr>
                  <th scope="col">Livro</th>
                  <th scope="col">Última etapa / mesa conhecida</th>
                  <th scope="col">Aberto em</th>
                  <th scope="col">Situação</th>
                  <th scope="col">Concluído em</th>
                  <th scope="col" class="ws-tabela__num">Dias</th>
                  <th scope="col">Local encontrado</th>
                  <th scope="col">Etapa onde foi encontrado</th>
                </tr>
              </thead>
              <tbody>
<?php foreach (array_slice($linhas, 0, LIMITE_LINHAS_TELA) as $ln): ?>
                <tr>
                  <td class="ws-tabela__titulo" data-rotulo="Livro"><?= escaparHtml($ln['livro']) ?></td>
                  <td data-rotulo="Última etapa / mesa"><?= escaparHtml($ln['ultima_etapa']) ?> — <?= escaparHtml($ln['ultima_mesa']) ?></td>
                  <td data-rotulo="Aberto em"><span class="ws-tabela__codigo"><?= escaparHtml($ln['aberto_em']) ?></span><span class="ws-tabela__sub">por <?= escaparHtml($ln['aberto_por']) ?></span></td>
                  <td data-rotulo="Situação"><?= $ln['situacao'] === 'Concluído' ? '<span class="ws-tag ws-tag--neutra">Concluído</span>' : '<span class="ws-status ws-status--extraviado">Em aberto</span>' ?></td>
                  <td class="ws-tabela__codigo" data-rotulo="Concluído em"><?= escaparHtml($ln['concluido_em'] ?: '—') ?></td>
                  <td class="ws-tabela__num" data-rotulo="Dias"><?= (int)$ln['dias'] ?></td>
                  <td data-rotulo="Local encontrado" style="max-width:220px;white-space:pre-line"><?= escaparHtml($ln['local'] ?: '—') ?></td>
                  <td data-rotulo="Etapa onde foi encontrado"><?= escaparHtml($ln['etapa_encontrado'] ?: '—') ?></td>
                </tr>
<?php endforeach; ?>
              </tbody>
            </table>
          </div>
<?php endif; ?>
<?php endif; ?>
        </section>
<?php relatorioFechar();
