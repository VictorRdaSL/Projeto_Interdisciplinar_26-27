<?php
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/relatorios.php';
require_once __DIR__ . '/includes/mesa.php';
require_once __DIR__ . '/includes/isbn.php';
require_once __DIR__ . '/includes/formatacao.php';
require_once __DIR__ . '/includes/relatorios_layout.php';

exigirPermissaoRelatorios();

// O filtro de etapa também tem "Concluído" e "Cancelado" (Passo 20.11): livro concluído ou cancelado não está em etapa
// nenhuma, então aparece nessas duas opções e NÃO na etapa onde terminou. ('filtro_etapa' = id da etapa, ou concluido/cancelado.)
$filtroEtapaBruto = entradaTexto($_GET, 'filtro_etapa');
$filtroEtapaStatus = in_array($filtroEtapaBruto, ['concluido', 'cancelado'], true) ? $filtroEtapaBruto : '';
$filtroEtapa = ($filtroEtapaStatus === '' && ctype_digit($filtroEtapaBruto)) ? (int)$filtroEtapaBruto : 0;
$filtroStatus = entradaTexto($_GET, 'filtro_status');
$filtroMesa = (int)($_GET['filtro_mesa'] ?? 0);
if ($filtroStatus !== '' && !isset(ROTULOS_STATUS_LIVRO[$filtroStatus])) {
    $filtroStatus = '';
}

$condicoes = [];
$parametros = [];
$tipos = '';
if ($filtroEtapaStatus !== '') { $condicoes[] = 'v.status = ?'; $parametros[] = $filtroEtapaStatus; $tipos .= 's'; }
elseif ($filtroEtapa > 0) { $condicoes[] = "v.etapa_id = ? AND v.status IN ('em_andamento', 'extraviado')"; $parametros[] = $filtroEtapa; $tipos .= 'i'; }
if ($filtroStatus !== '') { $condicoes[] = 'v.status = ?'; $parametros[] = $filtroStatus; $tipos .= 's'; }
if ($filtroMesa > 0) { $condicoes[] = 'v.mesa_id = ?'; $parametros[] = $filtroMesa; $tipos .= 'i'; }
$whereSql = $condicoes ? 'WHERE ' . implode(' AND ', $condicoes) : '';

