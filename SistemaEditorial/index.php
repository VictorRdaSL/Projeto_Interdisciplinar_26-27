<?php
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/permissoes.php';
require_once __DIR__ . '/includes/alertas.php';

// Painel inicial pós-pivô editorial — Passo 11 refez o dashboard por completo:
// livros por etapa, parados, extraviados/resgate, rodada excedente em aberto,
// concluídos no mês, atalho pra Minha mesa e busca (que delega pra livros.php).
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$totalLivros = (int)$conn->query('SELECT COUNT(*) total FROM livros')->fetch_assoc()['total'];
$livrosEmAndamento = (int)$conn->query("SELECT COUNT(*) total FROM livros WHERE status = 'em_andamento'")->fetch_assoc()['total'];
$livrosConcluidos = (int)$conn->query("SELECT COUNT(*) total FROM livros WHERE status = 'concluido'")->fetch_assoc()['total'];
$totalPessoas = (int)$conn->query('SELECT COUNT(*) total FROM pessoas')->fetch_assoc()['total'];
$livrosParados = buscarLivrosParados($conn);

// Limites do mês calculados em PHP (fuso America/Sao_Paulo já fixo em
// config/database.php) — nunca com MONTH()/NOW() do MySQL direto, mesma
// cautela de fuso já documentada em outras telas.
$inicioMes = (new DateTime('first day of this month 00:00:00'))->format('Y-m-d H:i:s');
$inicioProximoMes = (new DateTime('first day of next month 00:00:00'))->format('Y-m-d H:i:s');
$stmtMes = $conn->prepare("SELECT COUNT(*) total FROM livros WHERE status = 'concluido' AND atualizado_em >= ? AND atualizado_em < ?");
$stmtMes->bind_param('ss', $inicioMes, $inicioProximoMes);
$stmtMes->execute();
$livrosConcluidosNoMes = (int)$stmtMes->get_result()->fetch_assoc()['total'];

