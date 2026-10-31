<?php
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/permissoes.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/log.php';
require_once __DIR__ . '/includes/validacao.php';
require_once __DIR__ . '/includes/formatacao.php';
require_once __DIR__ . '/includes/campos.php';

function voltarPessoas(string $tipo, string $mensagem): never {
    $_SESSION['flash'] = ['type' => $tipo, 'message' => $mensagem];
    header('Location: pessoas.php');
    exit;
}

$errosPessoa = [];
$formPessoa = null; // preenchido quando o envio volta com erro: o modal reabre com o que foi digitado

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!usuarioTemPermissao('pessoas.gerenciar')) {
        voltarPessoas('erro', 'Você não tem permissão para gerenciar pessoas.');
    }
    if (!csrfValidar($_POST['csrf_token'] ?? null)) {
        voltarPessoas('erro', 'Sessão expirada. Tente novamente.');
    }
    $acao = $_POST['acao'] ?? '';
    $usuarioId = (int)$_SESSION['usuario_id'];

    if ($acao === 'salvar_pessoa') {
        $pessoaId = (int)($_POST['pessoa_id'] ?? 0);
        $nome = entradaTexto($_POST, 'nome');
        $email = entradaTexto($_POST, 'email');
        $telefone = entradaTexto($_POST, 'telefone');

        // Erros de campo: a página volta com o modal aberto e a mensagem junto do campo (nada é gravado).
        $errosPessoa = [];
        if ($nome === '') {
            $errosPessoa['nome'] = 'Informe o nome da pessoa.';
        } elseif ($erroTam = erroTamanho($nome, LIMITE_NOME_PESSOA, 'O nome')) {
            $errosPessoa['nome'] = $erroTam;
        }
        // O e-mail da pessoa é só um contato informativo (texto livre, aceita "não tem"): só o tamanho é conferido.
        if ($erroTam = erroTamanho($email, LIMITE_EMAIL, 'O e-mail')) {
            $errosPessoa['email'] = $erroTam;
        }
        if ($erroTam = erroTamanho($telefone, LIMITE_TELEFONE, 'O telefone')) {
            $errosPessoa['telefone'] = $erroTam;
        }
        if ($errosPessoa) {
            $formPessoa = ['id' => $pessoaId, 'nome' => $nome, 'email' => $email, 'telefone' => $telefone];
        }
        $emailParam = $email !== '' ? $email : null;
        $telefoneParam = $telefone !== '' ? $telefone : null;

        if (!$errosPessoa && $pessoaId > 0) {
            $stmtAntes = $conn->prepare('SELECT nome FROM pessoas WHERE id = ?');
            $stmtAntes->bind_param('i', $pessoaId);
            $stmtAntes->execute();
            $antes = $stmtAntes->get_result()->fetch_assoc();
            if (!$antes) voltarPessoas('erro', 'Pessoa não encontrada.');

            $conn->begin_transaction();
            try {
                $stmt = $conn->prepare('UPDATE pessoas SET nome = ?, email = ?, telefone = ? WHERE id = ?');
                $stmt->bind_param('sssi', $nome, $emailParam, $telefoneParam, $pessoaId);
                $stmt->execute();

                if ($nome !== $antes['nome']) {
                    registrarLog($conn, $usuarioId, 'pessoa.editar', 'pessoa', $pessoaId, $antes['nome'], $nome);
                }
                $conn->commit();
            } catch (Throwable $e) {
                $conn->rollback();
                voltarPessoas('erro', mensagemErroSegura($e, 'atualizar a pessoa'));
            }
            voltarPessoas('sucesso', 'Pessoa atualizada com sucesso.');
        } elseif (!$errosPessoa) {
            // Cadastro novo só passa uma vez por exibição do formulário (duplo clique / reenvio).
            if (!envioUnicoConsumir($_POST['envio_id'] ?? null)) {
                if (empty($_SESSION['flash'])) voltarPessoas('erro', MSG_ENVIO_DUPLICADO);
                header('Location: pessoas.php');
                exit;
            }
            $agora = date('Y-m-d H:i:s');
            $conn->begin_transaction();
            try {
                $stmt = $conn->prepare('INSERT INTO pessoas (nome, email, telefone, criado_em) VALUES (?, ?, ?, ?)');
                $stmt->bind_param('ssss', $nome, $emailParam, $telefoneParam, $agora);
                $stmt->execute();
                $novaPessoaId = $conn->insert_id;
                registrarLog($conn, $usuarioId, 'pessoa.criar', 'pessoa', $novaPessoaId, null, $nome);
                $conn->commit();
            } catch (Throwable $e) {
                $conn->rollback();
                voltarPessoas('erro', mensagemErroSegura($e, 'cadastrar a pessoa'));
            }
            voltarPessoas('sucesso', 'Pessoa cadastrada com sucesso.');
        }
    }

    if ($acao === 'excluir_pessoa') {
        // Excluir é irreversível: só grava com o campo de confirmação, que apenas o botão de confirmar do modal preenche.
        if (entradaTexto($_POST, 'confirmacao') !== '1') {
            voltarPessoas('erro', 'Esta ação precisa de confirmação. Use o botão de confirmar na janela que se abre; nada foi gravado.');
        }
        $pessoaId = (int)($_POST['pessoa_id'] ?? 0);
        $stmtAntes = $conn->prepare('SELECT nome FROM pessoas WHERE id = ?');
        $stmtAntes->bind_param('i', $pessoaId);
        $stmtAntes->execute();
        $antes = $stmtAntes->get_result()->fetch_assoc();

        if (!$antes) voltarPessoas('erro', 'Pessoa não encontrada.');

        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare('DELETE FROM pessoas WHERE id = ?');
            $stmt->bind_param('i', $pessoaId);
            $stmt->execute();
            registrarLog($conn, $usuarioId, 'pessoa.excluir', 'pessoa', $pessoaId, $antes['nome'], null);
            $conn->commit();
            voltarPessoas('sucesso', 'Pessoa excluída com sucesso.');
        } catch (mysqli_sql_exception $e) {
            $conn->rollback();
            voltarPessoas('erro', 'Não é possível excluir "' . $antes['nome'] . '": ela está vinculada a um ou mais livros.');
        } catch (Throwable $e) {
            $conn->rollback();
            voltarPessoas('erro', mensagemErroSegura($e, 'excluir a pessoa'));
        }
    }
}

