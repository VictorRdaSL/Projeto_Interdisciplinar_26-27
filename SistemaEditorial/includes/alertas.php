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

    // Livro + etapa + mesa + movimentação aberta vêm da view (movimentacao_id NULL = sem
    // movimentação aberta, o que o JOIN interno da consulta antiga excluía).
    $stmt = $conn->prepare("
        SELECT v.livro_id AS id, v.titulo, v.etapa_nome, v.etapa_dias_limite,
               v.rodadas_incluidas, v.mesa_nome AS responsavel_nome,
               v.rodada, v.excedente, v.etapa_desde AS data_entrada
        FROM vw_livros_situacao_atual v
        WHERE v.movimentacao_id IS NOT NULL AND v.status NOT IN ('concluido', 'cancelado')
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
