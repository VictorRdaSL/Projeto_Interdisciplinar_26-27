<?php
/**
 * Cálculo de rodada e excedente, isolado do restante do fluxo.
 */

function contarEntradasAnteriores(mysqli $conn, int $livroId, int $etapaId): int
{
    $stmt = $conn->prepare("SELECT COUNT(*) total FROM movimentacoes WHERE livro_id = ? AND etapa_id = ? AND tipo IN ('avanco','repeticao','retrocesso')");
    $stmt->bind_param('ii', $livroId, $etapaId);
    $stmt->execute();
    return (int)$stmt->get_result()->fetch_assoc()['total'];
}

function calcularRodada(mysqli $conn, int $livroId, int $etapaId): int
{
    return contarEntradasAnteriores($conn, $livroId, $etapaId) + 1;
}

function calcularExcedente(array $etapa, int $rodada): bool
{
    return $etapa['rodadas_incluidas'] !== null && $rodada > (int)$etapa['rodadas_incluidas'];
}
