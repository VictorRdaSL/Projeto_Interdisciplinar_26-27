<?php
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/permissoes.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/validacao.php';
require_once __DIR__ . '/includes/formatacao.php';
require_once __DIR__ . '/includes/isbn.php';
require_once __DIR__ . '/includes/campos.php';
require_once __DIR__ . '/includes/mesa.php';
require_once __DIR__ . '/includes/alertas.php';
require_once __DIR__ . '/includes/movimentacoes.php';

$livroId = idDaUrl($_GET, 'id');
if ($livroId <= 0) {
    $_SESSION['flash'] = ['type' => 'erro', 'message' => 'Livro não encontrado.'];
    header('Location: livros.php');
    exit;
}

/**
 * Etapa de destino + rodada que a ação abriria + se essa rodada é excedente (cobrada). É a mesma conta que
 * avancarEtapa/repetirEtapa/voltarEtapa fazem; aqui serve só para decidir se a ação exige confirmação (a tela
 * e o POST usam esta função, então sempre concordam). Devolve null se a etapa não existe.
 */
function rodadaDoDestino(mysqli $conn, int $livroId, int $etapaId): ?array
{
    $etapa = buscarEtapa($conn, $etapaId);
    if (!$etapa) {
        return null;
    }
    $rodada = calcularRodada($conn, $livroId, $etapaId);
    return ['etapa' => $etapa, 'rodada' => $rodada, 'excedente' => calcularExcedente($etapa, $rodada)];
}

