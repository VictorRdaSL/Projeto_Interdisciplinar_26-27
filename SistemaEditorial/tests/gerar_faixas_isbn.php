<?php
// Gera includes/isbn_faixas.php a partir do RangeMessage.xml da International ISBN Agency.
// Uso (CLI): php tests/gerar_faixas_isbn.php caminho/RangeMessage.xml
// O XML vem de https://www.isbn-international.org/export_rangemessage.xml e muda algumas vezes por ano;
// quando atualizar, baixe de novo, rode este script e confira com tests/isbn_teste.php.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

$origem = $argv[1] ?? '';
if ($origem === '' || !is_file($origem)) {
    fwrite(STDERR, "Informe o caminho do RangeMessage.xml.\n");
    exit(1);
}

$xml = simplexml_load_file($origem);
if ($xml === false) {
    fwrite(STDERR, "XML inválido.\n");
    exit(1);
}

// Cada regra vira [início, fim, tamanho]: início e fim são os 7 dígitos depois do prefixo (ou do grupo)
// como número inteiro; tamanho é quantos dígitos pertencem ao grupo (ou à editora). Tamanho 0 = faixa não usada.
function regras(SimpleXMLElement $no): array
{
    $saida = [];
    foreach ($no->Rules->Rule as $r) {
        [$ini, $fim] = explode('-', trim((string)$r->Range));
        $saida[] = [(int)$ini, (int)$fim, (int)$r->Length];
    }
    return $saida;
}

$prefixos = [];
foreach ($xml->{'EAN.UCCPrefixes'}->{'EAN.UCC'} as $p) {
    $prefixos[trim((string)$p->Prefix)] = regras($p);
}

$grupos = [];
foreach ($xml->RegistrationGroups->Group as $g) {
    $grupos[trim((string)$g->Prefix)] = regras($g);
}

$data = trim((string)$xml->MessageDate);
$serie = trim((string)$xml->MessageSerialNumber);

function emitir(array $conjunto): string
{
    $linhas = '';
    foreach ($conjunto as $chave => $faixas) {
        $itens = array_map(fn($f) => '[' . $f[0] . ', ' . $f[1] . ', ' . $f[2] . ']', $faixas);
        $linhas .= "        '" . $chave . "' => [" . implode(', ', $itens) . "],\n";
    }
    return $linhas;
}

$php = "<?php\n"
    . "// ARQUIVO GERADO por tests/gerar_faixas_isbn.php — não edite à mão.\n"
    . "// Fonte: International ISBN Agency (RangeMessage.xml), mensagem de $data, série $serie.\n"
    . "// 'prefixos': faixas que dizem quantos dígitos tem o grupo de registro (idioma/país) depois de 978 ou 979.\n"
    . "// 'grupos': por grupo (ex.: '978-85'), faixas que dizem quantos dígitos tem o prefixo da editora.\n"
    . "// Cada faixa é [início, fim, tamanho] sobre os 7 dígitos seguintes; tamanho 0 = faixa ainda não distribuída.\n"
    . "return [\n"
    . "    'data' => '" . addslashes($data) . "',\n"
    . "    'prefixos' => [\n" . emitir($prefixos) . "    ],\n"
    . "    'grupos' => [\n" . emitir($grupos) . "    ],\n"
    . "];\n";

$destino = __DIR__ . '/../includes/isbn_faixas.php';
file_put_contents($destino, $php);
echo 'Gerado: ' . realpath($destino) . ' (' . count($grupos) . " grupos, $data)\n";
