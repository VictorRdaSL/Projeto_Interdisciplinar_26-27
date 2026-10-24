<?php
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/includes/permissoes.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/log.php';
require_once __DIR__ . '/includes/alertas.php';
require_once __DIR__ . '/includes/validacao.php';

// Formulários de cadastro novo só valem uma vez por exibição (duplo clique / reenvio / "voltar").
// Se um flash do primeiro envio ainda não foi mostrado, ele é preservado.
function exigirEnvioUnico(): void
{
    if (envio_unico_consumir($_POST['envio_id'] ?? null)) {
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
$podeGerenciarEtapasPapeis = usuarioTemPermissao('config.etapas_papeis');
$agindoComoAdmin = papelAtual() === 'admin';

// Nome exato semeado em database/seed.sql — mesmo identificado usado em
// includes/movimentacoes.php (NOME_ETAPA_CATALOGACAO) pro aviso de ISBN
// vazio. Não há coluna de identificador estável em `etapas`; renomear essa
// etapa por aqui desativa aquele aviso silenciosamente (risco já documentado).
const NOME_ETAPA_CATALOGACAO_CONFIG = 'Catalogação — ISBN e ficha catalográfica';

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

if ($podeConfigurarDiasLimite && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'alterar_dias_limite') {
    if (!csrf_validar($_POST['csrf_token'] ?? null)) {
        $_SESSION['flash'] = ['type' => 'erro', 'message' => 'Sessão expirada. Tente novamente.'];
    } else {
        $novoDiasLimite = (int)($_POST['dias_limite_livro_parado'] ?? 0);
        if ($novoDiasLimite <= 0) {
            $_SESSION['flash'] = ['type' => 'erro', 'message' => 'Informe um número de dias maior que zero.'];
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
            $totalAdmins = (int)$conn->query("SELECT COUNT(*) total FROM vw_usuarios_publico WHERE perfil = 'admin'")->fetch_assoc()['total'];
            $stmtAtual = $conn->prepare('SELECT perfil FROM vw_usuarios_publico WHERE id = ?');
            $stmtAtual->bind_param('i', $usuarioId);
            $stmtAtual->execute();
            $usuarioAtualLinha = $stmtAtual->get_result()->fetch_assoc();

            if (!$usuarioAtualLinha) {
                $_SESSION['flash'] = ['type' => 'erro', 'message' => 'Usuário não encontrado.'];
            } elseif (!$agindoComoAdmin && $usuarioAtualLinha['perfil'] === 'admin') {
                $_SESSION['flash'] = ['type' => 'erro', 'message' => 'Somente administradores podem alterar o papel de um Administrador.'];
            } elseif ($usuarioAtualLinha['perfil'] === 'admin' && $novoPapel !== 'admin' && $totalAdmins <= 1) {
                $_SESSION['flash'] = ['type' => 'erro', 'message' => 'Não é possível remover o último administrador do sistema.'];
            } else {
                $papelAnterior = $usuarioAtualLinha['perfil'];
                $stmt = $conn->prepare('UPDATE usuarios SET perfil = ? WHERE id = ?');
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
    exigirEnvioUnico();

    $novoNome = entradaTexto($_POST, 'novo_nome');
    $novoEmail = entradaTexto($_POST, 'novo_email');
    $novaSenha = entradaBruta($_POST, 'nova_senha');
    $novaConfirmarSenha = entradaBruta($_POST, 'nova_confirmar_senha');
    $novoPapelCriado = $_POST['novo_papel'] ?? 'usuario';

    if ($novoNome === '' || $novoEmail === '' || $novaSenha === '' || $novaConfirmarSenha === '') {
        $_SESSION['flash'] = ['type' => 'erro', 'message' => 'Preencha todos os campos do novo usuário.'];
    } elseif ($erroTam = erroTamanho($novoNome, LIMITE_NOME_USUARIO, 'O nome')) {
        $_SESSION['flash'] = ['type' => 'erro', 'message' => $erroTam];
    } elseif (!filter_var($novoEmail, FILTER_VALIDATE_EMAIL)) {
        $_SESSION['flash'] = ['type' => 'erro', 'message' => 'Informe um e-mail válido para o novo usuário.'];
    } elseif ($erroTam = erroTamanho($novoEmail, LIMITE_EMAIL, 'O e-mail')) {
        $_SESSION['flash'] = ['type' => 'erro', 'message' => $erroTam];
    } elseif (strlen($novaSenha) < 6) {
        $_SESSION['flash'] = ['type' => 'erro', 'message' => 'A senha deve ter pelo menos 6 caracteres.'];
    } elseif ($novaSenha !== $novaConfirmarSenha) {
        $_SESSION['flash'] = ['type' => 'erro', 'message' => 'As senhas não coincidem.'];
    } elseif (!in_array($novoPapelCriado, PAPEIS_VALIDOS, true)) {
        $_SESSION['flash'] = ['type' => 'erro', 'message' => 'Papel inválido.'];
    } elseif (!$agindoComoAdmin && $novoPapelCriado === 'admin') {
        $_SESSION['flash'] = ['type' => 'erro', 'message' => 'Somente administradores podem criar um usuário com o papel de Administrador.'];
    } else {
        $stmtExiste = $conn->prepare('SELECT id FROM vw_usuarios_publico WHERE email = ?');
        $stmtExiste->bind_param('s', $novoEmail);
        $stmtExiste->execute();
        if ($stmtExiste->get_result()->fetch_assoc()) {
            $_SESSION['flash'] = ['type' => 'erro', 'message' => 'Já existe um usuário com esse e-mail.'];
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
    }
    header('Location: configuracoes.php');
    exit;
}

if ($podeGerenciarEtapasPapeis && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'criar_etapa') {
    if (!csrf_validar($_POST['csrf_token'] ?? null)) {
        $_SESSION['flash'] = ['type' => 'erro', 'message' => 'Sessão expirada. Tente novamente.'];
        header('Location: configuracoes.php'); exit;
    }
    exigirEnvioUnico();
    $nome = entradaTexto($_POST, 'nome');
    $opcional = isset($_POST['opcional']) ? 1 : 0;
    $repetivel = isset($_POST['repetivel']) ? 1 : 0;
    $rodadasStr = entradaTexto($_POST, 'rodadas_incluidas');
    $diasStr = entradaTexto($_POST, 'dias_limite');

    if ($nome === '') {
        $_SESSION['flash'] = ['type' => 'erro', 'message' => 'Informe o nome da etapa.'];
    } elseif ($erroTam = erroTamanho($nome, LIMITE_NOME_ETAPA, 'O nome da etapa')) {
        $_SESSION['flash'] = ['type' => 'erro', 'message' => $erroTam];
    } elseif ($rodadasStr !== '' && (!ctype_digit($rodadasStr) || (int)$rodadasStr < 1)) {
        $_SESSION['flash'] = ['type' => 'erro', 'message' => 'Rodadas incluídas deve ser um número inteiro maior que zero, ou vazio para sem limite.'];
    } elseif ($diasStr !== '' && (!ctype_digit($diasStr) || (int)$diasStr < 1)) {
        $_SESSION['flash'] = ['type' => 'erro', 'message' => 'Dias limite deve ser um número inteiro maior que zero, ou vazio para usar o padrão global.'];
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
    header('Location: configuracoes.php');
    exit;
}

if ($podeGerenciarEtapasPapeis && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'editar_etapa') {
    if (!csrf_validar($_POST['csrf_token'] ?? null)) {
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
    } elseif ($nome === '') {
        $_SESSION['flash'] = ['type' => 'erro', 'message' => 'Informe o nome da etapa.'];
    } elseif ($erroTam = erroTamanho($nome, LIMITE_NOME_ETAPA, 'O nome da etapa')) {
        $_SESSION['flash'] = ['type' => 'erro', 'message' => $erroTam];
    } elseif ($rodadasStr !== '' && (!ctype_digit($rodadasStr) || (int)$rodadasStr < 1)) {
        $_SESSION['flash'] = ['type' => 'erro', 'message' => 'Rodadas incluídas deve ser um número inteiro maior que zero, ou vazio para sem limite.'];
    } elseif ($diasStr !== '' && (!ctype_digit($diasStr) || (int)$diasStr < 1)) {
        $_SESSION['flash'] = ['type' => 'erro', 'message' => 'Dias limite deve ser um número inteiro maior que zero, ou vazio para usar o padrão global.'];
    } else {
        $rodadas = $rodadasStr === '' ? null : (int)$rodadasStr;
        $dias = $diasStr === '' ? null : (int)$diasStr;
        $rodadasAntigo = $etapaAtualRow['rodadas_incluidas'] !== null ? (int)$etapaAtualRow['rodadas_incluidas'] : null;
        $diasAntigo = $etapaAtualRow['dias_limite'] !== null ? (int)$etapaAtualRow['dias_limite'] : null;

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
    header('Location: configuracoes.php');
    exit;
}

if ($podeGerenciarEtapasPapeis && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'mover_etapa') {
    if (!csrf_validar($_POST['csrf_token'] ?? null)) {
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

if ($podeGerenciarEtapasPapeis && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'criar_papel') {
    if (!csrf_validar($_POST['csrf_token'] ?? null)) {
        $_SESSION['flash'] = ['type' => 'erro', 'message' => 'Sessão expirada. Tente novamente.'];
        header('Location: configuracoes.php'); exit;
    }
    exigirEnvioUnico();
    $nome = entradaTexto($_POST, 'nome');
    if ($nome === '') {
        $_SESSION['flash'] = ['type' => 'erro', 'message' => 'Informe o nome do papel.'];
    } elseif ($erroTam = erroTamanho($nome, LIMITE_NOME_PAPEL, 'O nome do papel')) {
        $_SESSION['flash'] = ['type' => 'erro', 'message' => $erroTam];
    } else {
        $stmt = $conn->prepare('INSERT INTO papeis (nome, ativo) VALUES (?, 1)');
        $stmt->bind_param('s', $nome);
        $stmt->execute();
        $novoPapelId = $conn->insert_id;
        registrarLog($conn, (int)$_SESSION['usuario_id'], 'papel.criar', 'papel', $novoPapelId, null, $nome);
        $_SESSION['flash'] = ['type' => 'sucesso', 'message' => 'Papel cadastrado com sucesso.'];
    }
    header('Location: configuracoes.php');
    exit;
}

if ($podeGerenciarEtapasPapeis && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'editar_papel') {
    if (!csrf_validar($_POST['csrf_token'] ?? null)) {
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
    } elseif ($nome === '') {
        $_SESSION['flash'] = ['type' => 'erro', 'message' => 'Informe o nome do papel.'];
    } elseif ($erroTam = erroTamanho($nome, LIMITE_NOME_PAPEL, 'O nome do papel')) {
        $_SESSION['flash'] = ['type' => 'erro', 'message' => $erroTam];
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
    header('Location: configuracoes.php');
    exit;
}

$usuarios = [];
$r = $conn->query('SELECT id, nome, email, perfil, criado_em FROM vw_usuarios_publico ORDER BY nome');
while ($row = $r->fetch_assoc()) $usuarios[] = $row;

$diasLimiteAtual = buscarDiasLimiteGlobal($conn);

$etapas = [];
$papeis = [];
$contagemPorEtapa = [];
if ($podeGerenciarEtapasPapeis) {
    $re = $conn->query('SELECT * FROM etapas ORDER BY ordem ASC, id ASC');
    while ($row = $re->fetch_assoc()) $etapas[] = $row;

    // Livros que ficariam "presos" numa etapa desativada ou reordenada —
    // extraviados contam (ainda estão fisicamente ali), concluídos/cancelados não.
    $rc = $conn->query("SELECT etapa_atual_id, COUNT(*) total FROM livros WHERE status NOT IN ('concluido', 'cancelado') GROUP BY etapa_atual_id");
    while ($row = $rc->fetch_assoc()) $contagemPorEtapa[(int)$row['etapa_atual_id']] = (int)$row['total'];

    $rp = $conn->query('SELECT * FROM papeis ORDER BY nome ASC');
    while ($row = $rp->fetch_assoc()) $papeis[] = $row;
}

// Log de auditoria: aberto a qualquer usuário logado (decisão confirmada no Passo 9).
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
        <a href="relatorios.php"><span>📄</span>Relatórios</a>
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

    <?php if ($podeConfigurarDiasLimite): ?>
    <section class="painel" style="margin-top:30px;">
        <div class="painel-topo">
            <div><h2>Livro parado</h2><p>Quantos dias um livro pode ficar na mesma etapa antes de entrar no alerta do Painel. Vale para etapas sem um limite próprio (hoje, todas).</p></div>
        </div>
        <form method="post" class="form-papel">
            <?= csrf_field() ?>
            <input type="hidden" name="acao" value="alterar_dias_limite">
            <div class="campo"><label for="dias_limite_livro_parado">Dias-limite padrão</label>
                <input type="number" id="dias_limite_livro_parado" name="dias_limite_livro_parado" min="1" value="<?= (int)$diasLimiteAtual ?>" required>
            </div>
            <button type="submit" class="btn-salvar">Salvar</button>
        </form>
    </section>
    <?php endif; ?>

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
                    $podeEditarEssaLinha = $agindoComoAdmin || (!$ehEuMesmo && $u['perfil'] !== 'admin');
                ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($u['nome']) ?></strong></td>
                        <td><?= htmlspecialchars($u['email']) ?></td>
                        <td><span class="papel-badge papel-<?= htmlspecialchars($u['perfil']) ?>"><?= htmlspecialchars(nomePapel($u['perfil'])) ?></span></td>
                        <td><?= date('d/m/Y', strtotime($u['criado_em'])) ?></td>
                        <td>
                            <?php if ($podeEditarEssaLinha): ?>
                            <form method="post" class="form-papel">
                                <?= csrf_field() ?>
                                <input type="hidden" name="acao" value="alterar_papel">
                                <input type="hidden" name="usuario_id" value="<?= (int)$u['id'] ?>">
                                <select name="papel">
                                    <?php if ($agindoComoAdmin): ?>
                                    <option value="admin" <?= $u['perfil'] === 'admin' ? 'selected' : '' ?>>Administrador</option>
                                    <?php endif; ?>
                                    <option value="gerente" <?= $u['perfil'] === 'gerente' ? 'selected' : '' ?>>Gerente</option>
                                    <option value="usuario" <?= $u['perfil'] === 'usuario' ? 'selected' : '' ?>>Usuário</option>
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
                <?= envio_unico_field() ?>
                <div class="campo"><label for="novo_nome">Nome</label><input type="text" id="novo_nome" name="novo_nome" maxlength="<?= LIMITE_NOME_USUARIO ?>" required></div>
                <div class="campo"><label for="novo_email">E-mail</label><input type="email" id="novo_email" name="novo_email" maxlength="<?= LIMITE_EMAIL ?>" required></div>
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

    <?php if ($podeGerenciarEtapasPapeis): ?>
    <section class="painel" style="margin-top:30px;">
        <div class="painel-topo">
            <div><h2>Etapas do fluxo</h2><p>Só Administrador. Uma etapa com histórico nunca é excluída — apenas desativada. Livros que já estão numa etapa continuam nela até serem movidos manualmente; desativar ou reordenar não os afeta.</p></div>
        </div>
        <div class="tabela-container">
            <table>
                <thead><tr><th>Ordem</th><th>Etapa</th><th>Opcional</th><th>Repetível</th><th>Rodadas incluídas</th><th>Dias limite</th><th>Status</th><th>Livros agora</th><th>Ações</th></tr></thead>
                <tbody>
                <?php foreach ($etapas as $idxEt => $et):
                    $qtdLivrosEtapa = $contagemPorEtapa[(int)$et['id']] ?? 0;
                    $temVizinhoAcima = $idxEt > 0;
                    $temVizinhoAbaixo = $idxEt < count($etapas) - 1;
                    $ehCatalogacao = $et['nome'] === NOME_ETAPA_CATALOGACAO_CONFIG;
                    $msgMoverEtapa = $qtdLivrosEtapa > 0
                        ? ($qtdLivrosEtapa . ' livro(s) estão nesta etapa agora. Mudar a ordem só afeta movimentações futuras, eles não são afetados. Continuar?')
                        : null;
                    $confirmMoverJs = $msgMoverEtapa ? json_encode($msgMoverEtapa, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) : null;
                ?>
                    <tr>
                        <td><?= (int)$et['ordem'] ?></td>
                        <td><?= htmlspecialchars($et['nome']) ?></td>
                        <td><?= (int)$et['opcional'] === 1 ? 'Sim' : 'Não' ?></td>
                        <td><?= (int)$et['repetivel'] === 1 ? 'Sim' : 'Não' ?></td>
                        <td><?= $et['rodadas_incluidas'] !== null ? (int)$et['rodadas_incluidas'] : 'Sem limite' ?></td>
                        <td><?= $et['dias_limite'] !== null ? (int)$et['dias_limite'] : 'Padrão global' ?></td>
                        <td><span class="status <?= (int)$et['ativa'] === 1 ? 'ok' : 'atencao' ?>"><?= (int)$et['ativa'] === 1 ? 'Ativa' : 'Inativa' ?></span></td>
                        <td><?= $qtdLivrosEtapa ?></td>
                        <td>
                            <div style="display:flex;gap:6px;flex-wrap:wrap;align-items:flex-start;">
                                <?php if ($temVizinhoAcima): ?>
                                <form method="post">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="acao" value="mover_etapa">
                                    <input type="hidden" name="etapa_id" value="<?= (int)$et['id'] ?>">
                                    <input type="hidden" name="direcao" value="up">
                                    <button type="submit" class="acao-btn" title="Mover para cima"<?= $confirmMoverJs ? " onclick='return confirm(" . $confirmMoverJs . ")'" : '' ?>>↑</button>
                                </form>
                                <?php endif; ?>
                                <?php if ($temVizinhoAbaixo): ?>
                                <form method="post">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="acao" value="mover_etapa">
                                    <input type="hidden" name="etapa_id" value="<?= (int)$et['id'] ?>">
                                    <input type="hidden" name="direcao" value="down">
                                    <button type="submit" class="acao-btn" title="Mover para baixo"<?= $confirmMoverJs ? " onclick='return confirm(" . $confirmMoverJs . ")'" : '' ?>>↓</button>
                                </form>
                                <?php endif; ?>
                                <details>
                                    <summary class="acao-btn" style="display:inline-block;cursor:pointer;">Editar</summary>
                                    <form method="post" style="margin-top:10px;min-width:260px;" onsubmit="return confirmarEdicaoEtapa(this, <?= (int)$et['ativa'] ?>, <?= $qtdLivrosEtapa ?>)">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="acao" value="editar_etapa">
                                        <input type="hidden" name="etapa_id" value="<?= (int)$et['id'] ?>">
                                        <?php if ($ehCatalogacao): ?>
                                            <p style="font-size:12px;color:#b98439;">Atenção: o aviso automático de ISBN vazio identifica esta etapa pelo nome exato. Renomear aqui desativa esse aviso.</p>
                                        <?php endif; ?>
                                        <div class="campo"><label>Nome</label><input type="text" name="nome" value="<?= htmlspecialchars($et['nome']) ?>" maxlength="<?= LIMITE_NOME_ETAPA ?>" required></div>
                                        <div class="campo"><label><input type="checkbox" name="opcional" <?= (int)$et['opcional'] === 1 ? 'checked' : '' ?>> Opcional</label></div>
                                        <div class="campo"><label><input type="checkbox" name="repetivel" <?= (int)$et['repetivel'] === 1 ? 'checked' : '' ?>> Repetível</label></div>
                                        <div class="campo"><label>Rodadas incluídas (vazio = sem limite)</label><input type="number" name="rodadas_incluidas" min="1" value="<?= $et['rodadas_incluidas'] !== null ? (int)$et['rodadas_incluidas'] : '' ?>"></div>
                                        <div class="campo"><label>Dias limite (vazio = padrão global)</label><input type="number" name="dias_limite" min="1" value="<?= $et['dias_limite'] !== null ? (int)$et['dias_limite'] : '' ?>"></div>
                                        <div class="campo"><label><input type="checkbox" name="ativa" <?= (int)$et['ativa'] === 1 ? 'checked' : '' ?>> Ativa</label></div>
                                        <button type="submit" class="btn-salvar">Salvar</button>
                                    </form>
                                </details>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="secao-simples" style="margin-top:24px;">
            <h2 style="font-size:16px;">+ Nova etapa</h2>
            <p style="font-size:13px;color:#8a9b91;margin-bottom:10px;">Entra no final do fluxo — use as setas da tabela pra reposicionar depois.</p>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="acao" value="criar_etapa">
                <?= envio_unico_field() ?>
                <div class="campo"><label for="nova_etapa_nome">Nome</label><input type="text" id="nova_etapa_nome" name="nome" maxlength="<?= LIMITE_NOME_ETAPA ?>" required></div>
                <div class="campo"><label><input type="checkbox" name="opcional"> Opcional</label></div>
                <div class="campo"><label><input type="checkbox" name="repetivel"> Repetível</label></div>
                <div class="campo"><label for="nova_etapa_rodadas">Rodadas incluídas (vazio = sem limite)</label><input type="number" id="nova_etapa_rodadas" name="rodadas_incluidas" min="1"></div>
                <div class="campo"><label for="nova_etapa_dias">Dias limite (vazio = padrão global)</label><input type="number" id="nova_etapa_dias" name="dias_limite" min="1"></div>
                <div class="form-botoes"><button type="submit" class="btn-salvar">Cadastrar etapa</button></div>
            </form>
        </div>
    </section>

    <section class="painel" style="margin-top:30px;">
        <div class="painel-topo">
            <div><h2>Papéis de autoria</h2><p>Autor, Organizador, Ilustrador — o papel de uma pessoa num livro (não confundir com o papel de acesso do usuário, na seção acima). Só Administrador. Nunca excluídos — apenas desativados.</p></div>
        </div>
        <div class="tabela-container">
            <table>
                <thead><tr><th>Nome</th><th>Status</th><th>Ações</th></tr></thead>
                <tbody>
                <?php if (!$papeis): ?>
                    <tr><td colspan="3" class="vazio">Nenhum papel cadastrado.</td></tr>
                <?php else: foreach ($papeis as $pp): ?>
                    <tr>
                        <td><?= htmlspecialchars($pp['nome']) ?></td>
                        <td><span class="status <?= (int)$pp['ativo'] === 1 ? 'ok' : 'atencao' ?>"><?= (int)$pp['ativo'] === 1 ? 'Ativo' : 'Inativo' ?></span></td>
                        <td>
                            <details>
                                <summary class="acao-btn" style="display:inline-block;cursor:pointer;">Editar</summary>
                                <form method="post" style="margin-top:10px;min-width:220px;">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="acao" value="editar_papel">
                                    <input type="hidden" name="papel_id" value="<?= (int)$pp['id'] ?>">
                                    <div class="campo"><label>Nome</label><input type="text" name="nome" value="<?= htmlspecialchars($pp['nome']) ?>" maxlength="<?= LIMITE_NOME_PAPEL ?>" required></div>
                                    <div class="campo"><label><input type="checkbox" name="ativo" <?= (int)$pp['ativo'] === 1 ? 'checked' : '' ?>> Ativo</label></div>
                                    <button type="submit" class="btn-salvar">Salvar</button>
                                </form>
                            </details>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>

        <div class="secao-simples" style="margin-top:24px;">
            <h2 style="font-size:16px;">+ Novo papel</h2>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="acao" value="criar_papel">
                <?= envio_unico_field() ?>
                <div class="campo"><label for="novo_papel_nome">Nome</label><input type="text" id="novo_papel_nome" name="nome" maxlength="<?= LIMITE_NOME_PAPEL ?>" required></div>
                <div class="form-botoes"><button type="submit" class="btn-salvar">Cadastrar papel</button></div>
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

<script src="envio_unico.js"></script>
<?php if ($podeGerenciarEtapasPapeis): ?>
<script>
function confirmarEdicaoEtapa(form, ativaOriginal, qtdLivros) {
  var chkAtiva = form.querySelector('input[name="ativa"]');
  if (ativaOriginal === 1 && chkAtiva && !chkAtiva.checked && qtdLivros > 0) {
    return confirm(qtdLivros + ' livro(s) estão nesta etapa agora. Eles continuam nela até serem movidos manualmente — desativar não os afeta. Desativar mesmo assim?');
  }
  return true;
}
</script>
<?php endif; ?>
</body>
</html>
