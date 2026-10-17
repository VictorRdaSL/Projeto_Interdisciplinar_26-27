<?php
/**
 * Moldura HTML (menu lateral, topo, flash, rodapé) das páginas de relatório.
 * Cada relatorio_*.php chama relatorioAbrir() antes do conteúdo e
 * relatorioFechar() depois. Nada pode ser impresso antes de um exportarCsv().
 */
function relatorioAbrir(string $titulo, string $subtitulo): void
{
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    ?>
<!DOCTYPE html>
<html lang="pt-br" data-theme="<?= htmlspecialchars($_SESSION['usuario_tema'] ?? 'claro') ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>WareSys | <?= htmlspecialchars($titulo) ?></title>
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
        <a href="index.php"><span>⌂</span>Painel</a>
        <a href="minha_mesa.php"><span>🗄</span>Minha mesa</a>
        <a href="livros.php"><span>📚</span>Livros</a>
        <a href="pessoas.php"><span>👤</span>Pessoas</a>
        <a href="relatorios.php" class="ativo"><span>📄</span>Relatórios</a>
        <a href="configuracoes.php"><span>⚙</span>Configurações</a>
    </nav>
    <div class="sidebar-bottom"><span class="versao">Versão acadêmica • MySQL / XAMPP</span></div>
</aside>

<main>
    <header class="topo">
        <div><p class="bem-vindo"><?= htmlspecialchars($subtitulo) ?></p><h2><?= htmlspecialchars($titulo) ?></h2></div>
        <div class="perfil">
            <div class="avatar"><?= htmlspecialchars(strtoupper(substr($_SESSION['usuario_nome'] ?? 'U', 0, 1))) ?></div>
            <div><strong><?= htmlspecialchars($_SESSION['usuario_nome'] ?? 'Usuário') ?></strong><p><span class="papel-badge papel-<?= htmlspecialchars(papelAtual()) ?>"><?= htmlspecialchars(nomePapel(papelAtual())) ?></span></p></div>
            <a href="logout.php" class="btn btn-sair">Sair</a>
        </div>
    </header>

    <?php if ($flash): ?>
        <div class="flash <?= htmlspecialchars($flash['type']) ?>"><?= htmlspecialchars($flash['message']) ?></div>
    <?php endif; ?>
<?php
}

function relatorioFechar(): void
{
    ?>
    <footer><p>WareSys © 2026 — Sistema acadêmico de Controle Editorial</p></footer>
</main>
</body>
</html>
<?php
}

/** Campos De/Até do filtro de período, para dentro de um <form method="get">. */
function relatorioCamposPeriodo(string $de, string $ate): void
{
    ?>
            <div class="campo">
                <label for="filtro_de">De</label>
                <input type="date" id="filtro_de" name="filtro_de" value="<?= htmlspecialchars($de) ?>">
            </div>
            <div class="campo">
                <label for="filtro_ate">Até</label>
                <input type="date" id="filtro_ate" name="filtro_ate" value="<?= htmlspecialchars($ate) ?>">
            </div>
<?php
}

/** Botões Filtrar / Limpar / Exportar CSV (o CSV leva os mesmos filtros da tela). */
function relatorioBotoes(string $arquivo, string $queryFiltros, bool $haErro = false, array $extrasCsv = []): void
{
    ?>
            <div class="campo campo-botao">
                <button type="submit" class="btn-salvar">Filtrar</button>
                <a href="<?= htmlspecialchars($arquivo) ?>" class="btn-cancelar">Limpar</a>
                <?php if (!$haErro): ?>
                    <?php if (!$extrasCsv): ?>
                        <a href="<?= htmlspecialchars($arquivo . '?' . ($queryFiltros !== '' ? $queryFiltros . '&' : '') . 'export=csv') ?>" class="btn">⬇ Exportar CSV</a>
                    <?php else: foreach ($extrasCsv as $rotulo => $valor): ?>
                        <a href="<?= htmlspecialchars($arquivo . '?' . ($queryFiltros !== '' ? $queryFiltros . '&' : '') . 'export=' . $valor) ?>" class="btn">⬇ <?= htmlspecialchars($rotulo) ?></a>
                    <?php endforeach; endif; ?>
                <?php endif; ?>
            </div>
<?php
}
