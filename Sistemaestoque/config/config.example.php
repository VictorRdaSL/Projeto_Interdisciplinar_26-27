<?php
// Copie este arquivo para "config.php" e ajuste os valores para o seu ambiente.
// config.php é ignorado pelo Git — nunca commitar credenciais reais.

return [
    'ambiente' => 'desenvolvimento', // 'desenvolvimento' ou 'producao'
    'db' => [
        'host'    => 'localhost',
        'porta'   => 3306,
        'nome'    => 'sistema_estoque',
        'usuario' => 'root',
        'senha'   => '',
        'charset' => 'utf8mb4',
    ],
];
