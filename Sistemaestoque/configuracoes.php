<?php
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/includes/permissoes.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/log.php';

$podeAdministrar = usuarioTemPermissao('config.acessar');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'alterar_tema') {
    if (!csrf_validar($_POST['csrf_token'] ?? null)) {
        $_SESSION['flash'] = ['type' => 'erro', 'message' => 'Sessão expirada. Tente novamente.'];
    } else {
        $novoTema = $_POST['tema'] ?? '';
        if (!in_array($novoTema, ['claro', 'escuro'], true)) {
            $_SESSION['flash'] = ['type' => 'erro', 'message' => 'Tema inválido.'];
        } else {
            $stmt = $conn->prepare('UPDATE usuarios SET tema = ? WHERE id = ?');
            $stmt->bind_param('si', $novoTema, $_SESSION['usuario_id']);
            $stmt->execute();
            $_SESSION['usuario_tema'] = $novoTema;
            $_SESSION['flash'] = ['type' => 'sucesso', 'message' => 'Tema atualizado com sucesso.'];
        }
    }
    header('Location: configuracoes.php');
    exit;
}

if ($podeAdministrar && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'alterar_papel') {
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
                $papelAnterior = $usuarioAtualLinha['role'];
                $stmt = $conn->prepare('UPDATE usuarios SET role = ? WHERE id = ?');
                $stmt->bind_param('si', $novoPapel, $usuarioId);
                $stmt->execute();
                if ($novoPapel !== $papelAnterior) {
                    registrarLog($conn, (int)$_SESSION['usuario_id'], 'usuario.alterar_papel', 'usuario', $usuarioId, $papelAnterior, $novoPapel);
                }
                $_SESSION['flash'] = ['type' => 'sucesso', 'message' => 'Papel do usuário atualizado com sucesso.'];
            }
        }
    }
    header('Location: configuracoes.php');
    exit;
}

$usuarios = [];
$logs = [];
$filtroUsuario = 0;
$filtroAcao = '';
$filtroDe = '';
$filtroAte = '';

