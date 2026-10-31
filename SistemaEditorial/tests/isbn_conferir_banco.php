<?php
// Confere os ISBNs já gravados em livros.isbn contra a regra do Passo 20.7 (CLI).
//   php tests/isbn_conferir_banco.php            só relata (não altera nada)
//   php tests/isbn_conferir_banco.php --aplicar  também grava só os 13 dígitos nos ISBNs válidos que ainda tenham
//                                                traços/espaços. Nunca apaga livro nem mexe em ISBN inválido ou repetido.
require_once __DIR__ . '/_guarda.php'; // só linha de comando e só em desenvolvimento
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/isbn.php';

$aplicar = in_array('--aplicar', $argv, true);

$livros = $conn->query("SELECT id, titulo, isbn FROM livros WHERE isbn IS NOT NULL AND isbn <> '' ORDER BY id")->fetch_all(MYSQLI_ASSOC);
echo count($livros) . " livro(s) com ISBN.\n";

$porDigitos = [];
$invalidos = [];
$paraConverter = [];
foreach ($livros as $l) {
    $erro = erroIsbn($l['isbn']);
    if ($erro !== null) {
        $invalidos[] = [$l, $erro];
        continue;
    }
    $digitos = normalizarIsbn($l['isbn']);
    $porDigitos[$digitos][] = $l;
    if ($digitos !== $l['isbn']) {
        $paraConverter[$l['id']] = $digitos;
    }
}
$repetidos = array_filter($porDigitos, fn($lista) => count($lista) > 1);

echo "\nInválidos: " . count($invalidos) . "\n";
foreach ($invalidos as [$l, $erro]) {
    echo "  livro {$l['id']} \"{$l['titulo']}\": \"{$l['isbn']}\" — $erro\n";
}
echo "\nRepetidos (mesmos 13 dígitos): " . count($repetidos) . "\n";
foreach ($repetidos as $digitos => $lista) {
    echo "  $digitos: " . implode('; ', array_map(fn($l) => "livro {$l['id']} \"{$l['titulo']}\"", $lista)) . "\n";
}
echo "\nVálidos que ainda não estão só com dígitos: " . count($paraConverter) . "\n";
foreach ($paraConverter as $id => $digitos) {
    echo "  livro $id: vira $digitos\n";
}

if ($aplicar && $paraConverter) {
    $n = 0;
    $stmt = $conn->prepare('UPDATE livros SET isbn = ? WHERE id = ?');
    foreach ($paraConverter as $id => $digitos) {
        if (isset($repetidos[$digitos])) {
            echo "  livro $id ignorado (ISBN repetido)\n";
            continue;
        }
        $stmt->bind_param('si', $digitos, $id);
        $stmt->execute();
        $n++;
    }
    echo "\nConvertidos: $n\n";
} elseif ($paraConverter) {
    echo "\nNada foi alterado. Use --aplicar para gravar só os dígitos nos válidos.\n";
}