$stmt = $conn->prepare("
    SELECT v.livro_id AS id, v.titulo, v.isbn, v.tem_copia, v.status, v.observacoes,
           v.etapa_nome, v.rodadas_incluidas, v.mesa_nome,
           c.pessoas_papeis
    FROM vw_livros_situacao_atual v
    LEFT JOIN vw_livros_contribuidores c ON c.livro_id = v.livro_id
    $whereSql
    ORDER BY v.titulo, v.livro_id
");
if ($parametros) $stmt->bind_param($tipos, ...$parametros);
$stmt->execute();
$livros = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Entrada real na etapa atual = início da permanência aberta (uma troca de mesa
// reabre a movimentação, mas o livro continua na mesma etapa).
$permanenciaAberta = [];
foreach (calcularPermanencias($conn, '', '') as $p) {
    if ($p['em_aberto']) $permanenciaAberta[$p['livro_id']] = $p;
}

$linhas = [];
foreach ($livros as $l) {
    $p = $permanenciaAberta[(int)$l['id']] ?? null;
    $rodada = '';
    $dias = '';
    if ($p && in_array($l['status'], ['em_andamento', 'extraviado'], true)) {
        $rodada = (string)$p['rodada'];
        if ($l['rodadas_incluidas'] !== null) $rodada .= ' de ' . (int)$l['rodadas_incluidas'];
        if ($p['excedente']) $rodada .= ' (cobrável)';
        $dias = diasEntre($p['entrada']);
    }
    $linhas[] = [
        'titulo' => $l['titulo'],
        'pessoas' => $l['pessoas_papeis'] ?? '',
        'isbn' => formatarIsbn($l['isbn']),
        'copia' => (int)$l['tem_copia'] === 1 ? 'Sim' : 'Não',
        'etapa' => textoEtapaAtual($l['status'], $l['etapa_nome']),
        'rodada' => $rodada,
        'mesa' => ($l['mesa_nome'] ?? '') === '' ? '' : textoMesaAtual($l['status'], $l['mesa_nome']), // última mesa, marcada "(concluído)"/"(cancelado)"
        'status' => rotuloStatusLivro($l['status']),
        'dias' => $dias,
        'observacoes' => $l['observacoes'] ?? '',
    ];
}

$filtrosAtivos = ['filtro_etapa' => $filtroEtapaStatus !== '' ? $filtroEtapaStatus : $filtroEtapa, 'filtro_status' => $filtroStatus, 'filtro_mesa' => $filtroMesa];

if (($_GET['export'] ?? '') === 'csv') {
    exportarCsv(
        'relatorio_livros.csv',
        ['Título', 'Pessoas e papéis', 'ISBN', 'Tem cópia', 'Etapa atual', 'Rodada', 'Mesa', 'Status', 'Dias na etapa atual', 'Observações'],
        array_map('array_values', $linhas)
    );
}

$etapasFiltro = $conn->query('SELECT id, nome FROM etapas ORDER BY ordem, id')->fetch_all(MYSQLI_ASSOC);
// O Administrador não faz parte do fluxo (includes/mesa.php): não aparece como opção de mesa no filtro.
$usuariosFiltro = usuariosQuePodemSerMesa($conn);

relatorioAbrir('Relatório de livros', 'Relatórios');
?>
        <section class="ws-secao" aria-labelledby="filtros-titulo">
          <h2 class="ws-sr-only" id="filtros-titulo">Filtros</h2>
          <p class="ws-texto-suave">Situação atual de cada livro. "Dias na etapa atual" conta desde que o livro entrou na etapa (trocas de mesa não zeram) e só existe para livros em andamento ou extraviados. Livro concluído ou cancelado aparece como "Concluído" ou "Cancelado" na etapa, e é achado por essas duas opções do filtro (não pela etapa onde terminou); a Mesa dele é a última, marcada como "(concluído)" ou "(cancelado)".</p>
          <form class="ws-filtros" method="get">
            <div class="ws-campo">
              <label class="ws-campo__rotulo" for="filtro_etapa">Etapa</label>
              <select class="ws-select" id="filtro_etapa" name="filtro_etapa">
                <option value="0">Todas</option>
<?php foreach ($etapasFiltro as $e): ?>
                <option value="<?= (int)$e['id'] ?>"<?= $filtroEtapa === (int)$e['id'] ? ' selected' : '' ?>><?= escaparHtml($e['nome']) ?></option>
<?php endforeach; ?>
                <option value="concluido"<?= $filtroEtapaStatus === 'concluido' ? ' selected' : '' ?>>Concluído</option>
                <option value="cancelado"<?= $filtroEtapaStatus === 'cancelado' ? ' selected' : '' ?>>Cancelado</option>
              </select>
            </div>
            <div class="ws-campo">
              <label class="ws-campo__rotulo" for="filtro_status">Status</label>
              <select class="ws-select" id="filtro_status" name="filtro_status">
                <option value="">Todos</option>
<?php foreach (ROTULOS_STATUS_LIVRO as $valor => $rotulo): ?>
                <option value="<?= escaparHtml($valor) ?>"<?= $filtroStatus === $valor ? ' selected' : '' ?>><?= escaparHtml($rotulo) ?></option>
<?php endforeach; ?>
              </select>
            </div>
            <div class="ws-campo">
              <label class="ws-campo__rotulo" for="filtro_mesa">Mesa</label>
              <select class="ws-select" id="filtro_mesa" name="filtro_mesa">
                <option value="0">Todas</option>
<?php foreach ($usuariosFiltro as $u): ?>
                <option value="<?= (int)$u['id'] ?>"<?= $filtroMesa === (int)$u['id'] ? ' selected' : '' ?>><?= escaparHtml($u['nome']) ?></option>
<?php endforeach; ?>
              </select>
            </div>
            <?php relatorioBotoes('relatorio_livros.php'); ?>
          </form>
        </section>

        <section class="ws-secao" aria-labelledby="resultado-titulo">
          <div class="ws-secao__cabecalho">
            <div>
              <h2 id="resultado-titulo">Resultado</h2>
              <p><?= escaparHtml(count($linhas) . ' livro(s)' . (count($linhas) > LIMITE_LINHAS_TELA ? ' — mostrando os primeiros ' . LIMITE_LINHAS_TELA . '; o CSV leva todos' : '')) ?>.</p>
            </div>
            <?php relatorioExportar('relatorio_livros.php', queryFiltros($filtrosAtivos)); ?>
          </div>
<?php if (!$linhas): ?>
          <?php relatorioVazio('Nenhum livro encontrado para esses filtros.', 'Confira a etapa, o status e a mesa escolhidos.'); ?>
<?php else: ?>
          <!-- No celular as linhas viram cartões (.ws-tabela--empilhar + data-rotulo) -->
          <div class="ws-tabela-wrap" tabindex="0" role="region" aria-labelledby="resultado-titulo">
            <table class="ws-tabela ws-tabela--empilhar">
              <thead>
                <tr>
                  <th scope="col">Título</th>
                  <th scope="col">Pessoas e papéis</th>
                  <th scope="col">ISBN</th>
                  <th scope="col">Cópia</th>
                  <th scope="col">Etapa atual</th>
                  <th scope="col">Rodada</th>
                  <th scope="col">Mesa</th>
                  <th scope="col">Status</th>
                  <th scope="col" class="ws-tabela__num">Dias na etapa</th>
                  <th scope="col">Observações</th>
                </tr>
              </thead>
              <tbody>
<?php foreach (array_slice($linhas, 0, LIMITE_LINHAS_TELA) as $ln):
    $cobrado = str_contains($ln['rodada'], ' (cobrável)');
    $rodadaTela = str_replace(' (cobrável)', '', $ln['rodada']);
?>
                <tr>
                  <td class="ws-tabela__titulo" data-rotulo="Título"><?= escaparHtml($ln['titulo']) ?></td>
                  <td data-rotulo="Pessoas e papéis"><?= escaparHtml($ln['pessoas'] ?: '—') ?></td>
                  <td data-rotulo="ISBN"><?php if ($ln['isbn'] !== ''): ?><span class="ws-tabela__codigo"><?= escaparHtml($ln['isbn']) ?></span><?php else: ?><span class="ws-tabela__suave">—</span><?php endif; ?></td>
                  <td data-rotulo="Cópia"><?= escaparHtml($ln['copia']) ?></td>
                  <td data-rotulo="Etapa atual"><?= in_array($ln['status'], [ROTULOS_STATUS_LIVRO['concluido'], ROTULOS_STATUS_LIVRO['cancelado']], true) ? htmlStatusLivroRelatorio($ln['status']) : escaparHtml($ln['etapa']) ?></td>
                  <td data-rotulo="Rodada"><?= escaparHtml($rodadaTela ?: '—') ?><?= $cobrado ? ' ' . htmlChipCobrado() : '' ?></td>
                  <td data-rotulo="Mesa"><?= escaparHtml($ln['mesa'] ?: '—') ?></td>
                  <td data-rotulo="Status"><?= htmlStatusLivroRelatorio($ln['status']) ?></td>
                  <td class="ws-tabela__num" data-rotulo="Dias na etapa"><?= $ln['dias'] === '' ? '—' : (int)$ln['dias'] ?></td>
                  <td data-rotulo="Observações" style="max-width:260px;white-space:pre-line"><?= escaparHtml($ln['observacoes'] ?: '—') ?></td>
                </tr>
<?php endforeach; ?>
              </tbody>
            </table>
          </div>
<?php endif; ?>
        </section>
<?php relatorioFechar();
