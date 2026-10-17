<?php
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/permissoes.php';

$usuarioId = (int)$_SESSION['usuario_id'];

$stmt = $conn->prepare('
    SELECT l.id, l.titulo, l.isbn, e.nome AS etapa_nome, e.rodadas_incluidas,
           m.rodada, m.excedente, m.data_entrada
    FROM livros l
    JOIN etapas e ON e.id = l.etapa_atual_id
    JOIN movimentacoes m ON m.livro_id = l.id AND m.data_saida IS NULL
    WHERE l.responsavel_atual_id = ? AND l.status = \'em_andamento\'
    ORDER BY m.data_entrada ASC
');
$stmt->bind_param('i', $usuarioId);
$stmt->execute();
$livros = [];
$r = $stmt->get_result();
$agora = new DateTime();
while ($row = $r->fetch_assoc()) {
    $row['dias_parado'] = $agora->diff(new DateTime($row['data_entrada']))->days;
    $livros[] = $row;
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
?>
<!DOCTYPE html>
<html lang="pt-br" data-theme="<?= htmlspecialchars($_SESSION['usuario_tema'] ?? 'claro') ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>WareSys | Minha mesa</title>
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
        <a href="minha_mesa.php" class="ativo"><span>🗄</span>Minha mesa</a>
        <a href="livros.php"><span>📚</span>Livros</a>
        <a href="pessoas.php"><span>👤</span>Pessoas</a>
        <a href="relatorios.php"><span>📄</span>Relatórios</a>
        <a href="configuracoes.php"><span>⚙</span>Configurações</a>
    </nav>
    <div class="sidebar-bottom"><span class="versao">Versão acadêmica • MySQL / XAMPP</span></div>
</aside>

<main>
    <header class="topo">
        <div><p class="bem-vindo">Livros com</p><h2>Minha mesa</h2></div>
        <div class="perfil">
            <div class="avatar"><?= htmlspecialchars(strtoupper(substr($_SESSION['usuario_nome'] ?? 'U', 0, 1))) ?></div>
            <div><strong><?= htmlspecialchars($_SESSION['usuario_nome'] ?? 'Usuário') ?></strong><p><span class="papel-badge papel-<?= htmlspecialchars(papelAtual()) ?>"><?= htmlspecialchars(nomePapel(papelAtual())) ?></span></p></div>
            <a href="logout.php" class="btn btn-sair">Sair</a>
        </div>
    </header>

    <?php if ($flash): ?>
        <div class="flash <?= htmlspecialchars($flash['type']) ?>"><?= htmlspecialchars($flash['message']) ?></div>
    <?php endif; ?>

    <section class="painel" id="minha-mesa">
        <div class="painel-topo">
            <div><h2>Livros na sua mesa</h2><p>Livros em andamento cujo responsável atual é você.</p></div>
        </div>
        <div class="tabela-container">
            <table>
                <thead><tr><th>Título</th><th>ISBN</th><th>Etapa atual</th><th>Rodada</th><th>Dias parado</th></tr></thead>
                <tbody>
                <?php if (!$livros): ?>
                    <tr><td colspan="5" class="vazio">Nenhum livro na sua mesa no momento.</td></tr>
                <?php else: foreach ($livros as $l):
                    $rodadaLabel = 'Rodada ' . (int)$l['rodada'];
                    if ($l['rodadas_incluidas'] !== null) {
                        $rodadaLabel .= ' de ' . (int)$l['rodadas_incluidas'];
                    }
                ?>
                    <tr>
                        <td class="livro-titulo">
                            <div class="livro-icon">📖</div>
                            <div><strong><a href="livro_detalhes.php?id=<?= (int)$l['id'] ?>"><?= htmlspecialchars($l['titulo']) ?></a></strong></div>
                        </td>
                        <td><?= htmlspecialchars($l['isbn'] ?: '—') ?></td>
                        <td><?= htmlspecialchars($l['etapa_nome']) ?></td>
                        <td><?= htmlspecialchars($rodadaLabel) ?><?php if ((int)$l['excedente'] === 1): ?> <span class="status baixo">Cobrável</span><?php endif; ?></td>
                        <td><?= (int)$l['dias_parado'] === 0 ? 'menos de 1 dia' : (int)$l['dias_parado'] . ' dia' . ((int)$l['dias_parado'] > 1 ? 's' : '') ?></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <footer><p>WareSys © 2026 — Sistema acadêmico de Controle Editorial</p></footer>
</main>
</body>
</html>
