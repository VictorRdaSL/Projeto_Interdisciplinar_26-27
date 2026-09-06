<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['usuario_id'])) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/config/database.php';

$stmt = $conn->prepare('SELECT nome, role FROM usuarios WHERE id = ?');
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
$_SESSION['usuario_role'] = $usuarioAtual['role'];
