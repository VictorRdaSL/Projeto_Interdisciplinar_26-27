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

// Nome exato semeado em seed_editorial.sql. Não há coluna de identificador
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

function avancarEtapa(mysqli $conn, int $livroId, int $etapaDestinoId, int $registradoPorId, ?int $etapaPuladaId = null): array
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

    $responsavelId = (int)$livro['responsavel_atual_id'];
    $agora = date('Y-m-d H:i:s');
    $avisos = [];

    $conn->begin_transaction();
    try {
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
        $stmtL = $conn->prepare('UPDATE livros SET etapa_atual_id = ?, atualizado_em = ? WHERE id = ?');
        $stmtL->bind_param('isi', $etapaDestinoId2, $agora, $livroId);
        $stmtL->execute();

        registrarLog($conn, $registradoPorId, 'movimentacao.avancar', 'livro', $livroId, $etapaOrigemNome, $etapaDestino['nome']);

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

function repetirEtapa(mysqli $conn, int $livroId, int $registradoPorId): array
{
    $livro = buscarLivroParaMovimentar($conn, $livroId);
    if ((int)$livro['etapa_atual_repetivel'] !== 1) {
        throw new InvalidArgumentException('Esta etapa não é repetível.');
    }

    $etapaAtualId = (int)$livro['etapa_atual_id'];
    $etapaAtualNome = $livro['etapa_atual_nome'];
    $responsavelId = (int)$livro['responsavel_atual_id'];
    $agora = date('Y-m-d H:i:s');
    $avisos = [];

    $conn->begin_transaction();
    try {
        fecharMovimentacaoAtual($conn, $livroId, $agora);
        $avisoIsbn = avisoIsbnVazioAoSairDaCatalogacao($etapaAtualNome, $livro['isbn']);
        if ($avisoIsbn) $avisos[] = $avisoIsbn;

        $etapaAtual = buscarEtapa($conn, $etapaAtualId);
        $rodada = calcularRodada($conn, $livroId, $etapaAtualId);
        $excedente = calcularExcedente($etapaAtual, $rodada);
        $novaMovId = abrirMovimentacao($conn, $livroId, $etapaAtualId, $responsavelId, $registradoPorId, 'repeticao', $rodada, $excedente, $agora);

        $stmtL = $conn->prepare('UPDATE livros SET atualizado_em = ? WHERE id = ?');
        $stmtL->bind_param('si', $agora, $livroId);
        $stmtL->execute();

        registrarLog($conn, $registradoPorId, 'movimentacao.repetir', 'livro', $livroId, null, $etapaAtualNome);

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

function voltarEtapa(mysqli $conn, int $livroId, int $etapaDestinoId, int $registradoPorId): array
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

    $responsavelId = (int)$livro['responsavel_atual_id'];
    $agora = date('Y-m-d H:i:s');
    $avisos = [];

    $conn->begin_transaction();
    try {
        fecharMovimentacaoAtual($conn, $livroId, $agora);
        $avisoIsbn = avisoIsbnVazioAoSairDaCatalogacao($etapaOrigemNome, $livro['isbn']);
        if ($avisoIsbn) $avisos[] = $avisoIsbn;

        $rodada = calcularRodada($conn, $livroId, (int)$etapaDestino['id']);
        $excedente = calcularExcedente($etapaDestino, $rodada);
        $novaMovId = abrirMovimentacao($conn, $livroId, (int)$etapaDestino['id'], $responsavelId, $registradoPorId, 'retrocesso', $rodada, $excedente, $agora);

        $etapaDestinoId2 = (int)$etapaDestino['id'];
        $stmtL = $conn->prepare('UPDATE livros SET etapa_atual_id = ?, atualizado_em = ? WHERE id = ?');
        $stmtL->bind_param('isi', $etapaDestinoId2, $agora, $livroId);
        $stmtL->execute();

        registrarLog($conn, $registradoPorId, 'movimentacao.voltar', 'livro', $livroId, $etapaOrigemNome, $etapaDestino['nome']);

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
        fecharMovimentacaoAtual($conn, $livroId, $agora);
        $avisoIsbn = avisoIsbnVazioAoSairDaCatalogacao($etapaAtualNome, $livro['isbn']);
        if ($avisoIsbn) $avisos[] = $avisoIsbn;

        $stmtL = $conn->prepare("UPDATE livros SET status = 'concluido', atualizado_em = ? WHERE id = ?");
        $stmtL->bind_param('si', $agora, $livroId);
        $stmtL->execute();

        registrarLog($conn, $registradoPorId, 'movimentacao.concluir', 'livro', $livroId, $etapaAtualNome, 'concluido');

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
