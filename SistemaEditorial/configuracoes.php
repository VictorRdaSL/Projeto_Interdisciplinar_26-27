<?php
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/includes/permissoes.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/log.php';
require_once __DIR__ . '/includes/alertas.php';
require_once __DIR__ . '/includes/validacao.php';
require_once __DIR__ . '/includes/formatacao.php';
require_once __DIR__ . '/includes/campos.php';
require_once __DIR__ . '/includes/mesa.php';

// Formulários de cadastro novo só valem uma vez por exibição (duplo clique / reenvio / "voltar").
// Se um flash do primeiro envio ainda não foi mostrado, ele é preservado.
function exigirEnvioUnico(): void
{
    if (envioUnicoConsumir($_POST['envio_id'] ?? null)) {
        return;
    }
    if (empty($_SESSION['flash'])) {
        $_SESSION['flash'] = ['type' => 'erro', 'message' => MSG_ENVIO_DUPLICADO];
    }
    header('Location: configuracoes.php');
    exit;
}

$podeGerenciarUsuarios = usuarioTemPermissao('config.usuarios');
$podeConfigurarDiasLimite = usuarioTemPermissao('config.dias_limite');
$podeGerenciarEtapas = usuarioTemPermissao('config.etapas');        // criar, editar, desativar e reordenar etapas: Gerente e Admin
$podeGerenciarPapeis = usuarioTemPermissao('config.papeis');        // papéis de autoria: só Admin
$podeExcluirEtapa = usuarioTemPermissao('config.excluir_etapa'); // Gerente e Admin (Passo 20.9)
$podeVerLog = usuarioTemPermissao('log.ver');

// Log de alterações só para quem tem log.ver (Gerente e Admin). Quem não tem e chega com filtros do log na URL é recusado
// aqui, antes de qualquer consulta: a página volta sem filtros e com o aviso de permissão.
if (!$podeVerLog && $_SERVER['REQUEST_METHOD'] === 'GET') {
    foreach (['filtro_usuario', 'filtro_acao', 'filtro_de', 'filtro_ate'] as $chaveFiltroLog) {
        if (array_key_exists($chaveFiltroLog, $_GET)) {
            $_SESSION['flash'] = ['type' => 'erro', 'message' => 'Você não tem permissão para realizar esta ação.'];
            header('Location: configuracoes.php');
            exit;
        }
    }
}
$agindoComoAdmin = papelAtual() === 'admin';

// Erros de campo (Passo 20.6): em vez de redirecionar com um aviso no topo, a página volta com a mensagem junto do campo.
// Cada formulário tem o seu par erros/valores; os dois modais (editar etapa e editar papel) reabrem sozinhos.
$errosDias = []; $valorDiasPost = null;
$errosUsuario = []; $valoresUsuario = null;
$errosEtapaNova = []; $valoresEtapaNova = null;
$errosEtapaEd = []; $formEtapaEd = null;
$errosPapelNovo = []; $valoresPapelNovo = null;
$errosPapelEd = []; $formPapelEd = null;

// Nome exato semeado em database/seed.sql — mesmo identificado usado em
// includes/movimentacoes.php (NOME_ETAPA_CATALOGACAO) pro aviso de ISBN
// vazio. Não há coluna de identificador estável em `etapas`; renomear essa
// etapa por aqui desativa aquele aviso silenciosamente (risco já documentado).
const NOME_ETAPA_CATALOGACAO_CONFIG = 'Catalogação — ISBN e ficha catalográfica';

// Ação reconhecida, mas sem permissão: cada handler abaixo já a bloquearia, só que em silêncio. Aqui o usuário recebe a
// explicação. Na prática só acontece com POST forjado (a tela esconde essas seções).
$permissaoPorAcao = [
    'alterar_dias_limite' => $podeConfigurarDiasLimite,
    'alterar_papel' => $podeGerenciarUsuarios,
    'criar_usuario' => $podeGerenciarUsuarios,
    'remover_usuario' => $podeGerenciarUsuarios,
    'reativar_usuario' => $podeGerenciarUsuarios,
    'criar_etapa' => $podeGerenciarEtapas,
    'editar_etapa' => $podeGerenciarEtapas,
    'mover_etapa' => $podeGerenciarEtapas,
    'excluir_etapa' => $podeExcluirEtapa,
    'criar_papel' => $podeGerenciarPapeis,
    'editar_papel' => $podeGerenciarPapeis,
];
$acaoRecebida = entradaTexto($_POST, 'acao');
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($permissaoPorAcao[$acaoRecebida]) && !$permissaoPorAcao[$acaoRecebida]) {
    $_SESSION['flash'] = ['type' => 'erro', 'message' => 'Você não tem permissão para realizar esta ação.'];
    header('Location: configuracoes.php');
    exit;
}

/** Erros de campo do formulário de etapa (criar: $ignorarId = 0). Vazio = tudo certo. Chaves: nome, rodadas, dias. */
function errosCamposEtapa(mysqli $conn, string $nome, string $rodadasStr, string $diasStr, int $ignorarId): array
{
    $erros = [];
    if ($nome === '') {
        $erros['nome'] = 'Informe o nome da etapa.';
    } elseif ($erroTam = erroTamanho($nome, LIMITE_NOME_ETAPA, 'O nome da etapa')) {
        $erros['nome'] = $erroTam;
    } elseif (nomeEtapaJaExiste($conn, $nome, $ignorarId)) {
        $erros['nome'] = $ignorarId > 0
            ? 'Já existe outra etapa com esse nome (mesmo desativada). Escolha outro nome.'
            : 'Já existe uma etapa com esse nome (mesmo desativada). Escolha outro nome.';
    }
    if ($rodadasStr !== '' && inteiroPositivo($rodadasStr, LIMITE_RODADAS_MAX) === null) {
        $erros['rodadas'] = 'Rodadas incluídas deve ser um número inteiro entre 1 e ' . LIMITE_RODADAS_MAX . ', ou vazio para sem limite.';
    }
    if ($diasStr !== '' && inteiroPositivo($diasStr, LIMITE_DIAS_MAX) === null) {
        $erros['dias'] = 'Dias limite deve ser um número inteiro entre 1 e ' . LIMITE_DIAS_MAX . ', ou vazio para usar o padrão global.';
    }
    return $erros;
}

/** Erro do nome do papel de autoria (criar: $ignorarId = 0), ou null. */
function erroNomePapel(mysqli $conn, string $nome, int $ignorarId): ?string
{
    if ($nome === '') return 'Informe o nome do papel.';
    if ($erroTam = erroTamanho($nome, LIMITE_NOME_PAPEL, 'O nome do papel')) return $erroTam;
    if (nomePapelJaExiste($conn, $nome, $ignorarId)) {
        return $ignorarId > 0
            ? 'Já existe outro papel com esse nome (mesmo desativado). Escolha outro nome.'
            : 'Já existe um papel com esse nome (mesmo desativado). Escolha outro nome.';
    }
    return null;
}
/**
 * Campos do formulário de etapa, em blocos (Identificação, Regras, Limites, Situação). Só apresentação: os
 * `name` dos campos que são enviados (nome, opcional, repetivel, rodadas_incluidas, dias_limite, ativa) são os mesmos
 * de sempre e a validação continua nos handlers acima. Ordem e "Finaliza o livro" são só informativos (sem `name`,
 * não são enviados): a ordem muda pelas setas da lista e "finaliza_livro" não é editável por esta tela.
 */
function camposFormEtapa(string $p, bool $edicao, int $ordemNova = 0, array $v = [], array $erros = []): void
{
    $eNome = $erros['nome'] ?? null; $eRod = $erros['rodadas'] ?? null; $eDias = $erros['dias'] ?? null;
    ?>
              <fieldset class="ws-grupo-campos">
                <legend><h3 class="ws-h2">Identificação</h3></legend>
                <div class="ws-form__grade">
                  <div class="ws-campo ws-campo--inteiro<?= classeCampoErro($eNome) ?>">
                    <label class="ws-campo__rotulo" for="<?= $p ?>_nome">Nome da etapa</label>
                    <input class="ws-input" type="text" id="<?= $p ?>_nome" name="nome" value="<?= escaparHtml($v['nome'] ?? '') ?>" maxlength="<?= LIMITE_NOME_ETAPA ?>" required<?= atributosCampo($p . '_nome', $eNome) ?>>
                    <?= htmlErroCampo($p . '_nome', $eNome) ?>
                  </div>
                  <div class="ws-campo">
                    <label class="ws-campo__rotulo" for="<?= $p ?>_ordem">Ordem</label>
                    <input class="ws-input ws-input--codigo" type="number" id="<?= $p ?>_ordem" value="<?= $edicao ? ($v['ordem'] ?? '') : $ordemNova ?>" readonly tabindex="-1" aria-describedby="<?= $p ?>_ordem_ajuda" style="max-width:120px">
                    <p class="ws-campo__ajuda" id="<?= $p ?>_ordem_ajuda"><?= $edicao ? 'Para mudar a ordem, use as setas da lista.' : 'A etapa nova entra no final do fluxo; depois use as setas da lista.' ?></p>
                  </div>
                </div>
              </fieldset>
              <fieldset class="ws-grupo-campos">
                <legend><h3 class="ws-h2">Regras</h3></legend>
                <div class="ws-pilha ws-pilha--peq">
                  <label class="ws-opcao" for="<?= $p ?>_opcional"><input type="checkbox" id="<?= $p ?>_opcional" name="opcional"<?= !empty($v['opcional']) ? ' checked' : '' ?>>Etapa opcional</label>
                  <label class="ws-opcao" for="<?= $p ?>_repetivel"><input type="checkbox" id="<?= $p ?>_repetivel" name="repetivel"<?= !empty($v['repetivel']) ? ' checked' : '' ?>>Pode ser repetida</label>
                  <div>
                    <label class="ws-opcao" for="<?= $p ?>_finaliza"><input type="checkbox" id="<?= $p ?>_finaliza"<?= !empty($v['finaliza']) ? ' checked' : '' ?> disabled aria-describedby="<?= $p ?>_finaliza_ajuda">Finaliza o livro</label>
                    <p class="ws-campo__ajuda" id="<?= $p ?>_finaliza_ajuda">Não é editável nesta tela.</p>
                  </div>
                </div>
              </fieldset>
              <fieldset class="ws-grupo-campos">
                <legend><h3 class="ws-h2">Limites</h3></legend>
                <div class="ws-form__grade">
                  <div class="ws-campo<?= classeCampoErro($eRod) ?>">
                    <label class="ws-campo__rotulo" for="<?= $p ?>_rodadas">Rodadas incluídas <span class="ws-campo__opcional">(opcional)</span></label>
                    <input class="ws-input ws-input--codigo" type="number" id="<?= $p ?>_rodadas" name="rodadas_incluidas" value="<?= escaparHtml((string)($v['rodadas'] ?? '')) ?>" min="1" max="<?= LIMITE_RODADAS_MAX ?>"<?= atributosCampo($p . '_rodadas', $eRod, $p . '_rodadas_ajuda') ?>>
                    <?= htmlErroCampo($p . '_rodadas', $eRod) ?>
                    <p class="ws-campo__ajuda" id="<?= $p ?>_rodadas_ajuda">Vazio = sem limite de rodadas</p>
                  </div>
                  <div class="ws-campo<?= classeCampoErro($eDias) ?>">
                    <label class="ws-campo__rotulo" for="<?= $p ?>_dias">Dias limite <span class="ws-campo__opcional">(opcional)</span></label>
                    <input class="ws-input ws-input--codigo" type="number" id="<?= $p ?>_dias" name="dias_limite" value="<?= escaparHtml((string)($v['dias'] ?? '')) ?>" min="1" max="<?= LIMITE_DIAS_MAX ?>"<?= atributosCampo($p . '_dias', $eDias, $p . '_dias_ajuda') ?>>
                    <?= htmlErroCampo($p . '_dias', $eDias) ?>
                    <p class="ws-campo__ajuda" id="<?= $p ?>_dias_ajuda">Vazio = usa o padrão global</p>
                  </div>
                </div>
              </fieldset>
              <fieldset class="ws-grupo-campos">
                <legend><h3 class="ws-h2">Situação</h3></legend>
<?php if ($edicao): ?>
                <label class="ws-opcao" for="<?= $p ?>_ativa"><input type="checkbox" id="<?= $p ?>_ativa" name="ativa"<?= !empty($v['ativa']) ? ' checked' : '' ?>>Etapa ativa</label>
<?php else: ?>
                <div>
                  <label class="ws-opcao" for="<?= $p ?>_ativa"><input type="checkbox" id="<?= $p ?>_ativa" checked disabled aria-describedby="<?= $p ?>_ativa_ajuda">Etapa ativa</label>
                  <p class="ws-campo__ajuda" id="<?= $p ?>_ativa_ajuda">Toda etapa nova começa ativa.</p>
                </div>
<?php endif; ?>
              </fieldset>
<?php
}

// Ações que pedem confirmação (desativar etapa com livros, mudar a ordem de etapa com livros) só gravam com o campo
// oculto "confirmacao", que apenas o botão de confirmar do modal preenche (IV-8).
const MSG_PRECISA_CONFIRMAR = 'Esta ação precisa de confirmação. Use o botão de confirmar na janela que se abre; nada foi gravado.';

