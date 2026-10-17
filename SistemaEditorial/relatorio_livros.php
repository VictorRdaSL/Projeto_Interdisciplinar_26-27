<?php
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/relatorios.php';
require_once __DIR__ . '/includes/relatorios_layout.php';

exigirPermissaoRelatorios();

$filtroEtapa = (int)($_GET['filtro_etapa'] ?? 0);
$filtroStatus = trim($_GET['filtro_status'] ?? '');
$filtroMesa = (int)($_GET['filtro_mesa'] ?? 0);
if ($filtroStatus !== '' && !isset(ROTULOS_STATUS_LIVRO[$filtroStatus])) {
    $filtroStatus = '';
}

$condicoes = [];
$parametros = [];
$tipos = '';
if ($filtroEtapa > 0) { $condicoes[] = 'l.etapa_atual_id = ?'; $parametros[] = $filtroEtapa; $tipos .= 'i'; }
if ($filtroStatus !== '') { $condicoes[] = 'l.status = ?'; $parametros[] = $filtroStatus; $tipos .= 's'; }
if ($filtroMesa > 0) { $condicoes[] = 'l.responsavel_atual_id = ?'; $parametros[] = $filtroMesa; $tipos .= 'i'; }
$whereSql = $condicoes ? 'WHERE ' . implode(' AND ', $condicoes) : '';

$stmt = $conn->prepare("
    SELECT l.id, l.titulo, l.isbn, l.tem_copia, l.status, l.observacoes,
           e.nome AS etapa_nome, e.rodadas_incluidas,
           u.nome AS mesa_nome,
           " . SQL_PESSOAS_PAPEIS . " AS pessoas_papeis
    FROM livros l
    JOIN etapas e ON e.id = l.etapa_atual_id
    LEFT JOIN usuarios u ON u.id = l.responsavel_atual_id
    $whereSql
    ORDER BY l.titulo, l.id
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
        'isbn' => $l['isbn'] ?? '',
        'copia' => (int)$l['tem_copia'] === 1 ? 'Sim' : 'Não',
        'etapa' => $l['etapa_nome'],
        'rodada' => $rodada,
        'mesa' => $l['mesa_nome'] ?? '',
        'status' => rotuloStatusLivro($l['status']),
        'dias' => $dias,
        'observacoes' => $l['observacoes'] ?? '',
    ];
}

$filtrosAtivos = ['filtro_etapa' => $filtroEtapa, 'filtro_status' => $filtroStatus, 'filtro_mesa' => $filtroMesa];

if (($_GET['export'] ?? '') === 'csv') {
    exportarCsv(
        'relatorio_livros.csv',
        ['Título', 'Pessoas e papéis', 'ISBN', 'Tem cópia', 'Etapa atual', 'Rodada', 'Mesa', 'Status', 'Dias na etapa atual', 'Observações'],
        array_map('array_values', $linhas)
    );
}

$etapasFiltro = $conn->query('SELECT id, nome FROM etapas ORDER BY ordem, id')->fetch_all(MYSQLI_ASSOC);
$usuariosFiltro = $conn->query('SELECT id, nome FROM usuarios ORDER BY nome')->fetch_all(MYSQLI_ASSOC);

relatorioAbrir('Relatório de livros', 'Relatórios');
?>
    <section class="painel">
        <div class="painel-topo">
            <div><h2>Livros</h2><p>Situação atual de cada livro. "Dias na etapa atual" conta desde que o livro entrou na etapa (trocas de mesa não zeram) e só existe para livros em andamento ou extraviados.</p></div>
        </div>
        <form method="get" class="form-filtro-log">
            <div class="campo">
                <label for="filtro_etapa">Etapa</label>
                <select id="filtro_etapa" name="filtro_etapa">
                    <option value="0">Todas</option>
                    <?php foreach ($etapasFiltro as $e): ?>
                        <option value="<?= (int)$e['id'] ?>" <?= $filtroEtapa === (int)$e['id'] ? 'selected' : '' ?>><?= htmlspecialchars($e['nome']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="campo">
                <label for="filtro_status">Status</label>
                <select id="filtro_status" name="filtro_status">
                    <option value="">Todos</option>
                    <?php foreach (ROTULOS_STATUS_LIVRO as $valor => $rotulo): ?>
                        <option value="<?= htmlspecialchars($valor) ?>" <?= $filtroStatus === $valor ? 'selected' : '' ?>><?= htmlspecialchars($rotulo) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="campo">
                <label for="filtro_mesa">Mesa</label>
                <select id="filtro_mesa" name="filtro_mesa">
                    <option value="0">Todas</option>
                    <?php foreach ($usuariosFiltro as $u): ?>
                        <option value="<?= (int)$u['id'] ?>" <?= $filtroMesa === (int)$u['id'] ? 'selected' : '' ?>><?= htmlspecialchars($u['nome']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php relatorioBotoes('relatorio_livros.php', queryFiltros($filtrosAtivos)); ?>
        </form>

        <p style="font-size:13px;color:#8a9b91;margin:0 0 10px;"><?= count($linhas) ?> livro(s)<?= count($linhas) > LIMITE_LINHAS_TELA ? ' — mostrando os primeiros ' . LIMITE_LINHAS_TELA . '; o CSV leva todos' : '' ?>.</p>
        <div class="tabela-container">
            <table>
                <thead><tr><th>Título</th><th>Pessoas e papéis</th><th>ISBN</th><th>Cópia</th><th>Etapa atual</th><th>Rodada</th><th>Mesa</th><th>Status</th><th>Dias na etapa</th><th>Observações</th></tr></thead>
                <tbody>
                <?php if (!$linhas): ?>
                    <tr><td colspan="10" class="vazio">Nenhum livro encontrado para esses filtros.</td></tr>
                <?php else: foreach (array_slice($linhas, 0, LIMITE_LINHAS_TELA) as $ln): ?>
                    <tr>
                        <td><?= htmlspecialchars($ln['titulo']) ?></td>
                        <td><?= htmlspecialchars($ln['pessoas'] ?: '—') ?></td>
                        <td><?= htmlspecialchars($ln['isbn'] ?: '—') ?></td>
                        <td><?= htmlspecialchars($ln['copia']) ?></td>
                        <td><?= htmlspecialchars($ln['etapa']) ?></td>
                        <td><?= htmlspecialchars($ln['rodada'] ?: '—') ?></td>
                        <td><?= htmlspecialchars($ln['mesa'] ?: '—') ?></td>
                        <td><?= htmlspecialchars($ln['status']) ?></td>
                        <td><?= $ln['dias'] === '' ? '—' : (int)$ln['dias'] ?></td>
                        <td style="max-width:260px;white-space:pre-line;"><?= htmlspecialchars($ln['observacoes'] ?: '—') ?></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php relatorioFechar();
