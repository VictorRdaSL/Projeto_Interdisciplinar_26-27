<?php
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/permissoes.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/log.php';
require_once __DIR__ . '/includes/isbn.php';
require_once __DIR__ . '/includes/validacao.php';

function buscarNomePorId(array $lista, int $id): string
{
    foreach ($lista as $item) {
        if ((int)$item['id'] === $id) return $item['nome'];
    }
    return '#' . $id;
}

if (!usuarioTemPermissao('livros.gerenciar')) {
    $_SESSION['flash'] = ['type' => 'erro', 'message' => 'Você não tem permissão para gerenciar livros.'];
    header('Location: livros.php');
    exit;
}

$livroId = (int)($_GET['id'] ?? 0);
$editando = $livroId > 0;

if ($editando) {
    $stmt = $conn->prepare('SELECT * FROM livros WHERE id = ?');
    $stmt->bind_param('i', $livroId);
    $stmt->execute();
    $livroAtual = $stmt->get_result()->fetch_assoc();
    if (!$livroAtual) {
        $_SESSION['flash'] = ['type' => 'erro', 'message' => 'Livro não encontrado.'];
        header('Location: livros.php');
        exit;
    }
}

$pessoasDisponiveis = [];
$r = $conn->query('SELECT id, nome FROM pessoas ORDER BY nome');
while ($row = $r->fetch_assoc()) $pessoasDisponiveis[] = $row;

// Papel desativado continua aparecendo (marcado "inativo") nas contribuições que o livro JÁ tem: sem isso o campo
// voltava vazio e salvar apagava o vínculo em silêncio. Para vínculos novos, só papéis ativos.
$papeisDisponiveis = [];
if ($editando) {
    $stmtP = $conn->prepare('SELECT id, nome, ativo FROM papeis WHERE ativo = 1 OR id IN (SELECT papel_id FROM contribuicoes WHERE livro_id = ?) ORDER BY nome');
    $stmtP->bind_param('i', $livroId);
    $stmtP->execute();
    $r = $stmtP->get_result();
} else {
    $r = $conn->query('SELECT id, nome, ativo FROM papeis WHERE ativo = 1 ORDER BY nome');
}
while ($row = $r->fetch_assoc()) $papeisDisponiveis[] = $row;

// Vínculos (pessoa-papel) que o livro tem hoje, no banco — base para aceitar papel desativado só onde já existia.
$paresExistentes = [];
if ($editando) {
    $stmtE = $conn->prepare('SELECT pessoa_id, papel_id FROM contribuicoes WHERE livro_id = ?');
    $stmtE->bind_param('i', $livroId);
    $stmtE->execute();
    $re = $stmtE->get_result();
    while ($row = $re->fetch_assoc()) $paresExistentes[(int)$row['pessoa_id'] . '-' . (int)$row['papel_id']] = true;
}

$usuariosDisponiveis = [];
$r = $conn->query('SELECT id, nome FROM vw_usuarios_publico ORDER BY nome');
while ($row = $r->fetch_assoc()) $usuariosDisponiveis[] = $row;

$erro = '';
$titulo = $editando ? $livroAtual['titulo'] : '';
$isbn = $editando ? ($livroAtual['isbn'] ?? '') : '';
$temCopia = $editando ? (int)$livroAtual['tem_copia'] : 0;
$observacoes = $editando ? ($livroAtual['observacoes'] ?? '') : '';
$responsavelSelecionado = $editando ? (int)$livroAtual['responsavel_atual_id'] : (int)$_SESSION['usuario_id'];
$contribuicoesForm = [];

