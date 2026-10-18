<?php
/**
 * Ações de movimentação de livro entre etapas — avançar, repetir, voltar,
 * concluir. Cada função abre/fecha sua própria transação, para poder ser
 * chamada isoladamente (inclusive por script de teste), sem depender de
 * uma tela orquestrando o commit.
 */
require_once __DIR__ . '/fluxo_etapas.php';
require_once __DIR__ . '/rodadas.php';
require_once __DIR__ . '/log.php';

// Nome exato semeado em database/seed.sql. Não há coluna de identificador
// estável em `etapas` — se essa etapa for renomeada no futuro, o aviso de
// ISBN vazio para de disparar silenciosamente.
const NOME_ETAPA_CATALOGACAO = 'Catalogação — ISBN e ficha catalográfica';

function buscarLivroParaMovimentar(mysqli $conn, int $livroId): array
{
    $stmt = $conn->prepare('
        SELECT l.*, e.ordem AS etapa_atual_ordem, e.nome AS etapa_atual_nome,
               e.opcional AS etapa_atual_opcional, e.repetivel AS etapa_atual_repetivel,
               e.finaliza_livro AS etapa_atual_finaliza
        FROM livros l
        JOIN etapas e ON e.id = l.etapa_atual_id
        WHERE l.id = ?
    ');
    $stmt->bind_param('i', $livroId);
    $stmt->execute();
    $livro = $stmt->get_result()->fetch_assoc();
    if (!$livro) {
        throw new InvalidArgumentException('Livro não encontrado.');
    }
    if ($livro['status'] !== 'em_andamento') {
        throw new InvalidArgumentException('Este livro não está em andamento — não é possível movimentá-lo.');
    }
    return $livro;
}

function fecharMovimentacaoAtual(mysqli $conn, int $livroId, string $agora): array
{
    $stmt = $conn->prepare('SELECT * FROM movimentacoes WHERE livro_id = ? AND data_saida IS NULL ORDER BY data_entrada DESC LIMIT 1');
    $stmt->bind_param('i', $livroId);
    $stmt->execute();
    $mov = $stmt->get_result()->fetch_assoc();
    if (!$mov) {
        throw new InvalidArgumentException('Não há movimentação aberta para este livro.');
    }
    $stmtU = $conn->prepare('UPDATE movimentacoes SET data_saida = ? WHERE id = ?');
    $movId = (int)$mov['id'];
    $stmtU->bind_param('si', $agora, $movId);
    $stmtU->execute();
    return $mov;
}

function abrirMovimentacao(mysqli $conn, int $livroId, int $etapaId, int $responsavelId, int $registradoPorId, string $tipo, int $rodada, bool $excedente, string $agora, ?string $dataSaidaImediata = null): int
{
    $stmt = $conn->prepare('INSERT INTO movimentacoes (livro_id, etapa_id, responsavel_id, registrado_por_id, tipo, rodada, excedente, data_entrada, data_saida) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $excedenteInt = $excedente ? 1 : 0;
    $stmt->bind_param('iiiisiiss', $livroId, $etapaId, $responsavelId, $registradoPorId, $tipo, $rodada, $excedenteInt, $agora, $dataSaidaImediata);
    $stmt->execute();
    return $conn->insert_id;
}

function avisoIsbnVazioAoSairDaCatalogacao(string $nomeEtapaFechada, ?string $isbn): ?string
{
    if ($nomeEtapaFechada === NOME_ETAPA_CATALOGACAO && ($isbn === null || $isbn === '')) {
        return 'Atenção: o ISBN ainda não foi informado para este livro.';
    }
    return null;
}

function usuarioExiste(mysqli $conn, int $usuarioId): bool
{
    $stmt = $conn->prepare('SELECT 1 FROM usuarios WHERE id = ?');
    $stmt->bind_param('i', $usuarioId);
    $stmt->execute();
    return (bool)$stmt->get_result()->fetch_assoc();
}

function nomeUsuario(mysqli $conn, int $usuarioId): string
{
    $stmt = $conn->prepare('SELECT nome FROM usuarios WHERE id = ?');
    $stmt->bind_param('i', $usuarioId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return $row ? $row['nome'] : ('Usuário #' . $usuarioId);
}

function resolverResponsavel(mysqli $conn, array $livro, ?int $novoResponsavelId): int
{
    if ($novoResponsavelId === null) {
        return (int)$livro['responsavel_atual_id'];
    }
    if (!usuarioExiste($conn, $novoResponsavelId)) {
        throw new InvalidArgumentException('Usuário responsável inválido.');
    }
    return $novoResponsavelId;
}

function avancarEtapa(mysqli $conn, int $livroId, int $etapaDestinoId, int $registradoPorId, ?int $etapaPuladaId = null, ?int $novoResponsavelId = null): array
{
    $livro = buscarLivroParaMovimentar($conn, $livroId);
    $etapaOrigemOrdem = (int)$livro['etapa_atual_ordem'];
    $etapaOrigemNome = $livro['etapa_atual_nome'];

    $etapaDestino = buscarEtapa($conn, $etapaDestinoId);
    if (!$etapaDestino || (int)$etapaDestino['ativa'] !== 1) {
        throw new InvalidArgumentException('Etapa de destino inválida ou inativa.');
    }
    if ((int)$etapaDestino['ordem'] <= $etapaOrigemOrdem) {
        throw new InvalidArgumentException('Avançar só permite ir para uma etapa seguinte na ordem.');
    }

    $etapaPulada = null;
    if ($etapaPuladaId !== null) {
        $etapaPulada = buscarEtapa($conn, $etapaPuladaId);
        if (!$etapaPulada || (int)$etapaPulada['ativa'] !== 1) {
            throw new InvalidArgumentException('Etapa a pular inválida ou inativa.');
        }
        if ((int)$etapaPulada['opcional'] !== 1) {
            throw new InvalidArgumentException('Não é permitido pular uma etapa obrigatória.');
        }
        if ((int)$etapaPulada['ordem'] <= $etapaOrigemOrdem || (int)$etapaPulada['ordem'] >= (int)$etapaDestino['ordem']) {
            throw new InvalidArgumentException('Etapa a pular deve estar entre a etapa atual e o destino.');
        }
    }

    // Toda etapa ativa estritamente entre a origem e o destino precisa já ter
    // sido concluída antes pelo livro, ou ser exatamente a etapa marcada como
    // pulada agora — nunca pode ficar sem nenhum registro e sem checagem. Sem
    // isso, um destino distante permitiria pular etapas obrigatórias nunca
    // feitas (inclusive outras opcionais) sem deixar rastro nenhum.
    $stmtEntre = $conn->prepare('SELECT * FROM etapas WHERE ativa = 1 AND ordem > ? AND ordem < ? ORDER BY ordem');
    $stmtEntre->bind_param('ii', $etapaOrigemOrdem, $etapaDestino['ordem']);
    $stmtEntre->execute();
    $rEntre = $stmtEntre->get_result();
    while ($etapaEntre = $rEntre->fetch_assoc()) {
        if ($etapaPulada && (int)$etapaEntre['id'] === (int)$etapaPulada['id']) {
            continue;
        }
        if (!etapaJaConcluidaParaLivro($conn, $livroId, (int)$etapaEntre['id'])) {
            throw new InvalidArgumentException('Não é possível avançar direto para "' . $etapaDestino['nome'] . '": a etapa "' . $etapaEntre['nome'] . '" ainda não foi concluída.');
        }
    }

    $agora = date('Y-m-d H:i:s');
    $avisos = [];

    $conn->begin_transaction();
    try {
        $responsavelId = resolverResponsavel($conn, $livro, $novoResponsavelId);

        fecharMovimentacaoAtual($conn, $livroId, $agora);
        $avisoIsbn = avisoIsbnVazioAoSairDaCatalogacao($etapaOrigemNome, $livro['isbn']);
        if ($avisoIsbn) $avisos[] = $avisoIsbn;

        if ($etapaPulada) {
            $rodadaPulo = calcularRodada($conn, $livroId, (int)$etapaPulada['id']);
            abrirMovimentacao($conn, $livroId, (int)$etapaPulada['id'], $responsavelId, $registradoPorId, 'pulo_opcional', $rodadaPulo, false, $agora, $agora);
        }

        $rodada = calcularRodada($conn, $livroId, (int)$etapaDestino['id']);
        $excedente = calcularExcedente($etapaDestino, $rodada);
        $novaMovId = abrirMovimentacao($conn, $livroId, (int)$etapaDestino['id'], $responsavelId, $registradoPorId, 'avanco', $rodada, $excedente, $agora);

        $etapaDestinoId2 = (int)$etapaDestino['id'];
        $stmtL = $conn->prepare('UPDATE livros SET etapa_atual_id = ?, responsavel_atual_id = ?, atualizado_em = ? WHERE id = ?');
        $stmtL->bind_param('iisi', $etapaDestinoId2, $responsavelId, $agora, $livroId);
        $stmtL->execute();

        // Guarda só a referência — o detalhe (etapas, rodada, responsável) já está em movimentacoes.
        registrarLog($conn, $registradoPorId, 'movimentacao.avancar', 'livro', $livroId, null, 'mov#' . $novaMovId);

        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }

    return [
        'livro_id' => $livroId,
        'movimentacao_id' => $novaMovId,
        'etapa_id' => (int)$etapaDestino['id'],
        'etapa_nome' => $etapaDestino['nome'],
        'rodada' => $rodada,
        'excedente' => $excedente,
        'avisos' => $avisos,
    ];
}

function repetirEtapa(mysqli $conn, int $livroId, int $registradoPorId, ?int $novoResponsavelId = null): array
{
    $livro = buscarLivroParaMovimentar($conn, $livroId);
    if ((int)$livro['etapa_atual_repetivel'] !== 1) {
        throw new InvalidArgumentException('Esta etapa não é repetível.');
    }

    $etapaAtualId = (int)$livro['etapa_atual_id'];
    $etapaAtualNome = $livro['etapa_atual_nome'];
    $agora = date('Y-m-d H:i:s');
    $avisos = [];

    $conn->begin_transaction();
    try {
        $responsavelId = resolverResponsavel($conn, $livro, $novoResponsavelId);

        fecharMovimentacaoAtual($conn, $livroId, $agora);
        $avisoIsbn = avisoIsbnVazioAoSairDaCatalogacao($etapaAtualNome, $livro['isbn']);
        if ($avisoIsbn) $avisos[] = $avisoIsbn;

        $etapaAtual = buscarEtapa($conn, $etapaAtualId);
        $rodada = calcularRodada($conn, $livroId, $etapaAtualId);
        $excedente = calcularExcedente($etapaAtual, $rodada);
        $novaMovId = abrirMovimentacao($conn, $livroId, $etapaAtualId, $responsavelId, $registradoPorId, 'repeticao', $rodada, $excedente, $agora);

        $stmtL = $conn->prepare('UPDATE livros SET responsavel_atual_id = ?, atualizado_em = ? WHERE id = ?');
        $stmtL->bind_param('isi', $responsavelId, $agora, $livroId);
        $stmtL->execute();

        registrarLog($conn, $registradoPorId, 'movimentacao.repetir', 'livro', $livroId, null, 'mov#' . $novaMovId);

        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }

    return [
        'livro_id' => $livroId,
        'movimentacao_id' => $novaMovId,
        'etapa_id' => $etapaAtualId,
        'etapa_nome' => $etapaAtualNome,
        'rodada' => $rodada,
        'excedente' => $excedente,
        'avisos' => $avisos,
    ];
}

function voltarEtapa(mysqli $conn, int $livroId, int $etapaDestinoId, int $registradoPorId, ?int $novoResponsavelId = null): array
{
    $livro = buscarLivroParaMovimentar($conn, $livroId);
    $etapaOrigemNome = $livro['etapa_atual_nome'];
    $etapaOrigemOrdem = (int)$livro['etapa_atual_ordem'];

    $etapaDestino = buscarEtapa($conn, $etapaDestinoId);
    if (!$etapaDestino || (int)$etapaDestino['ativa'] !== 1) {
        throw new InvalidArgumentException('Etapa de destino inválida ou inativa.');
    }
    if ((int)$etapaDestino['ordem'] >= $etapaOrigemOrdem) {
        throw new InvalidArgumentException('Voltar etapa só permite ir para uma etapa anterior na ordem.');
    }

    $agora = date('Y-m-d H:i:s');
    $avisos = [];

    $conn->begin_transaction();
    try {
        $responsavelId = resolverResponsavel($conn, $livro, $novoResponsavelId);

        fecharMovimentacaoAtual($conn, $livroId, $agora);
        $avisoIsbn = avisoIsbnVazioAoSairDaCatalogacao($etapaOrigemNome, $livro['isbn']);
        if ($avisoIsbn) $avisos[] = $avisoIsbn;

        $rodada = calcularRodada($conn, $livroId, (int)$etapaDestino['id']);
        $excedente = calcularExcedente($etapaDestino, $rodada);
        $novaMovId = abrirMovimentacao($conn, $livroId, (int)$etapaDestino['id'], $responsavelId, $registradoPorId, 'retrocesso', $rodada, $excedente, $agora);

        $etapaDestinoId2 = (int)$etapaDestino['id'];
        $stmtL = $conn->prepare('UPDATE livros SET etapa_atual_id = ?, responsavel_atual_id = ?, atualizado_em = ? WHERE id = ?');
        $stmtL->bind_param('iisi', $etapaDestinoId2, $responsavelId, $agora, $livroId);
        $stmtL->execute();

        registrarLog($conn, $registradoPorId, 'movimentacao.voltar', 'livro', $livroId, null, 'mov#' . $novaMovId);

        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }

    return [
        'livro_id' => $livroId,
        'movimentacao_id' => $novaMovId,
        'etapa_id' => (int)$etapaDestino['id'],
        'etapa_nome' => $etapaDestino['nome'],
        'rodada' => $rodada,
        'excedente' => $excedente,
        'avisos' => $avisos,
    ];
}

function concluirLivro(mysqli $conn, int $livroId, int $registradoPorId): array
{
    $livro = buscarLivroParaMovimentar($conn, $livroId);
    if ((int)$livro['etapa_atual_finaliza'] !== 1) {
        throw new InvalidArgumentException('A etapa atual não finaliza o livro.');
    }

    $etapaAtualNome = $livro['etapa_atual_nome'];
    $agora = date('Y-m-d H:i:s');
    $avisos = [];

    $conn->begin_transaction();
    try {
        $movFechada = fecharMovimentacaoAtual($conn, $livroId, $agora);
        $avisoIsbn = avisoIsbnVazioAoSairDaCatalogacao($etapaAtualNome, $livro['isbn']);
        if ($avisoIsbn) $avisos[] = $avisoIsbn;

        $stmtL = $conn->prepare("UPDATE livros SET status = 'concluido', atualizado_em = ? WHERE id = ?");
        $stmtL->bind_param('si', $agora, $livroId);
        $stmtL->execute();

        registrarLog($conn, $registradoPorId, 'movimentacao.concluir', 'livro', $livroId, null, 'mov#' . $movFechada['id']);

        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }

    return [
        'livro_id' => $livroId,
        'movimentacao_id' => null,
        'etapa_id' => (int)$livro['etapa_atual_id'],
        'etapa_nome' => $etapaAtualNome,
        'rodada' => null,
        'excedente' => false,
        'avisos' => $avisos,
    ];
}

function cancelarLivro(mysqli $conn, int $livroId, int $registradoPorId): array
{
    $livro = buscarLivroParaMovimentar($conn, $livroId);
    $etapaAtualNome = $livro['etapa_atual_nome'];
    $agora = date('Y-m-d H:i:s');

    $conn->begin_transaction();
    try {
        $movFechada = fecharMovimentacaoAtual($conn, $livroId, $agora);

        $stmtL = $conn->prepare("UPDATE livros SET status = 'cancelado', atualizado_em = ? WHERE id = ?");
        $stmtL->bind_param('si', $agora, $livroId);
        $stmtL->execute();

        registrarLog($conn, $registradoPorId, 'livro.cancelar', 'livro', $livroId, null, 'mov#' . $movFechada['id']);

        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }

    return [
        'livro_id' => $livroId,
        'etapa_id' => (int)$livro['etapa_atual_id'],
        'etapa_nome' => $etapaAtualNome,
    ];
}

function passarParaOutraMesa(mysqli $conn, int $livroId, int $novoResponsavelId, int $registradoPorId): array
{
    $livro = buscarLivroParaMovimentar($conn, $livroId);
    $responsavelAntigoId = (int)$livro['responsavel_atual_id'];

    if (!usuarioExiste($conn, $novoResponsavelId)) {
        throw new InvalidArgumentException('Usuário responsável inválido.');
    }

    $agora = date('Y-m-d H:i:s');
    $avisos = [];

    $conn->begin_transaction();
    try {
        $movFechada = fecharMovimentacaoAtual($conn, $livroId, $agora);
        // Mesma etapa, mesma rodada — trocar de mesa nunca conta como rodada nova.
        $rodada = (int)$movFechada['rodada'];
        $excedente = (bool)$movFechada['excedente'];
        $etapaId = (int)$movFechada['etapa_id'];

        $novaMovId = abrirMovimentacao($conn, $livroId, $etapaId, $novoResponsavelId, $registradoPorId, 'troca_responsavel', $rodada, $excedente, $agora);

        $stmtL = $conn->prepare('UPDATE livros SET responsavel_atual_id = ?, atualizado_em = ? WHERE id = ?');
        $stmtL->bind_param('isi', $novoResponsavelId, $agora, $livroId);
        $stmtL->execute();

        registrarLog($conn, $registradoPorId, 'movimentacao.trocar_responsavel', 'livro', $livroId, nomeUsuario($conn, $responsavelAntigoId), nomeUsuario($conn, $novoResponsavelId));

        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }

    return [
        'livro_id' => $livroId,
        'movimentacao_id' => $novaMovId,
        'etapa_id' => $etapaId,
        'etapa_nome' => $livro['etapa_atual_nome'],
        'rodada' => $rodada,
        'excedente' => $excedente,
        'avisos' => $avisos,
    ];
}

function buscarResgateAbertoPorLivro(mysqli $conn, int $livroId): ?array
{
    $stmt = $conn->prepare('SELECT * FROM resgates WHERE livro_id = ? AND data_conclusao IS NULL ORDER BY id DESC LIMIT 1');
    $stmt->bind_param('i', $livroId);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc() ?: null;
}

// Não usa buscarLivroParaMovimentar() de propósito: aquela função exige
// status 'em_andamento', e aqui é exatamente o contrário que se valida.
function iniciarResgate(mysqli $conn, int $livroId, int $registradoPorId): array
{
    $stmt = $conn->prepare('SELECT * FROM livros WHERE id = ?');
    $stmt->bind_param('i', $livroId);
    $stmt->execute();
    $livro = $stmt->get_result()->fetch_assoc();
    if (!$livro) {
        throw new InvalidArgumentException('Livro não encontrado.');
    }
    if ($livro['status'] !== 'em_andamento') {
        throw new InvalidArgumentException('Só é possível iniciar um resgate para um livro em andamento.');
    }

    $stmtMov = $conn->prepare('SELECT * FROM movimentacoes WHERE livro_id = ? AND data_saida IS NULL ORDER BY data_entrada DESC, id DESC LIMIT 1');
    $stmtMov->bind_param('i', $livroId);
    $stmtMov->execute();
    $movAtual = $stmtMov->get_result()->fetch_assoc();
    if (!$movAtual) {
        throw new InvalidArgumentException('Não há movimentação aberta para referenciar o resgate.');
    }

    $agora = date('Y-m-d H:i:s');
    $movRefId = (int)$movAtual['id'];

    $conn->begin_transaction();
    try {
        $stmtL = $conn->prepare("UPDATE livros SET status = 'extraviado', atualizado_em = ? WHERE id = ?");
        $stmtL->bind_param('si', $agora, $livroId);
        $stmtL->execute();

        $stmtR = $conn->prepare('INSERT INTO resgates (livro_id, movimentacao_referencia_id, data_abertura, registrado_por_id) VALUES (?, ?, ?, ?)');
        $stmtR->bind_param('iisi', $livroId, $movRefId, $agora, $registradoPorId);
        $stmtR->execute();
        $resgateId = $conn->insert_id;

        registrarLog($conn, $registradoPorId, 'resgate.iniciar', 'livro', $livroId, null, 'resgate#' . $resgateId);

        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }

    return [
        'resgate_id' => $resgateId,
        'livro_id' => $livroId,
        'movimentacao_referencia_id' => $movRefId,
    ];
}

function concluirResgate(mysqli $conn, int $livroId, int $novaEtapaId, int $novoResponsavelId, string $localEncontrado, int $registradoPorId): array
{
    $stmt = $conn->prepare('SELECT * FROM livros WHERE id = ?');
    $stmt->bind_param('i', $livroId);
    $stmt->execute();
    $livro = $stmt->get_result()->fetch_assoc();
    if (!$livro) {
        throw new InvalidArgumentException('Livro não encontrado.');
    }
    if ($livro['status'] !== 'extraviado') {
        throw new InvalidArgumentException('Este livro não está extraviado.');
    }

    $resgate = buscarResgateAbertoPorLivro($conn, $livroId);
    if (!$resgate) {
        throw new InvalidArgumentException('Não há resgate em aberto para este livro.');
    }

    $novaEtapa = buscarEtapa($conn, $novaEtapaId);
    if (!$novaEtapa || (int)$novaEtapa['ativa'] !== 1) {
        throw new InvalidArgumentException('Etapa informada inválida ou inativa.');
    }
    if (!usuarioExiste($conn, $novoResponsavelId)) {
        throw new InvalidArgumentException('Usuário responsável inválido.');
    }
    $localEncontrado = trim($localEncontrado);
    if ($localEncontrado === '') {
        throw new InvalidArgumentException('Informe onde o livro foi encontrado.');
    }

    $agora = date('Y-m-d H:i:s');
    $resgateId = (int)$resgate['id'];

    $conn->begin_transaction();
    try {
        // Fecha a movimentação que ficou aberta desde antes do extravio (se ainda estiver aberta).
        $stmtMovAberta = $conn->prepare('SELECT id FROM movimentacoes WHERE livro_id = ? AND data_saida IS NULL ORDER BY data_entrada DESC, id DESC LIMIT 1');
        $stmtMovAberta->bind_param('i', $livroId);
        $stmtMovAberta->execute();
        $movAberta = $stmtMovAberta->get_result()->fetch_assoc();
        if ($movAberta) {
            $movAbertaId = (int)$movAberta['id'];
            $stmtFechar = $conn->prepare('UPDATE movimentacoes SET data_saida = ? WHERE id = ?');
            $stmtFechar->bind_param('si', $agora, $movAbertaId);
            $stmtFechar->execute();
        }

        // A movimentação de resgate NÃO abre rodada nova: mantém a rodada atual da
        // etapa (quantidade de entradas avanco/repeticao/retrocesso já registradas
        // ali, sem somar +1 como calcularRodada() faria). Numa etapa nunca
        // visitada, não há "rodada atual" para manter — usa 1 como base.
        $rodada = max(contarEntradasAnteriores($conn, $livroId, $novaEtapaId), 1);
        $excedente = calcularExcedente($novaEtapa, $rodada);
        abrirMovimentacao($conn, $livroId, $novaEtapaId, $novoResponsavelId, $registradoPorId, 'resgate', $rodada, $excedente, $agora);

        $stmtL = $conn->prepare("UPDATE livros SET status = 'em_andamento', etapa_atual_id = ?, responsavel_atual_id = ?, atualizado_em = ? WHERE id = ?");
        $stmtL->bind_param('iisi', $novaEtapaId, $novoResponsavelId, $agora, $livroId);
        $stmtL->execute();

        $stmtR = $conn->prepare('UPDATE resgates SET data_conclusao = ?, local_encontrado = ? WHERE id = ?');
        $stmtR->bind_param('ssi', $agora, $localEncontrado, $resgateId);
        $stmtR->execute();

        registrarLog($conn, $registradoPorId, 'resgate.concluir', 'livro', $livroId, null, 'resgate#' . $resgateId);

        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }

    return [
        'livro_id' => $livroId,
        'etapa_id' => $novaEtapaId,
        'etapa_nome' => $novaEtapa['nome'],
        'rodada' => $rodada,
        'excedente' => $excedente,
    ];
}