if ($podeAdministrar) {
    $r = $conn->query('SELECT id, nome, email, role, criado_em FROM usuarios ORDER BY nome');
    while ($row = $r->fetch_assoc()) $usuarios[] = $row;

    $filtroUsuario = (int)($_GET['filtro_usuario'] ?? 0);
    $filtroAcao = trim($_GET['filtro_acao'] ?? '');
    $filtroDe = trim($_GET['filtro_de'] ?? '');
    $filtroAte = trim($_GET['filtro_ate'] ?? '');

    $condicoes = [];
    $parametros = [];
    $tipos = '';
    if ($filtroUsuario > 0) { $condicoes[]='l.usuario_id = ?'; $parametros[]=$filtroUsuario; $tipos.='i'; }
    if ($filtroAcao !== '') { $condicoes[]='l.acao = ?'; $parametros[]=$filtroAcao; $tipos.='s'; }
    if ($filtroDe !== '') { $condicoes[]='DATE(l.criado_em) >= ?'; $parametros[]=$filtroDe; $tipos.='s'; }
    if ($filtroAte !== '') { $condicoes[]='DATE(l.criado_em) <= ?'; $parametros[]=$filtroAte; $tipos.='s'; }
    $whereSql = $condicoes ? 'WHERE '.implode(' AND ', $condicoes) : '';

    $sqlLog = "
        SELECT l.*, ator.nome AS ator_nome,
               CASE WHEN l.entidade_tipo='produto' THEN p.nome ELSE ue.nome END AS entidade_nome
        FROM log_alteracoes l
        JOIN usuarios ator ON ator.id = l.usuario_id
        LEFT JOIN produtos p ON l.entidade_tipo='produto' AND p.id = l.entidade_id
        LEFT JOIN usuarios ue ON l.entidade_tipo='usuario' AND ue.id = l.entidade_id
        $whereSql
        ORDER BY l.criado_em DESC
        LIMIT 100
    ";
    $stmtLog = $conn->prepare($sqlLog);
    if ($parametros) $stmtLog->bind_param($tipos, ...$parametros);
    $stmtLog->execute();
    $rl = $stmtLog->get_result();
    while ($row = $rl->fetch_assoc()) $logs[] = $row;
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
?>
<!DOCTYPE html>
<html lang="pt-br" data-theme="<?= htmlspecialchars($_SESSION['usuario_tema'] ?? 'claro') ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>WareSys | Configurações</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="dark.css">
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
        <a href="index.php#historico"><span>📊</span>Histórico</a>
        <?php if (usuarioTemPermissao('relatorios.ver')): ?>
        <a href="relatorios.php"><span>📄</span>Relatórios</a>
        <?php endif; ?>
        <a href="configuracoes.php" class="ativo"><span>⚙</span>Configurações</a>
    </nav>
    <div class="sidebar-bottom"><span class="versao">Versão acadêmica • MySQL / XAMPP</span></div>
</aside>

<main>
    <header class="topo">
        <div><p class="bem-vindo"><?= $podeAdministrar ? 'Área administrativa' : 'Suas preferências' ?></p><h2>Configurações</h2></div>
        <div class="perfil">
            <div class="avatar"><?= htmlspecialchars(strtoupper(substr($_SESSION['usuario_nome'] ?? 'U', 0, 1))) ?></div>
            <div><strong><?= htmlspecialchars($_SESSION['usuario_nome'] ?? 'Usuário') ?></strong><p><span class="papel-badge papel-<?= htmlspecialchars(papelAtual()) ?>"><?= htmlspecialchars(nomePapel(papelAtual())) ?></span></p></div>
            <a href="logout.php" class="btn btn-sair">Sair</a>
        </div>
    </header>

    <?php if ($flash): ?>
        <div class="flash <?= htmlspecialchars($flash['type']) ?>"><?= htmlspecialchars($flash['message']) ?></div>
    <?php endif; ?>

    <section class="painel">
        <div class="painel-topo">
            <div><h2>Aparência</h2><p>Escolha o tema de exibição do sistema. Fica salvo na sua conta e vale para qualquer dispositivo.</p></div>
        </div>
        <form method="post" class="form-tema">
            <?= csrf_field() ?>
            <input type="hidden" name="acao" value="alterar_tema">
            <div class="tema-opcoes">
                <button type="submit" name="tema" value="claro" class="tema-opcao <?= ($_SESSION['usuario_tema'] ?? 'claro') === 'claro' ? 'ativo' : '' ?>">
                    <span class="tema-icone">☀️</span> Tema Claro
                </button>
                <button type="submit" name="tema" value="escuro" class="tema-opcao <?= ($_SESSION['usuario_tema'] ?? 'claro') === 'escuro' ? 'ativo' : '' ?>">
                    <span class="tema-icone">🌙</span> Tema Escuro
                </button>
            </div>
        </form>
    </section>

    <?php if ($podeAdministrar): ?>
    <section class="painel" style="margin-top:30px;">
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

    <section class="painel" style="margin-top:30px;">
        <div class="painel-topo">
            <div><h2>Log de Alterações</h2><p>Auditoria de edições de produtos e ações de controle de acesso.</p></div>
        </div>
        <form method="get" class="form-filtro-log">
            <div class="campo">
                <label for="filtro_usuario">Usuário</label>
                <select id="filtro_usuario" name="filtro_usuario">
                    <option value="0">Todos</option>
                    <?php foreach ($usuarios as $u): ?>
                        <option value="<?= (int)$u['id'] ?>" <?= $filtroUsuario === (int)$u['id'] ? 'selected' : '' ?>><?= htmlspecialchars($u['nome']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="campo">
                <label for="filtro_acao">Tipo de ação</label>
                <select id="filtro_acao" name="filtro_acao">
                    <option value="">Todas</option>
                    <?php foreach (NOMES_ACOES_LOG as $valor => $rotulo): ?>
                        <option value="<?= htmlspecialchars($valor) ?>" <?= $filtroAcao === $valor ? 'selected' : '' ?>><?= htmlspecialchars($rotulo) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="campo">
                <label for="filtro_de">De</label>
                <input type="date" id="filtro_de" name="filtro_de" value="<?= htmlspecialchars($filtroDe) ?>">
            </div>
            <div class="campo">
                <label for="filtro_ate">Até</label>
                <input type="date" id="filtro_ate" name="filtro_ate" value="<?= htmlspecialchars($filtroAte) ?>">
            </div>
            <div class="campo campo-botao">
                <button type="submit" class="btn-salvar">Filtrar</button>
                <a href="configuracoes.php" class="btn-cancelar">Limpar</a>
            </div>
        </form>
        <div class="tabela-container">
            <table>
                <thead><tr><th>Data</th><th>Usuário</th><th>Ação</th><th>Item afetado</th><th>Valor anterior</th><th>Valor novo</th></tr></thead>
                <tbody>
                <?php if (!$logs): ?>
                    <tr><td colspan="6" class="vazio">Nenhum registro encontrado.</td></tr>
                <?php else: foreach ($logs as $l): ?>
                    <tr>
                        <td><?= date('d/m/Y H:i', strtotime($l['criado_em'])) ?></td>
                        <td><?= htmlspecialchars($l['ator_nome']) ?></td>
                        <td><?= htmlspecialchars(nomeAcaoLog($l['acao'])) ?></td>
                        <td><?= htmlspecialchars($l['entidade_nome'] ?: '#'.$l['entidade_id']) ?></td>
                        <td><?= htmlspecialchars($l['valor_anterior'] ?: '—') ?></td>
                        <td><?= htmlspecialchars($l['valor_novo'] ?: '—') ?></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </section>
    <?php endif; ?>

    <footer><p>WareSys © 2026 — Sistema acadêmico de Controle de Almoxarifado</p></footer>
</main>
</body>
</html>