$pessoas = [];
$r = $conn->query('SELECT id, nome, email, telefone, criado_em FROM pessoas ORDER BY nome');
while ($row = $r->fetch_assoc()) $pessoas[] = $row;


// O flash da sessão é lido, exibido e apagado pelo includes/rodape.php (toast).
$podeGerenciar = usuarioTemPermissao('pessoas.gerenciar');

$tituloPagina = 'Pessoas';
$paginaAtual = 'pessoas';
require __DIR__ . '/includes/cabecalho.php';
?>
      <header class="ws-topo">
        <div class="ws-topo__titulos">
          <h1 class="ws-topo__titulo">Pessoas</h1>
          <p class="ws-topo__subtitulo"><?= $pessoas ? escaparHtml(qtdTexto(count($pessoas), 'pessoa cadastrada', 'pessoas cadastradas')) : 'Nenhuma pessoa cadastrada' ?></p>
        </div>
<?php if ($podeGerenciar): ?>
        <div class="ws-topo__acoes">
          <button class="ws-btn ws-btn--primario" type="button" data-ws-modal-abrir="modal-pessoa" data-pessoa-nova><i data-lucide="user-plus"></i>Nova pessoa</button>
        </div>
<?php endif; ?>
      </header>

      <div class="ws-conteudo">
<?php if (!$pessoas): ?>
        <section class="ws-secao" aria-labelledby="vazio-titulo">
          <div class="ws-vazio">
            <i class="ws-vazio__icone" data-lucide="users"></i>
            <h2 class="ws-h2" id="vazio-titulo">Nenhuma pessoa cadastrada.</h2>
            <p class="ws-vazio__texto">Autores, organizadores e ilustradores vinculados a livros aparecem aqui.</p>
<?php if ($podeGerenciar): ?>
            <button class="ws-btn ws-btn--secundario" type="button" data-ws-modal-abrir="modal-pessoa" data-pessoa-nova>Cadastrar a primeira pessoa</button>
<?php endif; ?>
          </div>
        </section>
