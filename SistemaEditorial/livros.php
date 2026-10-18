<?php
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/permissoes.php';
require_once __DIR__ . '/includes/isbn.php';

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$podeGerenciar = usuarioTemPermissao('livros.gerenciar');
// Permite chegar com a busca já preenchida (ex.: a partir do painel) — o
// filtro em si continua sendo client-side, isso só define o valor inicial.
$termoBuscaInicial = trim($_GET['busca'] ?? '');

// GROUP_CONCAT é específico do MySQL/MariaDB (não é SQL padrão) — usado aqui
// só para exibição/busca na listagem, não faz parte do schema em si.
// mAtual é a movimentação aberta (0 ou 1 por livro, nunca mais que isso) — não
// infla o GROUP_CONCAT de pessoas, que vem de um JOIN N:N separado.
$sql = "
    SELECT l.id, l.titulo, l.isbn, l.status, l.tem_copia,
           e.nome AS etapa_nome, u.nome AS responsavel_nome,
           GROUP_CONCAT(DISTINCT pe.nome ORDER BY pe.nome SEPARATOR ', ') AS pessoas_nomes,
           EXISTS(SELECT 1 FROM resgates r WHERE r.livro_id = l.id) AS ja_teve_resgate,
           mAtual.data_entrada AS etapa_desde
    FROM livros l
    LEFT JOIN etapas e ON e.id = l.etapa_atual_id
    LEFT JOIN usuarios u ON u.id = l.responsavel_atual_id
    LEFT JOIN contribuicoes c ON c.livro_id = l.id
    LEFT JOIN pessoas pe ON pe.id = c.pessoa_id
    LEFT JOIN movimentacoes mAtual ON mAtual.livro_id = l.id AND mAtual.data_saida IS NULL
    GROUP BY l.id
    ORDER BY l.titulo
";
$livros = [];
$r = $conn->query($sql);
while ($row = $r->fetch_assoc()) $livros[] = $row;

const NOMES_STATUS_LIVRO = [
    'em_andamento' => 'Em andamento',
    'concluido'    => 'Concluído',
    'extraviado'   => 'Extraviado',
    'cancelado'    => 'Cancelado',
];
function nomeStatusLivro(string $status): string
{
    return NOMES_STATUS_LIVRO[$status] ?? $status;
}
function classeStatusLivro(string $status): string
{
    return match ($status) {
        'concluido' => 'ok',
        'extraviado', 'cancelado' => 'atencao',
        default => '',
    };
}
?>
<!DOCTYPE html>
<html lang="pt-br" data-theme="<?= htmlspecialchars($_SESSION['usuario_tema'] ?? 'claro') ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>WareSys | Livros</title>
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
        <a href="livros.php" class="ativo"><span>📚</span>Livros</a>
        <a href="pessoas.php"><span>👤</span>Pessoas</a>
        <a href="relatorios.php"><span>📄</span>Relatórios</a>
        <a href="configuracoes.php"><span>⚙</span>Configurações</a>
    </nav>
    <div class="sidebar-bottom"><span class="versao">Versão acadêmica • MySQL / XAMPP</span></div>
</aside>

