<?php
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/permissoes.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/movimentacoes.php';
require_once __DIR__ . '/includes/formatacao.php';

$livroId = idDaUrl($_GET, 'id');
if ($livroId <= 0) {
    $_SESSION['flash'] = ['type' => 'erro', 'message' => 'Livro não encontrado.'];
    header('Location: livros.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!usuarioTemPermissao('livros.gerenciar')) {
        $_SESSION['flash'] = ['type' => 'erro', 'message' => 'Você não tem permissão para concluir resgates.'];
        header('Location: resgate.php?id=' . $livroId);
        exit;
    }
    if (!csrf_validar($_POST['csrf_token'] ?? null)) {
        $_SESSION['flash'] = ['type' => 'erro', 'message' => 'Sessão expirada. Tente novamente.'];
        header('Location: resgate.php?id=' . $livroId);
        exit;
    }

    $usuarioId = (int)$_SESSION['usuario_id'];
    $novaEtapaId = (int)($_POST['etapa_id'] ?? 0);
    $novoResponsavelId = (int)($_POST['responsavel_id'] ?? 0);
    $localEncontrado = entradaTexto($_POST, 'local_encontrado');

    try {
        $resultado = concluirResgate($conn, $livroId, $novaEtapaId, $novoResponsavelId, $localEncontrado, $usuarioId);
        $_SESSION['flash'] = ['type' => 'sucesso', 'message' => 'Resgate concluído. Livro de volta em andamento, na etapa ' . $resultado['etapa_nome'] . '.'];
        header('Location: livro_detalhes.php?id=' . $livroId);
        exit;
    } catch (Throwable $e) {
        $_SESSION['flash'] = ['type' => 'erro', 'message' => mensagemErroSegura($e, 'concluir o resgate')];
    }

    header('Location: resgate.php?id=' . $livroId);
    exit;
}

$stmt = $conn->prepare('SELECT * FROM livros WHERE id = ?');
$stmt->bind_param('i', $livroId);
$stmt->execute();
$livro = $stmt->get_result()->fetch_assoc();

if (!$livro) {
    $_SESSION['flash'] = ['type' => 'erro', 'message' => 'Livro não encontrado.'];
    header('Location: livros.php');
    exit;
}

if ($livro['status'] !== 'extraviado') {
    $_SESSION['flash'] = ['type' => 'erro', 'message' => 'Este livro não está em processo de resgate.'];
    header('Location: livro_detalhes.php?id=' . $livroId);
    exit;
}

$resgate = buscarResgateAbertoPorLivro($conn, $livroId);
if (!$resgate) {
    $_SESSION['flash'] = ['type' => 'erro', 'message' => 'Não há resgate em aberto para este livro.'];
    header('Location: livro_detalhes.php?id=' . $livroId);
    exit;
}

