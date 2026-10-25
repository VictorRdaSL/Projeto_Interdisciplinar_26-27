<?php
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/permissoes.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/log.php';
require_once __DIR__ . '/includes/isbn.php';
require_once __DIR__ . '/includes/validacao.php';
require_once __DIR__ . '/includes/mesa.php';
require_once __DIR__ . '/includes/formatacao.php';

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

$livroId = idDaUrl($_GET, 'id');
$editando = $livroId > 0;
if (isset($_GET['id']) && !$editando) {
    $_SESSION['flash'] = ['type' => 'erro', 'message' => 'Livro não encontrado.'];
    header('Location: livros.php');
    exit;
}

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

// Só Gerente e Usuário comum podem ser mesa (o Administrador não faz parte do fluxo): ver includes/mesa.php.
$usuariosDisponiveis = usuariosQuePodemSerMesa($conn);
$idsMesaPossiveis = array_map('intval', array_column($usuariosDisponiveis, 'id'));

$erro = '';
// Campo a que a mensagem de $erro se refere (titulo, isbn, observacoes, responsavel, pessoas); vazio = erro geral.
$erroCampo = '';
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
        $isbn = $isbnBruto; // o que foi digitado volta ao campo se houver erro (antes o campo voltava ao valor antigo)
        // O select envia '' para "Não" e '1' para "Sim"; isset() dava 1 nos dois casos.
        $temCopia = entradaTexto($_POST, 'tem_copia') === '1' ? 1 : 0;
        $observacoes = entradaTexto($_POST, 'observacoes');
        $responsavelSelecionado = (int)($_POST['responsavel_atual_id'] ?? 0);

        $contribuicoesPost = is_array($_POST['contribuicoes'] ?? null) ? $_POST['contribuicoes'] : [];
        $vistos = [];
        $idsPessoas = array_map('intval', array_column($pessoasDisponiveis, 'id'));
        $idsPapeis = array_map('intval', array_column($papeisDisponiveis, 'id'));
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
            $erroCampo = 'titulo';
        } elseif ($erroTamanho = erroTamanho($titulo, LIMITE_TITULO_LIVRO, 'O título')) {
            $erro = $erroTamanho;
            $erroCampo = 'titulo';
        } elseif ($isbnBruto !== '' && !isbnValido($isbnBruto)) {
            $erro = 'ISBN inválido. Informe um ISBN-13 válido (13 dígitos, com dígito verificador correto), ou deixe o campo vazio.';
            $erroCampo = 'isbn';
        } elseif ($erroTamanho = erroTamanho($observacoes, LIMITE_OBSERVACOES, 'As observações')) {
            $erro = $erroTamanho;
            $erroCampo = 'observacoes';
        } elseif (!$editando && $responsavelSelecionado <= 0) {
            $erro = 'Selecione o responsável inicial.';
            $erroCampo = 'responsavel';
        } elseif (!$editando && ($erroMesa = erroResponsavelMesa($conn, $responsavelSelecionado)) !== null) {
            $erro = $erroMesa;
            $erroCampo = 'responsavel';
        } elseif ($papelInativoNovo !== null) {
            $erro = 'O papel "' . $papelInativoNovo . '" está desativado: ele só pode ser mantido nas pessoas que já o tinham neste livro. Escolha outro papel para as demais.';
            $erroCampo = 'pessoas';
        } elseif ($contribuicaoInvalida) {
            $erro = 'Há uma pessoa ou um papel inválido nas pessoas vinculadas. Escolha apenas itens da lista.';
            $erroCampo = 'pessoas';
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
                    $erroCampo = 'isbn';
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


// ---- Apresentação (Passo IV-6): só leitura, nada aqui muda regra ou gravação.
// Ids dos campos (destino do link do resumo de erro).
$idsCampoErro = [
    'titulo' => 'titulo', 'isbn' => 'isbn', 'observacoes' => 'observacoes',
    'responsavel' => 'responsavel_atual_id', 'pessoas' => 'pessoas-vinculadas',
];
/** aria-describedby: junta só os ids que valem agora (erro quando há erro, ajuda sempre que existe). */
function ariaDescr(array $ids): string
{
    $ativos = array_keys(array_filter($ids));
    return $ativos ? ' aria-describedby="' . escaparHtml(implode(' ', $ativos)) . '"' : '';
}
$temErroCampo = fn(string $campo): bool => $erro !== '' && $erroCampo === $campo;

// Primeira etapa ativa (onde o livro entra) e etapas ativas com limite de rodadas, para a coluna lateral.
$primeiraEtapa = $conn->query('SELECT nome FROM etapas WHERE ativa = 1 ORDER BY ordem ASC LIMIT 1')->fetch_assoc();
$nomePrimeiraEtapa = $primeiraEtapa['nome'] ?? null;
$etapasComLimite = $conn->query('SELECT nome, rodadas_incluidas FROM etapas WHERE ativa = 1 AND rodadas_incluidas IS NOT NULL ORDER BY ordem')->fetch_all(MYSQLI_ASSOC);

$tituloPagina = $editando ? 'Editar livro' : 'Novo livro';
$paginaAtual = 'livros';
require __DIR__ . '/includes/cabecalho.php';
?>
      <header class="ws-topo">
        <div class="ws-topo__titulos">
          <a class="ws-topo__voltar ws-link-limpo" href="<?= $editando ? 'livro_detalhes.php?id=' . $livroId : 'livros.php' ?>"><i data-lucide="arrow-left" class="ws-icone--peq"></i><?= $editando ? 'Livro' : 'Livros' ?></a>
<?php if ($editando): ?>
          <p class="ws-eyebrow ws-eyebrow--destaque">Livro <?= escaparHtml(codigoLivro($livroId)) ?></p>
<?php endif; ?>
          <h1 class="ws-topo__titulo"><?= $editando ? 'Editar livro' : 'Novo livro' ?></h1>
<?php if ($editando): ?>
          <p class="ws-topo__subtitulo"><?= escaparHtml($livroAtual['titulo']) ?></p>
<?php elseif ($nomePrimeiraEtapa !== null): ?>
          <p class="ws-topo__subtitulo">O livro entra em <?= escaparHtml($nomePrimeiraEtapa) ?>, na mesa escolhida.</p>
<?php else: ?>
          <p class="ws-topo__subtitulo">Nenhuma etapa ativa cadastrada: não é possível criar um livro.</p>
<?php endif; ?>
        </div>
      </header>

      <div class="ws-conteudo">
        <div class="ws-grade">
          <div class="ws-col-8 ws-pilha">

<?php if ($erro !== ''): ?>
            <div class="ws-alerta ws-alerta--cobrado" role="alert" id="resumo-erro">
              <i class="ws-alerta__icone" data-lucide="circle-alert"></i>
              <div class="ws-alerta__corpo">
                <p class="ws-alerta__titulo">O livro não foi salvo.<?= isset($idsCampoErro[$erroCampo]) ? ' Corrija 1 campo.' : '' ?></p>
<?php if (isset($idsCampoErro[$erroCampo])): ?>
                <p><a href="#<?= escaparHtml($idsCampoErro[$erroCampo]) ?>"><?= escaparHtml($erro) ?></a></p>
<?php else: ?>
                <p><?= escaparHtml($erro) ?></p>
<?php endif; ?>
              </div>
            </div>
<?php endif; ?>

            <form class="ws-form ws-card ws-card--superficie" method="post" novalidate>
              <?= csrf_field() ?>
<?php if (!$editando) echo '              ' . envio_unico_field() . "\n"; ?>

              <fieldset class="ws-grupo-campos">
                <legend><h2>Dados do livro</h2></legend>
                <div class="ws-form__grade">
                  <div class="ws-campo ws-campo--inteiro<?= $temErroCampo('titulo') ? ' ws-campo--erro' : '' ?>">
                    <label class="ws-campo__rotulo" for="titulo">Título</label>
                    <input class="ws-input" type="text" id="titulo" name="titulo" value="<?= escaparHtml($titulo) ?>" maxlength="<?= LIMITE_TITULO_LIVRO ?>" required<?= $temErroCampo('titulo') ? ' aria-invalid="true"' : '' ?><?= ariaDescr(['titulo-erro' => $temErroCampo('titulo')]) ?>>
<?php if ($temErroCampo('titulo')): ?>
                    <p class="ws-campo__erro" id="titulo-erro"><i data-lucide="circle-alert"></i><?= escaparHtml($erro) ?></p>
<?php endif; ?>
                  </div>

                  <div class="ws-campo<?= $temErroCampo('isbn') ? ' ws-campo--erro' : '' ?>">
                    <label class="ws-campo__rotulo" for="isbn">ISBN <span class="ws-campo__opcional">(opcional)</span></label>
                    <input class="ws-input ws-input--codigo" type="text" id="isbn" name="isbn" value="<?= escaparHtml($isbn) ?>" inputmode="numeric"<?= $temErroCampo('isbn') ? ' aria-invalid="true"' : '' ?><?= ariaDescr(['isbn-erro' => $temErroCampo('isbn'), 'isbn-ajuda' => true]) ?>>
<?php if ($temErroCampo('isbn')): ?>
                    <p class="ws-campo__erro" id="isbn-erro"><i data-lucide="circle-alert"></i><?= escaparHtml($erro) ?></p>
<?php endif; ?>
                    <p class="ws-campo__ajuda" id="isbn-ajuda">ISBN-13. Pode ficar vazio até a Catalogação.</p>
                  </div>

                  <div class="ws-campo">
                    <label class="ws-campo__rotulo" for="tem_copia">Tem cópia?</label>
                    <select class="ws-select" id="tem_copia" name="tem_copia">
                      <option value="">Não</option>
                      <option value="1"<?= $temCopia ? ' selected' : '' ?>>Sim</option>
                    </select>
                  </div>

<?php if (!$editando): ?>
                  <div class="ws-campo ws-campo--inteiro<?= $temErroCampo('responsavel') ? ' ws-campo--erro' : '' ?>">
                    <label class="ws-campo__rotulo" for="responsavel_atual_id">Responsável inicial</label>
                    <select class="ws-select" id="responsavel_atual_id" name="responsavel_atual_id" required<?= $temErroCampo('responsavel') ? ' aria-invalid="true"' : '' ?><?= ariaDescr(['responsavel-erro' => $temErroCampo('responsavel'), 'responsavel-ajuda' => $nomePrimeiraEtapa !== null]) ?>>
<?php if (!in_array($responsavelSelecionado, $idsMesaPossiveis, true)): ?>
                      <option value=""><?= $usuariosDisponiveis ? 'Escolha o responsável' : 'Nenhum Gerente ou Usuário cadastrado' ?></option>
<?php endif; ?>
<?php foreach ($usuariosDisponiveis as $u): ?>
                      <option value="<?= (int)$u['id'] ?>"<?= $responsavelSelecionado === (int)$u['id'] ? ' selected' : '' ?>><?= escaparHtml($u['nome']) ?></option>
<?php endforeach; ?>
                    </select>
<?php if ($temErroCampo('responsavel')): ?>
                    <p class="ws-campo__erro" id="responsavel-erro"><i data-lucide="circle-alert"></i><?= escaparHtml($erro) ?></p>
<?php endif; ?>
<?php if ($nomePrimeiraEtapa !== null): ?>
                    <p class="ws-campo__ajuda" id="responsavel-ajuda">Quem recebe o livro em <?= escaparHtml($nomePrimeiraEtapa) ?>.</p>
<?php endif; ?>
<?php if (!$usuariosDisponiveis): ?>
                    <div class="ws-alerta ws-alerta--limite" role="status">
                      <i class="ws-alerta__icone" data-lucide="triangle-alert"></i>
                      <div class="ws-alerta__corpo">
                        <p>O Administrador não pode ser responsável por um livro. Cadastre um Gerente ou um Usuário em Configurações antes de criar livros.</p>
                      </div>
                    </div>
<?php endif; ?>
                  </div>
<?php endif; ?>
                </div>
              </fieldset>

              <fieldset class="ws-grupo-campos" id="pessoas-vinculadas"<?= $temErroCampo('pessoas') ? ' aria-invalid="true"' : '' ?><?= ariaDescr(['pessoas-erro' => $temErroCampo('pessoas'), 'pessoas-ajuda' => true]) ?>>
                <legend><h2>Pessoas vinculadas</h2></legend>
                <p class="ws-texto-suave" id="pessoas-ajuda" style="margin-top:-8px">Autores, organizadores e ilustradores. Não são usuários do sistema.</p>
<?php if ($temErroCampo('pessoas')): ?>
                <p class="ws-campo__erro" id="pessoas-erro"><i data-lucide="circle-alert"></i><?= escaparHtml($erro) ?></p>
<?php endif; ?>

                <div class="ws-campo">
                  <label class="ws-campo__rotulo" for="filtro-pessoas">Filtrar pessoas</label>
                  <div class="ws-busca">
                    <i class="ws-busca__icone ws-icone--peq" data-lucide="search"></i>
                    <input class="ws-input" type="search" id="filtro-pessoas" placeholder="Filtrar pessoas nos campos abaixo" oninput="agendarFiltroPessoasForm()" autocomplete="off">
                  </div>
                </div>

                <div id="lista-contribuicoes" class="ws-pilha"></div>

                <div class="ws-linha">
                  <button class="ws-btn ws-btn--secundario ws-btn--peq" type="button" onclick="adicionarLinhaContribuicao()"><i data-lucide="plus"></i>Adicionar pessoa</button>
                </div>
              </fieldset>

              <fieldset class="ws-grupo-campos">
                <legend><h2>Observações</h2></legend>
                <div class="ws-campo<?= $temErroCampo('observacoes') ? ' ws-campo--erro' : '' ?>">
                  <label class="ws-campo__rotulo" for="observacoes">Observações do livro <span class="ws-campo__opcional">(opcional)</span></label>
                  <textarea class="ws-textarea" id="observacoes" name="observacoes" maxlength="<?= LIMITE_OBSERVACOES ?>" placeholder="Pedidos da autora, prazos combinados, materiais pendentes."<?= $temErroCampo('observacoes') ? ' aria-invalid="true"' : '' ?><?= ariaDescr(['obs-erro' => $temErroCampo('observacoes'), 'obs-ajuda' => true]) ?>><?= escaparHtml($observacoes) ?></textarea>
<?php if ($temErroCampo('observacoes')): ?>
                  <p class="ws-campo__erro" id="obs-erro"><i data-lucide="circle-alert"></i><?= escaparHtml($erro) ?></p>
<?php endif; ?>
                  <p class="ws-campo__ajuda" id="obs-ajuda">Texto livre. Fica no livro, não na etapa.</p>
                </div>
              </fieldset>

              <div class="ws-form__acoes">
                <button class="ws-btn ws-btn--primario" type="submit">Salvar livro <span class="ws-btn__seta" aria-hidden="true">→</span></button>
                <a class="ws-btn ws-btn--link" href="<?= $editando ? 'livro_detalhes.php?id=' . $livroId : 'livros.php' ?>">Cancelar</a>
              </div>
            </form>
          </div>

          <aside class="ws-col-4 ws-pilha" aria-label="<?= $editando ? 'Etapa e mesa do livro' : 'Como o livro entra no fluxo' ?>">
<?php if ($editando): ?>
            <section class="ws-card">
              <p class="ws-eyebrow ws-eyebrow--destaque">Etapa e mesa</p>
              <h2 class="ws-card__titulo">Mudam na página do livro</h2>
              <p class="ws-card__texto">Editar aqui não move o livro no fluxo. Para avançar, voltar ou passar para outra mesa, use Movimentar livro.</p>
              <a class="ws-btn ws-btn--link" href="livro_detalhes.php?id=<?= $livroId ?>">Ver o livro <span class="ws-btn__seta" aria-hidden="true">→</span></a>
            </section>
<?php else: ?>
<?php if ($nomePrimeiraEtapa !== null): ?>
            <section class="ws-card">
              <p class="ws-eyebrow ws-eyebrow--destaque">Depois de salvar</p>
              <h2 class="ws-card__titulo">O livro entra em <?= escaparHtml($nomePrimeiraEtapa) ?></h2>
              <p class="ws-card__texto">A primeira movimentação é criada na mesa escolhida. Etapa, mesa e rodada passam a aparecer no histórico.</p>
            </section>
<?php endif; ?>
<?php if ($etapasComLimite): ?>
            <section class="ws-card">
              <h2 class="ws-card__titulo">Rodadas incluídas</h2>
<?php foreach ($etapasComLimite as $ec): ?>
              <?= htmlContadorRodadas($ec['nome'], (int)$ec['rodadas_incluidas'], null) . "\n" ?>
<?php endforeach; ?>
              <p class="ws-texto-suave">A última rodada incluída acende o alerta; o que passa disso é cobrado.</p>
            </section>
<?php endif; ?>
<?php endif; ?>
          </aside>
        </div>
      </div>

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

      // Um campo do design system: .ws-campo > label.ws-campo__rotulo + select.ws-select (label ligado por for/id).
      function criarCampoSelect(rotulo, id, nome, classeExtra){
        const campo = document.createElement('div');
        campo.className = 'ws-campo';
        const label = document.createElement('label');
        label.className = 'ws-campo__rotulo';
        label.htmlFor = id;
        label.textContent = rotulo;
        const select = document.createElement('select');
        select.className = 'ws-select' + (classeExtra ? ' ' + classeExtra : '');
        select.id = id;
        select.name = nome;
        campo.appendChild(label);
        campo.appendChild(select);
        return { campo, select };
      }

      function adicionarLinhaContribuicao(pessoaId, papelId){
        const i = contribIndex++;
        const linha = document.createElement('div');
        linha.className = 'ws-linha-campos';

        const pessoa = criarCampoSelect('Pessoa', 'pessoa-' + i, 'contribuicoes[' + i + '][pessoa_id]', 'select-pessoa');
        preencherOptions(pessoa.select, PESSOAS, 'Selecione a pessoa', pessoaId);
        const papel = criarCampoSelect('Papel', 'papel-' + i, 'contribuicoes[' + i + '][papel_id]');
        preencherOptions(papel.select, PAPEIS, 'Selecione o papel', papelId);

        const btnRemover = document.createElement('button');
        btnRemover.type = 'button';
        btnRemover.className = 'ws-btn ws-btn--secundario ws-btn--icone';
        const icone = document.createElement('i');
        icone.setAttribute('data-lucide', 'trash-2');
        btnRemover.appendChild(icone);
        // O nome da pessoa escolhida entra no rótulo do botão (leitor de tela); textContent, nunca HTML.
        function atualizarRotuloRemover(){
          const opcao = pessoa.select.options[pessoa.select.selectedIndex];
          btnRemover.setAttribute('aria-label', opcao && opcao.value !== '' ? 'Remover ' + opcao.textContent : 'Remover esta pessoa');
        }
        atualizarRotuloRemover();
        pessoa.select.addEventListener('change', atualizarRotuloRemover);
        btnRemover.onclick = function(){ linha.remove(); };

        linha.appendChild(pessoa.campo);
        linha.appendChild(papel.campo);
        linha.appendChild(btnRemover);
        document.getElementById('lista-contribuicoes').appendChild(linha);
        // Linhas criadas depois do carregamento precisam ganhar o ícone (o waresys.js só desenha os que já existiam).
        if (window.WareSys && typeof window.WareSys.iniciarIcones === 'function') window.WareSys.iniciarIcones();
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
<?php require __DIR__ . '/includes/rodape.php'; ?>
