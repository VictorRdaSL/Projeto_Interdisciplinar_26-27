<?php
// Trava comum dos scripts de tests/ (incluída no TOPO de cada um, antes de qualquer outra coisa).
// Eles criam e apagam dados (fluxo_completo.php), leem o banco e podem gravar nele (isbn_conferir_banco.php --aplicar)
// ou reescrevem arquivos do sistema (gerar_faixas_isbn.php). Por isso:
//   1) só rodam pela linha de comando (pelo navegador tests/.htaccess já dá 403; aqui é a segunda barreira);
//   2) só rodam com 'ambiente' => 'desenvolvimento' em config/config.php. Qualquer outra coisa — 'producao', valor
//      desconhecido ou config.php ausente — recusa, para nunca executar por engano num servidor publicado.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

$configDoGuarda = @include __DIR__ . '/../config/config.php';
if (!is_array($configDoGuarda) || ($configDoGuarda['ambiente'] ?? '') !== 'desenvolvimento') {
    fwrite(STDERR, "Este script só pode ser executado em ambiente de desenvolvimento (config/config.php: 'ambiente' => 'desenvolvimento').\n");
    exit(1);
}
unset($configDoGuarda);
