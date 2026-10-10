<?php
/**
 * Lógica de "qual é a próxima etapa", isolada do restante do fluxo porque o
 * encadeamento pode deixar de ser puramente sequencial no futuro.
 */

function buscarEtapa(mysqli $conn, int $etapaId): ?array
{
    $stmt = $conn->prepare('SELECT * FROM etapas WHERE id = ?');
    $stmt->bind_param('i', $etapaId);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc() ?: null;
}

function proximaEtapaAtiva(mysqli $conn, int $ordemAtual): ?array
{
    $stmt = $conn->prepare('SELECT * FROM etapas WHERE ativa = 1 AND ordem > ? ORDER BY ordem ASC LIMIT 1');
    $stmt->bind_param('i', $ordemAtual);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc() ?: null;
}

function etapaJaConcluidaParaLivro(mysqli $conn, int $livroId, int $etapaId): bool
{
    $stmt = $conn->prepare('SELECT 1 FROM movimentacoes WHERE livro_id = ? AND etapa_id = ? AND data_saida IS NOT NULL LIMIT 1');
    $stmt->bind_param('ii', $livroId, $etapaId);
    $stmt->execute();
    return (bool)$stmt->get_result()->fetch_assoc();
}

function primeiraEtapaNaoConcluida(mysqli $conn, int $livroId, int $aPartirDeOrdem): ?array
{
    $stmt = $conn->prepare('SELECT * FROM etapas WHERE ativa = 1 AND ordem > ? ORDER BY ordem ASC');
    $stmt->bind_param('i', $aPartirDeOrdem);
    $stmt->execute();
    $r = $stmt->get_result();
    while ($etapa = $r->fetch_assoc()) {
        if (!etapaJaConcluidaParaLivro($conn, $livroId, (int)$etapa['id'])) {
            return $etapa;
        }
    }
    return null;
}

function etapasAnterioresAtivas(mysqli $conn, int $ordemAtual): array
{
    $stmt = $conn->prepare('SELECT * FROM etapas WHERE ativa = 1 AND ordem < ? ORDER BY ordem ASC');
    $stmt->bind_param('i', $ordemAtual);
    $stmt->execute();
    $etapas = [];
    $r = $stmt->get_result();
    while ($row = $r->fetch_assoc()) $etapas[] = $row;
    return $etapas;
}
