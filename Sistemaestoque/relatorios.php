<?php
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/includes/permissoes.php';
require_once __DIR__ . '/config/database.php';

exigirPapel(['admin', 'gerente']);

/**
 * Fragmento reaproveitável do filtro de período, usado tanto na listagem por
 * movimentação quanto no resumo agrupado por produto.
 */
function condicaoPeriodo(string $alias, string $dataDe, string $dataAte, array &$parametros, string &$tipos): array
{
    $condicoes = [];
    if ($dataDe !== '') { $condicoes[] = "DATE($alias.criado_em) >= ?"; $parametros[] = $dataDe; $tipos .= 's'; }
    if ($dataAte !== '') { $condicoes[] = "DATE($alias.criado_em) <= ?"; $parametros[] = $dataAte; $tipos .= 's'; }
    return $condicoes;
}

function condicoesMovimentacao(string $alias, int $usuarioId, string $dataDe, string $dataAte, array &$parametros, string &$tipos): string
{
    $condicoes = condicaoPeriodo($alias, $dataDe, $dataAte, $parametros, $tipos);
    if ($usuarioId > 0) { $condicoes[] = "$alias.usuario_id = ?"; $parametros[] = $usuarioId; $tipos .= 'i'; }
    return $condicoes ? 'WHERE ' . implode(' AND ', $condicoes) : '';
}

function montarResumoPorProduto(mysqli $conn, string $dataDe, string $dataAte): array
{
    // Cada lado é somado numa subconsulta própria (1 linha por produto) antes de
    // juntar com produtos — evitar juntar entradas e saídas na mesma junção direta,
    // o que geraria produto cartesiano e somas infladas quando um produto tem mais
    // de uma entrada e mais de uma saída.
    $paramsE = []; $tiposE = '';
    $condE = condicaoPeriodo('e', $dataDe, $dataAte, $paramsE, $tiposE);
    $whereE = $condE ? 'WHERE ' . implode(' AND ', $condE) : '';

    $paramsS = []; $tiposS = '';
    $condS = condicaoPeriodo('s', $dataDe, $dataAte, $paramsS, $tiposS);
    $whereS = $condS ? 'WHERE ' . implode(' AND ', $condS) : '';

    $sql = "SELECT p.id, p.nome,
                COALESCE(te.total, 0) AS total_entrada,
                COALESCE(ts.total, 0) AS total_saida
            FROM produtos p
            LEFT JOIN (SELECT produto_id, SUM(quantidade) AS total FROM entradas_estoque e $whereE GROUP BY produto_id) te ON te.produto_id = p.id
            LEFT JOIN (SELECT produto_id, SUM(quantidade) AS total FROM saidas_estoque s $whereS GROUP BY produto_id) ts ON ts.produto_id = p.id
            WHERE COALESCE(te.total, 0) > 0 OR COALESCE(ts.total, 0) > 0
            ORDER BY p.nome";

    $parametros = array_merge($paramsE, $paramsS);
    $tipos = $tiposE . $tiposS;
    $stmt = $conn->prepare($sql);
    if ($parametros) $stmt->bind_param($tipos, ...$parametros);
    $stmt->execute();
    $linhas = [];
    $r = $stmt->get_result();
    while ($row = $r->fetch_assoc()) $linhas[] = $row;
    return $linhas;
}

