<?php
$configPath = __DIR__ . '/config.php';
if (!file_exists($configPath)) {
    die('<h2>Configuração ausente</h2><p>Copie <code>config/config.example.php</code> para <code>config/config.php</code> e ajuste os valores antes de continuar.</p>');
}
$config = require $configPath;

// Sem isso, o PHP usa o fuso do php.ini (frequentemente UTC ou Europe/Berlin),
// enquanto o MySQL usa o horário do sistema — perto da meia-noite os dois
// discordavam sobre qual é "hoje", quebrando filtros de período por data.
date_default_timezone_set('America/Sao_Paulo');

$producao = ($config['ambiente'] ?? 'desenvolvimento') === 'producao';

error_reporting(E_ALL);
if ($producao) {
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    ini_set('error_log', __DIR__ . '/../logs/app.log');
} else {
    ini_set('display_errors', '1');
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = $config['db'];

try {
    $conn = new mysqli($db['host'], $db['usuario'], $db['senha'], $db['nome'], (int)$db['porta']);
    $conn->set_charset($db['charset']);
} catch (mysqli_sql_exception $e) {
    if ($producao) {
        error_log('Erro ao conectar ao MySQL: ' . $e->getMessage());
        die('<h2>Erro ao conectar ao sistema</h2><p>Tente novamente mais tarde.</p>');
    }
    die('<h2>Erro ao conectar ao MySQL</h2><p>Importe o arquivo <strong>banco.sql</strong> no phpMyAdmin, confirme se Apache e MySQL estão ligados no XAMPP, e se <code>config/config.php</code> existe e está correto.</p>');
}