<main>
    <header class="topo">
        <div><p class="bem-vindo">Fluxo editorial</p><h2>Livros</h2></div>
        <div class="perfil">
            <div class="avatar"><?= htmlspecialchars(strtoupper(substr($_SESSION['usuario_nome'] ?? 'U', 0, 1))) ?></div>
            <div><strong><?= htmlspecialchars($_SESSION['usuario_nome'] ?? 'Usuário') ?></strong><p><span class="papel-badge papel-<?= htmlspecialchars(papelAtual()) ?>"><?= htmlspecialchars(nomePapel(papelAtual())) ?></span></p></div>
            <a href="logout.php" class="btn btn-sair">Sair</a>
        </div>
    </header>

    <?php if ($flash): ?>
        <div class="flash <?= htmlspecialchars($flash['type']) ?>"><?= htmlspecialchars($flash['message']) ?></div>
    <?php endif; ?>

    <section class="painel" id="livros">
        <div class="painel-topo">
            <div><h2>Livros cadastrados</h2><p>Busque por título, ISBN ou nome de pessoa vinculada.</p></div>
            <div class="pesquisa"><span>🔍</span><input id="busca" type="text" placeholder="Pesquisar livro..." oninput="agendarFiltroLivros()" value="<?= htmlspecialchars($termoBuscaInicial) ?>"></div>
        </div>
        <?php if ($podeGerenciar): ?>
        <div class="acoes" style="margin-bottom:20px;">
            <a href="livro_form.php" class="btn btn-principal">+ Novo Livro</a>
        </div>
        <?php endif; ?>
        <div class="tabela-container">
            <table id="tabela-livros">
                <thead><tr><th>Título</th><th>ISBN</th><th>Pessoas</th><th>Etapa atual</th><th>Na etapa desde</th><th>Responsável</th><th>Cópia</th><th>Status</th></tr></thead>
                <tbody>
                <?php if (!$livros): ?>
                    <tr><td colspan="8" class="vazio">Nenhum livro cadastrado.</td></tr>
                <?php else: foreach ($livros as $l):
                    $busca = strtolower($l['titulo'] . ' ' . ($l['isbn'] ?? '') . ' ' . ($l['pessoas_nomes'] ?? ''));
                ?>
                    <tr data-busca="<?= htmlspecialchars($busca, ENT_QUOTES) ?>">
                        <td class="livro-titulo">
                            <div class="livro-icon"><?= $l['status'] === 'extraviado' ? '🚨' : '📖' ?></div>
                            <div><strong><a href="livro_detalhes.php?id=<?= (int)$l['id'] ?>"><?= htmlspecialchars($l['titulo']) ?></a></strong>
                            <p><?= htmlspecialchars($l['pessoas_nomes'] ?: 'Sem pessoas vinculadas') ?></p></div>
                        </td>
                        <td><?= htmlspecialchars($l['isbn'] ?: '—') ?></td>
                        <td><?= htmlspecialchars($l['pessoas_nomes'] ?: '—') ?></td>
                        <td><?= htmlspecialchars($l['etapa_nome'] ?: '—') ?></td>
                        <td><?= $l['etapa_desde'] ? date('d/m/Y', strtotime($l['etapa_desde'])) : '—' ?></td>
                        <td><?= htmlspecialchars($l['responsavel_nome'] ?: '—') ?></td>
                        <td><?= ((int)$l['tem_copia'] === 1) ? 'Sim' : 'Não' ?></td>
                        <td>
                            <span class="status <?= htmlspecialchars(classeStatusLivro($l['status'])) ?>"><?= htmlspecialchars(nomeStatusLivro($l['status'])) ?></span>
                            <?php if ((int)$l['ja_teve_resgate'] === 1): ?>
                                <span class="status resgate" title="Já passou por um resgate">🔍 Já resgatado</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                <tr id="busca-sem-resultado" style="display:none;">
                    <td colspan="8" class="vazio">Nenhum livro encontrado para essa busca.</td>
                </tr>
                </tbody>
            </table>
        </div>
    </section>

    <footer><p>WareSys © 2026 — Sistema acadêmico de Controle Editorial</p></footer>
</main>

<script>
let filtroLivrosTimeout = null;
function agendarFiltroLivros(){
  clearTimeout(filtroLivrosTimeout);
  filtroLivrosTimeout = setTimeout(aplicarFiltroLivros, 300);
}
function aplicarFiltroLivros(){
  const termo = document.getElementById('busca').value.trim().toLowerCase();
  let visiveis = 0;
  document.querySelectorAll('#tabela-livros tbody tr[data-busca]').forEach(linha=>{
    const mostra = termo === '' || linha.dataset.busca.includes(termo);
    linha.style.display = mostra ? '' : 'none';
    if (mostra) visiveis++;
  });
  const semResultado = document.getElementById('busca-sem-resultado');
  if (semResultado) semResultado.style.display = (visiveis === 0 && termo !== '') ? '' : 'none';
}
if (document.getElementById('busca').value.trim() !== '') {
  aplicarFiltroLivros();
}
</script>
</body>
</html>
