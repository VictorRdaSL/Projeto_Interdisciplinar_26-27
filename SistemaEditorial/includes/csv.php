<?php
/**
 * Exportação CSV genérica, reaproveitável por qualquer relatório.
 * Envia os headers, grava BOM UTF-8 (necessário para acentos abrirem certo
 * no Excel), escreve o cabeçalho e as linhas, e termina a requisição.
 *
 * Delimitador é ";" (o Excel em português do Brasil usa ";" como separador de
 * lista — com "," tudo cairia numa coluna só). Campos com ";", aspas ou quebra
 * de linha são colocados entre aspas pelo fputcsv, então texto livre (ex.:
 * observações) não desloca colunas.
 */
function exportarCsv(string $nomeArquivo, array $cabecalho, array $linhas): void
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $nomeArquivo . '"');

    $saida = fopen('php://output', 'w');
    fwrite($saida, "\xEF\xBB\xBF");
    // escape_char vazio: o padrão "\" do PHP corrompe campos terminados em barra invertida.
    fputcsv($saida, array_map('neutralizarCelulaCsv', $cabecalho), ';', '"', '');
    foreach ($linhas as $linha) {
        fputcsv($saida, array_map('neutralizarCelulaCsv', $linha), ';', '"', '');
    }
    fclose($saida);
    exit;
}

// Texto que começa com = + - @ (ou tab/CR) vira fórmula ao abrir no Excel;
// o apóstrofo inicial força a célula a ser tratada como texto.
function neutralizarCelulaCsv($valor)
{
    if (is_string($valor) && $valor !== '' && strpos("=+-@\t\r", $valor[0]) !== false) {
        return "'" . $valor;
    }
    return $valor;
}
