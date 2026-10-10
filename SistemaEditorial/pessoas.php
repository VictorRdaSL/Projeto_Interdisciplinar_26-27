<?php
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/permissoes.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/log.php';

function voltarPessoas(string $tipo, string $mensagem): never {
    $_SESSION['flash'] = ['type' => $tipo, 'message' => $mensagem];
    header('Location: pessoas.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!usuarioTemPermissao('pessoas.gerenciar')) {
        voltarPessoas('erro', 'Você não tem permissão para gerenciar pessoas.');
    }
    if (!csrf_validar($_POST['csrf_token'] ?? null)) {
        voltarPessoas('erro', 'Sessão expirada. Tente novamente.');
    }
    $acao = $_POST['acao'] ?? '';
    $usuarioId = (int)$_SESSION['usuario_id'];

    if ($acao === 'salvar_pessoa') {
        $pessoaId = (int)($_POST['pessoa_id'] ?? 0);
        $nome = trim($_POST['nome'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $telefone = trim($_POST['telefone'] ?? '');

        if ($nome === '') voltarPessoas('erro', 'Informe o nome da pessoa.');
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            voltarPessoas('erro', 'Informe um e-mail válido, ou deixe o campo vazio.');
        }
        $emailParam = $email !== '' ? $email : null;
        $telefoneParam = $telefone !== '' ? $telefone : null;

        if ($pessoaId > 0) {
            $stmtAntes = $conn->prepare('SELECT nome FROM pessoas WHERE id = ?');
            $stmtAntes->bind_param('i', $pessoaId);
            $stmtAntes->execute();
            $antes = $stmtAntes->get_result()->fetch_assoc();
            if (!$antes) voltarPessoas('erro', 'Pessoa não encontrada.');

            $stmt = $conn->prepare('UPDATE pessoas SET nome = ?, email = ?, telefone = ? WHERE id = ?');
            $stmt->bind_param('sssi', $nome, $emailParam, $telefoneParam, $pessoaId);
            $stmt->execute();

            if ($nome !== $antes['nome']) {
                registrarLog($conn, $usuarioId, 'pessoa.editar', 'pessoa', $pessoaId, $antes['nome'], $nome);
            }
            voltarPessoas('sucesso', 'Pessoa atualizada com sucesso.');
        } else {
            $agora = date('Y-m-d H:i:s');
            $stmt = $conn->prepare('INSERT INTO pessoas (nome, email, telefone, criado_em) VALUES (?, ?, ?, ?)');
            $stmt->bind_param('ssss', $nome, $emailParam, $telefoneParam, $agora);
            $stmt->execute();
            $novaPessoaId = $conn->insert_id;
            registrarLog($conn, $usuarioId, 'pessoa.criar', 'pessoa', $novaPessoaId, null, $nome);
            voltarPessoas('sucesso', 'Pessoa cadastrada com sucesso.');
        }
    }

    if ($acao === 'excluir_pessoa') {
        $pessoaId = (int)($_POST['pessoa_id'] ?? 0);
        $stmtAntes = $conn->prepare('SELECT nome FROM pessoas WHERE id = ?');
        $stmtAntes->bind_param('i', $pessoaId);
        $stmtAntes->execute();
        $antes = $stmtAntes->get_result()->fetch_assoc();

        if (!$antes) voltarPessoas('erro', 'Pessoa não encontrada.');

        try {
            $stmt = $conn->prepare('DELETE FROM pessoas WHERE id = ?');
            $stmt->bind_param('i', $pessoaId);
            $stmt->execute();
            registrarLog($conn, $usuarioId, 'pessoa.excluir', 'pessoa', $pessoaId, $antes['nome'], null);
            voltarPessoas('sucesso', 'Pessoa excluída com sucesso.');
        } catch (mysqli_sql_exception $e) {
            voltarPessoas('erro', 'Não é possível excluir "' . $antes['nome'] . '": ela está vinculada a um ou mais livros.');
        }
    }
}

$pessoas = [];
$r = $conn->query('SELECT id, nome, email, telefone, criado_em FROM pessoas ORDER BY nome');
while ($row = $r->fetch_assoc()) $pessoas[] = $row;

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$podeGerenciar = usuarioTemPermissao('pessoas.gerenciar');
?>
<!DOCTYPE html>
<html lang="pt-br" data-theme="<?= htmlspecialchars($_SESSION['usuario_tema'] ?? 'claro') ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>WareSys | Pessoas</title>
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
        <span class="nav-desabilitado"><span>🗄</span>Minha mesa<span class="nav-tag">em breve</span></span>
        <a href="livros.php"><span>📚</span>Livros</a>
        <a href="pessoas.php" class="ativo"><span>👤</span>Pessoas</a>
        <span class="nav-desabilitado"><span>📄</span>Relatórios<span class="nav-tag">em breve</span></span>
        <a href="configuracoes.php"><span>⚙</span>Configurações</a>
    </nav>
    <div class="sidebar-bottom"><span class="versao">Versão acadêmica • MySQL / XAMPP</span></div>
</aside>

<main>
    <header class="topo">
        <div><p class="bem-vindo">Autores, organizadores e ilustradores</p><h2>Pessoas</h2></div>
        <div class="perfil">
            <div class="avatar"><?= htmlspecialchars(strtoupper(substr($_SESSION['usuario_nome'] ?? 'U', 0, 1))) ?></div>
            <div><strong><?= htmlspecialchars($_SESSION['usuario_nome'] ?? 'Usuário') ?></strong><p><span class="papel-badge papel-<?= htmlspecialchars(papelAtual()) ?>"><?= htmlspecialchars(nomePapel(papelAtual())) ?></span></p></div>
            <a href="logout.php" class="btn btn-sair">Sair</a>
        </div>
    </header>

    <?php if ($flash): ?>
        <div class="flash <?= htmlspecialchars($flash['type']) ?>"><?= htmlspecialchars($flash['message']) ?></div>
    <?php endif; ?>

    <section class="painel" id="pessoas">
        <div class="painel-topo">
            <div><h2>Pessoas cadastradas</h2><p>Não são usuários do sistema — são autores, organizadores e ilustradores vinculados a livros.</p></div>
            <div class="pesquisa"><span>🔍</span><input id="busca" type="text" placeholder="Pesquisar por nome ou e-mail..." oninput="agendarFiltroPessoas()"></div>
        </div>
        <?php if ($podeGerenciar): ?>
        <div class="acoes" style="margin-bottom:20px;">
            <button type="button" class="btn btn-principal" onclick="abrirNovaPessoa()">+ Nova Pessoa</button>
        </div>
        <?php endif; ?>
        <div class="tabela-container">
            <table id="tabela-pessoas">
                <thead><tr><th>Nome</th><th>E-mail</th><th>Telefone</th><th>Cadastrada em</th><?php if ($podeGerenciar): ?><th>Ações</th><?php endif; ?></tr></thead>
                <tbody>
                <?php if (!$pessoas): ?>
                    <tr><td colspan="<?= $podeGerenciar ? 5 : 4 ?>" class="vazio">Nenhuma pessoa cadastrada.</td></tr>
                <?php else: foreach ($pessoas as $p): ?>
                    <tr data-nome="<?= htmlspecialchars($p['nome'], ENT_QUOTES) ?>" data-email="<?= htmlspecialchars($p['email'] ?? '', ENT_QUOTES) ?>">
                        <td><strong><?= htmlspecialchars($p['nome']) ?></strong></td>
                        <td><?= htmlspecialchars($p['email'] ?: '—') ?></td>
                        <td><?= htmlspecialchars($p['telefone'] ?: '—') ?></td>
                        <td><?= date('d/m/Y', strtotime($p['criado_em'])) ?></td>
                        <?php if ($podeGerenciar): ?>
                        <td>
                            <button type="button" class="acao-btn" title="Editar"
                                data-id="<?= (int)$p['id'] ?>"
                                data-nome="<?= htmlspecialchars($p['nome'], ENT_QUOTES) ?>"
                                data-email="<?= htmlspecialchars($p['email'] ?? '', ENT_QUOTES) ?>"
                                data-telefone="<?= htmlspecialchars($p['telefone'] ?? '', ENT_QUOTES) ?>"
                                onclick="abrirEditarPessoa(this)">✏️</button>
                            <form method="post" style="display:inline" onsubmit="return confirm('Excluir esta pessoa?');">
                                <?= csrf_field() ?>
                                <input type="hidden" name="acao" value="excluir_pessoa">
                                <input type="hidden" name="pessoa_id" value="<?= (int)$p['id'] ?>">
                                <button type="submit" class="acao-btn" title="Excluir">🗑️</button>
                            </form>
                        </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; endif; ?>
                <tr id="busca-sem-resultado" style="display:none;">
                    <td colspan="<?= $podeGerenciar ? 5 : 4 ?>" class="vazio">Nenhuma pessoa encontrada para essa busca.</td>
                </tr>
                </tbody>
            </table>
        </div>
    </section>

    <footer><p>WareSys © 2026 — Sistema acadêmico de Controle Editorial</p></footer>
</main>

<?php if ($podeGerenciar): ?>
<div class="modal" id="modal-pessoa" style="display:none;">
    <div class="modal-box">
        <a href="javascript:void(0)" class="fechar" onclick="fecharPessoa()">×</a>
        <div class="modal-topo"><h2 id="pessoa-modal-titulo">Nova Pessoa</h2><p>Autor, organizador ou ilustrador.</p></div>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="acao" value="salvar_pessoa">
            <input type="hidden" name="pessoa_id" id="pessoa_id" value="0">
            <div class="campo full"><label for="pessoa_nome">Nome</label><input type="text" id="pessoa_nome" name="nome" required></div>
            <div class="campo"><label for="pessoa_email">E-mail</label><input type="email" id="pessoa_email" name="email"></div>
            <div class="campo"><label for="pessoa_telefone">Telefone</label><input type="text" id="pessoa_telefone" name="telefone"></div>
            <div class="form-botoes"><a href="javascript:void(0)" class="btn-cancelar" onclick="fecharPessoa()">Cancelar</a><button type="submit" class="btn-salvar">Salvar</button></div>
        </form>
    </div>
</div>
<?php endif; ?>

<script>
function abrirNovaPessoa(){
  document.getElementById('pessoa-modal-titulo').textContent = 'Nova Pessoa';
  document.getElementById('pessoa_id').value = '0';
  document.getElementById('pessoa_nome').value = '';
  document.getElementById('pessoa_email').value = '';
  document.getElementById('pessoa_telefone').value = '';
  document.getElementById('modal-pessoa').style.display = 'flex';
}
function abrirEditarPessoa(botao){
  document.getElementById('pessoa-modal-titulo').textContent = 'Editar Pessoa';
  document.getElementById('pessoa_id').value = botao.dataset.id;
  document.getElementById('pessoa_nome').value = botao.dataset.nome;
  document.getElementById('pessoa_email').value = botao.dataset.email;
  document.getElementById('pessoa_telefone').value = botao.dataset.telefone;
  document.getElementById('modal-pessoa').style.display = 'flex';
}
function fecharPessoa(){
  document.getElementById('modal-pessoa').style.display = 'none';
}

let filtroPessoasTimeout = null;
function agendarFiltroPessoas(){
  clearTimeout(filtroPessoasTimeout);
  filtroPessoasTimeout = setTimeout(aplicarFiltroPessoas, 300);
}
function aplicarFiltroPessoas(){
  const termo = document.getElementById('busca').value.trim().toLowerCase();
  let visiveis = 0;
  document.querySelectorAll('#tabela-pessoas tbody tr[data-nome]').forEach(linha=>{
    const alvo = (linha.dataset.nome + ' ' + linha.dataset.email).toLowerCase();
    const mostra = termo === '' || alvo.includes(termo);
    linha.style.display = mostra ? '' : 'none';
    if (mostra) visiveis++;
  });
  const semResultado = document.getElementById('busca-sem-resultado');
  if (semResultado) semResultado.style.display = (visiveis === 0 && termo !== '') ? '' : 'none';
}
</script>
</body>
</html>