<?php else: ?>
        <section class="ws-secao" aria-labelledby="busca-titulo">
          <h2 class="ws-sr-only" id="busca-titulo">Busca</h2>
          <div class="ws-filtros">
            <div class="ws-campo">
              <label class="ws-campo__rotulo" for="busca">Buscar</label>
              <div class="ws-busca">
                <i class="ws-busca__icone ws-icone--peq" data-lucide="search"></i>
                <input class="ws-input" id="busca" type="search" placeholder="Nome ou e-mail" oninput="agendarFiltroPessoas()" autocomplete="off">
              </div>
            </div>
          </div>
        </section>

        <section class="ws-secao" aria-labelledby="lista-titulo">
          <div class="ws-secao__cabecalho">
            <div>
              <h2 id="lista-titulo">Pessoas cadastradas</h2>
              <p>Autores, organizadores e ilustradores. Não são usuários do sistema.</p>
            </div>
          </div>
          <!-- No celular as linhas viram cartões (.ws-tabela--empilhar + data-rotulo) -->
          <div class="ws-tabela-wrap" id="pessoas-tabela-wrap" tabindex="0" role="region" aria-labelledby="lista-titulo">
            <table class="ws-tabela ws-tabela--empilhar" id="tabela-pessoas">
              <thead>
                <tr>
                  <th scope="col">Nome</th>
                  <th scope="col">E-mail</th>
                  <th scope="col">Telefone</th>
                  <th scope="col">Cadastrada em</th>
<?php if ($podeGerenciar): ?>
                  <th scope="col">Ações</th>
<?php endif; ?>
                </tr>
              </thead>
              <tbody>
<?php foreach ($pessoas as $p): $pid = (int)$p['id']; ?>
                <tr data-nome="<?= escaparHtml($p['nome']) ?>" data-email="<?= escaparHtml($p['email'] ?? '') ?>">
                  <td class="ws-tabela__titulo" data-rotulo="Nome"><?= escaparHtml($p['nome']) ?></td>
                  <td data-rotulo="E-mail"><?php if ($p['email']): ?><?= escaparHtml($p['email']) ?><?php else: ?><span class="ws-tabela__suave">—</span><?php endif; ?></td>
                  <td data-rotulo="Telefone"><?php if ($p['telefone']): ?><?= escaparHtml($p['telefone']) ?><?php else: ?><span class="ws-tabela__suave">—</span><?php endif; ?></td>
                  <td class="ws-tabela__codigo" data-rotulo="Cadastrada em"><?= escaparHtml(formatarDataHora($p['criado_em'])) ?></td>
<?php if ($podeGerenciar): ?>
                  <td class="ws-tabela__acoes" data-rotulo="Ações">
                    <button class="ws-btn ws-btn--secundario ws-btn--icone ws-btn--peq" type="button" data-ws-modal-abrir="modal-pessoa"
                      data-id="<?= $pid ?>"
                      data-nome="<?= escaparHtml($p['nome']) ?>"
                      data-email="<?= escaparHtml($p['email'] ?? '') ?>"
                      data-telefone="<?= escaparHtml($p['telefone'] ?? '') ?>"
                      aria-label="<?= escaparHtml('Editar ' . $p['nome']) ?>" title="Editar"><i data-lucide="pencil"></i></button>
                    <button class="ws-btn ws-btn--secundario ws-btn--icone ws-btn--peq" type="button" data-ws-modal-abrir="modal-excluir"
                      data-id="<?= $pid ?>"
                      data-nome="<?= escaparHtml($p['nome']) ?>"
                      aria-label="<?= escaparHtml('Excluir ' . $p['nome']) ?>" title="Excluir"><i data-lucide="trash-2"></i></button>
                  </td>
<?php endif; ?>
                </tr>
<?php endforeach; ?>
              </tbody>
            </table>
          </div>

          <!-- Busca sem resultado (mostrado pelo script abaixo) -->
          <div class="ws-vazio" id="busca-sem-resultado" style="display:none;">
            <i class="ws-vazio__icone" data-lucide="search"></i>
            <h2 class="ws-h2">Nenhuma pessoa encontrada para essa busca.</h2>
            <p class="ws-vazio__texto">Confira o nome ou o e-mail.</p>
            <button class="ws-btn ws-btn--secundario" type="button" onclick="limparBuscaPessoas()">Limpar busca</button>
          </div>
        </section>
