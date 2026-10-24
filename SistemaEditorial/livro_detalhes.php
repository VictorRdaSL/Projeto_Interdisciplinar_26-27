<?php
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/permissoes.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/movimentacoes.php';

$livroId = idDaUrl($_GET, 'id');
if ($livroId <= 0) {
    $_SESSION['flash'] = ['type' => 'erro', 'message' => 'Livro não encontrado.'];
    header('Location: livros.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!usuarioTemPermissao('livros.gerenciar')) {
        $_SESSION['flash'] = ['type' => 'erro', 'message' => 'Você não tem permissão para movimentar livros.'];
        header('Location: livro_detalhes.php?id=' . $livroId);
        exit;
    }
    if (!csrf_validar($_POST['csrf_token'] ?? null)) {
        $_SESSION['flash'] = ['type' => 'erro', 'message' => 'Sessão expirada. Tente novamente.'];
        header('Location: livro_detalhes.php?id=' . $livroId);
        exit;
    }

    $acaoPost = $_POST['acao'] ?? '';
    $usuarioId = (int)$_SESSION['usuario_id'];

    try {
        $responsavelIdPost = isset($_POST['responsavel_id']) && $_POST['responsavel_id'] !== '' ? (int)$_POST['responsavel_id'] : null;

        if ($acaoPost === 'avancar') {
            $etapaDestinoId = (int)($_POST['etapa_destino_id'] ?? 0);
            $etapaPuladaId = isset($_POST['etapa_pulada_id']) && $_POST['etapa_pulada_id'] !== '' ? (int)$_POST['etapa_pulada_id'] : null;
            $resultado = avancarEtapa($conn, $livroId, $etapaDestinoId, $usuarioId, $etapaPuladaId, $responsavelIdPost);
            $mensagem = 'Livro avançado para ' . $resultado['etapa_nome'] . '.';
        } elseif ($acaoPost === 'repetir') {
            $resultado = repetirEtapa($conn, $livroId, $usuarioId, $responsavelIdPost);
            $mensagem = 'Nova rodada aberta em ' . $resultado['etapa_nome'] . '.';
        } elseif ($acaoPost === 'voltar') {
            $etapaDestinoId = (int)($_POST['etapa_destino_id'] ?? 0);
            $resultado = voltarEtapa($conn, $livroId, $etapaDestinoId, $usuarioId, $responsavelIdPost);
            $mensagem = 'Livro devolvido para ' . $resultado['etapa_nome'] . '.';
        } elseif ($acaoPost === 'concluir') {
            $resultado = concluirLivro($conn, $livroId, $usuarioId);
            $mensagem = 'Livro concluído com sucesso.';
        } elseif ($acaoPost === 'passar_mesa') {
            $novoResponsavelId = (int)($_POST['novo_responsavel_id'] ?? 0);
            $resultado = passarParaOutraMesa($conn, $livroId, $novoResponsavelId, $usuarioId);
            $mensagem = 'Livro passado para a mesa de ' . nomeUsuario($conn, $novoResponsavelId) . '.';
        } elseif ($acaoPost === 'iniciar_resgate') {
            iniciarResgate($conn, $livroId, $usuarioId);
            $_SESSION['flash'] = ['type' => 'sucesso', 'message' => 'Resgate iniciado. O livro foi marcado como extraviado.'];
            header('Location: resgate.php?id=' . $livroId);
            exit;
        } elseif ($acaoPost === 'cancelar') {
            if (!usuarioTemPermissao('livros.cancelar')) {
                throw new InvalidArgumentException('Você não tem permissão para cancelar livros.');
            }
            cancelarLivro($conn, $livroId, $usuarioId);
            $_SESSION['flash'] = ['type' => 'sucesso', 'message' => 'Livro cancelado.'];
            header('Location: livro_detalhes.php?id=' . $livroId);
            exit;
        } else {
            throw new InvalidArgumentException('Ação inválida.');
        }

        if ($resultado['avisos']) {
            $mensagem .= ' ' . implode(' ', $resultado['avisos']);
        }
        $_SESSION['flash'] = ['type' => 'sucesso', 'message' => $mensagem];
    } catch (Throwable $e) {
        $_SESSION['flash'] = ['type' => 'erro', 'message' => mensagemErroSegura($e, 'concluir a ação')];
    }

    header('Location: livro_detalhes.php?id=' . $livroId);
    exit;
}

