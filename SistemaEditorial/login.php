<?php
require_once __DIR__ . '/includes/sessao.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/validacao.php';
require_once __DIR__ . '/includes/formatacao.php';
require_once __DIR__ . '/includes/campos.php';
require_once __DIR__ . '/includes/tema.php';

if (!empty($_SESSION['usuario_id'])) {
    header('Location: index.php');
    exit;
}

$erro = '';
$errosLogin = []; // e-mail e senha vazios: mensagem junto de cada campo
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrfValidar($_POST['csrf_token'] ?? null)) {
        $erro = 'Sessão expirada. Recarregue a página e tente novamente.';
    } else {
        $email = entradaTexto($_POST, 'email');
        $senha = entradaBruta($_POST, 'senha');

        if ($email === '') $errosLogin['email'] = 'Informe o e-mail.';
        if ($senha === '') $errosLogin['senha'] = 'Informe a senha.';
        if ($errosLogin) {
            // nada a conferir no banco: os campos voltam marcados
        } else {
            $stmt = $conn->prepare('SELECT id, nome, senha_hash, perfil, tema FROM usuarios WHERE email = ?');
            $stmt->bind_param('s', $email);
            $stmt->execute();
            $usuario = $stmt->get_result()->fetch_assoc();

            if ($usuario && password_verify($senha, $usuario['senha_hash'])) {
                session_regenerate_id(true);
                $_SESSION['usuario_id'] = $usuario['id'];
                $_SESSION['usuario_nome'] = $usuario['nome'];
                $_SESSION['usuario_perfil'] = $usuario['perfil'];
                $_SESSION['usuario_tema'] = $usuario['tema'];
                header('Location: index.php');
                exit;
            }

            $erro = 'E-mail ou senha inválidos.';
        }
    }
}
// Tela de entrada: sem sessão não há tema do usuário, então ela abre sempre no tema claro.
// Caminhos de CSS, JS e links pela BASE_URL; fonte e ícones locais (design system).
$ws_base = BASE_URL;
?>
<!DOCTYPE html>
<html lang="pt-BR" data-theme="<?= escaparHtml(temaDoUsuario()) ?>">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Entrar · WareSys</title>
  <!-- Design system WareSys: fonte Inter local primeiro, depois os 4 CSS nesta ordem -->
  <link rel="stylesheet" href="<?= escaparHtml($ws_base) ?>/assets/css/fontes.css">
  <link rel="stylesheet" href="<?= escaparHtml($ws_base) ?>/assets/css/tokens.css">
  <link rel="stylesheet" href="<?= escaparHtml($ws_base) ?>/assets/css/base.css">
  <link rel="stylesheet" href="<?= escaparHtml($ws_base) ?>/assets/css/layout.css">
  <link rel="stylesheet" href="<?= escaparHtml($ws_base) ?>/assets/css/components.css">
</head>
<body>
  <main class="ws-login">
    <!-- Painel da marca: cinza profundo, cor lisa -->
    <section class="ws-login__marca" aria-label="WareSys">
      <a class="ws-logo ws-logo--assinatura ws-logo--negativa" href="<?= escaparHtml($ws_base) ?>/login.php" aria-label="WareSys">
        <span class="ws-logo__simbolo ws-logo__simbolo--reservado" aria-hidden="true"></span>
        <span class="ws-logo__wordmark">WareSys</span>
      </a>
      <div class="ws-pilha">
        <p class="ws-eyebrow">Controle editorial · para editoras</p>
        <p class="ws-display ws-login__frase">Processos editoriais<br>de capa a capa.</p>
        <p class="ws-login__texto">Cada livro com sua etapa, seu responsável e suas rodadas à vista. Sem caderno, planilha ou grupo de WhatsApp.</p>
      </div>
      <p class="ws-login__texto" style="font-size:12px">Projeto acadêmico · Engenharia de Software · FATEC</p>
    </section>

    <section class="ws-login__area" aria-labelledby="login-titulo">
      <form class="ws-login__form" method="post" novalidate>
        <?= csrfCampo() ?>
        <div class="ws-pilha ws-pilha--peq">
          <h1 id="login-titulo">Entrar</h1>
          <p class="ws-texto-suave">Use o e-mail e a senha cadastrados pelo Gerente ou Administrador.</p>
        </div>

        <?php if ($erro !== ''): ?>
        <div class="ws-alerta ws-alerta--cobrado" role="alert">
          <i class="ws-alerta__icone" data-lucide="circle-alert"></i>
          <div class="ws-alerta__corpo"><p><?= escaparHtml($erro) ?></p></div>
        </div>
        <?php endif; ?>

        <div class="ws-campo<?= classeCampoErro($errosLogin['email'] ?? null) ?>">
          <label class="ws-campo__rotulo" for="email">E-mail</label>
          <input class="ws-input" id="email" name="email" type="email" value="<?= escaparHtml($email) ?>" autocomplete="username" required<?= atributosCampo('email', $errosLogin['email'] ?? null) ?>>
          <?= htmlErroCampo('email', $errosLogin['email'] ?? null) ?>
        </div>
        <div class="ws-campo<?= classeCampoErro($errosLogin['senha'] ?? null) ?>">
          <label class="ws-campo__rotulo" for="senha">Senha</label>
          <input class="ws-input" id="senha" name="senha" type="password" autocomplete="current-password" required<?= atributosCampo('senha', $errosLogin['senha'] ?? null) ?>>
          <?= htmlErroCampo('senha', $errosLogin['senha'] ?? null) ?>
        </div>
        <button class="ws-btn ws-btn--primario ws-btn--bloco" type="submit">Entrar <span class="ws-btn__seta" aria-hidden="true">→</span></button>
        <p class="ws-login__rodape">Sem acesso? Peça a um Gerente ou Administrador para criar sua conta em Configurações.</p>
      </form>
    </section>
  </main>

  <!-- Ícones Lucide (local; traço 1,5 aplicado pelo waresys.js) + comportamentos -->
  <script src="<?= escaparHtml($ws_base) ?>/assets/js/lucide.min.js"></script>
  <script src="<?= escaparHtml($ws_base) ?>/assets/js/waresys.js" defer></script>
</body>
</html>