<?php endif; ?>
      </div>

<?php if ($podeGerenciar): ?>
      <!-- Modal: nova pessoa / editar pessoa (um só, preenchido pelo script abaixo) -->
      <dialog class="ws-modal" id="modal-pessoa" aria-labelledby="modal-pessoa-titulo">
        <form class="ws-modal__caixa" method="post" novalidate>
          <?= csrfCampo() ?>
          <?= envioUnicoCampo() ?>
          <input type="hidden" name="acao" value="salvar_pessoa">
          <input type="hidden" name="pessoa_id" id="pessoa_id" value="<?= $formPessoa ? (int)$formPessoa['id'] : 0 ?>">
          <header class="ws-modal__cabecalho">
            <h2 id="modal-pessoa-titulo"><?= $formPessoa && (int)$formPessoa['id'] > 0 ? 'Editar pessoa' : 'Nova pessoa' ?></h2>
            <button class="ws-fechar" type="button" data-ws-modal-fechar aria-label="Fechar"><i data-lucide="x"></i></button>
          </header>
          <div class="ws-modal__corpo ws-pilha">
            <p class="ws-texto-suave">Autor, organizador ou ilustrador. Não é usuário do sistema.</p>
            <div class="ws-campo<?= classeCampoErro($errosPessoa['nome'] ?? null) ?>">
              <label class="ws-campo__rotulo" for="pessoa_nome">Nome</label>
              <input class="ws-input" type="text" id="pessoa_nome" name="nome" value="<?= escaparHtml($formPessoa['nome'] ?? '') ?>" maxlength="<?= LIMITE_NOME_PESSOA ?>" required autofocus<?= atributosCampo('pessoa_nome', $errosPessoa['nome'] ?? null) ?>>
              <?= htmlErroCampo('pessoa_nome', $errosPessoa['nome'] ?? null) ?>
            </div>
            <div class="ws-campo<?= classeCampoErro($errosPessoa['email'] ?? null) ?>">
              <label class="ws-campo__rotulo" for="pessoa_email">E-mail <span class="ws-campo__opcional">(opcional)</span></label>
              <input class="ws-input" type="text" id="pessoa_email" name="email" value="<?= escaparHtml($formPessoa['email'] ?? '') ?>" maxlength="<?= LIMITE_EMAIL ?>"<?= atributosCampo('pessoa_email', $errosPessoa['email'] ?? null) ?>>
              <?= htmlErroCampo('pessoa_email', $errosPessoa['email'] ?? null) ?>
            </div>
            <div class="ws-campo<?= classeCampoErro($errosPessoa['telefone'] ?? null) ?>">
              <label class="ws-campo__rotulo" for="pessoa_telefone">Telefone <span class="ws-campo__opcional">(opcional)</span></label>
              <input class="ws-input" type="text" id="pessoa_telefone" name="telefone" value="<?= escaparHtml($formPessoa['telefone'] ?? '') ?>" maxlength="<?= LIMITE_TELEFONE ?>"<?= atributosCampo('pessoa_telefone', $errosPessoa['telefone'] ?? null) ?>>
              <?= htmlErroCampo('pessoa_telefone', $errosPessoa['telefone'] ?? null) ?>
            </div>
          </div>
          <footer class="ws-modal__rodape">
            <button class="ws-btn ws-btn--secundario" type="button" data-ws-modal-fechar>Cancelar</button>
            <button class="ws-btn ws-btn--primario" type="submit">Salvar pessoa</button>
          </footer>
        </form>
      </dialog>

      <!-- Modal: excluir pessoa (o campo "confirmacao" só é preenchido pelo botão de confirmar) -->
      <dialog class="ws-modal" id="modal-excluir" aria-labelledby="modal-excluir-titulo">
        <form class="ws-modal__caixa" method="post">
          <?= csrfCampo() ?>
          <input type="hidden" name="acao" value="excluir_pessoa">
          <input type="hidden" name="pessoa_id" id="excluir_pessoa_id" value="0">
          <input type="hidden" name="confirmacao" value="">
          <header class="ws-modal__cabecalho">
            <h2 id="modal-excluir-titulo">Excluir esta pessoa?</h2>
            <button class="ws-fechar" type="button" data-ws-modal-fechar aria-label="Fechar"><i data-lucide="x"></i></button>
          </header>
          <div class="ws-modal__corpo">
            <p>A pessoa sai do cadastro. Se estiver vinculada a um livro, a exclusão é recusada.</p>
          </div>
          <footer class="ws-modal__rodape">
            <button class="ws-btn ws-btn--secundario" type="button" data-ws-modal-fechar>Manter pessoa</button>
            <button class="ws-btn ws-btn--escuro" type="submit" data-confirmar-campo>Excluir pessoa</button>
          </footer>
        </form>
      </dialog>