function montarConsultaMovimentacoes(mysqli $conn, string $filtroTipo, int $filtroUsuario, string $filtroDe, string $filtroAte): array
{
    $selectEntrada = "SELECT e.criado_em AS data_mov, 'entrada' AS tipo, e.quantidade, e.observacao, p.nome AS produto_nome, u.nome AS usuario_nome
        FROM entradas_estoque e
        LEFT JOIN produtos p ON p.id = e.produto_id
        LEFT JOIN usuarios u ON u.id = e.usuario_id";
    $selectSaida = "SELECT s.criado_em AS data_mov, 'saida' AS tipo, s.quantidade, s.observacao, p.nome AS produto_nome, u.nome AS usuario_nome
        FROM saidas_estoque s
        LEFT JOIN produtos p ON p.id = s.produto_id
        LEFT JOIN usuarios u ON u.id = s.usuario_id";

    $parametros = [];
    $tipos = '';

    if ($filtroTipo === 'entrada') {
        $where = condicoesMovimentacao('e', $filtroUsuario, $filtroDe, $filtroAte, $parametros, $tipos);
        $sql = "$selectEntrada $where ORDER BY data_mov DESC";
    } elseif ($filtroTipo === 'saida') {
        $where = condicoesMovimentacao('s', $filtroUsuario, $filtroDe, $filtroAte, $parametros, $tipos);
        $sql = "$selectSaida $where ORDER BY data_mov DESC";
    } else {
        $paramsE = []; $tiposE = '';
        $whereE = condicoesMovimentacao('e', $filtroUsuario, $filtroDe, $filtroAte, $paramsE, $tiposE);
        $paramsS = []; $tiposS = '';
        $whereS = condicoesMovimentacao('s', $filtroUsuario, $filtroDe, $filtroAte, $paramsS, $tiposS);
        $sql = "$selectEntrada $whereE UNION ALL $selectSaida $whereS ORDER BY data_mov DESC";
        $parametros = array_merge($paramsE, $paramsS);
        $tipos = $tiposE . $tiposS;
    }

    $stmt = $conn->prepare($sql);
    if ($parametros) $stmt->bind_param($tipos, ...$parametros);
    $stmt->execute();
    $linhas = [];
    $r = $stmt->get_result();
    while ($row = $r->fetch_assoc()) $linhas[] = $row;
    return $linhas;
}

$modo = ($_GET['modo'] ?? '') === 'produto' ? 'produto' : 'movimentacao';
$filtroTipo = $_GET['tipo'] ?? '';
if (!in_array($filtroTipo, ['entrada', 'saida'], true)) $filtroTipo = '';
$filtroUsuario = (int)($_GET['usuario_id'] ?? 0);
$filtroDe = trim($_GET['data_de'] ?? '');
$filtroAte = trim($_GET['data_ate'] ?? '');

if ($modo === 'produto') {
    $resumoProdutos = montarResumoPorProduto($conn, $filtroDe, $filtroAte);
} else {
    $movimentacoes = montarConsultaMovimentacoes($conn, $filtroTipo, $filtroUsuario, $filtroDe, $filtroAte);
}

if ($modo === 'movimentacao' && ($_GET['export'] ?? '') === 'csv') {
    $nomeArquivo = ($filtroTipo ?: 'movimentacoes') . '_' . date('Y-m-d') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $nomeArquivo . '"');
    $saida = fopen('php://output', 'w');
    fwrite($saida, "\xEF\xBB\xBF");
    fputcsv($saida, ['Data/Hora', 'Tipo', 'Produto', 'Quantidade', 'Responsável', 'Observação']);
    foreach ($movimentacoes as $m) {
        fputcsv($saida, [
            date('d/m/Y H:i', strtotime($m['data_mov'])),
            $m['tipo'] === 'entrada' ? 'Entrada' : 'Saída',
            $m['produto_nome'],
            $m['quantidade'],
            $m['usuario_nome'],
            $m['observacao'],
        ]);
    }
    fclose($saida);
    exit;
}

$usuarios = [];
$r = $conn->query('SELECT id, nome FROM usuarios ORDER BY nome');
while ($row = $r->fetch_assoc()) $usuarios[] = $row;