$stmt = $conn->prepare('
    SELECT l.*, e.nome AS etapa_nome, u.nome AS responsavel_nome
    FROM livros l
    LEFT JOIN etapas e ON e.id = l.etapa_atual_id
    LEFT JOIN usuarios u ON u.id = l.responsavel_atual_id
    WHERE l.id = ?
');
$stmt->bind_param('i', $livroId);
$stmt->execute();
$livro = $stmt->get_result()->fetch_assoc();

if (!$livro) {
    $_SESSION['flash'] = ['type' => 'erro', 'message' => 'Livro não encontrado.'];
    header('Location: livros.php');
    exit;
}

$stmtContrib = $conn->prepare('
    SELECT pe.nome AS pessoa_nome, pa.nome AS papel_nome
    FROM contribuicoes c
    JOIN pessoas pe ON pe.id = c.pessoa_id
    JOIN papeis pa ON pa.id = c.papel_id
    WHERE c.livro_id = ?
    ORDER BY pa.nome, pe.nome
');
$stmtContrib->bind_param('i', $livroId);
$stmtContrib->execute();
$contribuicoes = [];
$rc = $stmtContrib->get_result();
while ($row = $rc->fetch_assoc()) $contribuicoes[] = $row;

$stmtMov = $conn->prepare('
    SELECT m.*, e.nome AS etapa_nome, e.rodadas_incluidas AS etapa_rodadas_incluidas,
           resp.nome AS responsavel_nome, reg.nome AS registrado_por_nome
    FROM movimentacoes m
    LEFT JOIN etapas e ON e.id = m.etapa_id
    LEFT JOIN usuarios resp ON resp.id = m.responsavel_id
    LEFT JOIN usuarios reg ON reg.id = m.registrado_por_id
    WHERE m.livro_id = ?
    ORDER BY m.data_entrada DESC, m.id DESC
');
$stmtMov->bind_param('i', $livroId);
$stmtMov->execute();
$movimentacoes = [];
$rm = $stmtMov->get_result();
while ($row = $rm->fetch_assoc()) $movimentacoes[] = $row;

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$podeGerenciar = usuarioTemPermissao('livros.gerenciar');

$usuariosDisponiveis = [];
$ru = $conn->query('SELECT id, nome FROM vw_usuarios_publico ORDER BY nome');
while ($row = $ru->fetch_assoc()) $usuariosDisponiveis[] = $row;

const NOMES_STATUS_LIVRO_DET = [
    'em_andamento' => 'Em andamento',
    'concluido'    => 'Concluído',
    'extraviado'   => 'Extraviado',
    'cancelado'    => 'Cancelado',
];
const NOMES_TIPO_MOV = [
    'avanco'           => 'Avanço',
    'repeticao'        => 'Repetição',
    'pulo_opcional'    => 'Pulo (opcional)',
    'retrocesso'       => 'Retrocesso',
    'troca_responsavel'=> 'Troca de responsável',
    'resgate'          => 'Resgate',
];

// Classe de destaque visual por tipo de movimentação (volta, pulo, resgate).
const CLASSE_DESTAQUE_TIPO_MOV = [
    'retrocesso' => 'info',
    'pulo_opcional' => 'pulo',
    'resgate' => 'resgate',
];

// Calculado em PHP (DateTime::diff), não com DATEDIFF/NOW() do MySQL, pela
// mesma cautela de fuso já documentada para "dias com a mesa atual".
function formatarDuracaoEtapa(string $dataEntrada, ?string $dataSaida): string
{
    $inicio = new DateTime($dataEntrada);
    $fim = $dataSaida !== null ? new DateTime($dataSaida) : new DateTime();
    $diff = $inicio->diff($fim);

    if ($diff->days >= 1) {
        $texto = $diff->days . ' dia' . ($diff->days > 1 ? 's' : '');
    } elseif ($diff->h >= 1) {
        $texto = $diff->h . ' hora' . ($diff->h > 1 ? 's' : '');
    } else {
        $texto = 'menos de 1 hora';
    }

    return $dataSaida === null ? $texto . ' (em andamento)' : $texto;
}

// Resolução das ações de movimentação disponíveis para esta tela.
$etapaAtual = buscarEtapa($conn, (int)$livro['etapa_atual_id']);
$acoesDisponiveis = [];
$podeRepetir = false;
$rodadaRepetir = null;
$excedenteRepetir = false;
$etapasParaVoltar = [];
$infoVoltar = [];

if ($podeGerenciar && $livro['status'] === 'em_andamento' && $etapaAtual) {
    $proxima = proximaEtapaAtiva($conn, (int)$etapaAtual['ordem']);

    if (!$proxima) {
        if ((int)$etapaAtual['finaliza_livro'] === 1) {
            $acoesDisponiveis[] = ['modo' => 'concluir', 'label' => 'Concluir livro'];
        }
    } else {
        $proximaJaFeita = etapaJaConcluidaParaLivro($conn, $livroId, (int)$proxima['id']);
        if ($proximaJaFeita) {
            $rodada = calcularRodada($conn, $livroId, (int)$proxima['id']);
            $acoesDisponiveis[] = [
                'modo' => 'avancar', 'etapa_destino_id' => (int)$proxima['id'],
                'label' => 'Entrar de novo em ' . $proxima['nome'],
                'etapa_nome' => $proxima['nome'], 'rodada' => $rodada,
                'excedente' => calcularExcedente($proxima, $rodada),
                'rodadas_incluidas' => $proxima['rodadas_incluidas'],
            ];
            $naoFeita = primeiraEtapaNaoConcluida($conn, $livroId, (int)$etapaAtual['ordem']);
            if ($naoFeita) {
                $rodadaNf = calcularRodada($conn, $livroId, (int)$naoFeita['id']);
                $acoesDisponiveis[] = [
                    'modo' => 'avancar', 'etapa_destino_id' => (int)$naoFeita['id'],
                    'label' => 'Seguir direto para ' . $naoFeita['nome'],
                    'etapa_nome' => $naoFeita['nome'], 'rodada' => $rodadaNf,
                    'excedente' => calcularExcedente($naoFeita, $rodadaNf),
                    'rodadas_incluidas' => $naoFeita['rodadas_incluidas'],
                ];
            }
        } elseif ((int)$proxima['opcional'] === 1) {
            $rodada = calcularRodada($conn, $livroId, (int)$proxima['id']);
            $acoesDisponiveis[] = [
                'modo' => 'avancar', 'etapa_destino_id' => (int)$proxima['id'],
                'label' => 'Fazer ' . $proxima['nome'],
                'etapa_nome' => $proxima['nome'], 'rodada' => $rodada,
                'excedente' => calcularExcedente($proxima, $rodada),
                'rodadas_incluidas' => $proxima['rodadas_incluidas'],
            ];
            $depoisDaPulada = proximaEtapaAtiva($conn, (int)$proxima['ordem']);
            if ($depoisDaPulada) {
                $acoesDisponiveis[] = [
                    'modo' => 'avancar', 'etapa_destino_id' => (int)$depoisDaPulada['id'],
                    'etapa_pulada_id' => (int)$proxima['id'],
                    'label' => 'Pular ' . $proxima['nome'],
                ];
            }
        } else {
            $rodada = calcularRodada($conn, $livroId, (int)$proxima['id']);
            $acoesDisponiveis[] = [
                'modo' => 'avancar', 'etapa_destino_id' => (int)$proxima['id'],
                'label' => 'Avançar para ' . $proxima['nome'],
                'etapa_nome' => $proxima['nome'], 'rodada' => $rodada,
                'excedente' => calcularExcedente($proxima, $rodada),
                'rodadas_incluidas' => $proxima['rodadas_incluidas'],
            ];
        }
    }

    if ((int)$etapaAtual['repetivel'] === 1) {
        $podeRepetir = true;
        $rodadaRepetir = calcularRodada($conn, $livroId, (int)$etapaAtual['id']);
        $excedenteRepetir = calcularExcedente($etapaAtual, $rodadaRepetir);
    }

    $etapasParaVoltar = etapasAnterioresAtivas($conn, (int)$etapaAtual['ordem']);
    foreach ($etapasParaVoltar as $ev) {
        $r = calcularRodada($conn, $livroId, (int)$ev['id']);
        $infoVoltar[(int)$ev['id']] = [
            'nome' => $ev['nome'],
            'rodada' => $r,
            'excedente' => calcularExcedente($ev, $r),
            'rodadas_incluidas' => $ev['rodadas_incluidas'] !== null ? (int)$ev['rodadas_incluidas'] : null,
        ];
    }
}

// Rodada atual exibida nos cards — lida da movimentação mais recente (é a
// aberta, se o livro estiver em andamento; invariante: só existe uma
// movimentação aberta por livro).
$rodadaAtualLabel = '—';
$rodadaAtualExcedente = false;
$diasComMesaAtual = null;
if ($movimentacoes && $etapaAtual) {
    $movAtual = $movimentacoes[0];
    $rodadaAtualLabel = 'Rodada ' . (int)$movAtual['rodada'];
    if ($etapaAtual['rodadas_incluidas'] !== null) {
        $rodadaAtualLabel .= ' de ' . (int)$etapaAtual['rodadas_incluidas'];
    }
    $rodadaAtualExcedente = (int)$movAtual['excedente'] === 1;

    // Calculado em PHP (não com DATEDIFF/NOW() do MySQL) para não reabrir o bug
    // conhecido de "hoje" divergente entre PHP e MySQL quando os fusos não combinam.
    $diasComMesaAtual = (new DateTime())->diff(new DateTime($movAtual['data_entrada']))->days;
}

// Indicador de progresso no fluxo (ex: "Etapa 7 de 15").
$totalEtapas = (int)$conn->query('SELECT COUNT(*) total FROM etapas')->fetch_assoc()['total'];
$percentualProgresso = ($etapaAtual && $totalEtapas > 0) ? round(((int)$etapaAtual['ordem']) / $totalEtapas * 100) : 0;

// Resumo das rodadas nas etapas com limite (ex: "Diagramação: 2 de 3 · Revisão: 1 de 2")
// e quantas delas já ficaram cobráveis.
$resumoRodadasTexto = [];
$stmtResumo = $conn->prepare('
    SELECT e.nome, e.rodadas_incluidas, COALESCE(MAX(m.rodada), 0) AS rodada_max
    FROM etapas e
    LEFT JOIN movimentacoes m ON m.etapa_id = e.id AND m.livro_id = ? AND m.tipo IN (\'avanco\', \'repeticao\', \'retrocesso\')
    WHERE e.rodadas_incluidas IS NOT NULL
    GROUP BY e.id, e.nome, e.rodadas_incluidas, e.ordem
    ORDER BY e.ordem
');
$stmtResumo->bind_param('i', $livroId);
$stmtResumo->execute();
$rResumo = $stmtResumo->get_result();
while ($row = $rResumo->fetch_assoc()) {
    $resumoRodadasTexto[] = $row['nome'] . ': ' . (int)$row['rodada_max'] . ' de ' . (int)$row['rodadas_incluidas'];
}

$stmtCobraveis = $conn->prepare('SELECT COUNT(*) total FROM movimentacoes WHERE livro_id = ? AND excedente = 1');
$stmtCobraveis->bind_param('i', $livroId);
$stmtCobraveis->execute();
$totalCobraveis = (int)$stmtCobraveis->get_result()->fetch_assoc()['total'];
?>
<!DOCTYPE html>
<html lang="pt-br" data-theme="<?= htmlspecialchars($_SESSION['usuario_tema'] ?? 'claro') ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>WareSys | <?= htmlspecialchars($livro['titulo']) ?></title>
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
        <div><p class="bem-vindo"><a href="livros.php" class="btn">← Voltar para Livros</a></p><h2><?= htmlspecialchars($livro['titulo']) ?></h2></div>
        <div class="perfil">
            <div class="avatar"><?= htmlspecialchars(strtoupper(substr($_SESSION['usuario_nome'] ?? 'U', 0, 1))) ?></div>
            <div><strong><?= htmlspecialchars($_SESSION['usuario_nome'] ?? 'Usuário') ?></strong><p><span class="papel-badge papel-<?= htmlspecialchars(papelAtual()) ?>"><?= htmlspecialchars(nomePapel(papelAtual())) ?></span></p></div>
            <a href="logout.php" class="btn btn-sair">Sair</a>
        </div>
    </header>

    <?php if ($flash): ?>
        <div class="flash <?= htmlspecialchars($flash['type']) ?>"><?= htmlspecialchars($flash['message']) ?></div>
    <?php endif; ?>

    <?php if ($etapaAtual): ?>
    <section class="painel" style="padding:18px 24px;">
        <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:20px;">
            <div style="flex:1;min-width:220px;">
                <p style="font-size:13px;color:#8a9b91;margin-bottom:6px;">Progresso no fluxo — Etapa <?= (int)$etapaAtual['ordem'] ?> de <?= $totalEtapas ?></p>
                <div style="background:#eef2ef;border-radius:999px;height:10px;overflow:hidden;">
                    <div style="background:var(--verde-500);height:100%;width:<?= $percentualProgresso ?>%;"></div>
                </div>
            </div>
            <?php if ($resumoRodadasTexto || $totalCobraveis > 0): ?>
            <div style="font-size:13px;color:#53645a;text-align:right;">
                <?php if ($resumoRodadasTexto): ?>
                    <div><?= htmlspecialchars(implode(' · ', $resumoRodadasTexto)) ?></div>
                <?php endif; ?>
                <?php if ($totalCobraveis > 0): ?>
                    <span class="status atencao" style="margin-top:6px;display:inline-block;"><?= $totalCobraveis ?> rodada<?= $totalCobraveis > 1 ? 's' : '' ?> <?= $totalCobraveis > 1 ? 'cobráveis' : 'cobrável' ?> acumulada<?= $totalCobraveis > 1 ? 's' : '' ?></span>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </section>
    <?php endif; ?>

    <section class="cards">
        <div class="card"><div><p>Etapa atual</p><h3 style="font-size:18px;"><?= htmlspecialchars($livro['etapa_nome'] ?: '—') ?></h3></div><div class="card-icon">📍</div></div>
        <div class="card"><div><p>Responsável atual (mesa)</p><h3 style="font-size:18px;"><?= htmlspecialchars($livro['responsavel_nome'] ?: '—') ?></h3><?php if ($diasComMesaAtual !== null): ?><span style="font-size:12px;color:#8a9b91;">com ela há <?= $diasComMesaAtual === 0 ? 'menos de 1 dia' : $diasComMesaAtual . ' dia' . ($diasComMesaAtual > 1 ? 's' : '') ?></span><?php endif; ?></div><div class="card-icon">👤</div></div>
        <div class="card"><div><p>Status</p><h3 style="font-size:18px;"><?= htmlspecialchars(NOMES_STATUS_LIVRO_DET[$livro['status']] ?? $livro['status']) ?></h3></div><div class="card-icon">📊</div></div>
        <div class="card"><div><p>Tem cópia?</p><h3 style="font-size:18px;"><?= ((int)$livro['tem_copia'] === 1) ? 'Sim' : 'Não' ?></h3></div><div class="card-icon">📄</div></div>
        <div class="card"><div><p>Rodada atual</p><h3 style="font-size:18px;"><?= htmlspecialchars($rodadaAtualLabel) ?><?php if ($rodadaAtualExcedente): ?> <span class="status atencao" style="font-size:11px;">Cobrável</span><?php endif; ?></h3></div><div class="card-icon">🔁</div></div>
    </section>

    <?php if ($podeGerenciar): ?>
    <section class="acoes">
        <a href="livro_form.php?id=<?= (int)$livro['id'] ?>" class="btn btn-principal">✏️ Editar Livro</a>
    </section>
    <?php endif; ?>

    <?php if ($podeGerenciar && $livro['status'] === 'em_andamento' && $etapaAtual): ?>
    <section class="painel">
        <div class="painel-topo">
            <div><h2>Movimentar livro</h2>
                <p>Etapa atual: <strong><?= htmlspecialchars($etapaAtual['nome']) ?></strong><?= (int)$etapaAtual['opcional'] === 1 ? ' (opcional)' : '' ?><?= (int)$etapaAtual['repetivel'] === 1 ? ' (repetível)' : '' ?>.</p>
            </div>
        </div>

        <?php if ($acoesDisponiveis): ?>
        <div class="acoes" style="flex-wrap:wrap;">
            <?php foreach ($acoesDisponiveis as $a):
                $confirmJs = null;
                if (!empty($a['excedente'])) {
                    $limite = $a['rodadas_incluidas'] !== null ? (int)$a['rodadas_incluidas'] : '?';
                    $msg = 'Esta será a rodada ' . (int)$a['rodada'] . ' de ' . $a['etapa_nome'] . '. O limite incluído é ' . $limite . '. A rodada será marcada como cobrável.';
                    $confirmJs = json_encode($msg, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
                }
            ?>
                <form method="post" style="display:inline-flex;align-items:center;gap:6px;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="acao" value="<?= $a['modo'] === 'concluir' ? 'concluir' : 'avancar' ?>">
                    <?php if (isset($a['etapa_destino_id'])): ?>
                        <input type="hidden" name="etapa_destino_id" value="<?= (int)$a['etapa_destino_id'] ?>">
                    <?php endif; ?>
                    <?php if (isset($a['etapa_pulada_id'])): ?>
                        <input type="hidden" name="etapa_pulada_id" value="<?= (int)$a['etapa_pulada_id'] ?>">
                    <?php endif; ?>
                    <?php if ($a['modo'] !== 'concluir'): ?>
                    <select name="responsavel_id" title="Responsável na nova etapa">
                        <?php foreach ($usuariosDisponiveis as $u): ?>
                            <option value="<?= (int)$u['id'] ?>" <?= (int)$u['id'] === (int)$livro['responsavel_atual_id'] ? 'selected' : '' ?>><?= htmlspecialchars($u['nome']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php endif; ?>
                    <button type="submit" class="btn btn-principal"<?= $confirmJs ? " onclick='return confirm(" . $confirmJs . ")'" : '' ?>><?= htmlspecialchars($a['label']) ?></button>
                </form>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if ($podeRepetir):
            $confirmRepetirJs = null;
            if ($excedenteRepetir) {
                $limiteRepetir = $etapaAtual['rodadas_incluidas'] !== null ? (int)$etapaAtual['rodadas_incluidas'] : '?';
                $msgRepetir = 'Esta será a rodada ' . (int)$rodadaRepetir . ' de ' . $etapaAtual['nome'] . '. O limite incluído é ' . $limiteRepetir . '. A rodada será marcada como cobrável.';
                $confirmRepetirJs = json_encode($msgRepetir, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
            }
        ?>
        <form method="post" style="display:inline-flex;align-items:center;gap:6px;margin-top:10px;">
            <?= csrf_field() ?>
            <input type="hidden" name="acao" value="repetir">
            <select name="responsavel_id" title="Responsável na nova rodada">
                <?php foreach ($usuariosDisponiveis as $u): ?>
                    <option value="<?= (int)$u['id'] ?>" <?= (int)$u['id'] === (int)$livro['responsavel_atual_id'] ? 'selected' : '' ?>><?= htmlspecialchars($u['nome']) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn"<?= $confirmRepetirJs ? " onclick='return confirm(" . $confirmRepetirJs . ")'" : '' ?>>🔁 Repetir etapa</button>
        </form>
        <?php endif; ?>

        <?php if ($etapasParaVoltar): ?>
        <form method="post" style="display:inline-flex;align-items:center;gap:10px;margin-top:10px;" onsubmit="return confirmarVoltar(this)">
            <?= csrf_field() ?>
            <input type="hidden" name="acao" value="voltar">
            <select name="etapa_destino_id" required>
                <option value="">Voltar para...</option>
                <?php foreach ($etapasParaVoltar as $ev): ?>
                    <option value="<?= (int)$ev['id'] ?>"><?= htmlspecialchars($ev['nome']) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="responsavel_id" title="Responsável na etapa anterior">
                <?php foreach ($usuariosDisponiveis as $u): ?>
                    <option value="<?= (int)$u['id'] ?>" <?= (int)$u['id'] === (int)$livro['responsavel_atual_id'] ? 'selected' : '' ?>><?= htmlspecialchars($u['nome']) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn-cancelar">⬅ Voltar etapa</button>
        </form>
        <script>
        const INFO_VOLTAR = <?= json_encode($infoVoltar, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        function confirmarVoltar(form) {
            const select = form.querySelector('select[name="etapa_destino_id"]');
            const info = INFO_VOLTAR[select.value];
            if (info && info.excedente) {
                const limite = info.rodadas_incluidas !== null ? info.rodadas_incluidas : '?';
                return confirm('Esta será a rodada ' + info.rodada + ' de ' + info.nome + '. O limite incluído é ' + limite + '. A rodada será marcada como cobrável.');
            }
            return true;
        }
        </script>
        <?php endif; ?>

        <form method="post" style="display:inline-flex;align-items:center;gap:6px;margin-top:10px;">
            <?= csrf_field() ?>
            <input type="hidden" name="acao" value="passar_mesa">
            <select name="novo_responsavel_id" required>
                <?php foreach ($usuariosDisponiveis as $u): ?>
                    <option value="<?= (int)$u['id'] ?>" <?= (int)$u['id'] === (int)$livro['responsavel_atual_id'] ? 'selected' : '' ?>><?= htmlspecialchars($u['nome']) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn">🗄 Passar para outra mesa</button>
        </form>

        <form method="post" style="display:inline-block;margin-top:16px;" onsubmit="return confirm('Marcar este livro como extraviado e iniciar um resgate? Isso bloqueia avançar, repetir, voltar e trocar de mesa até o resgate ser concluído.');">
            <?= csrf_field() ?>
            <input type="hidden" name="acao" value="iniciar_resgate">
            <button type="submit" class="btn-cancelar">🚨 Iniciar resgate</button>
        </form>

        <?php if (usuarioTemPermissao('livros.cancelar')): ?>
        <form method="post" style="display:inline-block;margin-top:16px;margin-left:10px;" onsubmit="return confirm('Cancelar este livro? Essa ação encerra o fluxo editorial dele — não é possível avançar, repetir, voltar ou trocar de mesa depois.');">
            <?= csrf_field() ?>
            <input type="hidden" name="acao" value="cancelar">
            <button type="submit" class="btn-cancelar">🛑 Cancelar livro</button>
        </form>
        <?php endif; ?>
    </section>
    <?php endif; ?>

    <?php if ($livro['status'] === 'extraviado'): ?>
    <section class="painel">
        <div class="flash erro" style="margin:0;">🚨 Este livro está <strong>extraviado</strong>. <a href="resgate.php?id=<?= (int)$livro['id'] ?>">Ver resgate em andamento →</a></div>
    </section>
    <?php endif; ?>

    <section class="painel">
        <div class="painel-topo">
            <div><h2>Dados do livro</h2></div>
        </div>
        <p><strong>ISBN:</strong> <?= htmlspecialchars($livro['isbn'] ?: 'Não informado') ?></p>
        <p style="margin-top:10px;"><strong>Pessoas vinculadas:</strong></p>
        <div class="tabela-container">
            <table>
                <thead><tr><th>Pessoa</th><th>Papel</th></tr></thead>
                <tbody>
                <?php if (!$contribuicoes): ?>
                    <tr><td colspan="2" class="vazio">Nenhuma pessoa vinculada.</td></tr>
                <?php else: foreach ($contribuicoes as $c): ?>
                    <tr><td><?= htmlspecialchars($c['pessoa_nome']) ?></td><td><?= htmlspecialchars($c['papel_nome']) ?></td></tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="secao-simples">
        <h2>Observações</h2>
        <p><?= $livro['observacoes'] !== null && $livro['observacoes'] !== '' ? nl2br(htmlspecialchars($livro['observacoes'])) : '<span class="vazio" style="display:inline;padding:0;">Nenhuma observação registrada.</span>' ?></p>
    </section>

    <section class="secao-simples" id="historico">
        <h2>Histórico de movimentações</h2>
        <p>Nenhuma movimentação é apagada ou sobrescrita — este é o histórico completo do livro.</p>
        <div class="tabela-container historico">
            <table>
                <thead><tr><th>Entrada</th><th>Saída</th><th>Tempo na etapa</th><th>Etapa</th><th>Tipo</th><th>Rodada</th><th>Responsável (mesa)</th><th>Registrado por</th></tr></thead>
                <tbody>
                <?php if (!$movimentacoes): ?>
                    <tr><td colspan="8" class="vazio">Nenhuma movimentação registrada.</td></tr>
                <?php else: foreach ($movimentacoes as $m):
                    $rodadaLinhaLabel = 'Rodada ' . (int)$m['rodada'];
                    if ($m['etapa_rodadas_incluidas'] !== null) {
                        $rodadaLinhaLabel .= ' de ' . (int)$m['etapa_rodadas_incluidas'];
                    }
                    $classeDestaque = CLASSE_DESTAQUE_TIPO_MOV[$m['tipo']] ?? null;
                ?>
                    <tr class="<?= htmlspecialchars($classeDestaque ? 'mov-destaque mov-' . $classeDestaque : '') ?>">
                        <td><?= date('d/m/Y H:i', strtotime($m['data_entrada'])) ?></td>
                        <td><?= $m['data_saida'] ? date('d/m/Y H:i', strtotime($m['data_saida'])) : '—' ?></td>
                        <td><?= htmlspecialchars(formatarDuracaoEtapa($m['data_entrada'], $m['data_saida'])) ?></td>
                        <td><?= htmlspecialchars($m['etapa_nome'] ?: '—') ?></td>
                        <td>
                            <?php if ($classeDestaque): ?>
                                <span class="status <?= htmlspecialchars($classeDestaque) ?>"><?= htmlspecialchars(NOMES_TIPO_MOV[$m['tipo']] ?? $m['tipo']) ?></span>
                            <?php else: ?>
                                <?= htmlspecialchars(NOMES_TIPO_MOV[$m['tipo']] ?? $m['tipo']) ?>
                            <?php endif; ?>
                        </td>
                        <td><?= htmlspecialchars($rodadaLinhaLabel) ?><?php if ((int)$m['excedente'] === 1): ?> <span class="status atencao">Cobrável</span><?php endif; ?></td>
                        <td><?= htmlspecialchars($m['responsavel_nome'] ?: '—') ?></td>
                        <td><?= htmlspecialchars($m['registrado_por_nome'] ?: '—') ?></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <footer><p>WareSys © 2026 — Sistema acadêmico de Controle Editorial</p></footer>
</main>
<script src="envio_unico.js"></script>
</body>
</html>
