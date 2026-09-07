<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/log.php';

if (!empty($_SESSION['usuario_id'])) {
    header('Location: index.php');
    exit;
}

$erro = '';
$sucesso = '';
$nome = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_validar($_POST['csrf_token'] ?? null)) {
        $erro = 'Sessão expirada. Recarregue a página e tente novamente.';
    } else {
        $nome = trim($_POST['nome'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $senha = (string)($_POST['senha'] ?? '');
        $confirmarSenha = (string)($_POST['confirmar_senha'] ?? '');

        if ($nome === '' || $email === '' || $senha === '' || $confirmarSenha === '') {
            $erro = 'Preencha todos os campos.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $erro = 'Informe um e-mail válido.';
        } elseif (strlen($senha) < 6) {
            $erro = 'A senha deve ter pelo menos 6 caracteres.';
        } elseif ($senha !== $confirmarSenha) {
            $erro = 'As senhas não coincidem.';
        } else {
            $stmt = $conn->prepare('SELECT id FROM usuarios WHERE email = ?');
            $stmt->bind_param('s', $email);
            $stmt->execute();
            if ($stmt->get_result()->fetch_assoc()) {
                $erro = 'Este e-mail já está cadastrado.';
            } else {
                $senhaHash = password_hash($senha, PASSWORD_DEFAULT);
                $papelPadrao = 'usuario';
                try {
                    $insert = $conn->prepare('INSERT INTO usuarios (nome, email, senha_hash, role) VALUES (?, ?, ?, ?)');
                    $insert->bind_param('ssss', $nome, $email, $senhaHash, $papelPadrao);
                    $insert->execute();

                    $novoUsuarioId = $conn->insert_id;
                    registrarLog($conn, $novoUsuarioId, 'usuario.criar', 'usuario', $novoUsuarioId, null, $papelPadrao);

                    $_SESSION['usuario_id'] = $novoUsuarioId;
                    $_SESSION['usuario_nome'] = $nome;
                    $_SESSION['usuario_role'] = $papelPadrao;
                    $_SESSION['usuario_tema'] = 'claro';
                    header('Location: index.php');
                    exit;
                } catch (mysqli_sql_exception $e) {
                    $erro = 'Não foi possível concluir o cadastro. Tente novamente.';
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>WareSys | Criar Conta</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="auth.css">
</head>
<body class="auth-page">
<div class="auth-card">
    <div class="logo auth-logo">
        <div class="logo-icon">S</div>
        <div><h1>Ware<span>Sys</span></h1><p>Controle Inteligente</p></div>
    </div>
    <h2>Criar conta</h2>
    <p class="auth-sub">Cadastre-se para acessar o controle de almoxarifado.</p>

    <?php if ($erro): ?>
        <div class="flash erro"><?= htmlspecialchars($erro) ?></div>
    <?php endif; ?>
    <?php if ($sucesso): ?>
        <div class="flash sucesso"><?= htmlspecialchars($sucesso) ?></div>
    <?php endif; ?>

    <form method="post" class="form-auth" novalidate>
        <?= csrf_field() ?>
        <div class="campo">
            <label for="nome">Nome</label>
            <input type="text" id="nome" name="nome" value="<?= htmlspecialchars($nome) ?>" required>
        </div>
        <div class="campo">
            <label for="email">E-mail</label>
            <input type="email" id="email" name="email" value="<?= htmlspecialchars($email) ?>" required>
        </div>
        <div class="campo">
            <label for="senha">Senha</label>
            <input type="password" id="senha" name="senha" minlength="6" required>
        </div>
        <div class="campo">
            <label for="confirmar_senha">Confirmar Senha</label>
            <input type="password" id="confirmar_senha" name="confirmar_senha" minlength="6" required>
        </div>
        <button type="submit" class="btn-salvar full">Criar conta</button>
    </form>

    <p class="auth-footer">Já tem uma conta? <a href="login.php">Entrar</a></p>
</div>
</body>
</html>