$queryStringSemExport = http_build_query(array_filter([
    'tipo' => $filtroTipo,
    'usuario_id' => $filtroUsuario ?: null,
    'data_de' => $filtroDe,
    'data_ate' => $filtroAte,
]));
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>WareSys | Relatórios</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<aside class="sidebar">
    <div class="logo">
        <div class="logo-icon">S</div>
        <div><h1>Ware<span>Sys</span></h1><p>Controle Inteligente</p></div>
    </div>
    <nav>
        <a href="index.php#dashboard"><span>⌂</span>Dashboard</a>
        <a href="index.php#produtos"><span>📦</span>Produtos</a>
        <a href="index.php#entradas"><span>↓</span>Entradas</a>
        <a href="index.php#saidas"><span>↑</span>Saídas</a>
        <a href="index.php#historico"><span>📊</span>Histórico</a>
        <a href="relatorios.php" class="ativo"><span>📄</span>Relatórios</a>
        <?php if (usuarioTemPermissao('config.acessar')): ?>
        <a href="configuracoes.php"><span>⚙</span>Configurações</a>
        <?php endif; ?>
    </nav>
    <div class="sidebar-bottom"><span class="versao">Versão acadêmica • MySQL / XAMPP</span></div>
</aside>

<main>
    <header class="topo">
        <div><p class="bem-vindo">Relatórios de</p><h2>Entradas e Saídas de Estoque</h2></div>
        <div class="perfil">
            <div class="avatar"><?= htmlspecialchars(strtoupper(substr($_SESSION['usuario_nome'] ?? 'U', 0, 1))) ?></div>
            <div><strong><?= htmlspecialchars($_SESSION['usuario_nome'] ?? 'Usuário') ?></strong><p><span class="papel-badge papel-<?= htmlspecialchars(papelAtual()) ?>"><?= htmlspecialchars(nomePapel(papelAtual())) ?></span></p></div>
            <a href="logout.php" class="btn btn-sair">Sair</a>
        </div>
    </header>

    <div class="tabs-historico tabs-relatorio">
        <a href="relatorios.php?modo=movimentacao&data_de=<?= htmlspecialchars($filtroDe) ?>&data_ate=<?= htmlspecialchars($filtroAte) ?>" class="tab-btn <?= $modo === 'movimentacao' ? 'ativo' : '' ?>">Por Movimentação</a>
        <a href="relatorios.php?modo=produto&data_de=<?= htmlspecialchars($filtroDe) ?>&data_ate=<?= htmlspecialchars($filtroAte) ?>" class="tab-btn <?= $modo === 'produto' ? 'ativo' : '' ?>">Por Produto no Período</a>
    </div>

    <?php if ($modo === 'movimentacao'): ?>
    <section class="painel">
        <div class="painel-topo">
            <div><h2>Movimentações de Estoque</h2><p>Cada linha é uma movimentação individual — consulte e exporte por período, usuário e tipo.</p></div>
        </div>
        <form method="get" class="form-filtro-log">
            <input type="hidden" name="modo" value="movimentacao">
            <div class="campo">
                <label for="tipo">Tipo</label>
                <select id="tipo" name="tipo">
                    <option value="">Entrada e Saída</option>
                    <option value="entrada" <?= $filtroTipo === 'entrada' ? 'selected' : '' ?>>Somente Entradas</option>
                    <option value="saida" <?= $filtroTipo === 'saida' ? 'selected' : '' ?>>Somente Saídas</option>
                </select>
            </div>
            <div class="campo">
                <label for="usuario_id">Responsável</label>
                <select id="usuario_id" name="usuario_id">
                    <option value="0">Todos</option>
                    <?php foreach ($usuarios as $u): ?>
                        <option value="<?= (int)$u['id'] ?>" <?= $filtroUsuario === (int)$u['id'] ? 'selected' : '' ?>><?= htmlspecialchars($u['nome']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="campo">
                <label for="data_de">De</label>
                <input type="date" id="data_de" name="data_de" value="<?= htmlspecialchars($filtroDe) ?>">
            </div>
            <div class="campo">
                <label for="data_ate">Até</label>
                <input type="date" id="data_ate" name="data_ate" value="<?= htmlspecialchars($filtroAte) ?>">
            </div>
            <div class="campo campo-botao">
                <button type="submit" class="btn-salvar">Filtrar</button>
                <a href="relatorios.php" class="btn-cancelar">Limpar</a>
                <a href="relatorios.php?<?= $queryStringSemExport ?><?= $queryStringSemExport ? '&' : '' ?>export=csv" class="btn btn-principal">⬇ Exportar CSV</a>
            </div>
        </form>
        <div class="tabela-container">
            <table>
                <thead><tr><th>Data</th><th>Produto</th><th>Tipo</th><th>Quantidade</th><th>Responsável</th><th>Observação</th></tr></thead>
                <tbody>
                <?php if (!$movimentacoes): ?>
                    <tr><td colspan="6" class="vazio">Nenhuma movimentação encontrada para os filtros selecionados.</td></tr>
                <?php else: foreach ($movimentacoes as $m): ?>
                    <tr>
                        <td><?= date('d/m/Y H:i', strtotime($m['data_mov'])) ?></td>
                        <td><?= htmlspecialchars($m['produto_nome']) ?></td>
                        <td><span class="badge-tipo <?= $m['tipo'] ?>"><?= strtoupper($m['tipo']) ?></span></td>
                        <td><?= (int)$m['quantidade'] ?></td>
                        <td><?= htmlspecialchars($m['usuario_nome'] ?: '—') ?></td>
                        <td><?= htmlspecialchars($m['observacao'] ?: '—') ?></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <p class="total-relatorio"><?= count($movimentacoes) ?> registro(s) encontrado(s).</p>
    </section>
    <?php else: ?>
    <section class="painel">
        <div class="painel-topo">
            <div><h2>Resumo por Produto no Período</h2><p>Visão agregada — diferente do relatório "Por Movimentação": aqui cada produto aparece <strong>uma única vez</strong>, somando tudo que entrou e tudo que saiu no intervalo escolhido.</p></div>
        </div>
        <form method="get" class="form-filtro-log">
            <input type="hidden" name="modo" value="produto">
            <div class="campo">
                <label for="data_de_p">De</label>
                <input type="date" id="data_de_p" name="data_de" value="<?= htmlspecialchars($filtroDe) ?>">
            </div>
            <div class="campo">
                <label for="data_ate_p">Até</label>
                <input type="date" id="data_ate_p" name="data_ate" value="<?= htmlspecialchars($filtroAte) ?>">
            </div>
            <div class="campo campo-botao">
                <button type="submit" class="btn-salvar">Filtrar</button>
                <a href="relatorios.php?modo=produto" class="btn-cancelar">Limpar</a>
            </div>
        </form>
        <?php if (!$filtroDe && !$filtroAte): ?>
            <p class="aviso-periodo">Nenhum período selecionado — mostrando o total histórico (desde sempre) de cada produto.</p>
        <?php endif; ?>
        <div class="tabela-container">
            <table>
                <thead><tr><th>Produto</th><th>Total Entrada</th><th>Total Saída</th><th>Saldo do período</th></tr></thead>
                <tbody>
                <?php if (!$resumoProdutos): ?>
                    <tr><td colspan="4" class="vazio">Nenhum produto teve entrada ou saída no período selecionado.</td></tr>
                <?php else: foreach ($resumoProdutos as $rp): $saldo=(int)$rp['total_entrada']-(int)$rp['total_saida']; ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($rp['nome']) ?></strong></td>
                        <td><span class="badge-tipo entrada">+<?= (int)$rp['total_entrada'] ?></span></td>
                        <td><span class="badge-tipo saida">-<?= (int)$rp['total_saida'] ?></span></td>
                        <td><?= $saldo > 0 ? '+' : '' ?><?= $saldo ?></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <p class="total-relatorio"><?= count($resumoProdutos) ?> produto(s) com movimentação no período.</p>
    </section>
    <?php endif; ?>

    <footer><p>WareSys © 2026 — Sistema acadêmico de Controle de Almoxarifado</p></footer>
</main>
</body>
</html>