if ($editando && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $stmtC = $conn->prepare('SELECT pessoa_id, papel_id FROM contribuicoes WHERE livro_id = ?');
    $stmtC->bind_param('i', $livroId);
    $stmtC->execute();
    $rc = $stmtC->get_result();
    while ($row = $rc->fetch_assoc()) $contribuicoesForm[] = $row;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_validar($_POST['csrf_token'] ?? null)) {
        $erro = 'Sessão expirada. Recarregue a página e tente novamente.';
    } else {
        // Cadastro novo só passa uma vez por exibição do formulário (duplo clique / reenvio).
        if (!$editando && !envio_unico_consumir($_POST['envio_id'] ?? null)) {
            if (empty($_SESSION['flash'])) {
                $_SESSION['flash'] = ['type' => 'erro', 'message' => MSG_ENVIO_DUPLICADO];
            }
            header('Location: livros.php');
            exit;
        }

        $titulo = entradaTexto($_POST, 'titulo');
        $isbnBruto = entradaTexto($_POST, 'isbn');
        $temCopia = isset($_POST['tem_copia']) ? 1 : 0;
        $observacoes = entradaTexto($_POST, 'observacoes');
        $responsavelSelecionado = (int)($_POST['responsavel_atual_id'] ?? 0);

        $contribuicoesPost = is_array($_POST['contribuicoes'] ?? null) ? $_POST['contribuicoes'] : [];
        $vistos = [];
        $idsPessoas = array_map('intval', array_column($pessoasDisponiveis, 'id'));
        $idsPapeis = array_map('intval', array_column($papeisDisponiveis, 'id'));
        $idsUsuarios = array_map('intval', array_column($usuariosDisponiveis, 'id'));
        $papeisInativos = [];
        foreach ($papeisDisponiveis as $p) {
            if ((int)$p['ativo'] === 0) $papeisInativos[(int)$p['id']] = $p['nome'];
        }
        $contribuicaoInvalida = false;
        $papelInativoNovo = null;
        foreach ($contribuicoesPost as $linha) {
            if (!is_array($linha)) continue;
            $pessoaId = (int)($linha['pessoa_id'] ?? 0);
            $papelId = (int)($linha['papel_id'] ?? 0);
            if ($pessoaId <= 0 || $papelId <= 0) continue;
            if (!in_array($pessoaId, $idsPessoas, true) || !in_array($papelId, $idsPapeis, true)) {
                $contribuicaoInvalida = true;
                continue;
            }
            $chave = $pessoaId . '-' . $papelId;
            if (isset($papeisInativos[$papelId]) && !isset($paresExistentes[$chave])) {
                $papelInativoNovo = $papeisInativos[$papelId];
                continue;
            }
            if (isset($vistos[$chave])) continue;
            $vistos[$chave] = true;
            $contribuicoesForm[] = ['pessoa_id' => $pessoaId, 'papel_id' => $papelId];
        }

        $isbnNormalizado = $isbnBruto !== '' ? normalizarIsbn($isbnBruto) : '';

        if ($titulo === '') {
            $erro = 'Informe o título do livro.';
        } elseif ($erroTamanho = erroTamanho($titulo, LIMITE_TITULO_LIVRO, 'O título')) {
            $erro = $erroTamanho;
        } elseif ($isbnBruto !== '' && !isbnValido($isbnBruto)) {
            $erro = 'ISBN inválido. Informe um ISBN-13 válido (13 dígitos, com dígito verificador correto), ou deixe o campo vazio.';
        } elseif ($erroTamanho = erroTamanho($observacoes, LIMITE_OBSERVACOES, 'As observações')) {
            $erro = $erroTamanho;
        } elseif (!$editando && $responsavelSelecionado <= 0) {
            $erro = 'Selecione o responsável inicial.';
        } elseif (!$editando && !in_array($responsavelSelecionado, $idsUsuarios, true)) {
            $erro = 'Responsável inicial inválido. Escolha um usuário da lista.';
        } elseif ($papelInativoNovo !== null) {
            $erro = 'O papel "' . $papelInativoNovo . '" está desativado: ele só pode ser mantido nas pessoas que já o tinham neste livro. Escolha outro papel para as demais.';
        } elseif ($contribuicaoInvalida) {
            $erro = 'Há uma pessoa ou um papel inválido nas pessoas vinculadas. Escolha apenas itens da lista.';
        } else {
            if ($isbnNormalizado !== '') {
                $sqlDup = 'SELECT id FROM livros WHERE isbn = ?' . ($editando ? ' AND id != ?' : '');
                $stmtDup = $conn->prepare($sqlDup);
                if ($editando) {
                    $stmtDup->bind_param('si', $isbnNormalizado, $livroId);
                } else {
                    $stmtDup->bind_param('s', $isbnNormalizado);
                }
                $stmtDup->execute();
                if ($stmtDup->get_result()->fetch_assoc()) {
                    $erro = 'Já existe outro livro cadastrado com esse ISBN.';
                }
            }
        }

        if ($erro === '') {
            $observacoesParam = $observacoes !== '' ? $observacoes : null;
            $isbnParam = $isbnNormalizado !== '' ? $isbnNormalizado : null;
            $usuarioId = (int)$_SESSION['usuario_id'];
            $agora = date('Y-m-d H:i:s');
            $contribuicoesAntigas = [];

            $conn->begin_transaction();
            try {
                if ($editando) {
                    $stmtCAntes = $conn->prepare('SELECT pessoa_id, papel_id FROM contribuicoes WHERE livro_id = ?');
                    $stmtCAntes->bind_param('i', $livroId);
                    $stmtCAntes->execute();
                    $rcAntes = $stmtCAntes->get_result();
                    while ($row = $rcAntes->fetch_assoc()) $contribuicoesAntigas[] = $row;

                    $stmtU = $conn->prepare('UPDATE livros SET titulo=?, isbn=?, tem_copia=?, observacoes=?, atualizado_em=? WHERE id=?');
                    $stmtU->bind_param('ssissi', $titulo, $isbnParam, $temCopia, $observacoesParam, $agora, $livroId);
                    $stmtU->execute();

                    $stmtDel = $conn->prepare('DELETE FROM contribuicoes WHERE livro_id = ?');
                    $stmtDel->bind_param('i', $livroId);
                    $stmtDel->execute();

                    if ($titulo !== $livroAtual['titulo']) {
                        registrarLog($conn, $usuarioId, 'livro.editar_titulo', 'livro', $livroId, $livroAtual['titulo'], $titulo);
                    }
                    $isbnAntigoLog = $livroAtual['isbn'] ?? null;
                    if ($isbnAntigoLog !== $isbnParam) {
                        registrarLog($conn, $usuarioId, 'livro.editar_isbn', 'livro', $livroId, $isbnAntigoLog ?: '(vazio)', $isbnParam ?: '(vazio)');
                    }
                    if ((int)$livroAtual['tem_copia'] !== $temCopia) {
                        registrarLog($conn, $usuarioId, 'livro.editar_copia', 'livro', $livroId, (int)$livroAtual['tem_copia'] ? 'Sim' : 'Não', $temCopia ? 'Sim' : 'Não');
                    }
                    $obsAntigaOriginal = $livroAtual['observacoes'] ?? null;
                    if ($obsAntigaOriginal !== $observacoesParam) {
                        registrarLog($conn, $usuarioId, 'livro.editar_observacoes', 'livro', $livroId, truncarParaLog($obsAntigaOriginal), truncarParaLog($observacoesParam));
                    }
                    $novoLivroId = $livroId;
                } else {
                    $stmtEtapa = $conn->query('SELECT id FROM etapas WHERE ativa = 1 ORDER BY ordem ASC LIMIT 1');
                    $etapaInicial = $stmtEtapa->fetch_assoc();
                    if (!$etapaInicial) throw new InvalidArgumentException('Nenhuma etapa ativa cadastrada — não é possível criar um livro.');
                    $etapaInicialId = (int)$etapaInicial['id'];

                    $stmtI = $conn->prepare('INSERT INTO livros (titulo, isbn, tem_copia, observacoes, etapa_atual_id, responsavel_atual_id, status, criado_em, atualizado_em) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
                    $statusInicial = 'em_andamento';
                    $stmtI->bind_param('ssisiisss', $titulo, $isbnParam, $temCopia, $observacoesParam, $etapaInicialId, $responsavelSelecionado, $statusInicial, $agora, $agora);
                    $stmtI->execute();
                    $novoLivroId = $conn->insert_id;

                    $stmtMov = $conn->prepare('INSERT INTO movimentacoes (livro_id, etapa_id, responsavel_id, registrado_por_id, tipo, rodada, excedente, data_entrada) VALUES (?, ?, ?, ?, ?, 1, 0, ?)');
                    $tipoInicial = 'avanco';
                    $stmtMov->bind_param('iiiiss', $novoLivroId, $etapaInicialId, $responsavelSelecionado, $usuarioId, $tipoInicial, $agora);
                    $stmtMov->execute();

                    registrarLog($conn, $usuarioId, 'livro.criar', 'livro', $novoLivroId, null, $titulo);
                }

                $chaveAntigas = [];
                foreach ($contribuicoesAntigas as $c) $chaveAntigas[$c['pessoa_id'] . '-' . $c['papel_id']] = $c;
                $chaveNovas = [];
                foreach ($contribuicoesForm as $c) $chaveNovas[$c['pessoa_id'] . '-' . $c['papel_id']] = $c;

                foreach ($chaveNovas as $chave => $c) {
                    if (!isset($chaveAntigas[$chave])) {
                        $desc = buscarNomePorId($pessoasDisponiveis, $c['pessoa_id']) . ' (' . buscarNomePorId($papeisDisponiveis, $c['papel_id']) . ')';
                        registrarLog($conn, $usuarioId, 'contribuicao.adicionar', 'livro', $novoLivroId, null, $desc);
                    }
                }
                foreach ($chaveAntigas as $chave => $c) {
                    if (!isset($chaveNovas[$chave])) {
                        $desc = buscarNomePorId($pessoasDisponiveis, $c['pessoa_id']) . ' (' . buscarNomePorId($papeisDisponiveis, $c['papel_id']) . ')';
                        registrarLog($conn, $usuarioId, 'contribuicao.remover', 'livro', $novoLivroId, $desc, null);
                    }
                }

                if ($contribuicoesForm) {
                    $stmtContrib = $conn->prepare('INSERT INTO contribuicoes (livro_id, pessoa_id, papel_id) VALUES (?, ?, ?)');
                    foreach ($contribuicoesForm as $c) {
                        $stmtContrib->bind_param('iii', $novoLivroId, $c['pessoa_id'], $c['papel_id']);
                        $stmtContrib->execute();
                    }
                }

                $conn->commit();
                $_SESSION['flash'] = ['type' => 'sucesso', 'message' => $editando ? 'Livro atualizado com sucesso.' : 'Livro cadastrado com sucesso.'];
                header('Location: livro_detalhes.php?id=' . $novoLivroId);
                exit;
            } catch (Throwable $e) {
                $conn->rollback();
                $erro = mensagemErroSegura($e, 'salvar o livro');
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-br" data-theme="<?= htmlspecialchars($_SESSION['usuario_tema'] ?? 'claro') ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>WareSys | <?= $editando ? 'Editar Livro' : 'Novo Livro' ?></title>
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
        <a href="livros.php" class="ativo"><span>📚</span>Livros</a>
        <a href="pessoas.php"><span>👤</span>Pessoas</a>
        <a href="relatorios.php"><span>📄</span>Relatórios</a>
        <a href="configuracoes.php"><span>⚙</span>Configurações</a>
    </nav>
    <div class="sidebar-bottom"><span class="versao">Versão acadêmica • MySQL / XAMPP</span></div>
</aside>

<main>
    <header class="topo">
        <div><p class="bem-vindo"><?= $editando ? 'Editando' : 'Cadastrar novo' ?></p><h2><?= $editando ? htmlspecialchars($livroAtual['titulo']) : 'Novo Livro' ?></h2></div>
        <div class="perfil">
            <div class="avatar"><?= htmlspecialchars(strtoupper(substr($_SESSION['usuario_nome'] ?? 'U', 0, 1))) ?></div>
            <div><strong><?= htmlspecialchars($_SESSION['usuario_nome'] ?? 'Usuário') ?></strong><p><span class="papel-badge papel-<?= htmlspecialchars(papelAtual()) ?>"><?= htmlspecialchars(nomePapel(papelAtual())) ?></span></p></div>
            <a href="logout.php" class="btn btn-sair">Sair</a>
        </div>
    </header>

    <?php if ($erro): ?>
        <div class="flash erro"><?= htmlspecialchars($erro) ?></div>
    <?php endif; ?>

    <section class="painel">
        <div class="painel-topo">
            <div><h2><?= $editando ? 'Editar Livro' : 'Novo Livro' ?></h2><p>Título, ISBN (opcional), cópia e observações.</p></div>
        </div>
        <form method="post">
            <?= csrf_field() ?>
            <?php if (!$editando) echo envio_unico_field(); ?>
            <div class="campo full"><label for="titulo">Título</label><input type="text" id="titulo" name="titulo" value="<?= htmlspecialchars($titulo) ?>" maxlength="<?= LIMITE_TITULO_LIVRO ?>" required></div>
            <div class="campo"><label for="isbn">ISBN (opcional)</label><input type="text" id="isbn" name="isbn" value="<?= htmlspecialchars($isbn) ?>" placeholder="Ex: 978-3-16-148410-0"></div>
            <div class="campo"><label for="tem_copia">Tem cópia?</label>
                <select id="tem_copia" name="tem_copia">
                    <option value="">Não</option>
                    <option value="1" <?= $temCopia ? 'selected' : '' ?>>Sim</option>
                </select>
            </div>
            <?php if (!$editando): ?>
            <div class="campo full"><label for="responsavel_atual_id">Responsável inicial</label>
                <select id="responsavel_atual_id" name="responsavel_atual_id" required>
                    <?php foreach ($usuariosDisponiveis as $u): ?>
                        <option value="<?= (int)$u['id'] ?>" <?= $responsavelSelecionado === (int)$u['id'] ? 'selected' : '' ?>><?= htmlspecialchars($u['nome']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <div class="campo full"><label for="observacoes">Observações</label><textarea id="observacoes" name="observacoes" maxlength="<?= LIMITE_OBSERVACOES ?>" placeholder="Texto livre, opcional..."><?= htmlspecialchars($observacoes) ?></textarea></div>

            <div class="campo full">
                <label>Pessoas vinculadas (autores, organizadores, ilustradores)</label>
                <input type="text" id="filtro-pessoas" placeholder="Filtrar pessoas nos campos abaixo..." oninput="agendarFiltroPessoasForm()" style="margin-bottom:10px;padding:13px;border:1px solid #dfe9e2;border-radius:10px;width:100%;">
                <div id="lista-contribuicoes"></div>
                <button type="button" class="btn" onclick="adicionarLinhaContribuicao()">+ Adicionar pessoa</button>
                <a href="pessoas.php" target="_blank" class="btn-cancelar" style="text-decoration:none;display:inline-block;margin-left:8px;">+ Cadastrar nova pessoa (abre em outra aba)</a>
            </div>

            <div class="form-botoes">
                <a href="<?= $editando ? 'livro_detalhes.php?id='.$livroId : 'livros.php' ?>" class="btn-cancelar">Cancelar</a>
                <button type="submit" class="btn-salvar">Salvar</button>
            </div>
        </form>
    </section>

    <footer><p>WareSys © 2026 — Sistema acadêmico de Controle Editorial</p></footer>
</main>

<script src="envio_unico.js"></script>
<script>
const PESSOAS = <?= json_encode($pessoasDisponiveis, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const PAPEIS = <?= json_encode($papeisDisponiveis, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const CONTRIBUICOES_INICIAIS = <?= json_encode($contribuicoesForm, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

let contribIndex = 0;

// Construção via DOM (createElement/textContent), nunca innerHTML com string
// concatenada — nome de pessoa é dado do usuário, não pode virar HTML/JS.
function preencherOptions(selectEl, itens, rotuloPadrao, selecionadoId){
  const optPadrao = document.createElement('option');
  optPadrao.value = '';
  optPadrao.textContent = rotuloPadrao;
  selectEl.appendChild(optPadrao);
  itens.forEach(item => {
    const opt = document.createElement('option');
    opt.value = item.id;
    opt.textContent = item.nome + (item.ativo !== undefined && Number(item.ativo) === 0 ? ' (inativo)' : '');
    if (String(item.id) === String(selecionadoId)) opt.selected = true;
    selectEl.appendChild(opt);
  });
}

function adicionarLinhaContribuicao(pessoaId, papelId){
  const i = contribIndex++;
  const div = document.createElement('div');
  div.className = 'form-grade';
  div.style.marginBottom = '10px';

  const campoPessoa = document.createElement('div');
  campoPessoa.className = 'campo';
  const labelPessoa = document.createElement('label');
  labelPessoa.textContent = 'Pessoa';
  const selectPessoa = document.createElement('select');
  selectPessoa.className = 'select-pessoa';
  selectPessoa.name = 'contribuicoes[' + i + '][pessoa_id]';
  preencherOptions(selectPessoa, PESSOAS, 'Selecione a pessoa...', pessoaId);
  campoPessoa.appendChild(labelPessoa);
  campoPessoa.appendChild(selectPessoa);

  const campoPapel = document.createElement('div');
  campoPapel.className = 'campo';
  const labelPapel = document.createElement('label');
  labelPapel.textContent = 'Papel';
  const selectPapel = document.createElement('select');
  selectPapel.name = 'contribuicoes[' + i + '][papel_id]';
  preencherOptions(selectPapel, PAPEIS, 'Selecione o papel...', papelId);
  campoPapel.appendChild(labelPapel);
  campoPapel.appendChild(selectPapel);

  const btnRemover = document.createElement('button');
  btnRemover.type = 'button';
  btnRemover.className = 'btn-cancelar';
  btnRemover.style.alignSelf = 'flex-end';
  btnRemover.textContent = 'Remover';
  btnRemover.onclick = function(){ div.remove(); };

  div.appendChild(campoPessoa);
  div.appendChild(campoPapel);
  div.appendChild(btnRemover);
  document.getElementById('lista-contribuicoes').appendChild(div);
}

CONTRIBUICOES_INICIAIS.forEach(c => adicionarLinhaContribuicao(c.pessoa_id, c.papel_id));
if (CONTRIBUICOES_INICIAIS.length === 0) adicionarLinhaContribuicao();

let filtroPessoasFormTimeout = null;
function agendarFiltroPessoasForm(){
  clearTimeout(filtroPessoasFormTimeout);
  filtroPessoasFormTimeout = setTimeout(aplicarFiltroPessoasForm, 300);
}
function aplicarFiltroPessoasForm(){
  const termo = document.getElementById('filtro-pessoas').value.trim().toLowerCase();
  document.querySelectorAll('.select-pessoa').forEach(select => {
    Array.from(select.options).forEach(opt => {
      if (opt.value === '') return;
      opt.hidden = termo !== '' && !opt.textContent.toLowerCase().includes(termo);
    });
  });
}
</script>
</body>
</html>
