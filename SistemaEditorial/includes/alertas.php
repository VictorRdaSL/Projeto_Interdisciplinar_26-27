<?php
/**
 * Alerta de livro parado — livro cuja movimentação atual está aberta há
 * mais dias do que o limite da etapa (etapas.dias_limite) ou, quando esse
 * limite é NULL, do que o padrão global (configuracoes.dias_limite_livro_parado).
 */

function buscarDiasLimiteGlobal(mysqli $conn): int
{
    $stmt = $conn->prepare("SELECT valor FROM configuracoes WHERE chave = 'dias_limite_livro_parado'");
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return $row ? (int)$row['valor'] : 15;
}

function buscarLivrosParados(mysqli $conn): array
{
    $limiteGlobal = buscarDiasLimiteGlobal($conn);

    // Movimentação aberta é 1:1 por livro — join simples, sem agregação.
    $stmt = $conn->prepare("
        SELECT l.id, l.titulo, e.nome AS etapa_nome, e.dias_limite AS etapa_dias_limite,
               e.rodadas_incluidas, u.nome AS responsavel_nome,
               m.rodada, m.excedente, m.data_entrada
        FROM livros l
        JOIN etapas e ON e.id = l.etapa_atual_id
        LEFT JOIN usuarios u ON u.id = l.responsavel_atual_id
        JOIN movimentacoes m ON m.livro_id = l.id AND m.data_saida IS NULL
        WHERE l.status NOT IN ('concluido', 'cancelado')
    ");
    $stmt->execute();
    $r = $stmt->get_result();

    // Calculado em PHP (DateTime::diff), não com DATEDIFF/NOW() do MySQL —
    // mesma cautela de fuso já documentada em outras telas.
    $agora = new DateTime();
    $parados = [];
    while ($row = $r->fetch_assoc()) {
        $limite = $row['etapa_dias_limite'] !== null ? (int)$row['etapa_dias_limite'] : $limiteGlobal;
        $diasParado = $agora->diff(new DateTime($row['data_entrada']))->days;
        if ($diasParado > $limite) {
            $row['dias_parado'] = $diasParado;
            $row['limite_aplicado'] = $limite;
            $parados[] = $row;
        }
    }

    usort($parados, fn($a, $b) => $b['dias_parado'] <=> $a['dias_parado']);
    return $parados;
}
