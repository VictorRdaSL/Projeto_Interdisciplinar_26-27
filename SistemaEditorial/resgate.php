<?php
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/permissoes.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/movimentacoes.php';

$livroId = (int)($_GET['id'] ?? 0);
if ($livroId <= 0) {
    header('Location: livros.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!usuarioTemPermissao('livros.gerenciar')) {
        $_SESSION['flash'] = ['type' => 'erro', 'message' => 'Você não tem permissão para concluir resgates.'];
        header('Location: resgate.php?id=' . $livroId);
        exit;
    }
    if (!csrf_validar($_POST['csrf_token'] ?? null)) {
        $_SESSION['flash'] = ['type' => 'erro', 'message' => 'Sessão expirada. Tente novamente.'];
        header('Location: resgate.php?id=' . $livroId);
        exit;
    }

    $usuarioId = (int)$_SESSION['usuario_id'];
    $novaEtapaId = (int)($_POST['etapa_id'] ?? 0);
    $novoResponsavelId = (int)($_POST['responsavel_id'] ?? 0);
    $localEncontrado = trim($_POST['local_encontrado'] ?? '');

    try {
        $resultado = concluirResgate($conn, $livroId, $novaEtapaId, $novoResponsavelId, $localEncontrado, $usuarioId);
        $_SESSION['flash'] = ['type' => 'sucesso', 'message' => 'Resgate concluído. Livro de volta em andamento, na etapa ' . $resultado['etapa_nome'] . '.'];
        header('Location: livro_detalhes.php?id=' . $livroId);
        exit;
    } catch (InvalidArgumentException $e) {
        $_SESSION['flash'] = ['type' => 'erro', 'message' => $e->getMessage()];
    } catch (Throwable $e) {
        $_SESSION['flash'] = ['type' => 'erro', 'message' => 'Não foi possível concluir o resgate: ' . $e->getMessage()];
    }

    header('Location: resgate.php?id=' . $livroId);
    exit;
}

$stmt = $conn->prepare('SELECT * FROM livros WHERE id = ?');
$stmt->bind_param('i', $livroId);
$stmt->execute();
$livro = $stmt->get_result()->fetch_assoc();

if (!$livro) {
    $_SESSION['flash'] = ['type' => 'erro', 'message' => 'Livro não encontrado.'];
    header('Location: livros.php');
    exit;
}

if ($livro['status'] !== 'extraviado') {
    $_SESSION['flash'] = ['type' => 'erro', 'message' => 'Este livro não está em processo de resgate.'];
    header('Location: livro_detalhes.php?id=' . $livroId);
    exit;
}

$resgate = buscarResgateAbertoPorLivro($conn, $livroId);
if (!$resgate) {
    $_SESSION['flash'] = ['type' => 'erro', 'message' => 'Não há resgate em aberto para este livro.'];
    header('Location: livro_detalhes.php?id=' . $livroId);
    exit;
}

