<?php
// ISBN-13. No banco o ISBN fica só com os 13 dígitos; os traços existem apenas na exibição (formatarIsbn()).

/** Tira tudo que não for número (traços, espaços, pontos, letras). */
function normalizarIsbn(string $isbn): string
{
    return preg_replace('/\D/', '', $isbn) ?? '';
}

/**
 * Mensagem de erro do ISBN digitado (com ou sem traços), ou null se estiver certo.
 * Campo em branco não é erro: o ISBN é opcional (quem exige é o chamador). Texto sem nenhum número
 * ("abc", "---") é erro, para não virar "sem ISBN" em silêncio.
 */
function erroIsbn(string $isbn): ?string
{
    if (trim($isbn) === '') {
        return null;
    }
    $digitos = normalizarIsbn($isbn);
    if (strlen($digitos) !== 13) {
        return 'O ISBN precisa ter 13 números.';
    }
    if (!str_starts_with($digitos, '978') && !str_starts_with($digitos, '979')) {
        return 'O ISBN precisa começar com 978 ou 979.';
    }
    $soma = 0;
    for ($i = 0; $i < 12; $i++) {
        $soma += (int)$digitos[$i] * ($i % 2 === 0 ? 1 : 3);
    }
    if ((10 - ($soma % 10)) % 10 !== (int)$digitos[12]) {
        return 'Dígito verificador não confere.';
    }
    return null;
}

function isbnValido(string $isbn): bool
{
    return trim($isbn) !== '' && erroIsbn($isbn) === null;
}

/** Tamanho da parte que a tabela oficial reserva para $numero (7 dígitos) dentro das $faixas, ou null. */
function tamanhoPelaFaixa(array $faixas, int $numero): ?int
{
    foreach ($faixas as [$inicio, $fim, $tamanho]) {
        if ($numero >= $inicio && $numero <= $fim) {
            return $tamanho > 0 ? $tamanho : null; // 0 = faixa ainda não distribuída
        }
    }
    return null;
}

/**
 * ISBN com traços (978-85-7522-123-4), pela tabela oficial da International ISBN Agency
 * (includes/isbn_faixas.php, gerada por tests/gerar_faixas_isbn.php).
 * Se o ISBN for inválido ou não cair numa faixa conhecida, devolve só os 13 dígitos (ou o texto como veio,
 * se nem 13 dígitos tiver): nunca inventa traços.
 */
function formatarIsbn(?string $isbn): string
{
    static $tabela = null;

    $isbn = $isbn ?? '';
    $digitos = normalizarIsbn($isbn);
    if ($digitos === '' || erroIsbn($digitos) !== null) {
        return $isbn;
    }
    if ($tabela === null) {
        $tabela = require __DIR__ . '/isbn_faixas.php';
    }

    $prefixo = substr($digitos, 0, 3);                      // 978 ou 979
    $corpo = substr($digitos, 3, 9);                        // 9 dígitos entre o prefixo e o verificador
    $tamGrupo = tamanhoPelaFaixa($tabela['prefixos'][$prefixo] ?? [], (int)str_pad(substr($corpo, 0, 7), 7, '0'));
    if ($tamGrupo === null) {
        return $digitos;
    }

    $grupo = substr($corpo, 0, $tamGrupo);
    $restante = substr($corpo, $tamGrupo);                  // editora + título
    $tamEditora = tamanhoPelaFaixa($tabela['grupos'][$prefixo . '-' . $grupo] ?? [], (int)str_pad(substr($restante, 0, 7), 7, '0'));
    if ($tamEditora === null || $tamEditora >= strlen($restante)) {
        return $digitos;                                    // sem faixa, ou sobraria nada para o número do título
    }

    return $prefixo . '-' . $grupo . '-' . substr($restante, 0, $tamEditora) . '-' . substr($restante, $tamEditora) . '-' . $digitos[12];
}
