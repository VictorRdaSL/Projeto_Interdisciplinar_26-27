<?php
require_once __DIR__ . '/includes/sessao.php';

if (empty($_SESSION['usuario_id'])) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/config/database.php';

$stmt = $conn->prepare('SELECT nome, perfil, tema, ativo FROM vw_usuarios_publico WHERE id = ?');
$stmt->bind_param('i', $_SESSION['usuario_id']);
$stmt->execute();
$usuarioAtual = $stmt->get_result()->fetch_assoc();

if (!$usuarioAtual || (int)$usuarioAtual['ativo'] !== 1) {
    // Usuário excluído ou removido (sem acesso) com a sessão ainda aberta: cai na próxima página.
    $_SESSION = [];
    session_destroy();
    header('Location: login.php' . ($usuarioAtual ? '?motivo=sem_acesso' : ''));
    exit;
}

$_SESSION['usuario_nome'] = $usuarioAtual['nome'];
$_SESSION['usuario_perfil'] = $usuarioAtual['perfil'];
$_SESSION['usuario_tema'] = $usuarioAtual['tema'];