$stmtRef = $conn->prepare('
    SELECT m.*, e.nome AS etapa_nome, u.nome AS responsavel_nome
    FROM movimentacoes m
    LEFT JOIN etapas e ON e.id = m.etapa_id
    LEFT JOIN usuarios u ON u.id = m.responsavel_id
    WHERE m.id = ?
');
$movRefId = (int)$resgate['movimentacao_referencia_id'];
$stmtRef->bind_param('i', $movRefId);
$stmtRef->execute();
$movReferencia = $stmtRef->get_result()->fetch_assoc();

$diasDesdeAUltimaMovimentacao = $movReferencia
    ? (new DateTime())->diff(new DateTime($movReferencia['data_entrada']))->days
    : null;

$etapasDisponiveis = [];
$re = $conn->query('SELECT id, nome FROM etapas WHERE ativa = 1 ORDER BY ordem ASC');
while ($row = $re->fetch_assoc()) $etapasDisponiveis[] = $row;

$usuariosDisponiveis = [];
$ru = $conn->query('SELECT id, nome FROM usuarios ORDER BY nome');
while ($row = $ru->fetch_assoc()) $usuariosDisponiveis[] = $row;

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
?>
<!DOCTYPE html>
<html lang="pt-br" data-theme="<?= htmlspecialchars($_SESSION['usuario_tema'] ?? 'claro') ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>WareSys | Resgate — <?= htmlspecialchars($livro['titulo']) ?></title>
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
        <span class="nav-desabilitado"><span>📄</span>Relatórios<span class="nav-tag">em breve</span></span>
        <a href="configuracoes.php"><span>⚙</span>Configurações</a>
    </nav>
    <div class="sidebar-bottom"><span class="versao">Versão acadêmica • MySQL / XAMPP</span></div>
</aside>

<main>
    <header class="topo">
        <div><p class="bem-vindo"><a href="livro_detalhes.php?id=<?= (int)$livro['id'] ?>">← Voltar para o livro</a></p><h2>Resgate — <?= htmlspecialchars($livro['titulo']) ?></h2></div>
        <div class="perfil">
            <div class="avatar"><?= htmlspecialchars(strtoupper(substr($_SESSION['usuario_nome'] ?? 'U', 0, 1))) ?></div>
            <div><strong><?= htmlspecialchars($_SESSION['usuario_nome'] ?? 'Usuário') ?></strong><p><span class="papel-badge papel-<?= htmlspecialchars(papelAtual()) ?>"><?= htmlspecialchars(nomePapel(papelAtual())) ?></span></p></div>
            <a href="logout.php" class="btn btn-sair">Sair</a>
        </div>
    </header>

    <?php if ($flash): ?>
        <div class="flash <?= htmlspecialchars($flash['type']) ?>"><?= htmlspecialchars($flash['message']) ?></div>
    <?php endif; ?>

    <div class="flash erro" style="margin-bottom:22px;">🚨 Este livro está marcado como <strong>extraviado</strong> desde <?= date('d/m/Y H:i', strtotime($resgate['data_abertura'])) ?>. Enquanto o resgate estiver aberto, nenhuma movimentação normal pode ser feita.</div>

    <section class="cards">
        <div class="card"><div><p>Última etapa conhecida</p><h3 style="font-size:18px;"><?= htmlspecialchars($movReferencia['etapa_nome'] ?? '—') ?></h3></div><div class="card-icon">📍</div></div>
        <div class="card"><div><p>Última mesa conhecida</p><h3 style="font-size:18px;"><?= htmlspecialchars($movReferencia['responsavel_nome'] ?? '—') ?></h3></div><div class="card-icon">👤</div></div>
        <div class="card"><div><p>Data da última movimentação</p><h3 style="font-size:18px;"><?= $movReferencia ? date('d/m/Y H:i', strtotime($movReferencia['data_entrada'])) : '—' ?></h3></div><div class="card-icon">🕒</div></div>
        <div class="card"><div><p>Há quantos dias</p><h3 style="font-size:18px;"><?= $diasDesdeAUltimaMovimentacao !== null ? ($diasDesdeAUltimaMovimentacao === 0 ? 'menos de 1 dia' : $diasDesdeAUltimaMovimentacao . ' dia' . ($diasDesdeAUltimaMovimentacao > 1 ? 's' : '')) : '—' ?></h3></div><div class="card-icon">⏳</div></div>
    </section>

    <?php if (usuarioTemPermissao('livros.gerenciar')): ?>
    <section class="painel">
        <div class="painel-topo">
            <div><h2>Concluir resgate</h2><p>Informe onde o livro foi encontrado, com quem está agora e em qual etapa ele realmente está.</p></div>
        </div>
        <form method="post">
            <?= csrf_field() ?>
            <div class="campo full"><label for="local_encontrado">Onde o livro foi encontrado</label><input type="text" id="local_encontrado" name="local_encontrado" required></div>
            <div class="campo"><label for="etapa_id">Etapa em que ele realmente está</label>
                <select id="etapa_id" name="etapa_id" required>
                    <?php foreach ($etapasDisponiveis as $e): ?>
                        <option value="<?= (int)$e['id'] ?>"><?= htmlspecialchars($e['nome']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="campo"><label for="responsavel_id">Com quem está agora</label>
                <select id="responsavel_id" name="responsavel_id" required>
                    <?php foreach ($usuariosDisponiveis as $u): ?>
                        <option value="<?= (int)$u['id'] ?>"><?= htmlspecialchars($u['nome']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-botoes">
                <button type="submit" class="btn-salvar">✅ Concluir resgate</button>
            </div>
        </form>
    </section>
    <?php endif; ?>

    <footer><p>WareSys © 2026 — Sistema acadêmico de Controle Editorial</p></footer>
</main>
</body>
</html>