$stmtRef = $conn->prepare('
    SELECT m.*, e.nome AS etapa_nome, u.nome AS responsavel_nome
    FROM movimentacoes m
    LEFT JOIN etapas e ON e.id = m.etapa_id
    LEFT JOIN usuarios u ON u.id = m.responsavel_id
    WHERE m.id = ?
');
$movRefId = (int)$resgate['movimentacao_referencia_id'];
$stmtRef->bind_param('i', $movRefId);
$stmtRef->execute();
$movReferencia = $stmtRef->get_result()->fetch_assoc();

$diasDesdeAUltimaMovimentacao = $movReferencia
    ? (new DateTime())->diff(new DateTime($movReferencia['data_entrada']))->days
    : null;

$etapasDisponiveis = [];
$re = $conn->query('SELECT id, nome FROM etapas WHERE ativa = 1 ORDER BY ordem ASC');
while ($row = $re->fetch_assoc()) $etapasDisponiveis[] = $row;

// Só Gerente e Usuário comum podem ser mesa (o Administrador não faz parte do fluxo): ver includes/mesa.php.
$usuariosDisponiveis = usuariosQuePodemSerMesa($conn);

// O flash da sessão é lido, exibido e apagado pelo includes/rodape.php (toast).
$podeConcluir = usuarioTemPermissao('livros.gerenciar');
$codigo = codigoLivro((int)$livro['id']);

// Menus já marcados com o que o sistema sabe: a última etapa e a última mesa conhecidas. Etapa desativada ou mesa que
// não pode ser responsável (ex.: Administrador, em livro antigo) não são pré-escolhidas: o menu abre em branco e o
// campo obrigatório força uma escolha consciente.
$etapaSugeridaId = $movReferencia ? (int)$movReferencia['etapa_id'] : 0;
$mesaSugeridaId = $movReferencia ? (int)$movReferencia['responsavel_id'] : 0;
$etapaSugeridaValida = in_array($etapaSugeridaId, array_map('intval', array_column($etapasDisponiveis, 'id')), true);
$mesaSugeridaValida = in_array($mesaSugeridaId, array_map('intval', array_column($usuariosDisponiveis, 'id')), true);

$tituloPagina = 'Resgate · ' . $livro['titulo'];
$paginaAtual = 'livros';
require __DIR__ . '/includes/cabecalho.php';
?>
      <header class="ws-topo">
        <div class="ws-topo__titulos">
          <a class="ws-topo__voltar ws-link-limpo" href="livro_detalhes.php?id=<?= (int)$livro['id'] ?>"><i data-lucide="arrow-left" class="ws-icone--peq"></i>Livro</a>
          <p class="ws-eyebrow ws-eyebrow--destaque">Livro <?= escaparHtml($codigo) ?> · Resgate</p>
          <h1 class="ws-topo__titulo">Resgate</h1>
          <p class="ws-topo__subtitulo"><?= escaparHtml($livro['titulo']) ?></p>
        </div>
      </header>

      <div class="ws-conteudo">
        <div class="ws-alerta ws-alerta--cobrado" role="status">
          <i class="ws-alerta__icone" data-lucide="circle-alert"></i>
          <div class="ws-alerta__corpo">
            <p class="ws-alerta__titulo">Livro <?= escaparHtml($codigo) ?> está extraviado desde <?= escaparHtml(formatarDataHora($resgate['data_abertura'])) ?>.</p>
            <p>Enquanto o resgate estiver aberto, nenhuma movimentação normal pode ser feita.</p>
          </div>
        </div>

        <section class="ws-secao" aria-labelledby="posicao-titulo">
          <h2 id="posicao-titulo">Última posição conhecida</h2>
          <div class="ws-kpis">
            <div class="ws-kpi">
              <span class="ws-kpi__rotulo">Última etapa</span>
              <span class="ws-kpi__valor ws-kpi__valor--texto"><?= escaparHtml($movReferencia['etapa_nome'] ?? '—') ?></span>
            </div>
            <div class="ws-kpi">
              <span class="ws-kpi__rotulo">Última mesa</span>
              <span class="ws-kpi__valor ws-kpi__valor--texto"><?= escaparHtml($movReferencia['responsavel_nome'] ?? '—') ?></span>
            </div>
            <div class="ws-kpi">
              <span class="ws-kpi__rotulo">Última movimentação</span>
              <span class="ws-kpi__valor ws-kpi__valor--texto"><?= $movReferencia ? escaparHtml(formatarDataHora($movReferencia['data_entrada'])) : '—' ?></span>
            </div>
            <div class="ws-kpi">
              <span class="ws-kpi__rotulo">Há quantos dias</span>
              <span class="ws-kpi__valor ws-kpi__valor--texto"><?= $diasDesdeAUltimaMovimentacao !== null ? ($diasDesdeAUltimaMovimentacao === 0 ? 'menos de 1 dia' : escaparHtml(qtdTexto($diasDesdeAUltimaMovimentacao, 'dia', 'dias'))) : '—' ?></span>
              <span class="ws-status ws-status--extraviado">Extraviado</span>
            </div>
          </div>
        </section>

<?php if ($podeConcluir): ?>
        <section class="ws-secao" aria-labelledby="concluir-titulo">
          <form class="ws-form ws-card ws-card--superficie" method="post">
            <?= csrf_field() ?>

            <fieldset class="ws-grupo-campos">
              <legend><h2 id="concluir-titulo">Concluir resgate</h2></legend>
              <p class="ws-texto-suave" style="margin-top:-8px">Informe onde o livro foi encontrado, com quem está agora e em qual etapa ele realmente está.</p>
<?php if (!$usuariosDisponiveis): ?>
              <div class="ws-alerta ws-alerta--limite" role="status">
                <i class="ws-alerta__icone" data-lucide="triangle-alert"></i>
                <div class="ws-alerta__corpo">
                  <p>O Administrador não pode ser responsável por um livro. Cadastre um Gerente ou um Usuário em Configurações para concluir o resgate.</p>
                </div>
              </div>
<?php endif; ?>
              <div class="ws-form__grade">
                <div class="ws-campo ws-campo--inteiro">
                  <label class="ws-campo__rotulo" for="local_encontrado">Onde o livro foi encontrado</label>
                  <input class="ws-input" type="text" id="local_encontrado" name="local_encontrado" maxlength="<?= LIMITE_LOCAL_ENCONTRADO ?>" required aria-describedby="local-ajuda">
                  <p class="ws-campo__ajuda" id="local-ajuda">Texto livre. Fica registrado no resgate.</p>
                </div>

                <div class="ws-campo">
                  <label class="ws-campo__rotulo" for="etapa_id">Etapa em que ele realmente está</label>
                  <select class="ws-select" id="etapa_id" name="etapa_id" required<?= $etapaSugeridaValida ? ' aria-describedby="etapa-ajuda"' : '' ?>>
<?php if (!$etapaSugeridaValida): ?>
                    <option value="">Escolha a etapa</option>
<?php endif; ?>
<?php foreach ($etapasDisponiveis as $e): ?>
                    <option value="<?= (int)$e['id'] ?>"<?= $etapaSugeridaValida && (int)$e['id'] === $etapaSugeridaId ? ' selected' : '' ?>><?= escaparHtml($e['nome']) ?></option>
<?php endforeach; ?>
                  </select>
<?php if ($etapaSugeridaValida): ?>
                  <p class="ws-campo__ajuda" id="etapa-ajuda">Já vem marcada a última etapa conhecida.</p>
<?php endif; ?>
                </div>

                <div class="ws-campo">
                  <label class="ws-campo__rotulo" for="responsavel_id">Com quem está agora</label>
                  <select class="ws-select" id="responsavel_id" name="responsavel_id" required<?= $mesaSugeridaValida ? ' aria-describedby="mesa-ajuda"' : '' ?>>
<?php if (!$mesaSugeridaValida): ?>
                    <option value=""><?= $usuariosDisponiveis ? 'Escolha o responsável' : 'Nenhum Gerente ou Usuário cadastrado' ?></option>
<?php endif; ?>
<?php foreach ($usuariosDisponiveis as $u): ?>
                    <option value="<?= (int)$u['id'] ?>"<?= $mesaSugeridaValida && (int)$u['id'] === $mesaSugeridaId ? ' selected' : '' ?>><?= escaparHtml($u['nome']) ?></option>
<?php endforeach; ?>
                  </select>
<?php if ($mesaSugeridaValida): ?>
                  <p class="ws-campo__ajuda" id="mesa-ajuda">Já vem marcada a última mesa conhecida.</p>
<?php endif; ?>
                </div>
              </div>
            </fieldset>

            <div class="ws-form__acoes">
              <button class="ws-btn ws-btn--primario" type="submit">Concluir resgate <span class="ws-btn__seta" aria-hidden="true">→</span></button>
              <a class="ws-btn ws-btn--link" href="livro_detalhes.php?id=<?= (int)$livro['id'] ?>">Voltar ao livro</a>
            </div>
          </form>
        </section>
<?php endif; ?>
      </div>

      <script src="envio_unico.js"></script>
<?php require __DIR__ . '/includes/rodape.php'; ?>
