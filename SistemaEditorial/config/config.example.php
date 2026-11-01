<?php
// Modelo de configuração: só valores de EXEMPLO. Nunca coloque credenciais reais neste arquivo (ele vai para o Git).
//
// Desenvolvimento (XAMPP): copie para "config.php" nesta pasta e ajuste. config.php é ignorado pelo Git.
// Produção: crie o arquivo com os valores reais FORA da pasta pública do servidor (na Hostinger, a pasta do domínio,
// acima do public_html) com o nome "waresys-config.php", ou aponte para ele com a variável de ambiente WARESYS_CONFIG.
// A ordem de busca está em includes/configuracao.php e o passo a passo, no DEPLOY.md.

return [
    'ambiente' => 'desenvolvimento', // 'desenvolvimento' ou 'producao' (em produção os erros não aparecem na tela)

    // Caminho do sistema na URL, sem barra no final: '/Projeto_Interdisciplinar_26-27/SistemaEditorial' no XAMPP,
    // '' se ficar na raiz do domínio, '/sistema' se ficar numa subpasta. Usado nos caminhos de CSS, JS, imagens e menu.
    'base_url' => '/Projeto_Interdisciplinar_26-27/SistemaEditorial',

    // Opcional. Arquivo onde os erros são gravados em produção. Prefira um caminho FORA da pasta pública, por exemplo
    // '/home/SEU_USUARIO/domains/SEU_DOMINIO/waresys-logs/app.log' (a pasta precisa existir e ter permissão de escrita).
    // Sem esta chave, vale SistemaEditorial/logs/app.log.
    // 'log_erros' => '/caminho/fora/da/pasta/publica/app.log',

    // Opcional. true SÓ se a hospedagem coloca um proxy na frente do site e o IP real chega em cabeçalho
    // (CF-Connecting-IP ou X-Forwarded-For). Vale para o limite de tentativas de login e para detectar HTTPS.
    // Com true, um cabeçalho forjado por quem acessa direto passa a ser aceito: use só se o proxy for confiável.
    'confiar_proxy' => false,

    'db' => [
        'host'    => 'localhost',
        'porta'   => 3306,
        'nome'    => 'waresys',          // na hospedagem o nome real tem prefixo, ex.: u123456789_waresys
        'usuario' => 'USUARIO_DO_BANCO', // no XAMPP, 'root'; na hospedagem, o usuário criado só para o sistema
        'senha'   => 'SENHA_DO_BANCO',   // no XAMPP, '' (vazia); na hospedagem, uma senha forte só do sistema
        'charset' => 'utf8mb4',
    ],
];
