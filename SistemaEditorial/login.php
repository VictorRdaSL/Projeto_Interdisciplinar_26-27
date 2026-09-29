<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/csrf.php';

if (!empty($_SESSION['usuario_id'])) {
    header('Location: index.php');
    exit;
}

$erro = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_validar($_POST['csrf_token'] ?? null)) {
        $erro = 'Sessão expirada. Recarregue a página e tente novamente.';
    } else {
        $email = trim($_POST['email'] ?? '');
        $senha = (string)($_POST['senha'] ?? '');

        if ($email === '' || $senha === '') {
            $erro = 'Informe e-mail e senha.';
        } else {
            $stmt = $conn->prepare('SELECT id, nome, senha_hash, role, tema FROM usuarios WHERE email = ?');
            $stmt->bind_param('s', $email);
            $stmt->execute();
            $usuario = $stmt->get_result()->fetch_assoc();

            if ($usuario && password_verify($senha, $usuario['senha_hash'])) {
                session_regenerate_id(true);
                $_SESSION['usuario_id'] = $usuario['id'];
                $_SESSION['usuario_nome'] = $usuario['nome'];
                $_SESSION['usuario_role'] = $usuario['role'];
                $_SESSION['usuario_tema'] = $usuario['tema'];
                header('Location: index.php');
                exit;
            }

            $erro = 'E-mail ou senha inválidos.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>WareSys | Entrar</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="auth.css">
</head>
<body class="auth-page">
<div class="auth-card">
    <div class="logo auth-logo">
        <div class="logo-icon">S</div>
        <div><h1>Ware<span>Sys</span></h1><p>Controle Inteligente</p></div>
    </div>
    <h2>Entrar</h2>
    <p class="auth-sub">Acesse o controle de almoxarifado.</p>

    <?php if ($erro): ?>
        <div class="flash erro"><?= htmlspecialchars($erro) ?></div>
    <?php endif; ?>

    <form method="post" class="form-auth" novalidate>
        <?= csrf_field() ?>
        <div class="campo">
            <label for="email">E-mail</label>
            <input type="email" id="email" name="email" value="<?= htmlspecialchars($email) ?>" required>
        </div>
        <div class="campo">
            <label for="senha">Senha</label>
            <input type="password" id="senha" name="senha" required>
        </div>
        <button type="submit" class="btn-salvar full">Entrar</button>
    </form>

    <p class="auth-footer">Ainda não tem conta? <a href="register.php">Criar conta</a></p>
</div>
</body>
</html>
