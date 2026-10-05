<?php
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/permissoes.php';

$livroId = (int)($_GET['id'] ?? 0);
if ($livroId <= 0) {
    header('Location: livros.php');
    exit;
}

$stmt = $conn->prepare('
    SELECT l.*, e.nome AS etapa_nome, u.nome AS responsavel_nome
    FROM livros l
    LEFT JOIN etapas e ON e.id = l.etapa_atual_id
    LEFT JOIN usuarios u ON u.id = l.responsavel_atual_id
    WHERE l.id = ?
');
$stmt->bind_param('i', $livroId);
$stmt->execute();
$livro = $stmt->get_result()->fetch_assoc();

if (!$livro) {
    $_SESSION['flash'] = ['type' => 'erro', 'message' => 'Livro não encontrado.'];
    header('Location: livros.php');
    exit;
}

$stmtContrib = $conn->prepare('
    SELECT pe.nome AS pessoa_nome, pa.nome AS papel_nome
    FROM contribuicoes c
    JOIN pessoas pe ON pe.id = c.pessoa_id
    JOIN papeis pa ON pa.id = c.papel_id
    WHERE c.livro_id = ?
    ORDER BY pa.nome, pe.nome
');
$stmtContrib->bind_param('i', $livroId);
$stmtContrib->execute();
$contribuicoes = [];
$rc = $stmtContrib->get_result();
while ($row = $rc->fetch_assoc()) $contribuicoes[] = $row;

$stmtMov = $conn->prepare('
    SELECT m.*, e.nome AS etapa_nome, resp.nome AS responsavel_nome, reg.nome AS registrado_por_nome
    FROM movimentacoes m
    LEFT JOIN etapas e ON e.id = m.etapa_id
    LEFT JOIN usuarios resp ON resp.id = m.responsavel_id
    LEFT JOIN usuarios reg ON reg.id = m.registrado_por_id
    WHERE m.livro_id = ?
    ORDER BY m.data_entrada DESC
');
$stmtMov->bind_param('i', $livroId);
$stmtMov->execute();
$movimentacoes = [];
$rm = $stmtMov->get_result();
while ($row = $rm->fetch_assoc()) $movimentacoes[] = $row;

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$podeGerenciar = usuarioTemPermissao('livros.gerenciar');

const NOMES_STATUS_LIVRO_DET = [
    'em_andamento' => 'Em andamento',
    'concluido'    => 'Concluído',
    'extraviado'   => 'Extraviado',
    'cancelado'    => 'Cancelado',
];
const NOMES_TIPO_MOV = [
    'avanco'           => 'Avanço',
    'repeticao'        => 'Repetição',
    'pulo_opcional'    => 'Pulo (opcional)',
    'retrocesso'       => 'Retrocesso',
    'troca_responsavel'=> 'Troca de responsável',
    'resgate'          => 'Resgate',
];
?>
<!DOCTYPE html>
<html lang="pt-br" data-theme="<?= htmlspecialchars($_SESSION['usuario_tema'] ?? 'claro') ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>WareSys | <?= htmlspecialchars($livro['titulo']) ?></title>
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
        <a href="index.php#dashboard"><span>⌂</span>Dashboard</a>
        <a href="livros.php" class="ativo"><span>📚</span>Livros</a>
        <a href="pessoas.php"><span>👤</span>Pessoas</a>
        <?php if (usuarioTemPermissao('relatorios.ver')): ?>
        <a href="relatorios.php"><span>📄</span>Relatórios</a>
        <?php endif; ?>
        <a href="configuracoes.php"><span>⚙</span>Configurações</a>
    </nav>
    <div class="sidebar-bottom"><span class="versao">Versão acadêmica • MySQL / XAMPP</span></div>
</aside>

<main>
    <header class="topo">
        <div><p class="bem-vindo"><a href="livros.php">← Voltar para Livros</a></p><h2><?= htmlspecialchars($livro['titulo']) ?></h2></div>
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
        <div class="card"><div><p>Etapa atual</p><h3 style="font-size:18px;"><?= htmlspecialchars($livro['etapa_nome'] ?: '—') ?></h3></div><div class="card-icon">📍</div></div>
        <div class="card"><div><p>Responsável atual (mesa)</p><h3 style="font-size:18px;"><?= htmlspecialchars($livro['responsavel_nome'] ?: '—') ?></h3></div><div class="card-icon">👤</div></div>
        <div class="card"><div><p>Status</p><h3 style="font-size:18px;"><?= htmlspecialchars(NOMES_STATUS_LIVRO_DET[$livro['status']] ?? $livro['status']) ?></h3></div><div class="card-icon">📊</div></div>
        <div class="card"><div><p>Tem cópia?</p><h3 style="font-size:18px;"><?= ((int)$livro['tem_copia'] === 1) ? 'Sim' : 'Não' ?></h3></div><div class="card-icon">📄</div></div>
    </section>

    <?php if ($podeGerenciar): ?>
    <section class="acoes">
        <a href="livro_form.php?id=<?= (int)$livro['id'] ?>" class="btn btn-principal">✏️ Editar Livro</a>
    </section>
    <?php endif; ?>

    <section class="painel">
        <div class="painel-topo">
            <div><h2>Dados do livro</h2></div>
        </div>
        <p><strong>ISBN:</strong> <?= htmlspecialchars($livro['isbn'] ?: 'Não informado') ?></p>
        <p style="margin-top:10px;"><strong>Pessoas vinculadas:</strong></p>
        <div class="tabela-container">
            <table>
                <thead><tr><th>Pessoa</th><th>Papel</th></tr></thead>
                <tbody>
                <?php if (!$contribuicoes): ?>
                    <tr><td colspan="2" class="vazio">Nenhuma pessoa vinculada.</td></tr>
                <?php else: foreach ($contribuicoes as $c): ?>
                    <tr><td><?= htmlspecialchars($c['pessoa_nome']) ?></td><td><?= htmlspecialchars($c['papel_nome']) ?></td></tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="secao-simples">
        <h2>Observações</h2>
        <p><?= $livro['observacoes'] !== null && $livro['observacoes'] !== '' ? nl2br(htmlspecialchars($livro['observacoes'])) : '<span class="vazio" style="display:inline;padding:0;">Nenhuma observação registrada.</span>' ?></p>
    </section>

    <section class="secao-simples" id="historico">
        <h2>Histórico de movimentações</h2>
        <p>Nenhuma movimentação é apagada ou sobrescrita — este é o histórico completo do livro.</p>
        <div class="tabela-container historico">
            <table>
                <thead><tr><th>Entrada</th><th>Saída</th><th>Etapa</th><th>Tipo</th><th>Rodada</th><th>Excedente</th><th>Responsável</th><th>Registrado por</th></tr></thead>
                <tbody>
                <?php if (!$movimentacoes): ?>
                    <tr><td colspan="8" class="vazio">Nenhuma movimentação registrada.</td></tr>
                <?php else: foreach ($movimentacoes as $m): ?>
                    <tr>
                        <td><?= date('d/m/Y H:i', strtotime($m['data_entrada'])) ?></td>
                        <td><?= $m['data_saida'] ? date('d/m/Y H:i', strtotime($m['data_saida'])) : '—' ?></td>
                        <td><?= htmlspecialchars($m['etapa_nome'] ?: '—') ?></td>
                        <td><?= htmlspecialchars(NOMES_TIPO_MOV[$m['tipo']] ?? $m['tipo']) ?></td>
                        <td><?= (int)$m['rodada'] ?></td>
                        <td><?= ((int)$m['excedente'] === 1) ? '<span class="status baixo">Cobrável</span>' : '—' ?></td>
                        <td><?= htmlspecialchars($m['responsavel_nome'] ?: '—') ?></td>
                        <td><?= htmlspecialchars($m['registrado_por_nome'] ?: '—') ?></td>
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