<?php endif; ?>

      <script src="envio_unico.js"></script>
      <script>
      // Este script roda antes do waresys.js (defer): os cliques abaixo preenchem o modal antes de ele abrir.
      document.querySelectorAll('[data-ws-modal-abrir="modal-pessoa"]').forEach(function (botao) {
        botao.addEventListener('click', function () {
          const nova = botao.hasAttribute('data-pessoa-nova');
          if (window.WareSys) window.WareSys.limparErros('modal-pessoa');
          document.getElementById('modal-pessoa-titulo').textContent = nova ? 'Nova pessoa' : 'Editar pessoa';
          document.getElementById('pessoa_id').value = nova ? '0' : botao.dataset.id;
          document.getElementById('pessoa_nome').value = nova ? '' : botao.dataset.nome;
          document.getElementById('pessoa_email').value = nova ? '' : botao.dataset.email;
          document.getElementById('pessoa_telefone').value = nova ? '' : botao.dataset.telefone;
        });
      });
<?php if ($formPessoa): ?>
      // O envio voltou com erro: reabre o modal, com o que foi digitado e a mensagem junto do campo.
      document.addEventListener('DOMContentLoaded', function () { window.WareSys.abrirModal('modal-pessoa'); });
<?php endif; ?>
      document.querySelectorAll('[data-ws-modal-abrir="modal-excluir"]').forEach(function (botao) {
        botao.addEventListener('click', function () {
          document.getElementById('excluir_pessoa_id').value = botao.dataset.id;
          // textContent: o nome da pessoa é dado do usuário, nunca vira HTML.
          document.getElementById('modal-excluir-titulo').textContent = 'Excluir ' + botao.dataset.nome + '?';
          document.querySelector('#modal-excluir input[name="confirmacao"]').value = '';
        });
      });
      // Campo oculto "confirmacao": só o botão de confirmar do modal o preenche (com 1).
      document.querySelectorAll('dialog.ws-modal [data-confirmar-campo]').forEach(function (botao) {
        botao.addEventListener('click', function () {
          if (botao.form && botao.form.elements.confirmacao) botao.form.elements.confirmacao.value = '1';
        });
      });

      let filtroPessoasTimeout = null;
      function agendarFiltroPessoas(){
        clearTimeout(filtroPessoasTimeout);
        filtroPessoasTimeout = setTimeout(aplicarFiltroPessoas, 300);
      }
      function aplicarFiltroPessoas(){
        const campo = document.getElementById('busca');
        if (!campo) return;
        const termo = campo.value.trim().toLowerCase();
        let visiveis = 0;
        document.querySelectorAll('#tabela-pessoas tbody tr[data-nome]').forEach(linha=>{
          const alvo = (linha.dataset.nome + ' ' + linha.dataset.email).toLowerCase();
          const mostra = termo === '' || alvo.includes(termo);
          linha.style.display = mostra ? '' : 'none';
          if (mostra) visiveis++;
        });
        const semResultado = visiveis === 0 && termo !== '';
        document.getElementById('busca-sem-resultado').style.display = semResultado ? '' : 'none';
        document.getElementById('pessoas-tabela-wrap').style.display = semResultado ? 'none' : '';
      }
      function limparBuscaPessoas(){
        const campo = document.getElementById('busca');
        campo.value = '';
        aplicarFiltroPessoas();
        campo.focus();
      }
      </script>
<?php require __DIR__ . '/includes/rodape.php'; ?>
