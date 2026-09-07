<?php
// Sem isso, o PHP usa o fuso do php.ini (frequentemente UTC ou Europe/Berlin),
// enquanto o MySQL usa o horário do sistema — perto da meia-noite os dois
// discordavam sobre qual é "hoje", quebrando filtros de período por data.
date_default_timezone_set('America/Sao_Paulo');

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$host = 'localhost';
$usuario = 'root';
$senha = '';
$banco = 'sistema_estoque';

try {
    $conn = new mysqli($host, $usuario, $senha, $banco);
    $conn->set_charset('utf8mb4');
} catch (mysqli_sql_exception $e) {
    die('<h2>Erro ao conectar ao MySQL</h2><p>Importe o arquivo <strong>banco.sql</strong> no phpMyAdmin e confirme se Apache e MySQL estão ligados no XAMPP.</p>');
}
