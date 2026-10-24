<?php
require_once __DIR__ . '/includes/sessao.php';

if (empty($_SESSION['usuario_id'])) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/config/database.php';

$stmt = $conn->prepare('SELECT nome, perfil, tema FROM vw_usuarios_publico WHERE id = ?');
$stmt->bind_param('i', $_SESSION['usuario_id']);
$stmt->execute();
$usuarioAtual = $stmt->get_result()->fetch_assoc();

if (!$usuarioAtual) {
    $_SESSION = [];
    session_destroy();
    header('Location: login.php');
    exit;
}

$_SESSION['usuario_nome'] = $usuarioAtual['nome'];
$_SESSION['usuario_perfil'] = $usuarioAtual['perfil'];
$_SESSION['usuario_tema'] = $usuarioAtual['tema'];
