<?php
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/includes/permissoes.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/config/database.php';

exigirPapel(['admin']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'alterar_papel') {
    if (!csrf_validar($_POST['csrf_token'] ?? null)) {
        $_SESSION['flash'] = ['type' => 'erro', 'message' => 'Sessão expirada. Tente novamente.'];
    } else {
        $usuarioId = (int)($_POST['usuario_id'] ?? 0);
        $novoPapel = $_POST['papel'] ?? '';

        if (!in_array($novoPapel, PAPEIS_VALIDOS, true)) {
            $_SESSION['flash'] = ['type' => 'erro', 'message' => 'Papel inválido.'];
        } elseif ($usuarioId === (int)$_SESSION['usuario_id'] && $novoPapel !== 'admin') {
            $_SESSION['flash'] = ['type' => 'erro', 'message' => 'Você não pode remover seu próprio acesso de administrador.'];
        } else {
            $totalAdmins = (int)$conn->query("SELECT COUNT(*) total FROM usuarios WHERE role = 'admin'")->fetch_assoc()['total'];
            $stmtAtual = $conn->prepare('SELECT role FROM usuarios WHERE id = ?');
            $stmtAtual->bind_param('i', $usuarioId);
            $stmtAtual->execute();
            $usuarioAtualLinha = $stmtAtual->get_result()->fetch_assoc();

            if (!$usuarioAtualLinha) {
                $_SESSION['flash'] = ['type' => 'erro', 'message' => 'Usuário não encontrado.'];
            } elseif ($usuarioAtualLinha['role'] === 'admin' && $novoPapel !== 'admin' && $totalAdmins <= 1) {
                $_SESSION['flash'] = ['type' => 'erro', 'message' => 'Não é possível remover o último administrador do sistema.'];
            } else {
                $stmt = $conn->prepare('UPDATE usuarios SET role = ? WHERE id = ?');
                $stmt->bind_param('si', $novoPapel, $usuarioId);
                $stmt->execute();
                $_SESSION['flash'] = ['type' => 'sucesso', 'message' => 'Papel do usuário atualizado com sucesso.'];
            }
        }
    }
    header('Location: configuracoes.php');
    exit;
}

$usuarios = [];
$r = $conn->query('SELECT id, nome, email, role, criado_em FROM usuarios ORDER BY nome');
while ($row = $r->fetch_assoc()) $usuarios[] = $row;

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>WareSys | Configurações</title>
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
        <a href="configuracoes.php" class="ativo"><span>⚙</span>Configurações</a>
    </nav>
    <div class="sidebar-bottom"><span class="versao">Versão acadêmica • MySQL / XAMPP</span></div>
</aside>

<main>
    <header class="topo">
        <div><p class="bem-vindo">Área administrativa</p><h2>Configurações</h2></div>
        <div class="perfil">
            <div class="avatar"><?= htmlspecialchars(strtoupper(substr($_SESSION['usuario_nome'] ?? 'U', 0, 1))) ?></div>
            <div><strong><?= htmlspecialchars($_SESSION['usuario_nome'] ?? 'Usuário') ?></strong><p><span class="papel-badge papel-admin">Administrador</span></p></div>
            <a href="logout.php" class="btn btn-sair">Sair</a>
        </div>
    </header>

    <?php if ($flash): ?>
        <div class="flash <?= htmlspecialchars($flash['type']) ?>"><?= htmlspecialchars($flash['message']) ?></div>
    <?php endif; ?>

    <section class="painel">
        <div class="painel-topo">
            <div><h2>Usuários e papéis de acesso</h2><p>Defina o nível de permissão de cada usuário do sistema.</p></div>
        </div>
        <div class="tabela-container">
            <table>
                <thead><tr><th>Nome</th><th>E-mail</th><th>Papel atual</th><th>Cadastrado em</th><th>Alterar papel</th></tr></thead>
                <tbody>
                <?php foreach ($usuarios as $u): ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($u['nome']) ?></strong></td>
                        <td><?= htmlspecialchars($u['email']) ?></td>
                        <td><span class="papel-badge papel-<?= htmlspecialchars($u['role']) ?>"><?= htmlspecialchars(nomePapel($u['role'])) ?></span></td>
                        <td><?= date('d/m/Y', strtotime($u['criado_em'])) ?></td>
                        <td>
                            <form method="post" class="form-papel">
                                <?= csrf_field() ?>
                                <input type="hidden" name="acao" value="alterar_papel">
                                <input type="hidden" name="usuario_id" value="<?= (int)$u['id'] ?>">
                                <select name="papel">
                                    <option value="admin" <?= $u['role'] === 'admin' ? 'selected' : '' ?>>Administrador</option>
                                    <option value="gerente" <?= $u['role'] === 'gerente' ? 'selected' : '' ?>>Gerente</option>
                                    <option value="usuario" <?= $u['role'] === 'usuario' ? 'selected' : '' ?>>Usuário</option>
                                </select>
                                <button type="submit" class="btn-salvar">Salvar</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <footer><p>WareSys © 2026 — Sistema acadêmico de Controle de Almoxarifado</p></footer>
</main>
</body>
</html>