// Livros por etapa, na ordem do fluxo — só livros em andamento (extraviados
// aparecem na seção própria, concluídos/cancelados não "estão" em etapa nenhuma).
$livrosPorEtapa = [];
$maxPorEtapa = 1;
$r = $conn->query("
    SELECT e.id, e.nome, e.ordem, e.opcional, COUNT(l.id) AS total
    FROM etapas e
    LEFT JOIN livros l ON l.etapa_atual_id = e.id AND l.status = 'em_andamento'
    WHERE e.ativa = 1
    GROUP BY e.id, e.nome, e.ordem, e.opcional
    ORDER BY e.ordem
");
while ($row = $r->fetch_assoc()) {
    $row['total'] = (int)$row['total'];
    $livrosPorEtapa[] = $row;
    if ($row['total'] > $maxPorEtapa) $maxPorEtapa = $row['total'];
}

// Livros extraviados, com resgate em aberto.
$livrosExtraviados = [];
$r = $conn->query("
    SELECT l.id, l.titulo, e.nome AS ultima_etapa_nome, r.data_abertura
    FROM livros l
    JOIN resgates r ON r.livro_id = l.id AND r.data_conclusao IS NULL
    LEFT JOIN movimentacoes m ON m.id = r.movimentacao_referencia_id
    LEFT JOIN etapas e ON e.id = m.etapa_id
    WHERE l.status = 'extraviado'
    ORDER BY r.data_abertura ASC
");
$agora = new DateTime();
while ($row = $r->fetch_assoc()) {
    $row['dias_perdido'] = $agora->diff(new DateTime($row['data_abertura']))->days;
    $livrosExtraviados[] = $row;
}

// Livros com rodada excedente em aberto — cobráveis agora (só Diagramação/
// Revisão têm rodadas_incluidas definido, então só elas podem ter excedente=1).
$livrosExcedentes = [];
$r = $conn->query("
    SELECT l.id, l.titulo, e.nome AS etapa_nome, m.rodada, e.rodadas_incluidas, u.nome AS responsavel_nome
    FROM livros l
    JOIN movimentacoes m ON m.livro_id = l.id AND m.data_saida IS NULL AND m.excedente = 1
    JOIN etapas e ON e.id = m.etapa_id
    LEFT JOIN usuarios u ON u.id = l.responsavel_atual_id
    WHERE l.status NOT IN ('concluido', 'cancelado')
    ORDER BY m.rodada DESC
");
while ($row = $r->fetch_assoc()) $livrosExcedentes[] = $row;
?>
<!DOCTYPE html>
<html lang="pt-br" data-theme="<?= htmlspecialchars($_SESSION['usuario_tema'] ?? 'claro') ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>WareSys | Controle Editorial</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="dark.css">
</head>
<body>
<aside class="sidebar">
    <div class="logo">
        <div class="logo-icon">S</div>
        <div><h1>Ware<span>Sys</span></h1><p>Controle Editorial</p></div>
    </div>
    <nav>
        <a href="index.php" class="ativo"><span>⌂</span>Painel</a>
        <a href="minha_mesa.php"><span>🗄</span>Minha mesa</a>
        <a href="livros.php"><span>📚</span>Livros</a>
        <a href="pessoas.php"><span>👤</span>Pessoas</a>
        <span class="nav-desabilitado"><span>📄</span>Relatórios<span class="nav-tag">em breve</span></span>
        <a href="configuracoes.php"><span>⚙</span>Configurações</a>
    </nav>
    <div class="sidebar-bottom"><span class="versao">Versão acadêmica • MySQL / XAMPP</span></div>
</aside>

<main id="dashboard">
    <header class="topo">
        <div><p class="bem-vindo">Bem-vindo ao</p><h2>Controle Editorial</h2></div>
        <div class="perfil">
            <div class="avatar"><?= htmlspecialchars(strtoupper(substr($_SESSION['usuario_nome'] ?? 'U', 0, 1))) ?></div>
            <div><strong><?= htmlspecialchars($_SESSION['usuario_nome'] ?? 'Usuário') ?></strong><p><span class="papel-badge papel-<?= htmlspecialchars(papelAtual()) ?>"><?= htmlspecialchars(nomePapel(papelAtual())) ?></span></p></div>
            <a href="logout.php" class="btn btn-sair">Sair</a>
        </div>
    </header>

    <?php if ($flash): ?>
        <div class="flash <?= htmlspecialchars($flash['type']) ?>"><?= htmlspecialchars($flash['message']) ?></div>
    <?php endif; ?>

    <section class="painel">
        <div class="painel-topo">
            <div><h2>Buscar livro</h2><p>Por título, pessoa vinculada ou ISBN — abre a lista de livros já filtrada.</p></div>
        </div>
        <form method="get" action="livros.php" style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;">
            <div class="pesquisa" style="flex:1;min-width:220px;"><span>🔍</span><input type="text" name="busca" placeholder="Pesquisar livro..."></div>
            <button type="submit" class="btn btn-principal">Buscar</button>
        </form>
    </section>

    <section class="acoes">
        <?php if (usuarioTemPermissao('livros.gerenciar')): ?>
        <a href="livro_form.php" class="btn btn-principal">+ Novo Livro</a>
        <?php endif; ?>
        <a href="livros.php" class="btn">📚 Ver todos os livros</a>
        <a href="minha_mesa.php" class="btn">🗄 Minha mesa</a>
        <?php if (usuarioTemPermissao('pessoas.gerenciar')): ?>
        <a href="pessoas.php" class="btn">+ Nova Pessoa</a>
        <?php endif; ?>
    </section>

    <section class="cards">
        <div class="card"><div><p>Total de Livros</p><h3><?= $totalLivros ?></h3><span class="positivo">cadastrados</span></div><div class="card-icon">📚</div></div>
        <div class="card"><div><p>Em Andamento</p><h3><?= $livrosEmAndamento ?></h3><span class="positivo">no fluxo editorial</span></div><div class="card-icon">🔄</div></div>
        <div class="card"><div><p>Concluídos</p><h3><?= $livrosConcluidos ?></h3><span class="positivo">finalizados</span></div><div class="card-icon">✓</div></div>
        <div class="card"><div><p>Concluídos no Mês</p><h3><?= $livrosConcluidosNoMes ?></h3><span class="positivo">depósito legal feito</span></div><div class="card-icon">📅</div></div>
        <div class="card"><div><p>Pessoas Cadastradas</p><h3><?= $totalPessoas ?></h3><span class="positivo">autores, organizadores, ilustradores</span></div><div class="card-icon">👤</div></div>
        <div class="card"><div><p>Livros Parados</p><h3><?= count($livrosParados) ?></h3><span class="<?= $livrosParados ? 'alerta' : 'positivo' ?>"><?= $livrosParados ? 'precisam de atenção' : 'tudo em dia' ?></span></div><div class="card-icon">⚠️</div></div>
    </section>

    <section class="painel">
        <div class="painel-topo">
            <div><h2>Livros por etapa</h2><p>Quantidade de livros em andamento em cada etapa do fluxo, na ordem em que acontecem.</p></div>
        </div>
        <div class="tabela-container">
            <table>
                <thead><tr><th>Etapa</th><th>Livros em andamento</th></tr></thead>
                <tbody>
                <?php foreach ($livrosPorEtapa as $ep):
                    $larguraBarra = $ep['total'] > 0 ? max(4, round($ep['total'] / $maxPorEtapa * 100)) : 0;
                ?>
                    <tr>
                        <td><?= htmlspecialchars($ep['nome']) ?><?php if ((int)$ep['opcional'] === 1): ?> <small style="color:#8a9b91;">(opcional)</small><?php endif; ?></td>
                        <td style="min-width:220px;">
                            <div style="display:flex;align-items:center;gap:10px;">
                                <div style="background:#eef2ef;border-radius:999px;height:10px;flex:1;overflow:hidden;">
                                    <div style="background:var(--verde-500);height:100%;width:<?= $larguraBarra ?>%;"></div>
                                </div>
                                <strong style="min-width:18px;text-align:right;"><?= $ep['total'] ?></strong>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <?php if ($livrosParados): ?>
    <section class="painel">
        <div class="painel-topo">
            <div><h2>⚠️ Livros parados</h2><p>Movimentação atual aberta além do limite de dias da etapa (ou do padrão global, em Configurações, quando a etapa não tem um limite próprio).</p></div>
        </div>
        <div class="tabela-container">
            <table>
                <thead><tr><th>Livro</th><th>Etapa</th><th>Rodada</th><th>Mesa</th><th>Dias parado</th></tr></thead>
                <tbody>
                <?php foreach ($livrosParados as $lp):
                    $rodadaLabel = 'Rodada ' . (int)$lp['rodada'];
                    if ($lp['rodadas_incluidas'] !== null) $rodadaLabel .= ' de ' . (int)$lp['rodadas_incluidas'];
                ?>
                    <tr>
                        <td><a href="livro_detalhes.php?id=<?= (int)$lp['id'] ?>"><?= htmlspecialchars($lp['titulo']) ?></a></td>
                        <td><?= htmlspecialchars($lp['etapa_nome']) ?></td>
                        <td><?= htmlspecialchars($rodadaLabel) ?><?php if ((int)$lp['excedente'] === 1): ?> <span class="status baixo">Cobrável</span><?php endif; ?></td>
                        <td><?= htmlspecialchars($lp['responsavel_nome'] ?: '—') ?></td>
                        <td><span class="alerta"><?= (int)$lp['dias_parado'] ?> dia<?= (int)$lp['dias_parado'] > 1 ? 's' : '' ?> <small>(limite: <?= (int)$lp['limite_aplicado'] ?>)</small></span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
    <?php endif; ?>

    <?php if ($livrosExtraviados): ?>
    <section class="painel">
        <div class="painel-topo">
            <div><h2>🚨 Extraviados / em resgate</h2><p>Livros com resgate em aberto, a partir da última movimentação conhecida.</p></div>
        </div>
        <div class="tabela-container">
            <table>
                <thead><tr><th>Livro</th><th>Última etapa conhecida</th><th>Perdido há</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($livrosExtraviados as $le): ?>
                    <tr>
                        <td><a href="livro_detalhes.php?id=<?= (int)$le['id'] ?>"><?= htmlspecialchars($le['titulo']) ?></a></td>
                        <td><?= htmlspecialchars($le['ultima_etapa_nome'] ?: '—') ?></td>
                        <td><span class="status resgate"><?= (int)$le['dias_perdido'] ?> dia<?= (int)$le['dias_perdido'] > 1 ? 's' : '' ?></span></td>
                        <td><a href="resgate.php?id=<?= (int)$le['id'] ?>" class="btn">Ver resgate</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
    <?php endif; ?>

    <?php if ($livrosExcedentes): ?>
    <section class="painel">
        <div class="painel-topo">
            <div><h2>💰 Rodada excedente em aberto</h2><p>Livros agora numa rodada cobrável de Diagramação ou Revisão.</p></div>
        </div>
        <div class="tabela-container">
            <table>
                <thead><tr><th>Livro</th><th>Etapa</th><th>Rodada</th><th>Mesa</th></tr></thead>
                <tbody>
                <?php foreach ($livrosExcedentes as $lx):
                    $rodadaLabelX = 'Rodada ' . (int)$lx['rodada'];
                    if ($lx['rodadas_incluidas'] !== null) $rodadaLabelX .= ' de ' . (int)$lx['rodadas_incluidas'];
                ?>
                    <tr>
                        <td><a href="livro_detalhes.php?id=<?= (int)$lx['id'] ?>"><?= htmlspecialchars($lx['titulo']) ?></a></td>
                        <td><?= htmlspecialchars($lx['etapa_nome']) ?></td>
                        <td><?= htmlspecialchars($rodadaLabelX) ?> <span class="status baixo">Cobrável</span></td>
                        <td><?= htmlspecialchars($lx['responsavel_nome'] ?: '—') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
    <?php endif; ?>

    <footer><p>WareSys © 2026 — Sistema acadêmico de Controle Editorial</p></footer>
</main>
</body>
</html>
