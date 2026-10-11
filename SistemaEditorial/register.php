<?php
// Cadastro público desativado no Passo 9 — usuários agora são criados só por
// Admin/Gerente, em Configurações. Arquivo mantido (em vez de apagado) para
// não quebrar links/favoritos antigos; não grava nada no banco.
require_once __DIR__ . '/includes/sessao.php';

if (!empty($_SESSION['usuario_id'])) {
    header('Location: index.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>WareSys | Cadastro indisponível</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="auth.css">
</head>
<body class="auth-page">
<div class="auth-card">
    <div class="logo auth-logo">
        <div class="logo-icon">S</div>
        <div><h1>Ware<span>Sys</span></h1><p>Controle Editorial</p></div>
    </div>
    <h2>Cadastro indisponível</h2>
    <p class="auth-sub">O cadastro de novas contas não é mais autoatendido. Peça a um Gerente ou Administrador para criar sua conta em Configurações.</p>
    <p class="auth-footer">Já tem uma conta? <a href="login.php">Entrar</a></p>
</div>
</body>
</html>
