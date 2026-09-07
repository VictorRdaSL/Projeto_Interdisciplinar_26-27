<?php
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/includes/permissoes.php';
require_once __DIR__ . '/includes/periodo.php';
require_once __DIR__ . '/config/database.php';

exigirPapel(['admin', 'gerente']);

function condicoesMovimentacao(string $alias, int $usuarioId, string $dataDe, string $dataAte, array &$parametros, string &$tipos): string
{
    $condicoes = condicaoPeriodo($alias, $dataDe, $dataAte, $parametros, $tipos);
    if ($usuarioId > 0) { $condicoes[] = "$alias.usuario_id = ?"; $parametros[] = $usuarioId; $tipos .= 'i'; }
    return $condicoes ? 'WHERE ' . implode(' AND ', $condicoes) : '';
}

function montarConsultaMovimentacoes(mysqli $conn, string $filtroTipo, int $filtroUsuario, string $filtroDe, string $filtroAte): array
{
    $selectEntrada = "SELECT e.criado_em AS data_mov, 'entrada' AS tipo, e.quantidade, e.observacao, e.produto_id AS produto_id, p.nome AS produto_nome, u.nome AS usuario_nome
        FROM entradas_estoque e
        LEFT JOIN produtos p ON p.id = e.produto_id
        LEFT JOIN usuarios u ON u.id = e.usuario_id";
    $selectSaida = "SELECT s.criado_em AS data_mov, 'saida' AS tipo, s.quantidade, s.observacao, s.produto_id AS produto_id, p.nome AS produto_nome, u.nome AS usuario_nome
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

/**
 * Agrupa por produto a mesma lista de movimentações individuais usada no
 * relatório "Por Movimentação" — evita duplicar a consulta SQL: os totais e
 * o detalhamento por produto vêm do mesmo conjunto de linhas já filtrado
 * por período/tipo.
 */
function agruparMovimentacoesPorProduto(array $movimentos): array
{
    $porProduto = [];
    foreach ($movimentos as $m) {
        $id = (int)$m['produto_id'];
        if (!isset($porProduto[$id])) {
            $porProduto[$id] = [
                'produto_id' => $id,
                'nome' => $m['produto_nome'],
                'total_entrada' => 0,
                'total_saida' => 0,
                'movimentos' => [],
            ];
        }
        if ($m['tipo'] === 'entrada') {
            $porProduto[$id]['total_entrada'] += (int)$m['quantidade'];
        } else {
            $porProduto[$id]['total_saida'] += (int)$m['quantidade'];
        }
        $porProduto[$id]['movimentos'][] = $m;
    }
    usort($porProduto, fn($a, $b) => strcmp($a['nome'], $b['nome']));
    return $porProduto;
}

$modo = ($_GET['modo'] ?? '') === 'produto' ? 'produto' : 'movimentacao';
$filtroTipo = $_GET['tipo'] ?? '';
if (!in_array($filtroTipo, ['entrada', 'saida'], true)) $filtroTipo = '';
$filtroUsuario = (int)($_GET['usuario_id'] ?? 0);
$filtroDe = trim($_GET['data_de'] ?? '');
$filtroAte = trim($_GET['data_ate'] ?? '');

$periodoCompleto = $filtroDe !== '' && $filtroAte !== '';

if ($modo === 'produto') {
    $resumoProdutos = $periodoCompleto
        ? agruparMovimentacoesPorProduto(montarConsultaMovimentacoes($conn, $filtroTipo, 0, $filtroDe, $filtroAte))
        : [];
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
<html lang="pt-br" data-theme="<?= htmlspecialchars($_SESSION['usuario_tema'] ?? 'claro') ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>WareSys | Relatórios</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="dark.css">
</head>
<body>
<aside class="sidebar">
    <div class="logo">
        <div class="logo-icon">S</div>
        <div><h1>Ware<span>Sys</span></h1><p>Controle Inteligente</p></div>
    </div>
    <nav>
        <a href="index.php#dashboard"><span>⌂</span>Dashboard</a>
        <a href="index.php#historico"><span>📊</span>Histórico</a>
        <a href="relatorios.php" class="ativo"><span>📄</span>Relatórios</a>
        <a href="configuracoes.php"><span>⚙</span>Configurações</a>
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
            <div><h2>Consulta por Produto num Intervalo</h2><p>Diferente do relatório "Por Movimentação" (que lista cada lançamento individualmente): aqui cada produto aparece <strong>uma única vez</strong>, com o total que entrou e o total que saiu dentro do intervalo de datas — e cada linha pode ser expandida para ver os lançamentos individuais daquele produto no período.</p></div>
        </div>
        <form method="get" class="form-filtro-log">
            <input type="hidden" name="modo" value="produto">
            <div class="campo">
                <label for="tipo_p">Tipo</label>
                <select id="tipo_p" name="tipo">
                    <option value="">Entrada e Saída</option>
                    <option value="entrada" <?= $filtroTipo === 'entrada' ? 'selected' : '' ?>>Somente Entradas</option>
                    <option value="saida" <?= $filtroTipo === 'saida' ? 'selected' : '' ?>>Somente Saídas</option>
                </select>
            </div>
            <div class="campo">
                <label for="data_de_p">Data inicial *</label>
                <input type="date" id="data_de_p" name="data_de" value="<?= htmlspecialchars($filtroDe) ?>" required>
            </div>
            <div class="campo">
                <label for="data_ate_p">Data final *</label>
                <input type="date" id="data_ate_p" name="data_ate" value="<?= htmlspecialchars($filtroAte) ?>" required>
            </div>
            <div class="campo campo-botao">
                <button type="submit" class="btn-salvar">Consultar</button>
                <a href="relatorios.php?modo=produto" class="btn-cancelar">Limpar</a>
            </div>
        </form>

        <?php if (!$periodoCompleto): ?>
            <p class="aviso-periodo">Informe a data inicial <strong>e</strong> a data final para consultar. Os dois campos são obrigatórios nesta visão.</p>
        <?php else: ?>
            <p class="aviso-periodo aviso-periodo-ok">Período consultado: <strong><?= date('d/m/Y', strtotime($filtroDe)) ?></strong> até <strong><?= date('d/m/Y', strtotime($filtroAte)) ?></strong> (intervalo completo, incluindo as duas datas de ponta).</p>
            <div class="tabela-container">
                <table>
                    <thead>
                        <tr>
                            <th>Produto</th>
                            <?php if ($filtroTipo !== 'saida'): ?><th>Total Entrada</th><?php endif; ?>
                            <?php if ($filtroTipo !== 'entrada'): ?><th>Total Saída</th><?php endif; ?>
                            <?php if ($filtroTipo === ''): ?><th>Saldo do período</th><?php endif; ?>
                            <th>Lançamentos</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$resumoProdutos): ?>
                        <tr><td colspan="5" class="vazio">Nenhum produto teve movimentação nesse período.</td></tr>
                    <?php else: foreach ($resumoProdutos as $rp): $saldo=(int)$rp['total_entrada']-(int)$rp['total_saida']; ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($rp['nome']) ?></strong></td>
                            <?php if ($filtroTipo !== 'saida'): ?><td><span class="badge-tipo entrada">+<?= (int)$rp['total_entrada'] ?></span></td><?php endif; ?>
                            <?php if ($filtroTipo !== 'entrada'): ?><td><span class="badge-tipo saida">-<?= (int)$rp['total_saida'] ?></span></td><?php endif; ?>
                            <?php if ($filtroTipo === ''): ?><td><?= $saldo > 0 ? '+' : '' ?><?= $saldo ?></td><?php endif; ?>
                            <td>
                                <details class="detalhe-produto">
                                    <summary>Ver <?= count($rp['movimentos']) ?> lançamento(s)</summary>
                                    <table class="tabela-detalhe">
                                        <thead><tr><th>Data/Hora</th><th>Tipo</th><th>Quantidade</th><th>Responsável</th></tr></thead>
                                        <tbody>
                                        <?php foreach ($rp['movimentos'] as $mov): ?>
                                            <tr>
                                                <td><?= date('d/m/Y H:i', strtotime($mov['data_mov'])) ?></td>
                                                <td><span class="badge-tipo <?= $mov['tipo'] ?>"><?= strtoupper($mov['tipo']) ?></span></td>
                                                <td><?= (int)$mov['quantidade'] ?></td>
                                                <td><?= htmlspecialchars($mov['usuario_nome'] ?: '—') ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </details>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
            <p class="total-relatorio"><?= count($resumoProdutos) ?> produto(s) com movimentação no período.</p>
        <?php endif; ?>
    </section>
    <?php endif; ?>

    <footer><p>WareSys © 2026 — Sistema acadêmico de Controle de Almoxarifado</p></footer>
</main>
</body>
</html>
