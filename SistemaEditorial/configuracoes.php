<?php
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/includes/permissoes.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/log.php';

$podeGerenciarUsuarios = usuarioTemPermissao('config.usuarios');
$agindoComoAdmin = papelAtual() === 'admin';

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

if ($podeGerenciarUsuarios && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'alterar_papel') {
    if (!csrf_validar($_POST['csrf_token'] ?? null)) {
        $_SESSION['flash'] = ['type' => 'erro', 'message' => 'Sessão expirada. Tente novamente.'];
    } else {
        $usuarioId = (int)($_POST['usuario_id'] ?? 0);
        $novoPapel = $_POST['papel'] ?? '';

        if (!in_array($novoPapel, PAPEIS_VALIDOS, true)) {
            $_SESSION['flash'] = ['type' => 'erro', 'message' => 'Papel inválido.'];
        } elseif (!$agindoComoAdmin && $usuarioId === (int)$_SESSION['usuario_id']) {
            $_SESSION['flash'] = ['type' => 'erro', 'message' => 'Você não pode alterar o seu próprio papel.'];
        } elseif ($agindoComoAdmin && $usuarioId === (int)$_SESSION['usuario_id'] && $novoPapel !== 'admin') {
            $_SESSION['flash'] = ['type' => 'erro', 'message' => 'Você não pode remover seu próprio acesso de administrador.'];
        } elseif (!$agindoComoAdmin && $novoPapel === 'admin') {
            $_SESSION['flash'] = ['type' => 'erro', 'message' => 'Somente administradores podem conceder o papel de Administrador.'];
        } else {
            $totalAdmins = (int)$conn->query("SELECT COUNT(*) total FROM usuarios WHERE role = 'admin'")->fetch_assoc()['total'];
            $stmtAtual = $conn->prepare('SELECT role FROM usuarios WHERE id = ?');
            $stmtAtual->bind_param('i', $usuarioId);
            $stmtAtual->execute();
            $usuarioAtualLinha = $stmtAtual->get_result()->fetch_assoc();

            if (!$usuarioAtualLinha) {
                $_SESSION['flash'] = ['type' => 'erro', 'message' => 'Usuário não encontrado.'];
            } elseif (!$agindoComoAdmin && $usuarioAtualLinha['role'] === 'admin') {
                $_SESSION['flash'] = ['type' => 'erro', 'message' => 'Somente administradores podem alterar o papel de um Administrador.'];
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

if ($podeGerenciarUsuarios && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'criar_usuario') {
    if (!csrf_validar($_POST['csrf_token'] ?? null)) {
        $_SESSION['flash'] = ['type' => 'erro', 'message' => 'Sessão expirada. Tente novamente.'];
        header('Location: configuracoes.php');
        exit;
    }

    $novoNome = trim($_POST['novo_nome'] ?? '');
    $novoEmail = trim($_POST['novo_email'] ?? '');
    $novaSenha = (string)($_POST['nova_senha'] ?? '');
    $novaConfirmarSenha = (string)($_POST['nova_confirmar_senha'] ?? '');
    $novoPapelCriado = $_POST['novo_papel'] ?? 'usuario';

    if ($novoNome === '' || $novoEmail === '' || $novaSenha === '' || $novaConfirmarSenha === '') {
        $_SESSION['flash'] = ['type' => 'erro', 'message' => 'Preencha todos os campos do novo usuário.'];
    } elseif (!filter_var($novoEmail, FILTER_VALIDATE_EMAIL)) {
        $_SESSION['flash'] = ['type' => 'erro', 'message' => 'Informe um e-mail válido para o novo usuário.'];
    } elseif (strlen($novaSenha) < 6) {
        $_SESSION['flash'] = ['type' => 'erro', 'message' => 'A senha deve ter pelo menos 6 caracteres.'];
    } elseif ($novaSenha !== $novaConfirmarSenha) {
        $_SESSION['flash'] = ['type' => 'erro', 'message' => 'As senhas não coincidem.'];
    } elseif (!in_array($novoPapelCriado, PAPEIS_VALIDOS, true)) {
        $_SESSION['flash'] = ['type' => 'erro', 'message' => 'Papel inválido.'];
    } elseif (!$agindoComoAdmin && $novoPapelCriado === 'admin') {
        $_SESSION['flash'] = ['type' => 'erro', 'message' => 'Somente administradores podem criar um usuário com o papel de Administrador.'];
    } else {
        $stmtExiste = $conn->prepare('SELECT id FROM usuarios WHERE email = ?');
        $stmtExiste->bind_param('s', $novoEmail);
        $stmtExiste->execute();
        if ($stmtExiste->get_result()->fetch_assoc()) {
            $_SESSION['flash'] = ['type' => 'erro', 'message' => 'Já existe um usuário com esse e-mail.'];
        } else {
            $novaSenhaHash = password_hash($novaSenha, PASSWORD_DEFAULT);
            $stmtInsert = $conn->prepare('INSERT INTO usuarios (nome, email, senha_hash, role) VALUES (?, ?, ?, ?)');
            $stmtInsert->bind_param('ssss', $novoNome, $novoEmail, $novaSenhaHash, $novoPapelCriado);
            $stmtInsert->execute();
            $novoUsuarioId = $conn->insert_id;
            registrarLog($conn, (int)$_SESSION['usuario_id'], 'usuario.criar', 'usuario', $novoUsuarioId, null, $novoPapelCriado);
            $_SESSION['flash'] = ['type' => 'sucesso', 'message' => 'Usuário cadastrado com sucesso.'];
        }
    }
    header('Location: configuracoes.php');
    exit;
}

$usuarios = [];
$r = $conn->query('SELECT id, nome, email, role, criado_em FROM usuarios ORDER BY nome');
while ($row = $r->fetch_assoc()) $usuarios[] = $row;

// Log de auditoria: aberto a qualquer usuário logado (decisão confirmada no Passo 9).
$logs = [];
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
           CASE
               WHEN l.entidade_tipo='livro' THEN lv.titulo
               WHEN l.entidade_tipo='pessoa' THEN pe.nome
               WHEN l.entidade_tipo='usuario' THEN ue.nome
               ELSE NULL
           END AS entidade_nome
    FROM log_alteracoes l
    JOIN usuarios ator ON ator.id = l.usuario_id
    LEFT JOIN livros lv ON l.entidade_tipo='livro' AND lv.id = l.entidade_id
    LEFT JOIN pessoas pe ON l.entidade_tipo='pessoa' AND pe.id = l.entidade_id
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
        <div><h1>Ware<span>Sys</span></h1><p>Controle Editorial</p></div>
    </div>
    <nav>
        <a href="index.php"><span>⌂</span>Painel</a>
        <a href="minha_mesa.php"><span>🗄</span>Minha mesa</a>
        <a href="livros.php"><span>📚</span>Livros</a>
        <a href="pessoas.php"><span>👤</span>Pessoas</a>
        <span class="nav-desabilitado"><span>📄</span>Relatórios<span class="nav-tag">em breve</span></span>
        <a href="configuracoes.php" class="ativo"><span>⚙</span>Configurações</a>
    </nav>
    <div class="sidebar-bottom"><span class="versao">Versão acadêmica • MySQL / XAMPP</span></div>
</aside>

<main>
    <header class="topo">
        <div><p class="bem-vindo"><?= $podeGerenciarUsuarios ? 'Área administrativa' : 'Suas preferências' ?></p><h2>Configurações</h2></div>
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

    <?php if ($podeGerenciarUsuarios): ?>
    <section class="painel" style="margin-top:30px;">
        <div class="painel-topo">
            <div><h2>Usuários e papéis de acesso</h2><p>Defina o nível de permissão de cada usuário do sistema.<?= $agindoComoAdmin ? '' : ' Como Gerente, você pode definir Usuário ou Gerente, mas não pode conceder o papel de Administrador, alterar o papel de um Administrador, ou alterar o seu próprio papel.' ?></p></div>
        </div>
        <div class="tabela-container">
            <table>
                <thead><tr><th>Nome</th><th>E-mail</th><th>Papel atual</th><th>Cadastrado em</th><th>Alterar papel</th></tr></thead>
                <tbody>
                <?php foreach ($usuarios as $u):
                    $ehEuMesmo = (int)$u['id'] === (int)$_SESSION['usuario_id'];
                    $podeEditarEssaLinha = $agindoComoAdmin || (!$ehEuMesmo && $u['role'] !== 'admin');
                ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($u['nome']) ?></strong></td>
                        <td><?= htmlspecialchars($u['email']) ?></td>
                        <td><span class="papel-badge papel-<?= htmlspecialchars($u['role']) ?>"><?= htmlspecialchars(nomePapel($u['role'])) ?></span></td>
                        <td><?= date('d/m/Y', strtotime($u['criado_em'])) ?></td>
                        <td>
                            <?php if ($podeEditarEssaLinha): ?>
                            <form method="post" class="form-papel">
                                <?= csrf_field() ?>
                                <input type="hidden" name="acao" value="alterar_papel">
                                <input type="hidden" name="usuario_id" value="<?= (int)$u['id'] ?>">
                                <select name="papel">
                                    <?php if ($agindoComoAdmin): ?>
                                    <option value="admin" <?= $u['role'] === 'admin' ? 'selected' : '' ?>>Administrador</option>
                                    <?php endif; ?>
                                    <option value="gerente" <?= $u['role'] === 'gerente' ? 'selected' : '' ?>>Gerente</option>
                                    <option value="usuario" <?= $u['role'] === 'usuario' ? 'selected' : '' ?>>Usuário</option>
                                </select>
                                <button type="submit" class="btn-salvar">Salvar</button>
                            </form>
                            <?php else: ?>
                                <span class="vazio" style="display:inline;padding:0;">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="secao-simples" style="margin-top:24px;">
            <h2 style="font-size:16px;">+ Novo usuário</h2>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="acao" value="criar_usuario">
                <div class="campo"><label for="novo_nome">Nome</label><input type="text" id="novo_nome" name="novo_nome" required></div>
                <div class="campo"><label for="novo_email">E-mail</label><input type="email" id="novo_email" name="novo_email" required></div>
                <div class="campo"><label for="nova_senha">Senha</label><input type="password" id="nova_senha" name="nova_senha" minlength="6" required></div>
                <div class="campo"><label for="nova_confirmar_senha">Confirmar senha</label><input type="password" id="nova_confirmar_senha" name="nova_confirmar_senha" minlength="6" required></div>
                <div class="campo"><label for="novo_papel">Papel</label>
                    <select id="novo_papel" name="novo_papel">
                        <?php if ($agindoComoAdmin): ?>
                        <option value="admin">Administrador</option>
                        <?php endif; ?>
                        <option value="gerente">Gerente</option>
                        <option value="usuario" selected>Usuário</option>
                    </select>
                </div>
                <div class="form-botoes"><button type="submit" class="btn-salvar">Cadastrar usuário</button></div>
            </form>
        </div>
    </section>
    <?php endif; ?>

    <section class="painel" style="margin-top:30px;">
        <div class="painel-topo">
            <div><h2>Log de Alterações</h2><p>Auditoria de edições de livros, pessoas e ações de controle de acesso.</p></div>
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

    <footer><p>WareSys © 2026 — Sistema acadêmico de Controle Editorial</p></footer>
</main>
</body>
</html>
