<?php
/**
 * Exportação CSV genérica, reaproveitável por qualquer relatório.
 * Envia os headers, grava BOM UTF-8 (necessário para acentos abrirem certo
 * no Excel), escreve o cabeçalho e as linhas, e termina a requisição.
 */
function exportarCsv(string $nomeArquivo, array $cabecalho, array $linhas): void
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $nomeArquivo . '"');

    $saida = fopen('php://output', 'w');
    fwrite($saida, "\xEF\xBB\xBF");
    fputcsv($saida, $cabecalho);
    foreach ($linhas as $linha) {
        fputcsv($saida, $linha);
    }
    fclose($saida);
    exit;
}