$errosMov = [];   // [formulário][campo] => mensagem; formulário: 'avancar-<id da etapa>', 'repetir', 'voltar', 'passar_mesa'
$postMov = null;   // o que foi escolhido no envio com erro, para os menus voltarem como estavam

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!usuarioTemPermissao('livros.gerenciar')) {
        $_SESSION['flash'] = ['type' => 'erro', 'message' => 'Você não tem permissão para movimentar livros.'];
        header('Location: livro_detalhes.php?id=' . $livroId);
        exit;
    }
    if (!csrfValidar($_POST['csrf_token'] ?? null)) {
        $_SESSION['flash'] = ['type' => 'erro', 'message' => 'Sessão expirada. Tente novamente.'];
        header('Location: livro_detalhes.php?id=' . $livroId);
        exit;
    }

    $acaoPost = $_POST['acao'] ?? '';
    $usuarioId = (int)$_SESSION['usuario_id'];
    // Preenchido (com 1) só pelo botão de confirmar do modal (script no fim desta página).
    $confirmado = entradaTexto($_POST, 'confirmacao') === '1';

    // Campos obrigatórios vazios: a página volta com a mensagem junto do campo (nada é gravado).
    $etapaDestinoPost = (int)($_POST['etapa_destino_id'] ?? 0);
    $chaveFormMov = $acaoPost === 'avancar' ? 'avancar-' . $etapaDestinoPost : $acaoPost;
    if ($acaoPost === 'voltar' && $etapaDestinoPost <= 0) {
        $errosMov['voltar']['etapa'] = 'Escolha a etapa para a qual o livro volta.';
    }
    if ($acaoPost === 'passar_mesa' && (int)($_POST['novo_responsavel_id'] ?? 0) <= 0) {
        $errosMov['passar_mesa']['mesa'] = 'Escolha o responsável que recebe o livro.';
    }
    if (in_array($acaoPost, ['avancar', 'repetir', 'voltar'], true) && (!isset($_POST['responsavel_id']) || $_POST['responsavel_id'] === '')) {
        // Sem escolha o livro fica na mesa de hoje; isso só não vale quando a mesa de hoje não pode ser responsável (Administrador).
        $stmtMesaAtual = $conn->prepare('SELECT responsavel_atual_id FROM livros WHERE id = ?');
        $stmtMesaAtual->bind_param('i', $livroId);
        $stmtMesaAtual->execute();
        $linhaMesaAtual = $stmtMesaAtual->get_result()->fetch_assoc();
        if ($linhaMesaAtual && erroResponsavelMesa($conn, (int)$linhaMesaAtual['responsavel_atual_id']) !== null) {
            $errosMov[$chaveFormMov]['mesa'] = 'Escolha o responsável.';
        }
    }
    // Recado opcional para quem recebe o livro (só nas ações que abrem uma movimentação nova).
    $observacaoPost = entradaTexto($_POST, 'observacao');
    if (in_array($acaoPost, ['avancar', 'repetir', 'voltar', 'passar_mesa'], true) && ($erroObs = erroObservacaoMovimentacao($observacaoPost)) !== null) {
        $errosMov[$chaveFormMov]['obs'] = $erroObs;
    }
    if ($errosMov) {
        $postMov = ['form' => $chaveFormMov, 'obs' => $observacaoPost, 'mesa' => ($acaoPost === 'passar_mesa' ? ($_POST['novo_responsavel_id'] ?? '') : ($_POST['responsavel_id'] ?? '')) !== '' ? (int)($acaoPost === 'passar_mesa' ? $_POST['novo_responsavel_id'] : $_POST['responsavel_id']) : null];
    }

    if (!$errosMov) {
        try {
            // Ações que pedem confirmação: resgate, cancelamento e qualquer movimentação que abra rodada excedente.
            // Sem o campo de confirmação nada é gravado: volta para a página com o aviso.
            $exigeConfirmacao = false;
            if ($acaoPost === 'iniciar_resgate') {
                $exigeConfirmacao = true;
            } elseif ($acaoPost === 'cancelar') {
                $exigeConfirmacao = usuarioTemPermissao('livros.cancelar'); // sem permissão, a mensagem de permissão vem primeiro
            } elseif ($acaoPost === 'avancar' || $acaoPost === 'voltar') {
                $destino = rodadaDoDestino($conn, $livroId, (int)($_POST['etapa_destino_id'] ?? 0));
                $exigeConfirmacao = $destino !== null && $destino['excedente'];
            } elseif ($acaoPost === 'repetir') {
                $stmtEtapaAtual = $conn->prepare('SELECT etapa_atual_id FROM livros WHERE id = ?');
                $stmtEtapaAtual->bind_param('i', $livroId);
                $stmtEtapaAtual->execute();
                $linhaEtapaAtual = $stmtEtapaAtual->get_result()->fetch_assoc();
                $destino = $linhaEtapaAtual ? rodadaDoDestino($conn, $livroId, (int)$linhaEtapaAtual['etapa_atual_id']) : null;
                $exigeConfirmacao = $destino !== null && $destino['excedente'];
            }
            if ($exigeConfirmacao && !$confirmado) {
                $_SESSION['flash'] = ['type' => 'erro', 'message' => 'Esta ação precisa de confirmação. Use o botão de confirmar na janela que se abre; nada foi gravado.'];
                header('Location: livro_detalhes.php?id=' . $livroId);
                exit;
            }

            $responsavelIdPost = isset($_POST['responsavel_id']) && $_POST['responsavel_id'] !== '' ? (int)$_POST['responsavel_id'] : null;

            if ($acaoPost === 'avancar') {
                $etapaDestinoId = (int)($_POST['etapa_destino_id'] ?? 0);
                $etapaPuladaId = isset($_POST['etapa_pulada_id']) && $_POST['etapa_pulada_id'] !== '' ? (int)$_POST['etapa_pulada_id'] : null;
                $resultado = avancarEtapa($conn, $livroId, $etapaDestinoId, $usuarioId, $etapaPuladaId, $responsavelIdPost, $observacaoPost);
                $mensagem = 'Livro avançado para ' . $resultado['etapa_nome'] . '.';
            } elseif ($acaoPost === 'repetir') {
                $resultado = repetirEtapa($conn, $livroId, $usuarioId, $responsavelIdPost, $observacaoPost);
                $mensagem = 'Nova rodada aberta em ' . $resultado['etapa_nome'] . '.';
            } elseif ($acaoPost === 'voltar') {
                $etapaDestinoId = (int)($_POST['etapa_destino_id'] ?? 0);
                $resultado = voltarEtapa($conn, $livroId, $etapaDestinoId, $usuarioId, $responsavelIdPost, $observacaoPost);
                $mensagem = 'Livro devolvido para ' . $resultado['etapa_nome'] . '.';
            } elseif ($acaoPost === 'concluir') {
                $resultado = concluirLivro($conn, $livroId, $usuarioId);
                $mensagem = 'Livro concluído com sucesso.';
            } elseif ($acaoPost === 'passar_mesa') {
                $novoResponsavelId = (int)($_POST['novo_responsavel_id'] ?? 0);
                $resultado = passarParaOutraMesa($conn, $livroId, $novoResponsavelId, $usuarioId, $observacaoPost);
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

// O flash da sessão é lido, exibido e apagado pelo includes/rodape.php (toast).
$podeGerenciar = usuarioTemPermissao('livros.gerenciar');
$podeCancelar = usuarioTemPermissao('livros.cancelar');
// Cada um mexe só nos livros da própria mesa; Gerente e Admin mexem em qualquer um (o backend confere de novo em cada ação).
$motivoSemMesa = $podeGerenciar ? erroMovimentarLivro($conn, $livro, (int)$_SESSION['usuario_id']) : null;
$podeMovimentar = $podeGerenciar && $motivoSemMesa === null;

// Só Gerente e Usuário comum podem ser mesa (o Administrador não faz parte do fluxo): ver includes/mesa.php.
$usuariosDisponiveis = usuariosQuePodemSerMesa($conn);
// Livro de antes da regra pode estar com um Administrador como mesa: nesse caso o formulário obriga a escolher.
$mesaAtualValida = in_array((int)$livro['responsavel_atual_id'], array_map('intval', array_column($usuariosDisponiveis, 'id')), true);
// Erro de campo dos formulários de movimentação e a mesa marcada em cada um (o que foi escolhido num envio com erro, senão a mesa de hoje).
$erroMov = fn(string $form, string $campo): ?string => $errosMov[$form][$campo] ?? null;
// Texto que o usuário digitou na observação do formulário $form, quando o envio dele voltou com erro.
$obsDigitada = fn(string $form): string => ($postMov !== null && $postMov['form'] === $form) ? (string)$postMov['obs'] : '';
// Campo "Observação (opcional)" dos formulários que abrem uma movimentação nova. $idCampo: id do textarea; $form: chave do formulário em $errosMov.
$htmlCampoObservacao = function (string $idCampo, string $form) use ($erroMov, $obsDigitada): string {
    $erro = $erroMov($form, 'obs');
    return '<div class="ws-campo' . classeCampoErro($erro) . '">'
        . '<label class="ws-campo__rotulo" for="' . escaparHtml($idCampo) . '">Observação <span class="ws-campo__opcional">(opcional)</span></label>'
        . '<textarea class="ws-textarea" id="' . escaparHtml($idCampo) . '" name="observacao" rows="2" maxlength="' . LIMITE_OBSERVACAO_MOVIMENTACAO . '" placeholder="Recado para quem recebe o livro."' . atributosCampo($idCampo, $erro, $idCampo . '-ajuda') . '>' . escaparHtml($obsDigitada($form)) . '</textarea>'
        . htmlErroCampo($idCampo, $erro)
        . '<p class="ws-campo__ajuda" id="' . escaparHtml($idCampo) . '-ajuda">Fica no histórico do livro. Até ' . number_format(LIMITE_OBSERVACAO_MOVIMENTACAO, 0, ',', '.') . ' caracteres.</p>'
        . '</div>';
};
$mesaMarcada = fn(string $form): int => ($postMov !== null && $postMov['form'] === $form && $postMov['mesa'] !== null) ? (int)$postMov['mesa'] : (int)$livro['responsavel_atual_id'];

const NOMES_STATUS_LIVRO_DET = [
    'em_andamento' => 'Em andamento',
    'concluido'    => 'Concluído',
    'extraviado'   => 'Extraviado',
    'cancelado'    => 'Cancelado',
];
const NOMES_TIPO_MOV = [
    'avanco'           => 'Avanço',
    'repeticao'        => 'Repetição',
    'pulo_opcional'    => 'Pulo de opcional',
    'retrocesso'       => 'Retrocesso',
    'troca_responsavel'=> 'Troca de mesa',
    'resgate'          => 'Resgate',
];
// Ícone Lucide de cada tipo de movimentação (o tipo vem no ícone e no texto, sem cor de status).
const ICONE_TIPO_MOV = [
    'avanco'           => 'arrow-right',
    'repeticao'        => 'rotate-ccw',
    'pulo_opcional'    => 'skip-forward',
    'retrocesso'       => 'undo-2',
    'troca_responsavel'=> 'arrow-left-right',
    'resgate'          => 'search',
];
// Sufixo da classe ws-evento--* de cada tipo.
const CLASSE_TIPO_MOV = [
    'avanco'           => 'avanco',
    'repeticao'        => 'repeticao',
    'pulo_opcional'    => 'pulo',
    'retrocesso'       => 'retrocesso',
    'troca_responsavel'=> 'troca',
    'resgate'          => 'resgate',
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

if ($podeMovimentar && $livro['status'] === 'em_andamento' && $etapaAtual) {
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
                // O destino do pulo também pode abrir rodada excedente (ex.: etapa com limite já usada antes);
                // o POST usa a mesma conta, então a tela precisa oferecer o modal nesse caso.
                $rodadaPulo = calcularRodada($conn, $livroId, (int)$depoisDaPulada['id']);
                $acoesDisponiveis[] = [
                    'modo' => 'avancar', 'etapa_destino_id' => (int)$depoisDaPulada['id'],
                    'etapa_pulada_id' => (int)$proxima['id'],
                    'label' => 'Pular ' . $proxima['nome'],
                    'etapa_nome' => $depoisDaPulada['nome'], 'rodada' => $rodadaPulo,
                    'excedente' => calcularExcedente($depoisDaPulada, $rodadaPulo),
                    'rodadas_incluidas' => $depoisDaPulada['rodadas_incluidas'],
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
$rodadaAtual = null;
$rodadaAtualExcedente = false;
$diasComMesaAtual = null;
$movAtual = null;
if ($movimentacoes && $etapaAtual) {
    $movAtual = $movimentacoes[0];
    $rodadaAtual = (int)$movAtual['rodada'];
    $rodadaAtualExcedente = (int)$movAtual['excedente'] === 1;

    // Calculado em PHP (não com DATEDIFF/NOW() do MySQL) para não reabrir o bug
    // conhecido de "hoje" divergente entre PHP e MySQL quando os fusos não combinam.
    $diasComMesaAtual = (new DateTime())->diff(new DateTime($movAtual['data_entrada']))->days;
}
$limiteEtapaAtual = ($etapaAtual && $etapaAtual['rodadas_incluidas'] !== null) ? (int)$etapaAtual['rodadas_incluidas'] : null;
$livroAberto = in_array($livro['status'], ['em_andamento', 'extraviado'], true);

// Concluído e cancelado não estão em etapa nenhuma (Passo 20.11): "Etapa atual" mostra o status (com a data), mesa e rodada
// ficam em "—" e o Fluxo não marca etapa atual. No banco etapa_atual_id continua sendo a última etapa: é o histórico, e só
// a exibição muda. A data do fim é a saída da última movimentação (livros.atualizado_em muda se alguém editar o livro).
$statusFinal = in_array($livro['status'], ['concluido', 'cancelado'], true);
$dataFimLivro = $statusFinal ? ($movimentacoes[0]['data_saida'] ?? $livro['atualizado_em']) : null;
if ($statusFinal) {
    $rodadaAtual = null;
    $rodadaAtualExcedente = false;
    $diasComMesaAtual = null;
}

// Fluxo: as etapas ATIVAS cadastradas (nunca um número fixo) + a etapa atual do livro, se ela tiver sido desativada.
$stmtFluxo = $conn->prepare('SELECT * FROM etapas WHERE ativa = 1 OR id = ? ORDER BY ordem, id');
$etapaAtualId = (int)$livro['etapa_atual_id'];
$stmtFluxo->bind_param('i', $etapaAtualId);
$stmtFluxo->execute();
$etapasFluxo = $stmtFluxo->get_result()->fetch_all(MYSQLI_ASSOC);
$totalEtapas = count($etapasFluxo);
$posicaoEtapaAtual = 0;
foreach ($etapasFluxo as $i => $ef) {
    if ((int)$ef['id'] === $etapaAtualId) $posicaoEtapaAtual = $i + 1;
}
$percentualProgresso = ($posicaoEtapaAtual > 0 && $totalEtapas > 0) ? (int)round($posicaoEtapaAtual / $totalEtapas * 100) : 0;
// Barra de progresso: em andamento "Etapa N de M"; concluído = fluxo completo (100%); cancelado = até onde chegou, sem fechar a barra.
$progressoAgora = $posicaoEtapaAtual;
$progressoRotulo = 'Etapa ' . $posicaoEtapaAtual . ' de ' . $totalEtapas;
$progressoTexto = 'Etapa ' . $posicaoEtapaAtual . ' de ' . $totalEtapas . ', ' . ($livro['etapa_nome'] ?? '');
$mostrarProgresso = $posicaoEtapaAtual > 0 && $totalEtapas > 0;
if ($livro['status'] === 'concluido' && $totalEtapas > 0) {
    $percentualProgresso = 100;
    $progressoAgora = $totalEtapas;
    $progressoRotulo = 'Concluído';
    $progressoTexto = 'Concluído: todas as etapas do fluxo';
    $mostrarProgresso = true;
} elseif ($livro['status'] === 'cancelado') {
    $progressoRotulo = 'Cancelado';
    $progressoTexto = 'Cancelado na etapa ' . $posicaoEtapaAtual . ' de ' . $totalEtapas . ', ' . ($livro['etapa_nome'] ?? '');
}

// Etapas que o livro já concluiu (saiu delas) e as que foram puladas (opcionais), a partir do histórico.
$etapasConcluidas = [];
$etapasPuladas = [];
foreach ($movimentacoes as $m) {
    $eid = (int)$m['etapa_id'];
    if ($m['tipo'] === 'pulo_opcional') {
        if (!isset($etapasPuladas[$eid])) $etapasPuladas[$eid] = $m['data_entrada'];
    } elseif ($m['data_saida'] !== null) {
        $etapasConcluidas[$eid] = true;
    }
}

// Rodadas nas etapas com limite (ex: "Diagramação: 2 de 3 · Revisão: 1 de 2"), pelo MAX real de cada etapa.
$rodadasPorEtapa = [];
$stmtResumo = $conn->prepare('
    SELECT e.id, e.nome, e.rodadas_incluidas, e.ativa, COALESCE(MAX(m.rodada), 0) AS rodada_max
    FROM etapas e
    LEFT JOIN movimentacoes m ON m.etapa_id = e.id AND m.livro_id = ? AND m.tipo IN (\'avanco\', \'repeticao\', \'retrocesso\')
    WHERE e.rodadas_incluidas IS NOT NULL
    GROUP BY e.id, e.nome, e.rodadas_incluidas, e.ativa, e.ordem
    ORDER BY e.ordem
');
$stmtResumo->bind_param('i', $livroId);
$stmtResumo->execute();
$rResumo = $stmtResumo->get_result();
while ($row = $rResumo->fetch_assoc()) {
    // Etapa desativada sem nenhuma rodada deste livro não tem o que mostrar.
    if ((int)$row['ativa'] === 1 || (int)$row['rodada_max'] > 0) {
        $rodadasPorEtapa[(int)$row['id']] = [
            'nome' => $row['nome'], 'limite' => (int)$row['rodadas_incluidas'], 'max' => (int)$row['rodada_max'],
        ];
    }
}

$stmtCobraveis = $conn->prepare('SELECT COUNT(*) total FROM movimentacoes WHERE livro_id = ? AND excedente = 1');
$stmtCobraveis->bind_param('i', $livroId);
$stmtCobraveis->execute();
$totalCobraveis = (int)$stmtCobraveis->get_result()->fetch_assoc()['total'];

// "Parado": mesma função do painel e da lista de livros (movimentação aberta além do limite de dias).
$diasParado = null;
foreach (buscarLivrosParados($conn) as $lp) {
    if ((int)$lp['id'] === $livroId) $diasParado = (int)$lp['dias_parado'];
}

$codigo = codigoLivro($livroId);
$pessoasTopo = implode(' · ', array_map(fn($c) => $c['pessoa_nome'] . ' (' . $c['papel_nome'] . ')', $contribuicoes));
$formAcao = 'livro_detalhes.php?id=' . $livroId;

// Rodada atual, em texto, para os avisos e para o "passar para outra mesa".
$rodadaAtualTexto = $rodadaAtual !== null ? 'rodada ' . $rodadaAtual . ($limiteEtapaAtual !== null ? ' de ' . $limiteEtapaAtual : '') : '';

$tituloPagina = $livro['titulo'];
$paginaAtual = 'livros';
require __DIR__ . '/includes/cabecalho.php';
?>
      <header class="ws-topo">
        <div class="ws-topo__titulos">
          <a class="ws-topo__voltar ws-link-limpo" href="livros.php"><i data-lucide="arrow-left" class="ws-icone--peq"></i>Livros</a>
          <p class="ws-eyebrow ws-eyebrow--destaque">Livro <?= escaparHtml($codigo) ?> · <?= escaparHtml($statusFinal ? textoEtapaAtual($livro['status'], $livro['etapa_nome']) : ($livro['etapa_nome'] ?: 'Sem etapa')) ?></p>
          <h1 class="ws-topo__titulo"><?= escaparHtml($livro['titulo']) ?></h1>
          <p class="ws-topo__subtitulo"><?= escaparHtml($pessoasTopo !== '' ? $pessoasTopo : 'Sem pessoas vinculadas') ?></p>
        </div>
<?php if ($podeMovimentar && $livro['status'] !== 'concluido'): /* livro concluído não é mais alterado (Passo 20.12); só a mesa, Gerente ou Admin editam */ ?>
        <div class="ws-topo__acoes">
          <a class="ws-btn ws-btn--secundario" href="livro_form.php?id=<?= $livroId ?>"><i data-lucide="pencil"></i>Editar livro</a>
        </div>
<?php endif; ?>
      </header>

      <div class="ws-conteudo">
<?php if ($livro['status'] === 'extraviado'): ?>
        <div class="ws-alerta ws-alerta--cobrado" role="status">
          <i class="ws-alerta__icone" data-lucide="circle-alert"></i>
          <div class="ws-alerta__corpo">
            <p class="ws-alerta__titulo">Livro <?= escaparHtml($codigo) ?> está extraviado.</p>
            <p>Nenhuma movimentação é aceita até o resgate ser concluído. <a href="resgate.php?id=<?= $livroId ?>">Ver resgate em andamento</a></p>
          </div>
        </div>
<?php elseif ($livro['status'] === 'em_andamento' && $limiteEtapaAtual !== null && $rodadaAtual !== null): ?>
<?php if ($rodadaAtualExcedente): ?>
        <div class="ws-alerta ws-alerta--cobrado" role="status">
          <i class="ws-alerta__icone" data-lucide="circle-alert"></i>
          <div class="ws-alerta__corpo">
            <p class="ws-alerta__titulo">Livro <?= escaparHtml($codigo) ?> na rodada <?= $rodadaAtual ?> de <?= $limiteEtapaAtual ?> de <?= escaparHtml($etapaAtual['nome']) ?>.</p>
            <p>A etapa inclui <?= $limiteEtapaAtual ?> rodadas; esta rodada é cobrada.</p>
          </div>
        </div>
<?php elseif ($rodadaAtual === $limiteEtapaAtual): ?>
        <div class="ws-alerta ws-alerta--limite" role="status">
          <i class="ws-alerta__icone" data-lucide="triangle-alert"></i>
          <div class="ws-alerta__corpo">
            <p class="ws-alerta__titulo">Livro <?= escaparHtml($codigo) ?> na rodada <?= $rodadaAtual ?> de <?= $limiteEtapaAtual ?> de <?= escaparHtml($etapaAtual['nome']) ?>.</p>
            <p>Esta é a última rodada incluída. A próxima rodada de <?= escaparHtml($etapaAtual['nome']) ?> será cobrada.</p>
          </div>
        </div>
<?php endif; ?>
<?php endif; ?>
<?php if ($livro['status'] === 'concluido'): ?>
        <div class="ws-alerta" role="status">
          <i class="ws-alerta__icone" data-lucide="lock"></i>
          <div class="ws-alerta__corpo">
            <p class="ws-alerta__titulo">Livro concluído</p>
            <p>O livro já foi depositado na Biblioteca Nacional. O registro não pode mais ser alterado.</p>
          </div>
        </div>
<?php endif; ?>
<?php if ($livro['status'] === 'em_andamento' && $movAtual && $movAtual['data_saida'] === null && $movAtual['observacao'] !== null && $movAtual['observacao'] !== ''): ?>
        <!-- Recado de quem passou o livro para esta etapa/mesa (observação da movimentação aberta); o histórico completo fica na linha do tempo -->
        <div class="ws-alerta" role="status">
          <i class="ws-alerta__icone" data-lucide="message-square"></i>
          <div class="ws-alerta__corpo">
            <p class="ws-alerta__titulo">Observação de <?= escaparHtml($movAtual['registrado_por_nome'] ?: '—') ?></p>
            <p><?= nl2br(escaparHtml($movAtual['observacao'])) ?></p>
            <p class="ws-texto-suave">Registrada em <?= escaparHtml(formatarDataHora($movAtual['data_entrada'])) ?>, ao passar o livro para <?= escaparHtml($etapaAtual['nome']) ?>.</p>
          </div>
        </div>
<?php endif; ?>

        <div class="ws-grade">
          <!-- Coluna principal -->
          <div class="ws-col-8 ws-pilha">

            <section class="ws-secao" aria-labelledby="situacao-titulo">
              <h2 id="situacao-titulo">Situação atual</h2>
              <div class="ws-kpis">
                <div class="ws-kpi">
                  <span class="ws-kpi__rotulo">Etapa atual</span>
                  <span class="ws-kpi__valor ws-kpi__valor--texto"><?= $statusFinal ? htmlEtapaAtual($livro['status'], $livro['etapa_nome']) : escaparHtml($livro['etapa_nome'] ?: '—') ?></span>
<?php if ($statusFinal): ?>
                  <span class="ws-kpi__nota"><?= $livro['status'] === 'concluido' ? 'Concluído' : 'Cancelado' ?> em <?= escaparHtml(formatarDataHora($dataFimLivro)) ?></span>
<?php elseif ($posicaoEtapaAtual > 0): ?>
                  <span class="ws-kpi__nota">Etapa <?= $posicaoEtapaAtual ?> de <?= $totalEtapas ?></span>
<?php endif; ?>
                </div>
                <div class="ws-kpi">
                  <span class="ws-kpi__rotulo">Mesa</span>
                  <span class="ws-kpi__valor ws-kpi__valor--texto"><?= $statusFinal ? '—' : escaparHtml($livro['responsavel_nome'] ?: '—') ?></span>
<?php if ($diasComMesaAtual !== null && $livroAberto): ?>
                  <span class="ws-kpi__nota">Com ela há <?= $diasComMesaAtual === 0 ? 'menos de 1 dia' : escaparHtml(qtdTexto($diasComMesaAtual, 'dia', 'dias')) ?></span>
<?php endif; ?>
                </div>
                <div class="ws-kpi">
                  <span class="ws-kpi__rotulo">Rodada</span>
                  <span class="ws-kpi__valor ws-kpi__valor--texto"><?php if ($rodadaAtual !== null): ?><span class="ws-codigo" style="font-size:inherit"><?= escaparHtml(rotuloRodada($rodadaAtual, $limiteEtapaAtual)) ?></span><?php else: ?>—<?php endif; ?></span>
<?php if ($rodadaAtual !== null && $limiteEtapaAtual !== null && $livroAberto): ?>
<?php if ($rodadaAtualExcedente): ?>
                  <span class="ws-status ws-status--cobrado">Cobrado</span>
<?php elseif ($rodadaAtual === $limiteEtapaAtual): ?>
                  <span class="ws-status ws-status--limite">No limite</span>
<?php else: ?>
                  <span class="ws-status ws-status--ok">No prazo</span>
<?php endif; ?>
<?php elseif ($rodadaAtual !== null && $limiteEtapaAtual === null): ?>
                  <span class="ws-kpi__nota">Etapa sem limite de rodadas</span>
<?php endif; ?>
                </div>
                <div class="ws-kpi">
                  <span class="ws-kpi__rotulo">Livro</span>
                  <span class="ws-kpi__valor ws-kpi__valor--texto">
<?php if ($livro['status'] === 'concluido' || $livro['status'] === 'cancelado'): ?>
                    <span class="ws-tag ws-tag--neutra"><?= escaparHtml(NOMES_STATUS_LIVRO_DET[$livro['status']]) ?></span>
<?php elseif ($livro['status'] === 'extraviado'): ?>
                    <span class="ws-status ws-status--extraviado">Extraviado</span>
<?php elseif ($diasParado !== null): ?>
                    <span class="ws-status ws-status--parado">Parado <span class="ws-status__detalhe">· <?= escaparHtml(qtdTexto($diasParado, 'dia', 'dias')) ?></span></span>
<?php else: ?>
                    <span class="ws-status ws-status--ok">No prazo</span>
<?php endif; ?>
                  </span>
                  <span class="ws-kpi__nota"><?= escaparHtml(qtdTexto($totalCobraveis, 'rodada cobrável', 'rodadas cobráveis')) ?></span>
                </div>
              </div>
            </section>

<?php if ($rodadasPorEtapa): ?>
            <section class="ws-secao" aria-labelledby="rodadas-titulo">
              <div class="ws-secao__cabecalho">
                <div>
                  <h2 id="rodadas-titulo">Rodadas por etapa</h2>
                  <p><?= escaparHtml(implode(' e ', array_map(fn($r) => $r['nome'] . ' inclui ' . $r['limite'], $rodadasPorEtapa))) ?>. A última incluída acende o alerta; o que passa disso é cobrado.</p>
                </div>
              </div>
              <div class="ws-card ws-card--superficie">
<?php $primeiraRodada = true; foreach ($rodadasPorEtapa as $re):
    if ($re['max'] === 0) $legenda = 'Nenhuma rodada ainda.';
    elseif ($re['max'] < $re['limite']) $legenda = 'Rodada ' . $re['max'] . ' de ' . $re['limite'] . '. Dentro do limite incluído.';
    elseif ($re['max'] === $re['limite']) $legenda = 'Rodada ' . $re['max'] . ' de ' . $re['limite'] . '. A próxima será cobrada.';
    else $legenda = 'Rodada ' . $re['max'] . ' de ' . $re['limite'] . '. Acima do limite, cobrada.';
?>
<?php if (!$primeiraRodada): ?>
                <hr style="margin:4px 0">
<?php endif; $primeiraRodada = false; ?>
                <?= htmlContadorRodadas($re['nome'], $re['limite'], $re['max'], $legenda) . "\n" ?>
<?php endforeach; ?>
              </div>
            </section>
<?php endif; ?>

<?php if ($podeGerenciar && !$podeMovimentar && $livro['status'] === 'em_andamento' && $etapaAtual): ?>
            <section class="ws-secao" aria-labelledby="mover-titulo">
              <h2 id="mover-titulo">Movimentar livro</h2>
              <div class="ws-alerta ws-alerta--limite" role="status">
                <i class="ws-alerta__icone" data-lucide="lock"></i>
                <div class="ws-alerta__corpo">
                  <p class="ws-alerta__titulo">Você não é a mesa deste livro.</p>
                  <p><?= escaparHtml($motivoSemMesa) ?></p>
                </div>
              </div>
            </section>
<?php endif; ?>

<?php if ($podeMovimentar && $livro['status'] === 'em_andamento' && $etapaAtual): ?>
            <section class="ws-secao" aria-labelledby="mover-titulo">
              <h2 id="mover-titulo">Movimentar livro</h2>
<?php if (!$mesaAtualValida): ?>
              <div class="ws-alerta ws-alerta--limite" role="status">
                <i class="ws-alerta__icone" data-lucide="triangle-alert"></i>
                <div class="ws-alerta__corpo">
                  <p class="ws-alerta__titulo">Este livro está com um Administrador como responsável (mesa).</p>
                  <p>Isso não é permitido. Escolha um Gerente ou um Usuário no campo de responsável para continuar.</p>
                </div>
              </div>
<?php endif; ?>
              <p class="ws-texto-suave">Etapa atual: <?= escaparHtml($etapaAtual['nome']) ?><?= (int)$etapaAtual['opcional'] === 1 ? ' (opcional)' : '' ?><?= (int)$etapaAtual['repetivel'] === 1 ? ' (repetível)' : '' ?>.</p>
              <div class="ws-card ws-card--superficie ws-pilha">
<?php $blocoMover = 0; ?>
<?php foreach ($acoesDisponiveis as $i => $a):
    $modalAcao = !empty($a['excedente']) ? 'modal-avancar-' . $i : null;
    if ($blocoMover++ > 0) echo '                <hr style="margin:0">' . "\n";
?>
<?php if ($a['modo'] === 'concluir'): ?>
                <form method="post" class="ws-pilha ws-pilha--peq">
                  <?= csrfCampo() ?>
                  <input type="hidden" name="acao" value="concluir">
                  <div><button class="ws-btn ws-btn--primario" type="submit"><i data-lucide="check"></i><?= escaparHtml($a['label']) ?></button></div>
                </form>
<?php else: ?>
                <form method="post" class="ws-pilha ws-pilha--peq" novalidate<?= $modalAcao ? ' data-ws-confirmar="' . escaparHtml($modalAcao) . '"' : '' ?>>
                  <?= csrfCampo() ?>
                  <input type="hidden" name="acao" value="avancar">
                  <input type="hidden" name="etapa_destino_id" value="<?= (int)$a['etapa_destino_id'] ?>">
<?php if (isset($a['etapa_pulada_id'])): ?>
                  <input type="hidden" name="etapa_pulada_id" value="<?= (int)$a['etapa_pulada_id'] ?>">
<?php endif; ?>
<?php if ($modalAcao): ?>
                  <input type="hidden" name="confirmacao" value="">
<?php endif; ?>
                  <div class="ws-form__grade">
<?php $chaveAv = 'avancar-' . (int)$a['etapa_destino_id']; $erroMesaAv = $erroMov($chaveAv, 'mesa'); ?>
                    <div class="ws-campo<?= classeCampoErro($erroMesaAv) ?>">
                      <label class="ws-campo__rotulo" for="mesa-avancar-<?= $i ?>">Mesa na nova etapa</label>
                      <select class="ws-select" id="mesa-avancar-<?= $i ?>" name="responsavel_id"<?= $mesaAtualValida ? '' : ' required' ?><?= atributosCampo('mesa-avancar-' . $i, $erroMesaAv) ?>>
<?php if (!$mesaAtualValida): ?>
                        <option value="">Escolha o responsável</option>
<?php endif; ?>
<?php foreach ($usuariosDisponiveis as $u): ?>
                        <option value="<?= (int)$u['id'] ?>"<?= (int)$u['id'] === $mesaMarcada($chaveAv) ? ' selected' : '' ?>><?= escaparHtml($u['nome']) ?></option>
<?php endforeach; ?>
                      </select>
                      <?= htmlErroCampo('mesa-avancar-' . $i, $erroMesaAv) ?>
                    </div>
                  </div>
                  <?= $htmlCampoObservacao('obs-avancar-' . $i, $chaveAv) ?>

                  <div><button class="ws-btn <?= $i === 0 ? 'ws-btn--primario' : 'ws-btn--secundario' ?>" type="submit"><?= escaparHtml($a['label']) ?> <span class="ws-btn__seta" aria-hidden="true">→</span></button></div>
                </form>
<?php endif; ?>
<?php endforeach; ?>

<?php if ($podeRepetir):
    if ($blocoMover++ > 0) echo '                <hr style="margin:0">' . "\n";
?>
                <form method="post" class="ws-pilha ws-pilha--peq" novalidate<?= $excedenteRepetir ? ' data-ws-confirmar="modal-repetir"' : '' ?>>
                  <?= csrfCampo() ?>
                  <input type="hidden" name="acao" value="repetir">
<?php if ($excedenteRepetir): ?>
                  <input type="hidden" name="confirmacao" value="">
<?php endif; ?>
                  <div class="ws-form__grade">
                    <div class="ws-campo<?= classeCampoErro($erroMov('repetir', 'mesa')) ?>">
                      <label class="ws-campo__rotulo" for="mesa-repetir">Mesa na nova rodada</label>
                      <select class="ws-select" id="mesa-repetir" name="responsavel_id"<?= $mesaAtualValida ? '' : ' required' ?><?= atributosCampo('mesa-repetir', $erroMov('repetir', 'mesa')) ?>>
<?php if (!$mesaAtualValida): ?>
                        <option value="">Escolha o responsável</option>
<?php endif; ?>
<?php foreach ($usuariosDisponiveis as $u): ?>
                        <option value="<?= (int)$u['id'] ?>"<?= (int)$u['id'] === $mesaMarcada('repetir') ? ' selected' : '' ?>><?= escaparHtml($u['nome']) ?></option>
<?php endforeach; ?>
                      </select>
                      <?= htmlErroCampo('mesa-repetir', $erroMov('repetir', 'mesa')) ?>
                    </div>
                  </div>
                  <?= $htmlCampoObservacao('obs-repetir', 'repetir') ?>

                  <div><button class="ws-btn ws-btn--secundario" type="submit"><i data-lucide="rotate-ccw"></i>Repetir <?= escaparHtml($etapaAtual['nome']) ?></button></div>
                </form>
<?php endif; ?>

<?php if ($etapasParaVoltar):
    if ($blocoMover++ > 0) echo '                <hr style="margin:0">' . "\n";
?>
                <form method="post" class="ws-pilha ws-pilha--peq" id="form-voltar" data-ws-confirmar="modal-voltar" novalidate>
                  <?= csrfCampo() ?>
                  <input type="hidden" name="acao" value="voltar">
                  <input type="hidden" name="confirmacao" value="">
                  <div class="ws-form__grade">
                    <div class="ws-campo<?= classeCampoErro($erroMov('voltar', 'etapa')) ?>">
                      <label class="ws-campo__rotulo" for="etapa-voltar">Voltar para</label>
                      <select class="ws-select" id="etapa-voltar" name="etapa_destino_id" required<?= atributosCampo('etapa-voltar', $erroMov('voltar', 'etapa')) ?>>
                        <option value="">Escolha a etapa</option>
<?php foreach ($etapasParaVoltar as $ev): ?>
                        <option value="<?= (int)$ev['id'] ?>"><?= escaparHtml($ev['nome']) ?></option>
<?php endforeach; ?>
                      </select>
                      <?= htmlErroCampo('etapa-voltar', $erroMov('voltar', 'etapa')) ?>
                    </div>
                    <div class="ws-campo<?= classeCampoErro($erroMov('voltar', 'mesa')) ?>">
                      <label class="ws-campo__rotulo" for="mesa-voltar">Mesa</label>
                      <select class="ws-select" id="mesa-voltar" name="responsavel_id"<?= $mesaAtualValida ? '' : ' required' ?><?= atributosCampo('mesa-voltar', $erroMov('voltar', 'mesa')) ?>>
<?php if (!$mesaAtualValida): ?>
                        <option value="">Escolha o responsável</option>
<?php endif; ?>
<?php foreach ($usuariosDisponiveis as $u): ?>
                        <option value="<?= (int)$u['id'] ?>"<?= (int)$u['id'] === $mesaMarcada('voltar') ? ' selected' : '' ?>><?= escaparHtml($u['nome']) ?></option>
<?php endforeach; ?>
                      </select>
                      <?= htmlErroCampo('mesa-voltar', $erroMov('voltar', 'mesa')) ?>
                    </div>
                  </div>
                  <?= $htmlCampoObservacao('obs-voltar', 'voltar') ?>

                  <div><button class="ws-btn ws-btn--secundario" type="submit"><i data-lucide="undo-2"></i>Voltar etapa</button></div>
                </form>
<?php endif; ?>

<?php if ($blocoMover++ > 0) echo '                <hr style="margin:0">' . "\n"; ?>
                <form method="post" class="ws-pilha ws-pilha--peq" novalidate>
                  <?= csrfCampo() ?>
                  <input type="hidden" name="acao" value="passar_mesa">
                  <div class="ws-form__grade">
                    <div class="ws-campo<?= classeCampoErro($erroMov('passar_mesa', 'mesa')) ?>">
                      <label class="ws-campo__rotulo" for="mesa-passar">Passar para a mesa de</label>
                      <select class="ws-select" id="mesa-passar" name="novo_responsavel_id" required<?= atributosCampo('mesa-passar', $erroMov('passar_mesa', 'mesa')) ?>>
<?php if (!$mesaAtualValida || $erroMov('passar_mesa', 'mesa') !== null): ?>
                        <option value="">Escolha o responsável</option>
<?php endif; ?>
<?php foreach ($usuariosDisponiveis as $u): ?>
                        <option value="<?= (int)$u['id'] ?>"<?= $erroMov('passar_mesa', 'mesa') === null && (int)$u['id'] === (int)$livro['responsavel_atual_id'] ? ' selected' : '' ?>><?= escaparHtml($u['nome']) ?></option>
<?php endforeach; ?>
                      </select>
                      <?= htmlErroCampo('mesa-passar', $erroMov('passar_mesa', 'mesa')) ?>
                      <p class="ws-campo__ajuda">Continua em <?= escaparHtml($etapaAtual['nome']) ?><?= $rodadaAtualTexto !== '' ? ', na ' . escaparHtml($rodadaAtualTexto) : '' ?>. Não conta rodada nova.</p>
                    </div>
                  </div>
                  <?= $htmlCampoObservacao('obs-passar', 'passar_mesa') ?>

                  <div><button class="ws-btn ws-btn--secundario" type="submit"><i data-lucide="arrow-left-right"></i>Passar livro</button></div>
                </form>
              </div>
            </section>
<?php endif; ?>

            <section class="ws-secao" aria-labelledby="obs-titulo">
              <div class="ws-secao__cabecalho">
                <h2 id="obs-titulo">Observações</h2>
<?php if ($podeMovimentar && $livro['status'] !== 'concluido'): ?>
                <a class="ws-btn ws-btn--link" href="livro_form.php?id=<?= $livroId ?>">Editar</a>
<?php endif; ?>
              </div>
              <div class="ws-card">
<?php if ($livro['observacoes'] !== null && $livro['observacoes'] !== ''): ?>
                <p><?= nl2br(escaparHtml($livro['observacoes'])) ?></p>
<?php else: ?>
                <p class="ws-texto-suave">Nenhuma observação registrada.</p>
<?php endif; ?>
              </div>
            </section>

            <section class="ws-secao" id="historico" aria-labelledby="historico-titulo">
              <div class="ws-secao__cabecalho">
                <div>
                  <h2 id="historico-titulo">Histórico</h2>
                  <p>Nenhuma movimentação é apagada ou sobrescrita. Da mais recente para a mais antiga.</p>
                </div>
              </div>
<?php if (!$movimentacoes): ?>
              <div class="ws-vazio">
                <i class="ws-vazio__icone" data-lucide="history"></i>
                <h3 class="ws-h2">Nenhuma movimentação registrada.</h3>
              </div>
<?php else: ?>
              <ol class="ws-linha-tempo">
<?php foreach ($movimentacoes as $i => $m):
    $tipo = $m['tipo'];
    $etapaNomeMov = $m['etapa_nome'] ?: 'etapa removida';
    $limiteMov = $m['etapa_rodadas_incluidas'] !== null ? (int)$m['etapa_rodadas_incluidas'] : null;
    $rodadaMovTexto = 'Rodada ' . (int)$m['rodada'] . ($limiteMov !== null ? ' de ' . $limiteMov : '');
    $anterior = $movimentacoes[$i + 1] ?? null;
    switch ($tipo) {
        case 'avanco':            $titulo = 'Entrou em ' . $etapaNomeMov; break;
        case 'repeticao':         $titulo = $etapaNomeMov . ' de novo, ' . mb_strtolower($rodadaMovTexto); break;
        case 'retrocesso':        $titulo = 'Voltou para ' . $etapaNomeMov; break;
        case 'pulo_opcional':     $titulo = 'Pulou ' . $etapaNomeMov . ' (opcional)'; break;
        case 'troca_responsavel': $titulo = 'Passou da mesa de ' . ($anterior['responsavel_nome'] ?? '—') . ' para a de ' . ($m['responsavel_nome'] ?: '—'); break;
        case 'resgate':           $titulo = 'Resgate concluído: livro reencontrado em ' . $etapaNomeMov; break;
        default:                  $titulo = $etapaNomeMov;
    }
    $partesMeta = [NOMES_TIPO_MOV[$tipo] ?? $tipo];
    if ($tipo === 'troca_responsavel') {
        $partesMeta[] = $etapaNomeMov . ', ' . mb_strtolower($rodadaMovTexto);
    } elseif ($tipo !== 'pulo_opcional') {
        if ($tipo !== 'repeticao') $partesMeta[] = $rodadaMovTexto;
        $partesMeta[] = 'Mesa: ' . ($m['responsavel_nome'] ?: '—');
    }
    $partesMeta[] = 'Registrado por ' . ($m['registrado_por_nome'] ?: '—');
    $aberto = $m['data_saida'] === null && $tipo !== 'pulo_opcional';
    $momento = strtotime($m['data_entrada']);
?>
                <li class="ws-evento ws-evento--<?= escaparHtml(CLASSE_TIPO_MOV[$tipo] ?? 'avanco') ?><?= $aberto ? ' is-aberto' : '' ?>">
                  <span class="ws-evento__icone" aria-hidden="true"><i data-lucide="<?= escaparHtml(ICONE_TIPO_MOV[$tipo] ?? 'arrow-right') ?>"></i></span>
                  <div class="ws-evento__corpo">
                    <p class="ws-evento__titulo"><?= escaparHtml($titulo) ?></p>
                    <p class="ws-evento__meta"><?= escaparHtml(implode(' · ', $partesMeta)) ?></p>
<?php if ($tipo !== 'pulo_opcional'): ?>
                    <p class="ws-evento__meta">Tempo na etapa: <?= escaparHtml(formatarDuracaoEtapa($m['data_entrada'], $m['data_saida'])) ?><?= $m['data_saida'] !== null ? ' · Saída ' . escaparHtml(formatarDataHora($m['data_saida'])) : '' ?></p>
<?php endif; ?>
<?php if ($m['observacao'] !== null && $m['observacao'] !== ''): ?>
                    <p class="ws-evento__nota"><span class="ws-evento__nota-rotulo">Observação</span><?= nl2br(escaparHtml($m['observacao'])) ?></p>
<?php endif; ?>
<?php if ((int)$m['excedente'] === 1): ?>
                    <p><span class="ws-status ws-status--cobrado">Cobrado</span></p>
<?php elseif ($limiteMov !== null && in_array($tipo, ['avanco', 'repeticao', 'retrocesso'], true) && (int)$m['rodada'] === $limiteMov): ?>
                    <p><span class="ws-status ws-status--limite">No limite</span></p>
<?php endif; ?>
                  </div>
                  <time class="ws-evento__data" datetime="<?= $momento !== false ? escaparHtml(date('Y-m-d\TH:i', $momento)) : '' ?>"><?= escaparHtml(formatarDataHora($m['data_entrada'])) ?></time>
                </li>
<?php endforeach; ?>
              </ol>
<?php endif; ?>
            </section>
          </div>

          <!-- Coluna lateral -->
          <aside class="ws-col-4 ws-pilha" aria-label="Fluxo e dados do livro">
            <section class="ws-card ws-card--superficie" aria-labelledby="fluxo-titulo">
              <h2 id="fluxo-titulo">Fluxo</h2>
<?php if ($mostrarProgresso): ?>
              <div class="ws-progresso">
                <div class="ws-progresso__rotulo"><span><?= escaparHtml($progressoRotulo) ?></span><span class="ws-codigo"><?= $percentualProgresso ?>%</span></div>
                <div class="ws-progresso__trilho" role="progressbar" aria-valuemin="1" aria-valuemax="<?= $totalEtapas ?>" aria-valuenow="<?= $progressoAgora ?>" aria-valuetext="<?= escaparHtml($progressoTexto) ?>">
                  <span class="ws-progresso__preenchimento" style="--ws-progresso: <?= $percentualProgresso ?>%"></span>
                </div>
              </div>
<?php endif; ?>
              <ol class="ws-fluxo" aria-label="Fluxo de etapas do livro <?= escaparHtml($codigo) ?>">
<?php foreach ($etapasFluxo as $i => $ef):
    $eid = (int)$ef['id'];
    $ehAtual = $eid === $etapaAtualId && !$statusFinal;
    // Cancelado: nenhuma etapa é "atual"; a etapa onde o livro parou ganha só um detalhe neutro (e não vira "concluída",
    // mesmo tendo a movimentação fechada pelo cancelamento).
    $paradaAqui = $livro['status'] === 'cancelado' && $eid === $etapaAtualId;
    if ($ehAtual) { $estado = 'is-atual'; $estadoTexto = 'etapa atual'; }
    elseif ($paradaAqui) { $estado = ''; $estadoTexto = 'o livro parou aqui, cancelado'; }
    elseif (isset($etapasConcluidas[$eid])) { $estado = 'is-concluida'; $estadoTexto = 'concluída'; }
    elseif (isset($etapasPuladas[$eid])) { $estado = 'is-pulada'; $estadoTexto = 'pulada'; }
    else { $estado = ''; $estadoTexto = 'a fazer'; }
    $detalhe = '';
    if ($paradaAqui) {
        $detalhe = 'Parou aqui · cancelado em ' . date('d/m', strtotime($dataFimLivro));
    } elseif ($ehAtual) {
        if ($livro['status'] === 'extraviado') {
            $detalhe = 'Extraviado · Última mesa: ' . ($livro['responsavel_nome'] ?: '—');
        } elseif ($movAtual) {
            $detalhe = 'Desde ' . date('d/m', strtotime($movAtual['data_entrada'])) . ' · Mesa: ' . ($livro['responsavel_nome'] ?: '—');
        }
    } elseif ($estado === 'is-pulada') {
        $detalhe = 'Pulada em ' . date('d/m', strtotime($etapasPuladas[$eid]));
    }
    $extra = (isset($rodadasPorEtapa[$eid]) && $rodadasPorEtapa[$eid]['max'] > 0) ? rotuloRodada($rodadasPorEtapa[$eid]['max'], $rodadasPorEtapa[$eid]['limite']) : '';
?>
                <li class="ws-fluxo__etapa<?= $estado !== '' ? ' ' . $estado : '' ?>"<?= $ehAtual ? ' aria-current="step"' : '' ?>>
                  <span class="ws-fluxo__marcador" aria-hidden="true"><?php if ($estado === 'is-concluida'): ?><i data-lucide="check"></i><?php else: ?><?= str_pad((string)($i + 1), 2, '0', STR_PAD_LEFT) ?><?php endif; ?></span>
                  <span class="ws-fluxo__nome"><span><?= escaparHtml($ef['nome']) ?></span><?php if ((int)$ef['opcional'] === 1): ?> <span class="ws-tag ws-tag--opcional ws-tag--peq">Opcional</span><?php endif; ?><?php if ((int)$ef['ativa'] !== 1): ?> <span class="ws-tag ws-tag--neutra ws-tag--peq">Desativada</span><?php endif; ?><span class="ws-sr-only">(<?= escaparHtml($estadoTexto) ?>)</span><?php if ($detalhe !== ''): ?><span class="ws-fluxo__detalhe"><?= escaparHtml($detalhe) ?></span><?php endif; ?></span>
                  <span<?= $extra !== '' ? ' class="ws-fluxo__extra"' : '' ?>><?= escaparHtml($extra) ?></span>
                </li>
<?php endforeach; ?>
              </ol>
            </section>

            <section class="ws-card" aria-labelledby="dados-titulo">
              <h2 id="dados-titulo">Dados do livro</h2>
              <dl class="ws-dados">
                <div><dt>Livro</dt><dd class="ws-codigo"><?= escaparHtml($codigo) ?></dd></div>
                <div><dt>ISBN</dt><dd><?php if ($livro['isbn']): ?><span class="ws-codigo"><?= escaparHtml(formatarIsbn($livro['isbn'])) ?></span><?php else: ?><span class="ws-texto-suave">Ainda sem ISBN</span><?php endif; ?></dd></div>
                <div><dt>Tem cópia</dt><dd><?= ((int)$livro['tem_copia'] === 1) ? 'Sim' : 'Não' ?></dd></div>
                <div><dt>Cadastrado em</dt><dd class="ws-codigo"><?= escaparHtml(formatarDataHora($livro['criado_em'])) ?></dd></div>
                <div><dt>Pessoas</dt><dd>
<?php if (!$contribuicoes): ?>
                  <span class="ws-texto-suave">Nenhuma pessoa vinculada.</span>
<?php else: foreach ($contribuicoes as $ci => $c): ?>
                  <?= $ci > 0 ? '<br>' : '' ?><?= escaparHtml($c['pessoa_nome']) ?> <span class="ws-texto-suave">· <?= escaparHtml($c['papel_nome']) ?></span>
<?php endforeach; endif; ?>
                </dd></div>
              </dl>
            </section>

<?php if ($podeGerenciar && $livro['status'] === 'em_andamento' && $etapaAtual): ?>
            <section class="ws-card" aria-labelledby="outras-titulo">
              <h2 id="outras-titulo">Outras ações</h2>
              <p class="ws-texto-suave">Use só quando o livro sumiu do fluxo<?= $podeCancelar ? ' ou saiu da produção' : '' ?>.</p>
              <div class="ws-btn-grupo">
                <button class="ws-btn ws-btn--secundario ws-btn--peq" type="button" data-ws-modal-abrir="modal-resgate"><i data-lucide="search"></i>Iniciar resgate</button>
<?php if ($podeCancelar): ?>
                <button class="ws-btn ws-btn--secundario ws-btn--peq" type="button" data-ws-modal-abrir="modal-cancelar"><i data-lucide="ban"></i>Cancelar livro</button>
<?php endif; ?>
              </div>
            </section>
<?php endif; ?>
          </aside>
        </div>
      </div>

<?php if ($podeGerenciar && $livro['status'] === 'em_andamento' && $etapaAtual): ?>
<?php foreach ($acoesDisponiveis as $i => $a): if (empty($a['excedente'])) continue;
    $limiteA = (int)$a['rodadas_incluidas'];
?>
      <!-- Modal: a ação abre uma rodada cobrada -->
      <dialog class="ws-modal" id="modal-avancar-<?= $i ?>" aria-labelledby="modal-avancar-<?= $i ?>-titulo" aria-describedby="modal-avancar-<?= $i ?>-texto">
        <div class="ws-modal__caixa">
          <header class="ws-modal__cabecalho">
            <h2 id="modal-avancar-<?= $i ?>-titulo">Registrar a rodada <?= (int)$a['rodada'] ?> de <?= escaparHtml($a['etapa_nome']) ?>?</h2>
            <button class="ws-fechar" type="button" data-ws-modal-fechar aria-label="Fechar"><i data-lucide="x"></i></button>
          </header>
          <div class="ws-modal__corpo">
            <p id="modal-avancar-<?= $i ?>-texto">Esta será a rodada <?= (int)$a['rodada'] ?> de <?= escaparHtml($a['etapa_nome']) ?>, que inclui <?= $limiteA ?>. A rodada será cobrada.</p>
            <?= htmlContadorRodadas($a['etapa_nome'], $limiteA, (int)$a['rodada']) . "\n" ?>
          </div>
          <footer class="ws-modal__rodape">
            <button class="ws-btn ws-btn--secundario" type="button" data-ws-modal-fechar>Voltar</button>
            <button class="ws-btn ws-btn--primario" type="button" data-ws-confirmar-ok>Registrar rodada <?= (int)$a['rodada'] ?></button>
          </footer>
        </div>
      </dialog>

<?php endforeach; ?>
<?php if ($excedenteRepetir): $limiteRep = (int)$etapaAtual['rodadas_incluidas']; ?>
      <!-- Modal: repetir gera rodada cobrada -->
      <dialog class="ws-modal" id="modal-repetir" aria-labelledby="modal-repetir-titulo" aria-describedby="modal-repetir-texto">
        <div class="ws-modal__caixa">
          <header class="ws-modal__cabecalho">
            <h2 id="modal-repetir-titulo">Registrar nova rodada de <?= escaparHtml($etapaAtual['nome']) ?>?</h2>
            <button class="ws-fechar" type="button" data-ws-modal-fechar aria-label="Fechar"><i data-lucide="x"></i></button>
          </header>
          <div class="ws-modal__corpo">
            <p id="modal-repetir-texto">Esta será a rodada <?= (int)$rodadaRepetir ?> de <?= escaparHtml($etapaAtual['nome']) ?>, que inclui <?= $limiteRep ?>. A rodada será cobrada.</p>
            <?= htmlContadorRodadas($etapaAtual['nome'], $limiteRep, (int)$rodadaRepetir) . "\n" ?>
          </div>
          <footer class="ws-modal__rodape">
            <button class="ws-btn ws-btn--secundario" type="button" data-ws-modal-fechar>Voltar</button>
            <button class="ws-btn ws-btn--primario" type="button" data-ws-confirmar-ok>Registrar rodada <?= (int)$rodadaRepetir ?></button>
          </footer>
        </div>
      </dialog>

<?php endif; ?>
<?php if ($etapasParaVoltar): ?>
      <!-- Modal: voltar para uma etapa cuja nova rodada é cobrada (título e texto são preenchidos pelo script abaixo) -->
      <dialog class="ws-modal" id="modal-voltar" aria-labelledby="modal-voltar-titulo" aria-describedby="modal-voltar-texto">
        <div class="ws-modal__caixa">
          <header class="ws-modal__cabecalho">
            <h2 id="modal-voltar-titulo">Registrar nova rodada?</h2>
            <button class="ws-fechar" type="button" data-ws-modal-fechar aria-label="Fechar"><i data-lucide="x"></i></button>
          </header>
          <div class="ws-modal__corpo">
            <p id="modal-voltar-texto"></p>
<?php foreach ($infoVoltar as $etapaVoltarId => $iv): if (!$iv['excedente']) continue; ?>
            <div data-voltar-previa="<?= (int)$etapaVoltarId ?>" hidden>
              <?= htmlContadorRodadas($iv['nome'], (int)$iv['rodadas_incluidas'], (int)$iv['rodada']) . "\n" ?>
            </div>
<?php endforeach; ?>
          </div>
          <footer class="ws-modal__rodape">
            <button class="ws-btn ws-btn--secundario" type="button" data-ws-modal-fechar>Voltar</button>
            <button class="ws-btn ws-btn--primario" type="button" data-ws-confirmar-ok id="modal-voltar-ok">Registrar rodada</button>
          </footer>
        </div>
      </dialog>

<?php endif; ?>
      <!-- Modal: iniciar resgate -->
      <dialog class="ws-modal" id="modal-resgate" aria-labelledby="modal-resgate-titulo">
        <form class="ws-modal__caixa" method="post">
          <?= csrfCampo() ?>
          <input type="hidden" name="acao" value="iniciar_resgate">
          <input type="hidden" name="confirmacao" value="">
          <header class="ws-modal__cabecalho">
            <h2 id="modal-resgate-titulo">Iniciar resgate do livro <?= escaparHtml($codigo) ?>?</h2>
            <button class="ws-fechar" type="button" data-ws-modal-fechar aria-label="Fechar"><i data-lucide="x"></i></button>
          </header>
          <div class="ws-modal__corpo">
            <p>O livro fica marcado como extraviado e nenhuma movimentação é aceita até o resgate ser concluído.</p>
<?php if ($movAtual): ?>
            <p class="ws-texto-suave">Última posição conhecida: <?= escaparHtml($etapaAtual['nome']) ?><?= $rodadaAtualTexto !== '' ? ', ' . escaparHtml($rodadaAtualTexto) : '' ?>, mesa de <?= escaparHtml($livro['responsavel_nome'] ?: '—') ?>, desde <?= escaparHtml(date('d/m', strtotime($movAtual['data_entrada']))) ?>.</p>
<?php endif; ?>
          </div>
          <footer class="ws-modal__rodape">
            <button class="ws-btn ws-btn--secundario" type="button" data-ws-modal-fechar>Voltar</button>
            <button class="ws-btn ws-btn--primario" type="submit" data-confirmar-campo>Iniciar resgate</button>
          </footer>
        </form>
      </dialog>

<?php if ($podeCancelar): ?>
      <!-- Modal: cancelar livro -->
      <dialog class="ws-modal" id="modal-cancelar" aria-labelledby="modal-cancelar-titulo">
        <form class="ws-modal__caixa" method="post">
          <?= csrfCampo() ?>
          <input type="hidden" name="acao" value="cancelar">
          <input type="hidden" name="confirmacao" value="">
          <header class="ws-modal__cabecalho">
            <h2 id="modal-cancelar-titulo">Cancelar o livro <?= escaparHtml($codigo) ?>?</h2>
            <button class="ws-fechar" type="button" data-ws-modal-fechar aria-label="Fechar"><i data-lucide="x"></i></button>
          </header>
          <div class="ws-modal__corpo">
            <p>O livro sai da produção em <?= escaparHtml($etapaAtual['nome']) ?>. O histórico fica guardado, mas o livro não volta ao fluxo.</p>
          </div>
          <footer class="ws-modal__rodape">
            <button class="ws-btn ws-btn--secundario" type="button" data-ws-modal-fechar>Manter livro</button>
            <button class="ws-btn ws-btn--escuro" type="submit" data-confirmar-campo>Cancelar livro</button>
          </footer>
        </form>
      </dialog>

<?php endif; ?>
      <script>
      // Campo oculto "confirmacao": só o botão de confirmar do modal o preenche (com 1). Sem ele o PHP não grava a
      // ação e devolve o aviso. Este script roda antes do waresys.js (que é defer), então o preenchimento acontece
      // antes de o formulário ser enviado.
      (function () {
        document.querySelectorAll('dialog.ws-modal [data-ws-confirmar-ok]').forEach(function (botao) {
          botao.addEventListener('click', function () {
            var modal = botao.closest('dialog');
            var form = modal && modal._wsFormulario;
            if (form && form.elements.confirmacao) form.elements.confirmacao.value = '1';
          });
        });
        document.querySelectorAll('dialog.ws-modal [data-confirmar-campo]').forEach(function (botao) {
          botao.addEventListener('click', function () {
            if (botao.form && botao.form.elements.confirmacao) botao.form.elements.confirmacao.value = '1';
          });
        });
<?php if ($etapasParaVoltar): ?>

        // "Voltar etapa" só pede confirmação quando a nova rodada da etapa de destino é cobrada (como antes).
        var INFO_VOLTAR = <?= json_encode($infoVoltar, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        var formVoltar = document.getElementById('form-voltar');
        formVoltar.addEventListener('submit', function () {
          if (formVoltar.elements.confirmacao.value === '1') return; // já confirmado no modal: o envio segue
          var info = INFO_VOLTAR[formVoltar.elements.etapa_destino_id.value];
          if (!info || !info.excedente) {
            formVoltar.dataset.wsConfirmado = '1'; // sem rodada cobrada: o waresys.js deixa enviar direto
            return;
          }
          delete formVoltar.dataset.wsConfirmado;
          var limite = info.rodadas_incluidas !== null ? info.rodadas_incluidas : '?';
          document.getElementById('modal-voltar-titulo').textContent = 'Registrar a rodada ' + info.rodada + ' de ' + info.nome + '?';
          document.getElementById('modal-voltar-texto').textContent = 'Esta será a rodada ' + info.rodada + ' de ' + info.nome + ', que inclui ' + limite + '. A rodada será cobrada.';
          document.getElementById('modal-voltar-ok').textContent = 'Registrar rodada ' + info.rodada;
          document.querySelectorAll('#modal-voltar [data-voltar-previa]').forEach(function (previa) {
            previa.hidden = previa.getAttribute('data-voltar-previa') !== formVoltar.elements.etapa_destino_id.value;
          });
        });
<?php endif; ?>
      })();
      </script>
<?php endif; ?>
      <script src="envio_unico.js"></script>
<?php require __DIR__ . '/includes/rodape.php'; ?>
