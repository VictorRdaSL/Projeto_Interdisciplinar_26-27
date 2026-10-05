<?php
function normalizarIsbn(string $isbn): string
{
    return preg_replace('/[^0-9Xx]/', '', $isbn) ?? '';
}

function isbnValido(string $isbn): bool
{
    $limpo = normalizarIsbn($isbn);
    if (strlen($limpo) !== 13 || !ctype_digit($limpo)) {
        return false;
    }
    $soma = 0;
    for ($i = 0; $i < 12; $i++) {
        $soma += (int)$limpo[$i] * ($i % 2 === 0 ? 1 : 3);
    }
    $digitoVerificador = (10 - ($soma % 10)) % 10;
    return $digitoVerificador === (int)$limpo[12];
}
