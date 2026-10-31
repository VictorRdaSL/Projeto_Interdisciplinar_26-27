<?php
// Teste de includes/isbn.php (CLI). Uso: php tests/isbn_teste.php
// Rode de novo depois de atualizar includes/isbn_faixas.php.
require_once __DIR__ . '/_guarda.php'; // só linha de comando e só em desenvolvimento
require_once __DIR__ . '/../includes/isbn.php';

$falhas = 0;
function conferir(string $nome, $obtido, $esperado): void
{
    global $falhas;
    if ($obtido === $esperado) {
        echo "OK    $nome\n";
    } else {
        $falhas++;
        echo "FALHA $nome\n      obtido:   " . var_export($obtido, true) . "\n      esperado: " . var_export($esperado, true) . "\n";
    }
}

// Dígito verificador de um ISBN-13 a partir dos 12 primeiros dígitos.
function comVerificador(string $doze): string
{
    $s = 0;
    for ($i = 0; $i < 12; $i++) $s += (int)$doze[$i] * ($i % 2 === 0 ? 1 : 3);
    return $doze . ((10 - $s % 10) % 10);
}

// normalizar
conferir('normaliza traços e espaços', normalizarIsbn('978-85-7522-123-4 '), '9788575221234');
conferir('normaliza letras e pontos', normalizarIsbn('ISBN 978.85.7522.123.4'), '9788575221234');

// erros
$ok = comVerificador('978857522123');
conferir('vazio não é erro', erroIsbn(''), null);
conferir('só espaços não é erro (campo em branco)', erroIsbn('   '), null);
conferir('só traços é erro (não vira "sem ISBN" em silêncio)', erroIsbn(' - '), 'O ISBN precisa ter 13 números.');
conferir('só letras é erro', erroIsbn('abcdef'), 'O ISBN precisa ter 13 números.');
conferir('válido sem traços', erroIsbn($ok), null);
conferir('válido com traços', erroIsbn(substr($ok, 0, 3) . '-' . substr($ok, 3)), null);
conferir('12 dígitos', erroIsbn(substr($ok, 0, 12)), 'O ISBN precisa ter 13 números.');
conferir('14 dígitos', erroIsbn($ok . '0'), 'O ISBN precisa ter 13 números.');
conferir('ISBN-10 com X', erroIsbn('855201234X'), 'O ISBN precisa ter 13 números.');
conferir('prefixo 977 é aceito (prefixo não é conferido)', erroIsbn(comVerificador('977857522123')), null);
conferir('prefixo 123 é aceito', erroIsbn('1234567890123'), null);
conferir('1111111111111 é aceito', erroIsbn('1111111111111'), null);
conferir('prefixo 123 sem faixa: só os dígitos na exibição', formatarIsbn('1234567890123'), '1234567890123');
$errado = substr($ok, 0, 12) . (((int)$ok[12] + 1) % 10);
conferir('dígito verificador errado é aceito (não é conferido)', erroIsbn($errado), null);
conferir('dígito verificador errado ganha traços na exibição', formatarIsbn($errado), formatarIsbn(normalizarIsbn($errado)));
conferir('979 válido', erroIsbn(comVerificador('979109999999')), null);
conferir('isbnValido vazio', isbnValido(''), false);
conferir('isbnValido ok', isbnValido($ok), true);

// formatação — ISBNs reais conhecidos
conferir('Brasil 978-85 (editora de 3 dígitos)', formatarIsbn('9788532530806'), '978-85-325-3080-6');
conferir('Brasil com traços na entrada', formatarIsbn('978-85-325-3080-6'), '978-85-325-3080-6');
conferir('Brasil 978-85 (editora de 4 dígitos)', formatarIsbn(comVerificador('978857522123')), '978-85-7522-123-' . substr(comVerificador('978857522123'), 12, 1));
conferir('Inglês 978-0 (Oxford)', formatarIsbn('9780199535569'), '978-0-19-953556-9');
conferir('Inglês 978-1 (Penguin)', formatarIsbn('9781847941831'), '978-1-84794-183-1');
conferir('Alemanha 978-3 (Springer)', formatarIsbn('9783642272639'), '978-3-642-27263-9');
conferir('França 979-10', formatarIsbn('9791090388000'), '979-10-90388-00-0');
conferir('Portugal 978-972', formatarIsbn('9789722115100'), '978-972-21-1510-0');
conferir('Japão 978-4 (grupo de 1 dígito, editora curta)', formatarIsbn('9784101010014'), '978-4-10-101001-4');

// nunca inventa
conferir('nulo', formatarIsbn(null), '');
conferir('vazio', formatarIsbn(''), '');
conferir('inválido volta como veio', formatarIsbn('123'), '123');
conferir('dígito errado também ganha traços', formatarIsbn($errado), '978-85-7522-123-' . $errado[12]);
// 979 sem faixa distribuída: faixa 979-0 não existe na tabela
conferir('979 sem faixa devolve os 13 dígitos', formatarIsbn(comVerificador('979000000000')), comVerificador('979000000000'));
// faixa de editora ainda não distribuída (tamanho 0): 978-85, editora 9990000+
$semFaixa = comVerificador('978859999999');
$fmt = formatarIsbn($semFaixa);
conferir('faixa de editora não distribuída: sem traços inventados ou formato coerente', $fmt === $semFaixa || preg_match('/^978-85-\d+-\d+-\d$/', $fmt) === 1, true);

// qualquer ISBN válido: ao remover os traços volta ao original, e há sempre 5 partes ou nenhuma
$tabela = require __DIR__ . '/../includes/isbn_faixas.php';
mt_srand(7);
$formatados = 0;
$semFormato = 0;
$quebrados = 0;
for ($i = 0; $i < 20000; $i++) {
    $doze = (mt_rand(0, 1) ? '978' : '979') . str_pad((string)mt_rand(0, 999999999), 9, '0', STR_PAD_LEFT);
    $isbn = comVerificador($doze);
    $f = formatarIsbn($isbn);
    if ($f === $isbn) { $semFormato++; continue; }
    $formatados++;
    if (normalizarIsbn($f) !== $isbn || preg_match('/^97[89]-\d+-\d+-\d+-\d$/', $f) !== 1) $quebrados++;
}
conferir("20000 ISBNs aleatórios: nenhum formato quebrado ($formatados formatados, $semFormato sem faixa)", $quebrados, 0);

echo $falhas === 0 ? "\nTudo certo.\n" : "\n$falhas falha(s).\n";
exit($falhas === 0 ? 0 : 1);