// Quantos registros apontam para o usuário: livros em qualquer status (mesa), movimentações (mesa e quem registrou), resgates
// e log como autor da ação. Zero = nunca usou o sistema e pode ser APAGADO; senão a linha fica (as FKs são RESTRICT) para o
// nome continuar nos históricos, e a remoção só tira o acesso (usuarios.ativo = 0).
function usoDoUsuario(mysqli $conn, int $usuarioId): int
{
    $stmt = $conn->prepare('SELECT (SELECT COUNT(*) FROM livros WHERE responsavel_atual_id = ?)
        + (SELECT COUNT(*) FROM movimentacoes WHERE responsavel_id = ? OR registrado_por_id = ?)
        + (SELECT COUNT(*) FROM resgates WHERE registrado_por_id = ?)
        + (SELECT COUNT(*) FROM log_alteracoes WHERE usuario_id = ?)');
    $stmt->bind_param('iiiii', $usuarioId, $usuarioId, $usuarioId, $usuarioId, $usuarioId);
    $stmt->execute();
    return (int)$stmt->get_result()->fetch_row()[0];
}

// Motivo pelo qual $alvo (id, nome, perfil, ativo) NÃO pode ser removido por quem está agindo; null se pode.
// $travar: dentro da transação do POST, trava os livros do usuário para a conferência valer até o commit.
function motivoBloqueioRemocaoUsuario(mysqli $conn, array $alvo, int $atorId, bool $atorAdmin, bool $travar = false): ?string
{
    if ((int)$alvo['ativo'] !== 1) {
        return 'Este usuário já foi removido.';
    }
    if ((int)$alvo['id'] === $atorId) {
        return 'Você não pode remover a si mesmo.';
    }
    if ($alvo['perfil'] === 'admin' && !$atorAdmin) {
        return 'Somente um Administrador pode remover um Administrador.';
    }
    if ($alvo['perfil'] === 'admin' && (int)$conn->query("SELECT COUNT(*) FROM vw_usuarios_publico WHERE perfil = 'admin' AND ativo = 1")->fetch_row()[0] <= 1) {
        return 'Não é possível remover o último administrador do sistema.';
    }
    $livros = livrosSobResponsabilidade($conn, (int)$alvo['id'], $travar);
    if ($livros) {
        $titulos = array_map(fn($l) => '"' . mb_strimwidth($l['titulo'], 0, 60, '…', 'UTF-8') . '"', array_slice($livros, 0, 8));
        $resto = count($livros) - count($titulos);
        return $alvo['nome'] . ' ainda é responsável (mesa) por ' . count($livros) . ' livro(s). Passe-os para outra mesa antes de remover: '
            . implode(', ', $titulos) . ($resto > 0 ? " e mais $resto." : '.');
    }
    return null;
}

// Livros que ficariam "presos" na etapa: concluídos e cancelados não contam; extraviados contam (ainda estão fisicamente ali).
function livrosNaEtapa(mysqli $conn, int $etapaId): int
{
    $stmt = $conn->prepare("SELECT COUNT(*) FROM livros WHERE etapa_atual_id = ? AND status NOT IN ('concluido', 'cancelado')");
    $stmt->bind_param('i', $etapaId);
    $stmt->execute();
    return (int)$stmt->get_result()->fetch_row()[0];
}

// Uso de uma etapa, para decidir se ela pode ser EXCLUÍDA (Passo 20.9). Livros de qualquer status contam (concluído e
// cancelado também guardam a etapa em etapa_atual_id), e toda movimentação, aberta ou fechada, está no histórico.
function usoDaEtapa(mysqli $conn, int $etapaId): array
{
    $stmt = $conn->prepare('SELECT (SELECT COUNT(*) FROM livros WHERE etapa_atual_id = ?) AS livros, (SELECT COUNT(*) FROM movimentacoes WHERE etapa_id = ?) AS movs');
    $stmt->bind_param('ii', $etapaId, $etapaId);
    $stmt->execute();
    $r = $stmt->get_result()->fetch_assoc();
    return ['livros' => (int)$r['livros'], 'movs' => (int)$r['movs']];
}

/**
 * Por que a etapa NÃO pode ser excluída (texto para a tela), ou null se pode. Mesma função para a tela e para o
 * POST, então as duas sempre concordam. $ativas = quantas etapas ativas existem agora; $podeDesativar = quem lê
 * pode desativar (só o Admin), para a mensagem sugerir o caminho certo.
 */
function motivoBloqueioExclusaoEtapa(array $etapa, int $livros, int $movs, int $ativas, bool $podeDesativar): ?string
{
    if ((int)$etapa['finaliza_livro'] === 1) {
        return 'Esta etapa finaliza o livro; sem ela nenhum livro poderia ser concluído. Ela não pode ser excluída.';
    }
    if ((int)$etapa['ativa'] === 1 && $ativas <= 1) {
        return 'Esta é a única etapa ativa do fluxo. Não é possível excluí-la.';
    }
    if ($livros > 0 || $movs > 0) {
        $partes = [];
        if ($livros > 0) $partes[] = qtdTexto($livros, 'livro', 'livros') . ' nela';
        if ($movs > 0) $partes[] = qtdTexto($movs, 'movimentação', 'movimentações') . ' no histórico';
        $texto = 'Esta etapa tem ' . implode(' e ', $partes) . '.';
        if ((int)$etapa['ativa'] !== 1) {
            return $texto . ' Ela já está desativada: etapa com histórico não é excluída.';
        }
        return $texto . ($podeDesativar ? ' Desative em vez de excluir.' : ' Peça a um Administrador ou Gerente para desativá-la, se for o caso.');
    }
    return null;
}

/** Resumo da etapa para o log da exclusão: depois de excluída, é só o que resta dela. */
function descricaoEtapaParaLog(array $e): string
{
    $sn = fn($v) => (int)$v === 1 ? 'sim' : 'não';
    return $e['nome'] . ' — ordem ' . (int)$e['ordem'] . '; opcional: ' . $sn($e['opcional']) . '; repetível: ' . $sn($e['repetivel'])
        . '; rodadas incluídas: ' . ($e['rodadas_incluidas'] !== null ? (int)$e['rodadas_incluidas'] : '—')
        . '; dias limite: ' . ($e['dias_limite'] !== null ? (int)$e['dias_limite'] : '—')
        . '; ativa: ' . $sn($e['ativa']) . '; finaliza o livro: ' . $sn($e['finaliza_livro']);
}

/** Deixa `ordem` em 1..N sem buracos (todas as etapas, ativas ou não), só gravando as linhas que mudam. */
function renumerarEtapas(mysqli $conn): void
{
    $linhas = $conn->query('SELECT id, ordem FROM etapas ORDER BY ordem ASC, id ASC')->fetch_all(MYSQLI_ASSOC);
    $stmt = $conn->prepare('UPDATE etapas SET ordem = ? WHERE id = ?');
    $nova = 1;
    foreach ($linhas as $linha) {
        if ((int)$linha['ordem'] !== $nova) {
            $id = (int)$linha['id'];
            $stmt->bind_param('ii', $nova, $id);
            $stmt->execute();
        }
        $nova++;
    }
}

function nomeEtapaJaExiste(mysqli $conn, string $nome, int $ignorarId): bool
{
    // A collation (utf8mb4_unicode_ci) já ignora maiúsculas e acentos; vale também para etapa desativada.
    $stmt = $conn->prepare('SELECT 1 FROM etapas WHERE nome = ? AND id <> ? LIMIT 1');
    $stmt->bind_param('si', $nome, $ignorarId);
    $stmt->execute();
    return (bool)$stmt->get_result()->fetch_row();
}

function nomePapelJaExiste(mysqli $conn, string $nome, int $ignorarId): bool
{
    $stmt = $conn->prepare('SELECT 1 FROM papeis WHERE nome = ? AND id <> ? LIMIT 1');
    $stmt->bind_param('si', $nome, $ignorarId);
    $stmt->execute();
    return (bool)$stmt->get_result()->fetch_row();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'alterar_tema') {
    if (!csrfValidar($_POST['csrf_token'] ?? null)) {
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

if ($podeConfigurarDiasLimite && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'alterar_dias_limite') {
    if (!csrfValidar($_POST['csrf_token'] ?? null)) {
        $_SESSION['flash'] = ['type' => 'erro', 'message' => 'Sessão expirada. Tente novamente.'];
    } else {
        $diasPostStr = entradaTexto($_POST, 'dias_limite_livro_parado');
        $novoDiasLimite = inteiroPositivo($diasPostStr, LIMITE_DIAS_MAX);
        if ($novoDiasLimite === null) {
            $errosDias['dias_limite_livro_parado'] = $diasPostStr === '' ? 'Informe os dias-limite padrão.' : 'Informe um número inteiro de dias entre 1 e ' . LIMITE_DIAS_MAX . '.';
            $valorDiasPost = $diasPostStr;
        } else {
            $diasLimiteAntigo = (string)buscarDiasLimiteGlobal($conn);
            $stmt = $conn->prepare("UPDATE configuracoes SET valor = ? WHERE chave = 'dias_limite_livro_parado'");
            $novoDiasLimiteStr = (string)$novoDiasLimite;
            $stmt->bind_param('s', $novoDiasLimiteStr);
            $stmt->execute();
            if ($novoDiasLimiteStr !== $diasLimiteAntigo) {
                registrarLog($conn, (int)$_SESSION['usuario_id'], 'config.editar_dias_limite', 'configuracao', 0, $diasLimiteAntigo, $novoDiasLimiteStr);
            }
            $_SESSION['flash'] = ['type' => 'sucesso', 'message' => 'Limite padrão de dias atualizado com sucesso.'];
        }
    }
    if (!$errosDias) {
        header('Location: configuracoes.php');
        exit;
    }
}

if ($podeGerenciarUsuarios && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'alterar_papel') {
    if (!csrfValidar($_POST['csrf_token'] ?? null)) {
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
            $totalAdmins = (int)$conn->query("SELECT COUNT(*) total FROM vw_usuarios_publico WHERE perfil = 'admin' AND ativo = 1")->fetch_assoc()['total'];
            $stmtAtual = $conn->prepare('SELECT perfil, ativo FROM vw_usuarios_publico WHERE id = ?');
            $stmtAtual->bind_param('i', $usuarioId);
            $stmtAtual->execute();
            $usuarioAtualLinha = $stmtAtual->get_result()->fetch_assoc();

            if (!$usuarioAtualLinha) {
                $_SESSION['flash'] = ['type' => 'erro', 'message' => 'Usuário não encontrado.'];
            } elseif ((int)$usuarioAtualLinha['ativo'] !== 1) {
                $_SESSION['flash'] = ['type' => 'erro', 'message' => 'Este usuário foi removido. Reative-o antes de alterar o papel.'];
            } elseif (!$agindoComoAdmin && $usuarioAtualLinha['perfil'] === 'admin') {
                $_SESSION['flash'] = ['type' => 'erro', 'message' => 'Somente administradores podem alterar o papel de um Administrador.'];
            } elseif ($usuarioAtualLinha['perfil'] === 'admin' && $novoPapel !== 'admin' && $totalAdmins <= 1) {
                $_SESSION['flash'] = ['type' => 'erro', 'message' => 'Não é possível remover o último administrador do sistema.'];
            } else {
                $papelAnterior = $usuarioAtualLinha['perfil'];
                if ($novoPapel === $papelAnterior) {
                    $_SESSION['flash'] = ['type' => 'info', 'message' => 'Nenhuma alteração: o usuário já tem esse papel.'];
                } elseif ($novoPapel === 'admin' && ($livrosDoUsuario = livrosSobResponsabilidade($conn, $usuarioId))) {
                    // O Administrador não pode ser mesa: antes de virar Admin, os livros dele precisam ir para outra mesa.
                    $titulos = array_map(fn($l) => '"' . mb_strimwidth($l['titulo'], 0, 60, '…', 'UTF-8') . '"', array_slice($livrosDoUsuario, 0, 8));
                    $resto = count($livrosDoUsuario) - count($titulos);
                    $_SESSION['flash'] = ['type' => 'erro', 'message' =>
                        'Não é possível tornar este usuário Administrador: o Administrador não pode ser responsável (mesa) de livros, e ele ainda é responsável por '
                        . count($livrosDoUsuario) . ' livro(s). Passe-os para outra mesa antes: ' . implode(', ', $titulos) . ($resto > 0 ? " e mais $resto." : '.')];
                } else {
                    $stmt = $conn->prepare('UPDATE usuarios SET perfil = ? WHERE id = ?');
                    $stmt->bind_param('si', $novoPapel, $usuarioId);
                    $stmt->execute();
                    registrarLog($conn, (int)$_SESSION['usuario_id'], 'usuario.alterar_papel', 'usuario', $usuarioId, $papelAnterior, $novoPapel);
                    $_SESSION['flash'] = ['type' => 'sucesso', 'message' => 'Papel do usuário atualizado com sucesso.'];
                }
            }
        }
    }
    header('Location: configuracoes.php');
    exit;
}

if ($podeGerenciarUsuarios && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'criar_usuario') {
    if (!csrfValidar($_POST['csrf_token'] ?? null)) {
        $_SESSION['flash'] = ['type' => 'erro', 'message' => 'Sessão expirada. Tente novamente.'];
        header('Location: configuracoes.php');
        exit;
    }
    exigirEnvioUnico();

    $novoNome = entradaTexto($_POST, 'novo_nome');
    $novoEmail = entradaTexto($_POST, 'novo_email');
    $novaSenha = entradaBruta($_POST, 'nova_senha');
    $novaConfirmarSenha = entradaBruta($_POST, 'nova_confirmar_senha');
    $novoPapelCriado = $_POST['novo_papel'] ?? 'usuario';

    $errosUsuario = [];
    if ($novoNome === '') {
        $errosUsuario['nome'] = 'Informe o nome do usuário.';
    } elseif ($erroTam = erroTamanho($novoNome, LIMITE_NOME_USUARIO, 'O nome')) {
        $errosUsuario['nome'] = $erroTam;
    }
    if ($novoEmail === '') {
        $errosUsuario['email'] = 'Informe o e-mail do usuário.';
    } elseif (!filter_var($novoEmail, FILTER_VALIDATE_EMAIL)) {
        $errosUsuario['email'] = 'Informe um e-mail válido para o usuário.';
    } elseif ($erroTam = erroTamanho($novoEmail, LIMITE_EMAIL, 'O e-mail')) {
        $errosUsuario['email'] = $erroTam;
    } else {
        $stmtExiste = $conn->prepare('SELECT ativo FROM vw_usuarios_publico WHERE email = ?');
        $stmtExiste->bind_param('s', $novoEmail);
        $stmtExiste->execute();
        if ($linhaExiste = $stmtExiste->get_result()->fetch_assoc()) {
            $errosUsuario['email'] = (int)$linhaExiste['ativo'] === 1
                ? 'Já existe um usuário com esse e-mail.'
                : 'Este e-mail pertence a um usuário removido. Reative-o em "Removidos (sem acesso)".';
        }
    }
    if ($novaSenha === '') {
        $errosUsuario['senha'] = 'Informe a senha.';
    } elseif (strlen($novaSenha) < 6) {
        $errosUsuario['senha'] = 'A senha deve ter pelo menos 6 caracteres.';
    }
    if ($novaConfirmarSenha === '') {
        $errosUsuario['confirmar'] = 'Confirme a senha.';
    } elseif ($novaSenha !== '' && $novaSenha !== $novaConfirmarSenha) {
        $errosUsuario['confirmar'] = 'As senhas não coincidem.';
    }
    if (!is_string($novoPapelCriado) || !in_array($novoPapelCriado, PAPEIS_VALIDOS, true)) {
        $errosUsuario['papel'] = 'Escolha um papel válido.';
    } elseif (!$agindoComoAdmin && $novoPapelCriado === 'admin') {
        $errosUsuario['papel'] = 'Somente administradores podem criar um usuário com o papel de Administrador.';
    }

    if ($errosUsuario) {
        // As senhas nunca voltam para a tela; nome, e-mail e papel sim.
        $valoresUsuario = ['nome' => $novoNome, 'email' => $novoEmail, 'papel' => is_string($novoPapelCriado) ? $novoPapelCriado : 'usuario'];
    } else {
        $novaSenhaHash = password_hash($novaSenha, PASSWORD_DEFAULT);
        $criadoEm = date('Y-m-d H:i:s');
        $stmtInsert = $conn->prepare('INSERT INTO usuarios (nome, email, senha_hash, perfil, criado_em) VALUES (?, ?, ?, ?, ?)');
        $stmtInsert->bind_param('sssss', $novoNome, $novoEmail, $novaSenhaHash, $novoPapelCriado, $criadoEm);
        $stmtInsert->execute();
        $novoUsuarioId = $conn->insert_id;
        registrarLog($conn, (int)$_SESSION['usuario_id'], 'usuario.criar', 'usuario', $novoUsuarioId, null, $novoPapelCriado);
        $_SESSION['flash'] = ['type' => 'sucesso', 'message' => 'Usuário cadastrado com sucesso.'];
    }
    if (!$errosUsuario) {
        header('Location: configuracoes.php');
        exit;
    }
}
if ($podeGerenciarEtapas && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'criar_etapa') {
    if (!csrfValidar($_POST['csrf_token'] ?? null)) {
        $_SESSION['flash'] = ['type' => 'erro', 'message' => 'Sessão expirada. Tente novamente.'];
        header('Location: configuracoes.php'); exit;
    }
    exigirEnvioUnico();
    $nome = entradaTexto($_POST, 'nome');
    $opcional = isset($_POST['opcional']) ? 1 : 0;
    $repetivel = isset($_POST['repetivel']) ? 1 : 0;
    $rodadasStr = entradaTexto($_POST, 'rodadas_incluidas');
    $diasStr = entradaTexto($_POST, 'dias_limite');

    $errosEtapaNova = errosCamposEtapa($conn, $nome, $rodadasStr, $diasStr, 0);
    if ($errosEtapaNova) {
        $valoresEtapaNova = ['nome' => $nome, 'opcional' => $opcional, 'repetivel' => $repetivel, 'rodadas' => $rodadasStr, 'dias' => $diasStr];
    } else {
        $rodadas = $rodadasStr === '' ? null : (int)$rodadasStr;
        $dias = $diasStr === '' ? null : (int)$diasStr;
        // Sempre entra no final do fluxo — reposicionar é feito depois, pelas setas da tabela.
        $novaOrdem = (int)$conn->query('SELECT COALESCE(MAX(ordem), 0) + 1 AS o FROM etapas')->fetch_assoc()['o'];

        $stmt = $conn->prepare('INSERT INTO etapas (nome, ordem, opcional, repetivel, rodadas_incluidas, dias_limite, ativa, finaliza_livro) VALUES (?, ?, ?, ?, ?, ?, 1, 0)');
        $stmt->bind_param('siiiii', $nome, $novaOrdem, $opcional, $repetivel, $rodadas, $dias);
        $stmt->execute();
        $novaEtapaId = $conn->insert_id;
        registrarLog($conn, (int)$_SESSION['usuario_id'], 'etapa.criar', 'etapa', $novaEtapaId, null, $nome);
        $_SESSION['flash'] = ['type' => 'sucesso', 'message' => 'Etapa cadastrada com sucesso.'];
    }
    if (!$errosEtapaNova) {
        header('Location: configuracoes.php');
        exit;
    }
}

if ($podeGerenciarEtapas && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'editar_etapa') {
    if (!csrfValidar($_POST['csrf_token'] ?? null)) {
        $_SESSION['flash'] = ['type' => 'erro', 'message' => 'Sessão expirada. Tente novamente.'];
        header('Location: configuracoes.php'); exit;
    }
    $etapaId = (int)($_POST['etapa_id'] ?? 0);
    $nome = entradaTexto($_POST, 'nome');
    $opcional = isset($_POST['opcional']) ? 1 : 0;
    $repetivel = isset($_POST['repetivel']) ? 1 : 0;
    $rodadasStr = entradaTexto($_POST, 'rodadas_incluidas');
    $diasStr = entradaTexto($_POST, 'dias_limite');
    $ativa = isset($_POST['ativa']) ? 1 : 0;

    $stmtAtual = $conn->prepare('SELECT * FROM etapas WHERE id = ?');
    $stmtAtual->bind_param('i', $etapaId);
    $stmtAtual->execute();
    $etapaAtualRow = $stmtAtual->get_result()->fetch_assoc();

    if (!$etapaAtualRow) {
        $_SESSION['flash'] = ['type' => 'erro', 'message' => 'Etapa não encontrada.'];
    } elseif (($errosEtapaEd = errosCamposEtapa($conn, $nome, $rodadasStr, $diasStr, $etapaId)) !== []) {
        $formEtapaEd = [
            'id' => $etapaId, 'nomeOriginal' => $etapaAtualRow['nome'], 'ordem' => (int)$etapaAtualRow['ordem'], 'finaliza' => (int)$etapaAtualRow['finaliza_livro'],
            'ativaOriginal' => (int)$etapaAtualRow['ativa'], 'qtdLivros' => livrosNaEtapa($conn, $etapaId),
            'valores' => ['nome' => $nome, 'opcional' => $opcional, 'repetivel' => $repetivel, 'rodadas' => $rodadasStr, 'dias' => $diasStr, 'ativa' => $ativa],
        ];
    } else {
        $rodadas = $rodadasStr === '' ? null : (int)$rodadasStr;
        $dias = $diasStr === '' ? null : (int)$diasStr;
        $rodadasAntigo = $etapaAtualRow['rodadas_incluidas'] !== null ? (int)$etapaAtualRow['rodadas_incluidas'] : null;
        $diasAntigo = $etapaAtualRow['dias_limite'] !== null ? (int)$etapaAtualRow['dias_limite'] : null;

        // Desativar uma etapa que ainda tem livros só grava com o campo de confirmação (preenchido só pelo botão do modal).
        if ((int)$etapaAtualRow['ativa'] === 1 && $ativa === 0 && livrosNaEtapa($conn, $etapaId) > 0 && entradaTexto($_POST, 'confirmacao') !== '1') {
            $_SESSION['flash'] = ['type' => 'erro', 'message' => MSG_PRECISA_CONFIRMAR];
            header('Location: configuracoes.php');
            exit;
        }

        // Muda só daqui pra frente: calcularExcedente() lê etapas.rodadas_incluidas
        // no momento de cada movimentação — o que já foi gravado em
        // movimentacoes.excedente não é tocado por este UPDATE.
        $stmt = $conn->prepare('UPDATE etapas SET nome = ?, opcional = ?, repetivel = ?, rodadas_incluidas = ?, dias_limite = ?, ativa = ? WHERE id = ?');
        $stmt->bind_param('siiiiii', $nome, $opcional, $repetivel, $rodadas, $dias, $ativa, $etapaId);
        $stmt->execute();

        $uid = (int)$_SESSION['usuario_id'];
        if ($nome !== $etapaAtualRow['nome']) {
            registrarLog($conn, $uid, 'etapa.editar', 'etapa', $etapaId, 'Nome: ' . $etapaAtualRow['nome'], 'Nome: ' . $nome);
        }
        if ($opcional !== (int)$etapaAtualRow['opcional']) {
            registrarLog($conn, $uid, 'etapa.editar', 'etapa', $etapaId, 'Opcional: ' . ($etapaAtualRow['opcional'] ? 'Sim' : 'Não'), 'Opcional: ' . ($opcional ? 'Sim' : 'Não'));
        }
        if ($repetivel !== (int)$etapaAtualRow['repetivel']) {
            registrarLog($conn, $uid, 'etapa.editar', 'etapa', $etapaId, 'Repetível: ' . ($etapaAtualRow['repetivel'] ? 'Sim' : 'Não'), 'Repetível: ' . ($repetivel ? 'Sim' : 'Não'));
        }
        if ($rodadas !== $rodadasAntigo) {
            registrarLog($conn, $uid, 'etapa.editar', 'etapa', $etapaId, 'Rodadas incluídas: ' . ($rodadasAntigo ?? 'sem limite'), 'Rodadas incluídas: ' . ($rodadas ?? 'sem limite'));
        }
        if ($dias !== $diasAntigo) {
            registrarLog($conn, $uid, 'etapa.editar', 'etapa', $etapaId, 'Dias limite: ' . ($diasAntigo ?? 'padrão global'), 'Dias limite: ' . ($dias ?? 'padrão global'));
        }
        if ($ativa !== (int)$etapaAtualRow['ativa']) {
            if ($ativa === 0) {
                registrarLog($conn, $uid, 'etapa.desativar', 'etapa', $etapaId, 'Ativa', 'Inativa');
            } else {
                registrarLog($conn, $uid, 'etapa.editar', 'etapa', $etapaId, 'Inativa', 'Ativa');
            }
        }

        $_SESSION['flash'] = ['type' => 'sucesso', 'message' => 'Etapa atualizada com sucesso.'];
    }
    if (!$errosEtapaEd) {
        header('Location: configuracoes.php');
        exit;
    }
}

if ($podeGerenciarEtapas && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'mover_etapa') {
    if (!csrfValidar($_POST['csrf_token'] ?? null)) {
        $_SESSION['flash'] = ['type' => 'erro', 'message' => 'Sessão expirada. Tente novamente.'];
        header('Location: configuracoes.php'); exit;
    }
    $etapaId = (int)($_POST['etapa_id'] ?? 0);
    $direcao = $_POST['direcao'] ?? '';

    $todasEtapas = [];
    $rt = $conn->query('SELECT id, ordem FROM etapas ORDER BY ordem ASC, id ASC');
    while ($row = $rt->fetch_assoc()) $todasEtapas[] = $row;

    $idxAtual = null;
    foreach ($todasEtapas as $i => $e) {
        if ((int)$e['id'] === $etapaId) { $idxAtual = $i; break; }
    }
    $idxVizinho = $idxAtual === null ? null : ($direcao === 'up' ? $idxAtual - 1 : ($direcao === 'down' ? $idxAtual + 1 : null));

    if ($idxAtual === null || $idxVizinho === null || $idxVizinho < 0 || $idxVizinho >= count($todasEtapas)) {
        $_SESSION['flash'] = ['type' => 'erro', 'message' => 'Não é possível mover essa etapa nessa direção.'];
    } else {
        $atual = $todasEtapas[$idxAtual];
        $vizinho = $todasEtapas[$idxVizinho];
        $ordemAtual = (int)$atual['ordem'];
        $ordemVizinho = (int)$vizinho['ordem'];
        $idAtual = (int)$atual['id'];
        $idVizinho = (int)$vizinho['id'];

        // Mudar a ordem de uma etapa que tem livros só grava com o campo de confirmação (preenchido só pelo botão do modal).
        if (livrosNaEtapa($conn, $idAtual) > 0 && entradaTexto($_POST, 'confirmacao') !== '1') {
            $_SESSION['flash'] = ['type' => 'erro', 'message' => MSG_PRECISA_CONFIRMAR];
            header('Location: configuracoes.php');
            exit;
        }

        $conn->begin_transaction();
        try {
            $stmtMov = $conn->prepare('UPDATE etapas SET ordem = ? WHERE id = ?');
            $stmtMov->bind_param('ii', $ordemVizinho, $idAtual);
            $stmtMov->execute();
            $stmtMov->bind_param('ii', $ordemAtual, $idVizinho);
            $stmtMov->execute();

            $uid = (int)$_SESSION['usuario_id'];
            registrarLog($conn, $uid, 'etapa.editar', 'etapa', $idAtual, 'Ordem: ' . $ordemAtual, 'Ordem: ' . $ordemVizinho);
            registrarLog($conn, $uid, 'etapa.editar', 'etapa', $idVizinho, 'Ordem: ' . $ordemVizinho, 'Ordem: ' . $ordemAtual);

            $conn->commit();
            $_SESSION['flash'] = ['type' => 'sucesso', 'message' => 'Ordem atualizada com sucesso.'];
        } catch (Throwable $e) {
            $conn->rollback();
            $_SESSION['flash'] = ['type' => 'erro', 'message' => 'Não foi possível reordenar.'];
        }
    }
    header('Location: configuracoes.php');
    exit;
}

// Remover usuário: Gerente e Admin. Quem nunca usou o sistema é apagado de vez; quem já aparece em algum histórico perde o
// acesso (ativo = 0) e a linha fica, para o nome continuar na linha do tempo, nos relatórios e no log. A confirmação vem do
// modal (campo oculto "confirmacao"). Tudo numa transação que trava a linha do usuário (e os livros dele).
if ($podeGerenciarUsuarios && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'remover_usuario') {
    if (!csrfValidar($_POST['csrf_token'] ?? null)) {
        $_SESSION['flash'] = ['type' => 'erro', 'message' => 'Sessão expirada. Tente novamente.'];
        header('Location: configuracoes.php'); exit;
    }
    $usuarioRemoverId = (int)($_POST['usuario_id'] ?? 0);
    if (entradaTexto($_POST, 'confirmacao') !== '1') {
        $_SESSION['flash'] = ['type' => 'erro', 'message' => MSG_PRECISA_CONFIRMAR];
        header('Location: configuracoes.php'); exit;
    }

    $conn->begin_transaction();
    try {
        $stmtU = $conn->prepare('SELECT id, nome, email, perfil, ativo FROM usuarios WHERE id = ? FOR UPDATE');
        $stmtU->bind_param('i', $usuarioRemoverId);
        $stmtU->execute();
        $alvoRemover = $stmtU->get_result()->fetch_assoc();
        if (!$alvoRemover) {
            throw new InvalidArgumentException('Usuário não encontrado.');
        }
        $motivo = motivoBloqueioRemocaoUsuario($conn, $alvoRemover, (int)$_SESSION['usuario_id'], $agindoComoAdmin, true);
        if ($motivo !== null) {
            throw new InvalidArgumentException($motivo);
        }

        $descricaoUsuario = $alvoRemover['nome'] . ' <' . $alvoRemover['email'] . '> — ' . nomePapel($alvoRemover['perfil']);
        if (usoDoUsuario($conn, $usuarioRemoverId) === 0) {
            $stmtD = $conn->prepare('DELETE FROM usuarios WHERE id = ?');
            $stmtD->bind_param('i', $usuarioRemoverId);
            $stmtD->execute();
            registrarLog($conn, (int)$_SESSION['usuario_id'], 'usuario.excluir', 'usuario', $usuarioRemoverId, truncarParaLog($descricaoUsuario), '(excluído; nunca usou o sistema)');
            $mensagemRemover = 'Usuário "' . $alvoRemover['nome'] . '" excluído.';
        } else {
            $stmtA = $conn->prepare('UPDATE usuarios SET ativo = 0 WHERE id = ?');
            $stmtA->bind_param('i', $usuarioRemoverId);
            $stmtA->execute();
            registrarLog($conn, (int)$_SESSION['usuario_id'], 'usuario.remover', 'usuario', $usuarioRemoverId, truncarParaLog($descricaoUsuario), '(removido; sem acesso ao sistema)');
            $mensagemRemover = 'Usuário "' . $alvoRemover['nome'] . '" removido: não entra mais no sistema, e o nome continua nos históricos.';
        }

        $conn->commit();
        $_SESSION['flash'] = ['type' => 'sucesso', 'message' => $mensagemRemover];
    } catch (Throwable $e) {
        $conn->rollback();
        // 1451 = uma ação simultânea passou a apontar para o usuário entre a conferência e o DELETE; a FK RESTRICT segurou.
        $mensagem = ($e instanceof mysqli_sql_exception && $e->getCode() === 1451)
            ? 'Este usuário passou a ter registros no sistema e não foi excluído. Tente remover de novo.'
            : mensagemErroSegura($e, 'remover o usuário');
        $_SESSION['flash'] = ['type' => 'erro', 'message' => $mensagem];
    }
    header('Location: configuracoes.php');
    exit;
}

// Reativar usuário removido (sem acesso): devolve o acesso. Gerente não mexe em Administrador (como no papel de acesso).
if ($podeGerenciarUsuarios && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'reativar_usuario') {
    if (!csrfValidar($_POST['csrf_token'] ?? null)) {
        $_SESSION['flash'] = ['type' => 'erro', 'message' => 'Sessão expirada. Tente novamente.'];
        header('Location: configuracoes.php'); exit;
    }
    $usuarioReativarId = (int)($_POST['usuario_id'] ?? 0);
    $conn->begin_transaction();
    try {
        $stmtU = $conn->prepare('SELECT id, nome, email, perfil, ativo FROM usuarios WHERE id = ? FOR UPDATE');
        $stmtU->bind_param('i', $usuarioReativarId);
        $stmtU->execute();
        $alvoReativar = $stmtU->get_result()->fetch_assoc();
        if (!$alvoReativar) {
            throw new InvalidArgumentException('Usuário não encontrado.');
        }
        if ((int)$alvoReativar['ativo'] === 1) {
            throw new InvalidArgumentException('Este usuário já está ativo.');
        }
        if ($alvoReativar['perfil'] === 'admin' && !$agindoComoAdmin) {
            throw new InvalidArgumentException('Somente um Administrador pode reativar um Administrador.');
        }
        $stmtA = $conn->prepare('UPDATE usuarios SET ativo = 1 WHERE id = ?');
        $stmtA->bind_param('i', $usuarioReativarId);
        $stmtA->execute();
        registrarLog($conn, (int)$_SESSION['usuario_id'], 'usuario.reativar', 'usuario', $usuarioReativarId, '(removido; sem acesso)', truncarParaLog($alvoReativar['nome'] . ' <' . $alvoReativar['email'] . '> — reativado'));
        $conn->commit();
        $_SESSION['flash'] = ['type' => 'sucesso', 'message' => 'Usuário "' . $alvoReativar['nome'] . '" reativado: o acesso foi devolvido.'];
    } catch (Throwable $e) {
        $conn->rollback();
        $_SESSION['flash'] = ['type' => 'erro', 'message' => mensagemErroSegura($e, 'reativar o usuário')];
    }
    header('Location: configuracoes.php');
    exit;
}

// Excluir etapa (Passo 20.9): Gerente e Admin, só etapa que nunca foi usada. A confirmação vem do modal (campo oculto
// "confirmacao"); sem ela nada é excluído, nem sem JavaScript. Tudo numa transação que trava a linha da etapa.
if ($podeExcluirEtapa && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'excluir_etapa') {
    if (!csrfValidar($_POST['csrf_token'] ?? null)) {
        $_SESSION['flash'] = ['type' => 'erro', 'message' => 'Sessão expirada. Tente novamente.'];
        header('Location: configuracoes.php'); exit;
    }
    $etapaId = (int)($_POST['etapa_id'] ?? 0);
    if (entradaTexto($_POST, 'confirmacao') !== '1') {
        $_SESSION['flash'] = ['type' => 'erro', 'message' => MSG_PRECISA_CONFIRMAR];
        header('Location: configuracoes.php'); exit;
    }

    $conn->begin_transaction();
    try {
        $stmtE = $conn->prepare('SELECT * FROM etapas WHERE id = ? FOR UPDATE');
        $stmtE->bind_param('i', $etapaId);
        $stmtE->execute();
        $etapaExcluir = $stmtE->get_result()->fetch_assoc();
        if (!$etapaExcluir) {
            throw new InvalidArgumentException('Etapa não encontrada.');
        }
        $uso = usoDaEtapa($conn, $etapaId);
        $ativasAgora = (int)$conn->query('SELECT COUNT(*) FROM etapas WHERE ativa = 1')->fetch_row()[0];
        $motivo = motivoBloqueioExclusaoEtapa($etapaExcluir, $uso['livros'], $uso['movs'], $ativasAgora, $podeGerenciarEtapas);
        if ($motivo !== null) {
            throw new InvalidArgumentException($motivo);
        }

        $stmtD = $conn->prepare('DELETE FROM etapas WHERE id = ?');
        $stmtD->bind_param('i', $etapaId);
        $stmtD->execute();
        renumerarEtapas($conn);
        registrarLog($conn, (int)$_SESSION['usuario_id'], 'etapa.excluir', 'etapa', $etapaId, truncarParaLog(descricaoEtapaParaLog($etapaExcluir)), '(excluída; demais etapas reordenadas)');

        $conn->commit();
        $_SESSION['flash'] = ['type' => 'sucesso', 'message' => 'Etapa "' . $etapaExcluir['nome'] . '" excluída. As demais foram reordenadas.'];
    } catch (Throwable $e) {
        $conn->rollback();
        // 1451 = uma ação simultânea passou a usar a etapa entre a conferência e o DELETE; a FK RESTRICT segurou.
        $mensagem = ($e instanceof mysqli_sql_exception && $e->getCode() === 1451)
            ? 'Esta etapa passou a ser usada e não foi excluída.'
            : mensagemErroSegura($e, 'excluir a etapa');
        $_SESSION['flash'] = ['type' => 'erro', 'message' => $mensagem];
    }
    header('Location: configuracoes.php');
    exit;
}

if ($podeGerenciarPapeis && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'criar_papel') {
    if (!csrfValidar($_POST['csrf_token'] ?? null)) {
        $_SESSION['flash'] = ['type' => 'erro', 'message' => 'Sessão expirada. Tente novamente.'];
        header('Location: configuracoes.php'); exit;
    }
    exigirEnvioUnico();
    $nome = entradaTexto($_POST, 'nome');
    $erroPapelNovo = erroNomePapel($conn, $nome, 0);
    if ($erroPapelNovo !== null) {
        $errosPapelNovo['nome'] = $erroPapelNovo;
        $valoresPapelNovo = ['nome' => $nome];
    } else {
        $stmt = $conn->prepare('INSERT INTO papeis (nome, ativo) VALUES (?, 1)');
        $stmt->bind_param('s', $nome);
        $stmt->execute();
        $novoPapelId = $conn->insert_id;
        registrarLog($conn, (int)$_SESSION['usuario_id'], 'papel.criar', 'papel', $novoPapelId, null, $nome);
        $_SESSION['flash'] = ['type' => 'sucesso', 'message' => 'Papel cadastrado com sucesso.'];
    }
    if (!$errosPapelNovo) {
        header('Location: configuracoes.php');
        exit;
    }
}

if ($podeGerenciarPapeis && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'editar_papel') {
    if (!csrfValidar($_POST['csrf_token'] ?? null)) {
        $_SESSION['flash'] = ['type' => 'erro', 'message' => 'Sessão expirada. Tente novamente.'];
        header('Location: configuracoes.php'); exit;
    }
    $papelId = (int)($_POST['papel_id'] ?? 0);
    $nome = entradaTexto($_POST, 'nome');
    $ativo = isset($_POST['ativo']) ? 1 : 0;

    $stmtAtual = $conn->prepare('SELECT * FROM papeis WHERE id = ?');
    $stmtAtual->bind_param('i', $papelId);
    $stmtAtual->execute();
    $papelAtualRow = $stmtAtual->get_result()->fetch_assoc();

    if (!$papelAtualRow) {
        $_SESSION['flash'] = ['type' => 'erro', 'message' => 'Papel não encontrado.'];
    } elseif (($erroPapelEd = erroNomePapel($conn, $nome, $papelId)) !== null) {
        $errosPapelEd['nome'] = $erroPapelEd;
        $formPapelEd = ['id' => $papelId, 'nome' => $nome, 'ativo' => $ativo];
    } else {
        $stmt = $conn->prepare('UPDATE papeis SET nome = ?, ativo = ? WHERE id = ?');
        $stmt->bind_param('sii', $nome, $ativo, $papelId);
        $stmt->execute();

        $uid = (int)$_SESSION['usuario_id'];
        if ($nome !== $papelAtualRow['nome']) {
            registrarLog($conn, $uid, 'papel.editar', 'papel', $papelId, 'Nome: ' . $papelAtualRow['nome'], 'Nome: ' . $nome);
        }
        if ($ativo !== (int)$papelAtualRow['ativo']) {
            if ($ativo === 0) {
                registrarLog($conn, $uid, 'papel.desativar', 'papel', $papelId, 'Ativo', 'Inativo');
            } else {
                registrarLog($conn, $uid, 'papel.editar', 'papel', $papelId, 'Inativo', 'Ativo');
            }
        }
        $_SESSION['flash'] = ['type' => 'sucesso', 'message' => 'Papel atualizado com sucesso.'];
    }
    if (!$errosPapelEd) {
        header('Location: configuracoes.php');
        exit;
    }
}

$usuarios = [];
$r = $conn->query('SELECT id, nome, email, perfil, ativo, criado_em FROM vw_usuarios_publico ORDER BY nome');
while ($row = $r->fetch_assoc()) $usuarios[] = $row;
// Usuário removido (ativo = 0) não entra mais e sai da lista principal; fica numa seção própria, com o botão de reativar.
$usuariosAtivos = array_values(array_filter($usuarios, fn($u) => (int)$u['ativo'] === 1));
$usuariosRemovidos = array_values(array_filter($usuarios, fn($u) => (int)$u['ativo'] !== 1));
$infoRemocao = []; // [id] => ['motivo' => texto do bloqueio ou null, 'usado' => já aparece em algum histórico?]
if ($podeGerenciarUsuarios) {
    foreach ($usuariosAtivos as $uRem) {
        $infoRemocao[(int)$uRem['id']] = [
            'motivo' => motivoBloqueioRemocaoUsuario($conn, $uRem, (int)$_SESSION['usuario_id'], $agindoComoAdmin),
            'usado' => usoDoUsuario($conn, (int)$uRem['id']) > 0,
        ];
    }
}

$diasLimiteAtual = buscarDiasLimiteGlobal($conn);

$etapas = [];
$papeis = [];
$contagemPorEtapa = [];
$usoPorEtapa = [];          // [id da etapa] => ['livros' => N, 'movs' => N], para saber quais podem ser excluídas
$motivoExclusaoPorEtapa = []; // [id da etapa] => texto do motivo, ou null se pode excluir
if ($podeGerenciarEtapas || $podeExcluirEtapa) {
    $re = $conn->query('SELECT * FROM etapas ORDER BY ordem ASC, id ASC');
    while ($row = $re->fetch_assoc()) $etapas[] = $row;

    // Livros que ficariam "presos" numa etapa desativada ou reordenada —
    // extraviados contam (ainda estão fisicamente ali), concluídos/cancelados não.
    $rc = $conn->query("SELECT etapa_atual_id, COUNT(*) total FROM livros WHERE status NOT IN ('concluido', 'cancelado') GROUP BY etapa_atual_id");
    while ($row = $rc->fetch_assoc()) $contagemPorEtapa[(int)$row['etapa_atual_id']] = (int)$row['total'];

    // Uso total (qualquer status de livro + histórico) e o veredito de exclusão de cada etapa, numa consulta por tabela.
    foreach ($etapas as $e) $usoPorEtapa[(int)$e['id']] = ['livros' => 0, 'movs' => 0];
    $ru = $conn->query('SELECT etapa_atual_id AS id, COUNT(*) n FROM livros GROUP BY etapa_atual_id');
    while ($row = $ru->fetch_assoc()) $usoPorEtapa[(int)$row['id']]['livros'] = (int)$row['n'];
    $ru = $conn->query('SELECT etapa_id AS id, COUNT(*) n FROM movimentacoes GROUP BY etapa_id');
    while ($row = $ru->fetch_assoc()) $usoPorEtapa[(int)$row['id']]['movs'] = (int)$row['n'];
    $ativasAgoraTela = count(array_filter($etapas, fn($e) => (int)$e['ativa'] === 1));
    foreach ($etapas as $e) {
        $u = $usoPorEtapa[(int)$e['id']];
        $motivoExclusaoPorEtapa[(int)$e['id']] = motivoBloqueioExclusaoEtapa($e, $u['livros'], $u['movs'], $ativasAgoraTela, $podeGerenciarEtapas);
    }
}
if ($podeGerenciarPapeis) {
    $rp = $conn->query('SELECT * FROM papeis ORDER BY nome ASC');
    while ($row = $rp->fetch_assoc()) $papeis[] = $row;
}

// Log de auditoria: só Gerente e Admin (log.ver, Passo 20.5; antes era aberto a todos, decisão do Passo 9). A consulta nem roda sem a permissão.
$logs = [];
$filtroUsuario = (int)($_GET['filtro_usuario'] ?? 0);
$filtroAcao = entradaTexto($_GET, 'filtro_acao');
$filtroDe = entradaTexto($_GET, 'filtro_de');
$filtroAte = entradaTexto($_GET, 'filtro_ate');

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
               WHEN l.entidade_tipo='etapa' THEN et.nome
               WHEN l.entidade_tipo='papel' THEN pa.nome
               WHEN l.entidade_tipo='configuracao' THEN 'Configuração global'
               ELSE NULL
           END AS entidade_nome
    FROM log_alteracoes l
    JOIN usuarios ator ON ator.id = l.usuario_id
    LEFT JOIN livros lv ON l.entidade_tipo='livro' AND lv.id = l.entidade_id
    LEFT JOIN pessoas pe ON l.entidade_tipo='pessoa' AND pe.id = l.entidade_id
    LEFT JOIN usuarios ue ON l.entidade_tipo='usuario' AND ue.id = l.entidade_id
    LEFT JOIN etapas et ON l.entidade_tipo='etapa' AND et.id = l.entidade_id
    LEFT JOIN papeis pa ON l.entidade_tipo='papel' AND pa.id = l.entidade_id
    $whereSql
    ORDER BY l.criado_em DESC
    LIMIT 100
";
if ($podeVerLog) {
    $stmtLog = $conn->prepare($sqlLog);
    if ($parametros) $stmtLog->bind_param($tipos, ...$parametros);
    $stmtLog->execute();
    $rl = $stmtLog->get_result();
    while ($row = $rl->fetch_assoc()) $logs[] = $row;
}

// O flash da sessão é lido, exibido e apagado pelo includes/rodape.php (toast).
$temaAtual = ($_SESSION['usuario_tema'] ?? 'claro') === 'escuro' ? 'escuro' : 'claro';

$tituloPagina = 'Configurações';
$paginaAtual = 'configuracoes';
require __DIR__ . '/includes/cabecalho.php';
?>
      <header class="ws-topo">
        <div class="ws-topo__titulos">
          <h1 class="ws-topo__titulo">Configurações</h1>
          <p class="ws-topo__subtitulo"><?= $podeGerenciarUsuarios ? 'Área administrativa' : 'Suas preferências' ?></p>
        </div>
      </header>

      <div class="ws-conteudo">
        <!-- Seção 1 (IV-8): Aparência. O tema é salvo na conta (usuarios.tema); o formulário é um POST simples, sem JS. -->
        <section class="ws-secao" aria-labelledby="aparencia-titulo">
          <div class="ws-secao__cabecalho">
            <div>
              <h2 id="aparencia-titulo">Aparência</h2>
              <p>Escolha o tema de exibição do sistema. Fica salvo na sua conta e vale para qualquer dispositivo.</p>
            </div>
          </div>
          <form method="post" class="ws-card ws-card--superficie">
            <?= csrfCampo() ?>
            <input type="hidden" name="acao" value="alterar_tema">
            <div class="ws-btn-grupo" role="group" aria-label="Tema de exibição">
              <button type="submit" name="tema" value="claro" class="ws-btn ws-btn--secundario<?= $temaAtual === 'claro' ? ' is-pressionado' : '' ?>" aria-pressed="<?= $temaAtual === 'claro' ? 'true' : 'false' ?>"><i data-lucide="sun"></i>Tema claro</button>
              <button type="submit" name="tema" value="escuro" class="ws-btn ws-btn--secundario<?= $temaAtual === 'escuro' ? ' is-pressionado' : '' ?>" aria-pressed="<?= $temaAtual === 'escuro' ? 'true' : 'false' ?>"><i data-lucide="moon"></i>Tema escuro</button>
            </div>
          </form>
        </section>

<?php if ($podeConfigurarDiasLimite): ?>
        <!-- Seção 2 (IV-8): Livro parado (Gerente e Admin). -->
        <section class="ws-secao" aria-labelledby="parado-titulo">
          <div class="ws-secao__cabecalho">
            <div>
              <h2 id="parado-titulo">Livro parado</h2>
              <p>Quantos dias um livro pode ficar na mesma etapa antes de entrar no alerta do Painel. Vale para as etapas que não têm limite próprio, definido em Etapas do fluxo.</p>
            </div>
          </div>
          <form method="post" class="ws-form ws-card ws-card--superficie" novalidate>
            <?= csrfCampo() ?>
            <input type="hidden" name="acao" value="alterar_dias_limite">
            <div class="ws-campo<?= classeCampoErro($errosDias['dias_limite_livro_parado'] ?? null) ?>">
              <label class="ws-campo__rotulo" for="dias_limite_livro_parado">Dias-limite padrão</label>
              <input class="ws-input ws-input--codigo" type="number" id="dias_limite_livro_parado" name="dias_limite_livro_parado" min="1" max="<?= LIMITE_DIAS_MAX ?>" value="<?= $valorDiasPost !== null ? escaparHtml($valorDiasPost) : (int)$diasLimiteAtual ?>" required<?= atributosCampo('dias_limite_livro_parado', $errosDias['dias_limite_livro_parado'] ?? null, 'dias-ajuda') ?> style="max-width:160px">
              <?= htmlErroCampo('dias_limite_livro_parado', $errosDias['dias_limite_livro_parado'] ?? null) ?>
              <p class="ws-campo__ajuda" id="dias-ajuda">Número inteiro de 1 a <?= LIMITE_DIAS_MAX ?>.</p>
            </div>
            <div class="ws-form__acoes">
              <button class="ws-btn ws-btn--primario" type="submit">Salvar</button>
            </div>
          </form>
        </section>
<?php endif; ?>
<?php if ($podeGerenciarUsuarios): ?>
        <!-- Seção 3 (IV-8): Usuários e papéis de acesso (Gerente e Admin). "Papel" = papel de acesso (usuarios.perfil). -->
        <section class="ws-secao" aria-labelledby="usuarios-titulo">
          <div class="ws-secao__cabecalho">
            <div>
              <h2 id="usuarios-titulo">Usuários e papéis de acesso</h2>
              <p>Defina o nível de permissão de cada usuário do sistema. Autores, organizadores e ilustradores ficam em Pessoas.<?= $agindoComoAdmin ? '' : ' Como Gerente, você pode definir Usuário ou Gerente, mas não pode conceder o papel de Administrador, alterar o papel de um Administrador, ou alterar o seu próprio papel.' ?></p>
            </div>
          </div>
          <!-- No celular as linhas viram cartões (.ws-tabela--empilhar + data-rotulo) -->
          <div class="ws-tabela-wrap" tabindex="0" role="region" aria-labelledby="usuarios-titulo">
            <table class="ws-tabela ws-tabela--empilhar">
              <thead>
                <tr>
                  <th scope="col">Nome</th>
                  <th scope="col">E-mail</th>
                  <th scope="col">Papel atual</th>
                  <th scope="col">Cadastrado em</th>
                  <th scope="col">Alterar papel</th>
                  <th scope="col">Remover</th>
                </tr>
              </thead>
              <tbody>
<?php foreach ($usuariosAtivos as $u):
    $ehEuMesmo = (int)$u['id'] === (int)$_SESSION['usuario_id'];
    $podeEditarEssaLinha = $agindoComoAdmin || (!$ehEuMesmo && $u['perfil'] !== 'admin');
?>
                <tr>
                  <td data-rotulo="Nome"><span class="ws-usuario"><span class="ws-usuario__iniciais" aria-hidden="true"><?= escaparHtml(iniciaisDoNome($u['nome'])) ?></span><strong><?= escaparHtml($u['nome']) ?></strong></span></td>
                  <td data-rotulo="E-mail"><?= escaparHtml($u['email']) ?></td>
                  <td data-rotulo="Papel atual"><span class="ws-tag ws-tag--neutra"><?= escaparHtml(nomePapel($u['perfil'])) ?></span></td>
                  <td class="ws-tabela__codigo" data-rotulo="Cadastrado em"><?= escaparHtml(formatarDataHora($u['criado_em'])) ?></td>
                  <td data-rotulo="Alterar papel">
<?php if ($podeEditarEssaLinha): ?>
                    <form class="ws-linha" method="post" style="flex-wrap:nowrap;gap:8px">
                      <?= csrfCampo() ?>
                      <input type="hidden" name="acao" value="alterar_papel">
                      <input type="hidden" name="usuario_id" value="<?= (int)$u['id'] ?>">
                      <select class="ws-select ws-select--peq" name="papel" aria-label="<?= escaparHtml('Papel de ' . $u['nome']) ?>" style="flex:1 1 110px;min-width:0;max-width:150px">
<?php if ($agindoComoAdmin): ?>
                        <option value="admin"<?= $u['perfil'] === 'admin' ? ' selected' : '' ?>>Administrador</option>
<?php endif; ?>
                        <option value="gerente"<?= $u['perfil'] === 'gerente' ? ' selected' : '' ?>>Gerente</option>
                        <option value="usuario"<?= $u['perfil'] === 'usuario' ? ' selected' : '' ?>>Usuário</option>
                      </select>
                      <button class="ws-btn ws-btn--secundario ws-btn--peq" type="submit">Salvar</button>
                    </form>
<?php elseif ($ehEuMesmo): ?>
                    <span class="ws-tabela__suave">Você não altera o próprio papel</span>
<?php else: ?>
                    <span class="ws-tabela__suave">Só um Administrador altera</span>
<?php endif; ?>
                  </td>
                  <td data-rotulo="Remover">
<?php $remocao = $infoRemocao[(int)$u['id']] ?? ['motivo' => null, 'usado' => false]; ?>
<?php if ($remocao['motivo'] === null): ?>
                    <!-- Pode remover: o envio abre o modal de confirmação (campo oculto "confirmacao" preenchido só pelo botão do modal) -->
                    <form method="post" data-ws-confirmar="modal-remover-usuario" data-remover-nome="<?= escaparHtml($u['nome']) ?>" data-remover-usado="<?= $remocao['usado'] ? '1' : '0' ?>">
                      <?= csrfCampo() ?>
                      <input type="hidden" name="acao" value="remover_usuario">
                      <input type="hidden" name="usuario_id" value="<?= (int)$u['id'] ?>">
                      <input type="hidden" name="confirmacao" value="">
                      <button class="ws-btn ws-btn--secundario ws-btn--icone ws-btn--peq" type="submit" aria-label="<?= escaparHtml('Remover usuário ' . $u['nome']) ?>" title="Remover usuário"><i data-lucide="user-x"></i></button>
                    </form>
<?php else: ?>
                    <!-- Não pode remover: o botão abre um modal que explica o motivo -->
                    <button class="ws-btn ws-btn--secundario ws-btn--icone ws-btn--peq" type="button" data-ws-modal-abrir="modal-remover-bloqueado"
                      data-nome="<?= escaparHtml($u['nome']) ?>" data-motivo="<?= escaparHtml($remocao['motivo']) ?>"
                      aria-label="<?= escaparHtml('Remover usuário ' . $u['nome'] . ' (não é possível)') ?>" title="Remover usuário"><i data-lucide="user-x"></i></button>
<?php endif; ?>
                  </td>
                </tr>
<?php endforeach; ?>
              </tbody>
            </table>
          </div>

<?php if ($usuariosRemovidos): ?>
          <!-- Removidos (sem acesso): já aparecem em algum histórico, então a conta fica, mas não entra e não é mesa -->
          <details class="ws-card ws-card--superficie">
            <summary><strong>Removidos (sem acesso)</strong> <span class="ws-texto-suave">· <?= count($usuariosRemovidos) ?></span></summary>
            <p class="ws-texto-suave">Estas contas não entram mais no sistema e não podem ser mesa, mas o nome continua nos históricos. Reativar devolve o acesso.</p>
            <div class="ws-tabela-wrap" tabindex="0" role="region" aria-label="Usuários removidos">
              <table class="ws-tabela ws-tabela--empilhar">
                <thead>
                  <tr>
                    <th scope="col">Nome</th>
                    <th scope="col">E-mail</th>
                    <th scope="col">Papel</th>
                    <th scope="col">Reativar</th>
                  </tr>
                </thead>
                <tbody>
<?php foreach ($usuariosRemovidos as $u): ?>
                  <tr>
                    <td data-rotulo="Nome"><span class="ws-usuario"><span class="ws-usuario__iniciais" aria-hidden="true"><?= escaparHtml(iniciaisDoNome($u['nome'])) ?></span><strong><?= escaparHtml($u['nome']) ?></strong></span></td>
                    <td data-rotulo="E-mail"><?= escaparHtml($u['email']) ?></td>
                    <td data-rotulo="Papel"><span class="ws-tag ws-tag--neutra"><?= escaparHtml(nomePapel($u['perfil'])) ?></span></td>
                    <td data-rotulo="Reativar">
<?php if ($agindoComoAdmin || $u['perfil'] !== 'admin'): ?>
                      <form method="post">
                        <?= csrfCampo() ?>
                        <input type="hidden" name="acao" value="reativar_usuario">
                        <input type="hidden" name="usuario_id" value="<?= (int)$u['id'] ?>">
                        <button class="ws-btn ws-btn--secundario ws-btn--peq" type="submit"><i data-lucide="user-check"></i>Reativar</button>
                      </form>
<?php else: ?>
                      <span class="ws-tabela__suave">Só um Administrador reativa</span>
<?php endif; ?>
                    </td>
                  </tr>
<?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </details>
<?php endif; ?>

          <form class="ws-form ws-card ws-card--superficie" method="post" aria-labelledby="novo-usuario-titulo" novalidate>
            <?= csrfCampo() ?>
            <input type="hidden" name="acao" value="criar_usuario">
            <?= envioUnicoCampo() ?>
            <fieldset class="ws-grupo-campos">
              <legend><h3 class="ws-h2" id="novo-usuario-titulo">Novo usuário</h3></legend>
              <div class="ws-form__grade">
                <div class="ws-campo<?= classeCampoErro($errosUsuario['nome'] ?? null) ?>">
                  <label class="ws-campo__rotulo" for="novo_nome">Nome</label>
                  <input class="ws-input" type="text" id="novo_nome" name="novo_nome" value="<?= escaparHtml($valoresUsuario['nome'] ?? '') ?>" maxlength="<?= LIMITE_NOME_USUARIO ?>" autocomplete="off" required<?= atributosCampo('novo_nome', $errosUsuario['nome'] ?? null) ?>>
                  <?= htmlErroCampo('novo_nome', $errosUsuario['nome'] ?? null) ?>
                </div>
                <div class="ws-campo<?= classeCampoErro($errosUsuario['email'] ?? null) ?>">
                  <label class="ws-campo__rotulo" for="novo_email">E-mail</label>
                  <input class="ws-input" type="email" id="novo_email" name="novo_email" value="<?= escaparHtml($valoresUsuario['email'] ?? '') ?>" maxlength="<?= LIMITE_EMAIL ?>" autocomplete="off" required<?= atributosCampo('novo_email', $errosUsuario['email'] ?? null) ?>>
                  <?= htmlErroCampo('novo_email', $errosUsuario['email'] ?? null) ?>
                </div>
                <div class="ws-campo<?= classeCampoErro($errosUsuario['senha'] ?? null) ?>">
                  <label class="ws-campo__rotulo" for="nova_senha">Senha</label>
                  <input class="ws-input" type="password" id="nova_senha" name="nova_senha" minlength="6" autocomplete="new-password" required<?= atributosCampo('nova_senha', $errosUsuario['senha'] ?? null, 'senha-ajuda') ?>>
                  <?= htmlErroCampo('nova_senha', $errosUsuario['senha'] ?? null) ?>
                  <p class="ws-campo__ajuda" id="senha-ajuda">Mínimo de 6 caracteres.</p>
                </div>
                <div class="ws-campo<?= classeCampoErro($errosUsuario['confirmar'] ?? null) ?>">
                  <label class="ws-campo__rotulo" for="nova_confirmar_senha">Confirmar senha</label>
                  <input class="ws-input" type="password" id="nova_confirmar_senha" name="nova_confirmar_senha" minlength="6" autocomplete="new-password" required<?= atributosCampo('nova_confirmar_senha', $errosUsuario['confirmar'] ?? null) ?>>
                  <?= htmlErroCampo('nova_confirmar_senha', $errosUsuario['confirmar'] ?? null) ?>
                </div>
                <div class="ws-campo<?= classeCampoErro($errosUsuario['papel'] ?? null) ?>">
                  <label class="ws-campo__rotulo" for="novo_papel">Papel</label>
                  <select class="ws-select" id="novo_papel" name="novo_papel"<?= atributosCampo('novo_papel', $errosUsuario['papel'] ?? null) ?>>
<?php $papelMarcado = $valoresUsuario['papel'] ?? 'usuario'; ?>
<?php if ($agindoComoAdmin): ?>
                    <option value="admin"<?= $papelMarcado === 'admin' ? ' selected' : '' ?>>Administrador</option>
<?php endif; ?>
                    <option value="gerente"<?= $papelMarcado === 'gerente' ? ' selected' : '' ?>>Gerente</option>
                    <option value="usuario"<?= $papelMarcado === 'usuario' ? ' selected' : '' ?>>Usuário</option>
                  </select>
                  <?= htmlErroCampo('novo_papel', $errosUsuario['papel'] ?? null) ?>
                </div>
              </div>
            </fieldset>
            <div class="ws-form__acoes">
              <button class="ws-btn ws-btn--primario" type="submit">Cadastrar usuário</button>
            </div>
          </form>
        </section>
<?php endif; ?>



<?php if ($podeGerenciarEtapas || $podeExcluirEtapa): ?>
        <!-- Seção 4 (IV-8): Etapas do fluxo. Gerente e Admin criam, editam, desativam, reordenam e excluem (etapa nunca usada, Passo 20.9). -->
        <section class="ws-secao" aria-labelledby="etapas-titulo">
          <div class="ws-secao__cabecalho">
            <div>
              <h2 id="etapas-titulo">Etapas do fluxo</h2>
<?php if ($podeGerenciarEtapas): ?>
              <p>Gerente e Administrador criam, editam, desativam e reordenam etapas, e podem excluir uma etapa que nunca foi usada; uma etapa com histórico nunca é excluída — apenas desativada. Livros que já estão numa etapa continuam nela até serem movidos manualmente; desativar ou reordenar não os afeta.</p>
<?php else: ?>
              <p>Você pode excluir uma etapa que nunca foi usada: sem livros e sem histórico.</p>
<?php endif; ?>
            </div>
          </div>
<?php if (!$etapas): ?>
          <div class="ws-vazio">
            <i class="ws-vazio__icone" data-lucide="list-ordered"></i>
            <h3 class="ws-h2">Nenhuma etapa cadastrada.</h3>
            <p class="ws-vazio__texto">Cadastre a primeira etapa do fluxo no formulário abaixo.</p>
          </div>
<?php else: ?>
          <!-- No celular as linhas viram cartões (.ws-tabela--empilhar + data-rotulo) -->
          <div class="ws-tabela-wrap" tabindex="0" role="region" aria-labelledby="etapas-titulo">
            <table class="ws-tabela ws-tabela--empilhar">
              <thead>
                <tr>
                  <th scope="col" class="ws-tabela__num">Ordem</th>
                  <th scope="col">Nome</th>
                  <th scope="col">Opcional</th>
                  <th scope="col">Repetível</th>
                  <th scope="col" class="ws-tabela__num">Rodadas incluídas</th>
                  <th scope="col" class="ws-tabela__num">Dias limite</th>
                  <th scope="col">Ativa</th>
                  <th scope="col" class="ws-tabela__num">Livros agora</th>
                  <th scope="col">Ações</th>
                </tr>
              </thead>
              <tbody>
<?php foreach ($etapas as $idxEt => $et):
    $qtdLivrosEtapa = $contagemPorEtapa[(int)$et['id']] ?? 0;
    $temVizinhoAcima = $idxEt > 0;
    $temVizinhoAbaixo = $idxEt < count($etapas) - 1;
?>
                <tr>
                  <td class="ws-tabela__num" data-rotulo="Ordem"><?= (int)$et['ordem'] ?></td>
                  <td class="ws-tabela__titulo" data-rotulo="Nome"><?= escaparHtml($et['nome']) ?><?php if ((int)$et['ativa'] !== 1): ?> <span class="ws-tag ws-tag--neutra ws-tag--peq">Desativada</span><?php endif; ?></td>
                  <td data-rotulo="Opcional"><?= (int)$et['opcional'] === 1 ? 'Sim' : 'Não' ?></td>
                  <td data-rotulo="Repetível"><?= (int)$et['repetivel'] === 1 ? 'Sim' : 'Não' ?></td>
                  <td class="ws-tabela__num" data-rotulo="Rodadas incluídas"><?= $et['rodadas_incluidas'] !== null ? (int)$et['rodadas_incluidas'] : '—' ?></td>
                  <td class="ws-tabela__num" data-rotulo="Dias limite"><?= $et['dias_limite'] !== null ? (int)$et['dias_limite'] : '—' ?></td>
                  <td data-rotulo="Ativa"><?= (int)$et['ativa'] === 1 ? 'Sim' : 'Não' ?></td>
                  <td class="ws-tabela__num" data-rotulo="Livros agora"><?= $qtdLivrosEtapa ?></td>
                  <td class="ws-tabela__acoes" data-rotulo="Ações">
                    <div class="ws-linha" style="flex-wrap:nowrap;gap:6px;justify-content:flex-end">
<?php if ($podeGerenciarEtapas): ?>
<?php foreach ([['up', $temVizinhoAcima, 'arrow-up', 'Mover para cima'], ['down', $temVizinhoAbaixo, 'arrow-down', 'Mover para baixo']] as [$dir, $existe, $icone, $rotuloMover]): ?>
<?php if ($existe): ?>
                      <form method="post"<?= $qtdLivrosEtapa > 0 ? ' data-ws-confirmar="modal-mover-etapa" data-mover-nome="' . escaparHtml($et['nome']) . '" data-mover-qtd="' . $qtdLivrosEtapa . '"' : '' ?>>
                        <?= csrfCampo() ?>
                        <input type="hidden" name="acao" value="mover_etapa">
                        <input type="hidden" name="etapa_id" value="<?= (int)$et['id'] ?>">
                        <input type="hidden" name="direcao" value="<?= $dir ?>">
<?php if ($qtdLivrosEtapa > 0): ?>
                        <input type="hidden" name="confirmacao" value="">
<?php endif; ?>
                        <button class="ws-btn ws-btn--secundario ws-btn--icone ws-btn--peq" type="submit" aria-label="<?= escaparHtml($rotuloMover . ': ' . $et['nome']) ?>" title="<?= $rotuloMover ?>"><i data-lucide="<?= $icone ?>"></i></button>
                      </form>
<?php else: ?>
                      <span style="display:inline-block;width:32px" aria-hidden="true"></span>
<?php endif; ?>
<?php endforeach; ?>
                      <button class="ws-btn ws-btn--secundario ws-btn--icone ws-btn--peq" type="button" data-ws-modal-abrir="modal-etapa"
                        data-id="<?= (int)$et['id'] ?>"
                        data-nome="<?= escaparHtml($et['nome']) ?>"
                        data-ordem="<?= (int)$et['ordem'] ?>"
                        data-opcional="<?= (int)$et['opcional'] ?>"
                        data-repetivel="<?= (int)$et['repetivel'] ?>"
                        data-finaliza="<?= (int)$et['finaliza_livro'] ?>"
                        data-rodadas="<?= $et['rodadas_incluidas'] !== null ? (int)$et['rodadas_incluidas'] : '' ?>"
                        data-dias="<?= $et['dias_limite'] !== null ? (int)$et['dias_limite'] : '' ?>"
                        data-ativa="<?= (int)$et['ativa'] ?>"
                        data-qtd="<?= $qtdLivrosEtapa ?>"
                        aria-label="<?= escaparHtml('Editar etapa ' . $et['nome']) ?>" title="Editar etapa"><i data-lucide="pencil"></i></button>
<?php endif; ?>
<?php if ($podeExcluirEtapa):
    $motivoExcluir = $motivoExclusaoPorEtapa[(int)$et['id']] ?? null;
?>
<?php if ($motivoExcluir === null): ?>
                      <!-- Pode excluir: o envio abre o modal de confirmação (campo oculto "confirmacao" preenchido só pelo botão do modal) -->
                      <form method="post" data-ws-confirmar="modal-excluir-etapa" data-excluir-nome="<?= escaparHtml($et['nome']) ?>" data-excluir-catalogacao="<?= $et['nome'] === NOME_ETAPA_CATALOGACAO_CONFIG ? '1' : '0' ?>">
                        <?= csrfCampo() ?>
                        <input type="hidden" name="acao" value="excluir_etapa">
                        <input type="hidden" name="etapa_id" value="<?= (int)$et['id'] ?>">
                        <input type="hidden" name="confirmacao" value="">
                        <button class="ws-btn ws-btn--secundario ws-btn--icone ws-btn--peq" type="submit" aria-label="<?= escaparHtml('Excluir etapa ' . $et['nome']) ?>" title="Excluir etapa"><i data-lucide="trash-2"></i></button>
                      </form>
<?php else: ?>
                      <!-- Não pode excluir: o botão abre um modal que explica o motivo (e, para o Admin, oferece desativar) -->
                      <button class="ws-btn ws-btn--secundario ws-btn--icone ws-btn--peq" type="button" data-ws-modal-abrir="modal-excluir-bloqueado"
                        data-id="<?= (int)$et['id'] ?>"
                        data-nome="<?= escaparHtml($et['nome']) ?>"
                        data-motivo="<?= escaparHtml($motivoExcluir) ?>"
                        data-ativa="<?= (int)$et['ativa'] ?>"
                        data-pode-desativar="<?= $podeGerenciarEtapas && (int)$et['ativa'] === 1 && (int)$et['finaliza_livro'] !== 1 && !str_starts_with($motivoExcluir, 'Esta é a única') ? '1' : '0' ?>"
                        aria-label="<?= escaparHtml('Excluir etapa ' . $et['nome'] . ' (não é possível)') ?>" title="Excluir etapa"><i data-lucide="trash-2"></i></button>
<?php endif; ?>
<?php endif; ?>
                    </div>
                  </td>
                </tr>
<?php endforeach; ?>
              </tbody>
            </table>
          </div>
<?php endif; ?>

<?php if ($podeGerenciarEtapas): ?>
          <form class="ws-form ws-card ws-card--superficie" method="post" aria-labelledby="nova-etapa-titulo" novalidate>
            <?= csrfCampo() ?>
            <input type="hidden" name="acao" value="criar_etapa">
            <?= envioUnicoCampo() ?>
            <h3 class="ws-h2" id="nova-etapa-titulo">Nova etapa</h3>
            <?php camposFormEtapa('nova_etapa', false, $etapas ? (int)max(array_column($etapas, 'ordem')) + 1 : 1, $valoresEtapaNova ?? [], $errosEtapaNova); ?>
            <div class="ws-form__acoes">
              <button class="ws-btn ws-btn--primario" type="submit">Cadastrar etapa</button>
<?php if ($errosEtapaNova): ?>
              <a class="ws-btn ws-btn--link" href="configuracoes.php#nova-etapa-titulo">Limpar</a>
<?php else: ?>
              <button class="ws-btn ws-btn--link" type="reset">Limpar</button>
<?php endif; ?>
            </div>
          </form>
<?php endif; ?>
        </section>
<?php endif; ?>

<?php if ($podeGerenciarPapeis): ?>
        <!-- Seção 5 (IV-8): Papéis de autoria (só Admin: config.papeis). Papel de autoria = papel de uma pessoa num livro (papeis). -->
        <section class="ws-secao" aria-labelledby="papeis-titulo">
          <div class="ws-secao__cabecalho">
            <div>
              <h2 id="papeis-titulo">Papéis de autoria</h2>
              <p>Autor, Organizador, Ilustrador — o papel de uma pessoa num livro (não confundir com o papel de acesso do usuário, na seção acima). Só Administrador. Nunca excluídos — apenas desativados.</p>
            </div>
          </div>
<?php if (!$papeis): ?>
          <div class="ws-vazio">
            <i class="ws-vazio__icone" data-lucide="tags"></i>
            <h3 class="ws-h2">Nenhum papel cadastrado.</h3>
            <p class="ws-vazio__texto">Cadastre o primeiro papel de autoria no formulário abaixo.</p>
          </div>
<?php else: ?>
          <!-- No celular as linhas viram cartões (.ws-tabela--empilhar + data-rotulo) -->
          <div class="ws-tabela-wrap" tabindex="0" role="region" aria-labelledby="papeis-titulo">
            <table class="ws-tabela ws-tabela--empilhar">
              <thead>
                <tr>
                  <th scope="col">Nome</th>
                  <th scope="col">Situação</th>
                  <th scope="col">Ações</th>
                </tr>
              </thead>
              <tbody>
<?php foreach ($papeis as $pp): ?>
                <tr>
                  <td class="ws-tabela__titulo" data-rotulo="Nome"><?= escaparHtml($pp['nome']) ?><?php if ((int)$pp['ativo'] !== 1): ?> <span class="ws-tag ws-tag--neutra ws-tag--peq">Inativo</span><?php endif; ?></td>
                  <td data-rotulo="Situação"><?= (int)$pp['ativo'] === 1 ? 'Ativo' : 'Inativo' ?></td>
                  <td class="ws-tabela__acoes" data-rotulo="Ações">
                    <button class="ws-btn ws-btn--secundario ws-btn--icone ws-btn--peq" type="button" data-ws-modal-abrir="modal-papel"
                      data-id="<?= (int)$pp['id'] ?>"
                      data-nome="<?= escaparHtml($pp['nome']) ?>"
                      data-ativo="<?= (int)$pp['ativo'] ?>"
                      aria-label="<?= escaparHtml('Editar papel ' . $pp['nome']) ?>" title="Editar papel"><i data-lucide="pencil"></i></button>
                  </td>
                </tr>
<?php endforeach; ?>
              </tbody>
            </table>
          </div>
<?php endif; ?>

          <form class="ws-form ws-card ws-card--superficie" method="post" aria-labelledby="novo-papel-titulo" novalidate>
            <?= csrfCampo() ?>
            <input type="hidden" name="acao" value="criar_papel">
            <?= envioUnicoCampo() ?>
            <fieldset class="ws-grupo-campos">
              <legend><h3 class="ws-h2" id="novo-papel-titulo">Novo papel</h3></legend>
              <div class="ws-campo<?= classeCampoErro($errosPapelNovo['nome'] ?? null) ?>">
                <label class="ws-campo__rotulo" for="novo_papel_nome">Nome do papel</label>
                <input class="ws-input" type="text" id="novo_papel_nome" name="nome" value="<?= escaparHtml($valoresPapelNovo['nome'] ?? '') ?>" maxlength="<?= LIMITE_NOME_PAPEL ?>" required<?= atributosCampo('novo_papel_nome', $errosPapelNovo['nome'] ?? null) ?>>
                <?= htmlErroCampo('novo_papel_nome', $errosPapelNovo['nome'] ?? null) ?>
              </div>
            </fieldset>
            <div class="ws-form__acoes">
              <button class="ws-btn ws-btn--primario" type="submit">Cadastrar papel</button>
            </div>
          </form>
        </section>
<?php endif; ?>

<?php if ($podeVerLog): ?>
        <!-- Seção 6 (IV-8): Log de alterações. Só Gerente e Admin (log.ver, Passo 20.5). -->
        <section class="ws-secao" id="log" aria-labelledby="log-titulo">
          <div class="ws-secao__cabecalho">
            <div>
              <h2 id="log-titulo">Log de alterações</h2>
              <p>Auditoria de edições de livros, pessoas e ações de controle de acesso.</p>
            </div>
          </div>
<?php $temFiltroLog = $filtroUsuario > 0 || $filtroAcao !== '' || $filtroDe !== '' || $filtroAte !== ''; ?>
          <!-- Filtrar e Limpar voltam direto para esta seção (#log): ela é a última da página -->
          <form class="ws-filtros" method="get" action="configuracoes.php#log">
            <div class="ws-campo">
              <label class="ws-campo__rotulo" for="filtro_usuario">Usuário</label>
              <select class="ws-select" id="filtro_usuario" name="filtro_usuario">
                <option value="0">Todos</option>
<?php foreach ($usuarios as $u): ?>
                <option value="<?= (int)$u['id'] ?>"<?= $filtroUsuario === (int)$u['id'] ? ' selected' : '' ?>><?= escaparHtml($u['nome']) ?></option>
<?php endforeach; ?>
              </select>
            </div>
            <div class="ws-campo">
              <label class="ws-campo__rotulo" for="filtro_acao">Tipo de ação</label>
              <select class="ws-select" id="filtro_acao" name="filtro_acao">
                <option value="">Todas</option>
<?php foreach (NOMES_ACOES_LOG as $valor => $rotulo): ?>
                <option value="<?= escaparHtml($valor) ?>"<?= $filtroAcao === $valor ? ' selected' : '' ?>><?= escaparHtml($rotulo) ?></option>
<?php endforeach; ?>
              </select>
            </div>
            <div class="ws-campo">
              <label class="ws-campo__rotulo" for="filtro_de">De</label>
              <input class="ws-input" type="date" id="filtro_de" name="filtro_de" value="<?= escaparHtml($filtroDe) ?>">
            </div>
            <div class="ws-campo">
              <label class="ws-campo__rotulo" for="filtro_ate">Até</label>
              <input class="ws-input" type="date" id="filtro_ate" name="filtro_ate" value="<?= escaparHtml($filtroAte) ?>">
            </div>
            <div class="ws-filtros__acoes">
              <button class="ws-btn ws-btn--primario" type="submit">Filtrar</button>
              <a class="ws-btn ws-btn--link" href="configuracoes.php#log">Limpar</a>
            </div>
          </form>

<?php if (!$logs): ?>
          <div class="ws-vazio">
            <i class="ws-vazio__icone" data-lucide="history"></i>
            <h3 class="ws-h2"><?= $temFiltroLog ? 'Nenhum registro encontrado para esses filtros.' : 'Nenhum registro encontrado.' ?></h3>
<?php if ($temFiltroLog): ?>
            <p class="ws-vazio__texto">Confira o período, o usuário e o tipo de ação.</p>
            <a class="ws-btn ws-btn--secundario" href="configuracoes.php#log">Limpar filtros</a>
<?php else: ?>
            <p class="ws-vazio__texto">As edições de livros, pessoas e controle de acesso aparecem aqui.</p>
<?php endif; ?>
          </div>
<?php else: ?>
<?php if (count($logs) >= 100): ?>
          <p class="ws-texto-suave">Mostrando os 100 registros mais recentes. Use os filtros para ver outros.</p>
<?php endif; ?>
          <!-- No celular as linhas viram cartões (.ws-tabela--empilhar + data-rotulo) -->
          <div class="ws-tabela-wrap" tabindex="0" role="region" aria-labelledby="log-titulo">
            <table class="ws-tabela ws-tabela--empilhar">
              <thead>
                <tr>
                  <th scope="col">Data</th>
                  <th scope="col">Usuário</th>
                  <th scope="col">Ação</th>
                  <th scope="col">Item afetado</th>
                  <th scope="col">Valor anterior</th>
                  <th scope="col">Valor novo</th>
                </tr>
              </thead>
              <tbody>
<?php foreach ($logs as $l): ?>
                <tr>
                  <td class="ws-tabela__codigo" data-rotulo="Data"><?= escaparHtml(formatarDataHora($l['criado_em'])) ?></td>
                  <td data-rotulo="Usuário"><?= escaparHtml($l['ator_nome']) ?></td>
                  <td data-rotulo="Ação"><?= escaparHtml(nomeAcaoLog($l['acao'])) ?></td>
                  <td data-rotulo="Item afetado"><?= escaparHtml($l['entidade_nome'] ?: '#' . $l['entidade_id']) ?></td>
                  <td data-rotulo="Valor anterior"><?= escaparHtml($l['valor_anterior'] ?: '—') ?></td>
                  <td data-rotulo="Valor novo"><?= escaparHtml($l['valor_novo'] ?: '—') ?></td>
                </tr>
<?php endforeach; ?>
              </tbody>
            </table>
          </div>
<?php endif; ?>
        </section>
<?php endif; ?>

      </div>

<script src="envio_unico.js"></script>
<?php if ($podeGerenciarEtapas): ?>
      <!-- Modal: editar etapa (um só, preenchido pelo script abaixo) -->
      <dialog class="ws-modal" id="modal-etapa" aria-labelledby="modal-etapa-titulo">
        <form class="ws-modal__caixa" method="post" id="form-editar-etapa" data-ws-confirmar="modal-desativar-etapa" novalidate<?= $formEtapaEd ? ' data-ativa-original="' . (int)$formEtapaEd['ativaOriginal'] . '" data-qtd-livros="' . (int)$formEtapaEd['qtdLivros'] . '"' : '' ?>>
          <?= csrfCampo() ?>
          <input type="hidden" name="acao" value="editar_etapa">
          <input type="hidden" name="etapa_id" id="ed_etapa_id" value="<?= $formEtapaEd ? (int)$formEtapaEd['id'] : 0 ?>">
          <input type="hidden" name="confirmacao" value="">
          <header class="ws-modal__cabecalho">
            <h2 id="modal-etapa-titulo">Editar etapa</h2>
            <button class="ws-fechar" type="button" data-ws-modal-fechar aria-label="Fechar"><i data-lucide="x"></i></button>
          </header>
          <div class="ws-modal__corpo ws-pilha">
            <p class="ws-texto-suave">Alterações valem para as próximas movimentações; o que já foi registrado não muda.</p>
            <div class="ws-alerta ws-alerta--limite" role="status" id="ed_etapa_aviso"<?= $formEtapaEd && $formEtapaEd['nomeOriginal'] === NOME_ETAPA_CATALOGACAO_CONFIG ? '' : ' hidden' ?>>
              <i class="ws-alerta__icone" data-lucide="triangle-alert"></i>
              <div class="ws-alerta__corpo">
                <p>Atenção: o aviso automático de ISBN vazio identifica esta etapa pelo nome exato. Renomear aqui desativa esse aviso.</p>
              </div>
            </div>
            <?php camposFormEtapa('ed_etapa', true, 0, $formEtapaEd ? array_merge($formEtapaEd['valores'], ['ordem' => $formEtapaEd['ordem'], 'finaliza' => $formEtapaEd['finaliza']]) : [], $errosEtapaEd); ?>
          </div>
          <footer class="ws-modal__rodape">
            <button class="ws-btn ws-btn--secundario" type="button" data-ws-modal-fechar>Cancelar</button>
            <button class="ws-btn ws-btn--primario" type="submit">Salvar etapa</button>
          </footer>
        </form>
      </dialog>

      <!-- Modal: desativar uma etapa que ainda tem livros (título e texto preenchidos pelo script abaixo) -->
      <dialog class="ws-modal" id="modal-desativar-etapa" aria-labelledby="modal-desativar-etapa-titulo" aria-describedby="modal-desativar-etapa-texto">
        <div class="ws-modal__caixa">
          <header class="ws-modal__cabecalho">
            <h2 id="modal-desativar-etapa-titulo">Desativar esta etapa?</h2>
            <button class="ws-fechar" type="button" data-ws-modal-fechar aria-label="Fechar"><i data-lucide="x"></i></button>
          </header>
          <div class="ws-modal__corpo">
            <p id="modal-desativar-etapa-texto"></p>
          </div>
          <footer class="ws-modal__rodape">
            <button class="ws-btn ws-btn--secundario" type="button" data-ws-modal-fechar>Manter ativa</button>
            <button class="ws-btn ws-btn--primario" type="button" data-ws-confirmar-ok>Desativar etapa</button>
          </footer>
        </div>
      </dialog>

      <!-- Modal: mudar a ordem de uma etapa que tem livros (título e texto preenchidos pelo script abaixo) -->
      <dialog class="ws-modal" id="modal-mover-etapa" aria-labelledby="modal-mover-etapa-titulo" aria-describedby="modal-mover-etapa-texto">
        <div class="ws-modal__caixa">
          <header class="ws-modal__cabecalho">
            <h2 id="modal-mover-etapa-titulo">Mudar a ordem desta etapa?</h2>
            <button class="ws-fechar" type="button" data-ws-modal-fechar aria-label="Fechar"><i data-lucide="x"></i></button>
          </header>
          <div class="ws-modal__corpo">
            <p id="modal-mover-etapa-texto"></p>
          </div>
          <footer class="ws-modal__rodape">
            <button class="ws-btn ws-btn--secundario" type="button" data-ws-modal-fechar>Manter a ordem</button>
            <button class="ws-btn ws-btn--primario" type="button" data-ws-confirmar-ok>Mudar a ordem</button>
          </footer>
        </div>
      </dialog>

      <script>
      // Este script roda antes do waresys.js (defer): os cliques e envios abaixo preparam os modais antes de eles abrirem.
      const NOME_ETAPA_CATALOGACAO = <?= json_encode(NOME_ETAPA_CATALOGACAO_CONFIG, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

      // Editar etapa: o botão preenche o modal com os dados da linha.
      document.querySelectorAll('[data-ws-modal-abrir="modal-etapa"]').forEach(function (botao) {
        botao.addEventListener('click', function () {
          const d = botao.dataset, f = document.getElementById('form-editar-etapa');
          if (window.WareSys) window.WareSys.limparErros('modal-etapa');
          document.getElementById('ed_etapa_id').value = d.id;
          document.getElementById('ed_etapa_nome').value = d.nome;
          document.getElementById('ed_etapa_ordem').value = d.ordem;
          document.getElementById('ed_etapa_opcional').checked = d.opcional === '1';
          document.getElementById('ed_etapa_repetivel').checked = d.repetivel === '1';
          document.getElementById('ed_etapa_finaliza').checked = d.finaliza === '1';
          document.getElementById('ed_etapa_rodadas').value = d.rodadas;
          document.getElementById('ed_etapa_dias').value = d.dias;
          document.getElementById('ed_etapa_ativa').checked = d.ativa === '1';
          f.dataset.ativaOriginal = d.ativa;
          f.dataset.qtdLivros = d.qtd;
          f.elements.confirmacao.value = '';
          document.getElementById('ed_etapa_aviso').hidden = d.nome !== NOME_ETAPA_CATALOGACAO;
        });
      });
      document.getElementById('ed_etapa_nome').setAttribute('autofocus', '');
<?php if ($formEtapaEd): ?>
      // O envio voltou com erro: reabre o modal, com o que foi digitado e a mensagem junto do campo.
      document.addEventListener('DOMContentLoaded', function () { window.WareSys.abrirModal('modal-etapa'); });
<?php endif; ?>

      // Campo oculto "confirmacao": só o botão de confirmar do modal o preenche (com 1). Sem ele o PHP não grava.
      document.querySelectorAll('dialog.ws-modal [data-ws-confirmar-ok]').forEach(function (botao) {
        botao.addEventListener('click', function () {
          const modal = botao.closest('dialog');
          const form = modal && modal._wsFormulario;
          if (form && form.elements.confirmacao) form.elements.confirmacao.value = '1';
        });
      });

      // Desativar uma etapa que ainda tem livros pede confirmação (mesma regra e mesmo texto de antes).
      const formEditarEtapa = document.getElementById('form-editar-etapa');
      formEditarEtapa.addEventListener('submit', function () {
        if (formEditarEtapa.elements.confirmacao.value === '1') return; // já confirmado no modal: o envio segue
        const desativando = formEditarEtapa.dataset.ativaOriginal === '1' && !document.getElementById('ed_etapa_ativa').checked;
        const qtd = Number(formEditarEtapa.dataset.qtdLivros || 0);
        if (!desativando || qtd <= 0) {
          formEditarEtapa.dataset.wsConfirmado = '1'; // nada a confirmar: o waresys.js deixa enviar direto
          return;
        }
        delete formEditarEtapa.dataset.wsConfirmado;
        document.getElementById('modal-desativar-etapa-texto').textContent = qtd + ' livro(s) estão nesta etapa agora. Eles continuam nela até serem movidos manualmente — desativar não os afeta. Desativar mesmo assim?';
      });

      // Mudar a ordem de uma etapa que tem livros: o texto do modal vem do formulário que foi enviado.
      document.querySelectorAll('form[data-ws-confirmar="modal-mover-etapa"]').forEach(function (form) {
        form.addEventListener('submit', function () {
          if (form.elements.confirmacao.value === '1') return;
          document.getElementById('modal-mover-etapa-titulo').textContent = 'Mudar a ordem de ' + form.dataset.moverNome + '?';
          document.getElementById('modal-mover-etapa-texto').textContent = form.dataset.moverQtd + ' livro(s) estão nesta etapa agora. Mudar a ordem só afeta movimentações futuras, eles não são afetados. Continuar?';
        });
      });
      </script>
<?php endif; ?>
<?php if ($podeGerenciarUsuarios): ?>
      <!-- Modal: remover usuário (título e texto preenchidos pelo script abaixo, conforme o usuário já tenha ou não histórico) -->
      <dialog class="ws-modal" id="modal-remover-usuario" aria-labelledby="modal-remover-usuario-titulo" aria-describedby="modal-remover-usuario-texto">
        <div class="ws-modal__caixa">
          <header class="ws-modal__cabecalho">
            <h2 id="modal-remover-usuario-titulo">Remover este usuário?</h2>
            <button class="ws-fechar" type="button" data-ws-modal-fechar aria-label="Fechar"><i data-lucide="x"></i></button>
          </header>
          <div class="ws-modal__corpo ws-pilha">
            <p id="modal-remover-usuario-texto"></p>
          </div>
          <footer class="ws-modal__rodape">
            <button class="ws-btn ws-btn--secundario" type="button" data-ws-modal-fechar>Manter o usuário</button>
            <button class="ws-btn ws-btn--primario" type="button" data-ws-confirmar-ok>Remover usuário</button>
          </footer>
        </div>
      </dialog>

      <!-- Modal: usuário que NÃO pode ser removido (o motivo vem do servidor) -->
      <dialog class="ws-modal" id="modal-remover-bloqueado" aria-labelledby="modal-remover-bloqueado-titulo" aria-describedby="modal-remover-bloqueado-texto">
        <div class="ws-modal__caixa">
          <header class="ws-modal__cabecalho">
            <h2 id="modal-remover-bloqueado-titulo">Este usuário não pode ser removido</h2>
            <button class="ws-fechar" type="button" data-ws-modal-fechar aria-label="Fechar"><i data-lucide="x"></i></button>
          </header>
          <div class="ws-modal__corpo">
            <p id="modal-remover-bloqueado-texto"></p>
          </div>
          <footer class="ws-modal__rodape">
            <button class="ws-btn ws-btn--primario" type="button" data-ws-modal-fechar>Fechar</button>
          </footer>
        </div>
      </dialog>

      <script>
      // Remover usuário: o botão da linha é um formulário; o envio abre o modal (waresys.js) e só o botão "Remover usuário" do
      // modal preenche o campo oculto "confirmacao". Sem ele o PHP não remove, nem sem JavaScript.
      document.querySelectorAll('form[data-ws-confirmar="modal-remover-usuario"]').forEach(function (form) {
        form.addEventListener('submit', function () {
          if (form.elements.confirmacao.value === '1') return;
          const nome = form.dataset.removerNome;
          document.getElementById('modal-remover-usuario-titulo').textContent = 'Remover ' + nome + '?';
          document.getElementById('modal-remover-usuario-texto').textContent = form.dataset.removerUsado === '1'
            ? nome + ' já aparece nos históricos do sistema. O nome continua na linha do tempo, nos relatórios e no log, mas a conta deixa de entrar e não pode mais ser responsável por livros. Você pode reativá-la depois em "Removidos (sem acesso)".'
            : nome + ' nunca usou o sistema: nenhum livro, movimentação ou registro está ligado a esta conta. Remover exclui a conta de vez. Esta ação não pode ser desfeita.';
        });
      });
      document.querySelectorAll('#modal-remover-usuario [data-ws-confirmar-ok]').forEach(function (botao) {
        botao.addEventListener('click', function () {
          const form = document.getElementById('modal-remover-usuario')._wsFormulario;
          if (form && form.elements.confirmacao) form.elements.confirmacao.value = '1';
        });
      });
      document.querySelectorAll('[data-ws-modal-abrir="modal-remover-bloqueado"]').forEach(function (botao) {
        botao.addEventListener('click', function () {
          document.getElementById('modal-remover-bloqueado-titulo').textContent = botao.dataset.nome + ' não pode ser removido';
          document.getElementById('modal-remover-bloqueado-texto').textContent = botao.dataset.motivo;
        });
      });
      </script>
<?php endif; ?>
<?php if ($podeExcluirEtapa): ?>
      <!-- Modal: excluir etapa que nunca foi usada (Passo 20.9; título e texto preenchidos pelo script abaixo) -->
      <dialog class="ws-modal" id="modal-excluir-etapa" aria-labelledby="modal-excluir-etapa-titulo" aria-describedby="modal-excluir-etapa-texto">
        <div class="ws-modal__caixa">
          <header class="ws-modal__cabecalho">
            <h2 id="modal-excluir-etapa-titulo">Excluir esta etapa?</h2>
            <button class="ws-fechar" type="button" data-ws-modal-fechar aria-label="Fechar"><i data-lucide="x"></i></button>
          </header>
          <div class="ws-modal__corpo ws-pilha">
            <p id="modal-excluir-etapa-texto"></p>
            <div class="ws-alerta ws-alerta--limite" role="status" id="modal-excluir-etapa-aviso" hidden>
              <i class="ws-alerta__icone" data-lucide="triangle-alert"></i>
              <div class="ws-alerta__corpo">
                <p>Atenção: o aviso automático de ISBN vazio identifica esta etapa pelo nome exato. Sem ela, esse aviso deixa de existir.</p>
              </div>
            </div>
          </div>
          <footer class="ws-modal__rodape">
            <button class="ws-btn ws-btn--secundario" type="button" data-ws-modal-fechar>Manter a etapa</button>
            <button class="ws-btn ws-btn--primario" type="button" data-ws-confirmar-ok>Excluir etapa</button>
          </footer>
        </div>
      </dialog>

      <!-- Modal: etapa que NÃO pode ser excluída (o motivo vem do servidor; quem pode editar etapas vai para "desativar") -->
      <dialog class="ws-modal" id="modal-excluir-bloqueado" aria-labelledby="modal-excluir-bloqueado-titulo" aria-describedby="modal-excluir-bloqueado-texto">
        <div class="ws-modal__caixa">
          <header class="ws-modal__cabecalho">
            <h2 id="modal-excluir-bloqueado-titulo">Esta etapa não pode ser excluída</h2>
            <button class="ws-fechar" type="button" data-ws-modal-fechar aria-label="Fechar"><i data-lucide="x"></i></button>
          </header>
          <div class="ws-modal__corpo">
            <p id="modal-excluir-bloqueado-texto"></p>
          </div>
          <footer class="ws-modal__rodape">
            <button class="ws-btn ws-btn--secundario" type="button" data-ws-modal-fechar>Fechar</button>
            <button class="ws-btn ws-btn--primario" type="button" id="modal-excluir-desativar" hidden>Desativar em vez de excluir</button>
          </footer>
        </div>
      </dialog>

      <script>
      // Excluir etapa: o botão da linha é um formulário; o envio abre o modal (waresys.js) e só o botão "Excluir etapa" do modal
      // preenche o campo oculto "confirmacao". Sem ele o PHP não exclui.
      document.querySelectorAll('form[data-ws-confirmar="modal-excluir-etapa"]').forEach(function (form) {
        form.addEventListener('submit', function () {
          if (form.elements.confirmacao.value === '1') return;
          document.getElementById('modal-excluir-etapa-titulo').textContent = 'Excluir a etapa ' + form.dataset.excluirNome + '?';
          document.getElementById('modal-excluir-etapa-texto').textContent = 'A etapa ' + form.dataset.excluirNome + ' nunca foi usada: nenhum livro passou por ela. Excluir remove a etapa e reordena as demais. Esta ação não pode ser desfeita.';
          document.getElementById('modal-excluir-etapa-aviso').hidden = form.dataset.excluirCatalogacao !== '1';
        });
      });
      document.querySelectorAll('#modal-excluir-etapa [data-ws-confirmar-ok]').forEach(function (botao) {
        botao.addEventListener('click', function () {
          const form = document.getElementById('modal-excluir-etapa')._wsFormulario;
          if (form && form.elements.confirmacao) form.elements.confirmacao.value = '1';
        });
      });

      // Etapa que não pode ser excluída: o modal mostra o motivo; quem pode editar etapas (tem o botão de editar na linha) pode
      // seguir para desativar, que abre o "Editar etapa" já com "Etapa ativa" desmarcada (a confirmação de sempre vale).
      const botaoDesativarEmVezDeExcluir = document.getElementById('modal-excluir-desativar');
      document.querySelectorAll('[data-ws-modal-abrir="modal-excluir-bloqueado"]').forEach(function (botao) {
        botao.addEventListener('click', function () {
          document.getElementById('modal-excluir-bloqueado-titulo').textContent = 'A etapa ' + botao.dataset.nome + ' não pode ser excluída';
          document.getElementById('modal-excluir-bloqueado-texto').textContent = botao.dataset.motivo;
          botaoDesativarEmVezDeExcluir.dataset.id = botao.dataset.id;
          botaoDesativarEmVezDeExcluir.hidden = botao.dataset.podeDesativar !== '1';
        });
      });
      botaoDesativarEmVezDeExcluir.addEventListener('click', function () {
        const botaoEditar = document.querySelector('[data-ws-modal-abrir="modal-etapa"][data-id="' + botaoDesativarEmVezDeExcluir.dataset.id + '"]');
        document.getElementById('modal-excluir-bloqueado').close();
        if (!botaoEditar) return;
        botaoEditar.click();
        document.getElementById('ed_etapa_ativa').checked = false;
      });
      </script>
<?php endif; ?>
<?php if ($podeGerenciarPapeis): ?>
      <!-- Modal: editar papel de autoria (um só, preenchido pelo script abaixo) -->
      <dialog class="ws-modal" id="modal-papel" aria-labelledby="modal-papel-titulo">
        <form class="ws-modal__caixa" method="post" novalidate>
          <?= csrfCampo() ?>
          <input type="hidden" name="acao" value="editar_papel">
          <input type="hidden" name="papel_id" id="ed_papel_id" value="<?= $formPapelEd ? (int)$formPapelEd['id'] : 0 ?>">
          <header class="ws-modal__cabecalho">
            <h2 id="modal-papel-titulo">Editar papel</h2>
            <button class="ws-fechar" type="button" data-ws-modal-fechar aria-label="Fechar"><i data-lucide="x"></i></button>
          </header>
          <div class="ws-modal__corpo ws-pilha">
            <div class="ws-campo<?= classeCampoErro($errosPapelEd['nome'] ?? null) ?>">
              <label class="ws-campo__rotulo" for="ed_papel_nome">Nome do papel</label>
              <input class="ws-input" type="text" id="ed_papel_nome" name="nome" value="<?= escaparHtml($formPapelEd['nome'] ?? '') ?>" maxlength="<?= LIMITE_NOME_PAPEL ?>" required autofocus<?= atributosCampo('ed_papel_nome', $errosPapelEd['nome'] ?? null) ?>>
              <?= htmlErroCampo('ed_papel_nome', $errosPapelEd['nome'] ?? null) ?>
            </div>
            <div>
              <label class="ws-opcao" for="ed_papel_ativo"><input type="checkbox" id="ed_papel_ativo" name="ativo"<?= $formPapelEd && !empty($formPapelEd['ativo']) ? ' checked' : '' ?> aria-describedby="ed_papel_ativo_ajuda">Papel ativo</label>
              <p class="ws-campo__ajuda" id="ed_papel_ativo_ajuda">Um papel desativado continua nos vínculos que os livros já têm, mas não pode ser usado em vínculos novos.</p>
            </div>
          </div>
          <footer class="ws-modal__rodape">
            <button class="ws-btn ws-btn--secundario" type="button" data-ws-modal-fechar>Cancelar</button>
            <button class="ws-btn ws-btn--primario" type="submit">Salvar papel</button>
          </footer>
        </form>
      </dialog>

      <script>
      // Editar papel: o botão preenche o modal com os dados da linha (roda antes do waresys.js, que abre o modal).
      document.querySelectorAll('[data-ws-modal-abrir="modal-papel"]').forEach(function (botao) {
        botao.addEventListener('click', function () {
          if (window.WareSys) window.WareSys.limparErros('modal-papel');
          document.getElementById('ed_papel_id').value = botao.dataset.id;
          document.getElementById('ed_papel_nome').value = botao.dataset.nome;
          document.getElementById('ed_papel_ativo').checked = botao.dataset.ativo === '1';
        });
      });
<?php if ($formPapelEd): ?>
      // O envio voltou com erro: reabre o modal, com o que foi digitado e a mensagem junto do campo.
      document.addEventListener('DOMContentLoaded', function () { window.WareSys.abrirModal('modal-papel'); });
<?php endif; ?>
      </script>
<?php endif; ?>
<?php require __DIR__ . '/includes/rodape.php'; ?>
