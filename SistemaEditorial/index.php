<?php
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/permissoes.php';
require_once __DIR__ . '/includes/alertas.php';

// Dashboard mínimo pós-pivô editorial — a reconstrução completa (gargalos)
// está indo aos poucos: Passo 10 trouxe o alerta de livro parado.
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$totalLivros = (int)$conn->query('SELECT COUNT(*) total FROM livros')->fetch_assoc()['total'];
$livrosEmAndamento = (int)$conn->query("SELECT COUNT(*) total FROM livros WHERE status = 'em_andamento'")->fetch_assoc()['total'];
$livrosConcluidos = (int)$conn->query("SELECT COUNT(*) total FROM livros WHERE status = 'concluido'")->fetch_assoc()['total'];
$totalPessoas = (int)$conn->query('SELECT COUNT(*) total FROM pessoas')->fetch_assoc()['total'];
$livrosParados = buscarLivrosParados($conn);
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

    <section class="cards">
        <div class="card"><div><p>Total de Livros</p><h3><?= $totalLivros ?></h3><span class="positivo">cadastrados</span></div><div class="card-icon">📚</div></div>
        <div class="card"><div><p>Em Andamento</p><h3><?= $livrosEmAndamento ?></h3><span class="positivo">no fluxo editorial</span></div><div class="card-icon">🔄</div></div>
        <div class="card"><div><p>Concluídos</p><h3><?= $livrosConcluidos ?></h3><span class="positivo">finalizados</span></div><div class="card-icon">✓</div></div>
        <div class="card"><div><p>Pessoas Cadastradas</p><h3><?= $totalPessoas ?></h3><span class="positivo">autores, organizadores, ilustradores</span></div><div class="card-icon">👤</div></div>
        <div class="card"><div><p>Livros Parados</p><h3><?= count($livrosParados) ?></h3><span class="<?= $livrosParados ? 'alerta' : 'positivo' ?>"><?= $livrosParados ? 'precisam de atenção' : 'tudo em dia' ?></span></div><div class="card-icon">⚠️</div></div>
    </section>

    <section class="acoes">
        <?php if (usuarioTemPermissao('livros.gerenciar')): ?>
        <a href="livro_form.php" class="btn btn-principal">+ Novo Livro</a>
        <?php endif; ?>
        <a href="livros.php" class="btn">📚 Ver todos os livros</a>
        <?php if (usuarioTemPermissao('pessoas.gerenciar')): ?>
        <a href="pessoas.php" class="btn">+ Nova Pessoa</a>
        <?php endif; ?>
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

    <footer><p>WareSys © 2026 — Sistema acadêmico de Controle Editorial</p></footer>
</main>
</body>
</html>
