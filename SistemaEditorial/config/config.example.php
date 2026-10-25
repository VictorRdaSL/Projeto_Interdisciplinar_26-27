<?php
// Copie este arquivo para "config.php" e ajuste os valores para o seu ambiente.
// config.php é ignorado pelo Git — nunca commitar credenciais reais.

return [
    'ambiente' => 'desenvolvimento', // 'desenvolvimento' ou 'producao'
    // Caminho do sistema na URL, sem barra no final: '/Projeto_Interdisciplinar_26-27/SistemaEditorial' no XAMPP,
    // '' se ficar na raiz do domínio, '/sistema' se ficar numa subpasta. Usado nos caminhos de CSS, JS, imagens e menu.
    'base_url' => '/Projeto_Interdisciplinar_26-27/SistemaEditorial',
    'db' => [
        'host'    => 'localhost',
        'porta'   => 3306,
        'nome'    => 'waresys',
        'usuario' => 'root',
        'senha'   => '',
        'charset' => 'utf8mb4',
    ],
];
