<?php
/**
 * Cabeçalho das telas novas (design system WareSys): <head>, barra móvel, sidebar com o menu real e a abertura
 * de <main class="ws-principal">. Quem fecha tudo é o includes/rodape.php.
 *
 * Contrato da tela (antes do require):
 *   - já carregou o auth_check.php (sessão, conexão, login);
 *   - $tituloPagina (opcional): texto do <title>, vira "{título} · WareSys"; sem ele, "WareSys";
 *   - $paginaAtual  (opcional): painel | minha_mesa | livros | pessoas | relatorios | configuracoes;
 *                    marca aria-current="page" no item do menu; valor ausente ou desconhecido = nenhum item marcado.
 * Depois do require, a tela escreve o próprio <header class="ws-topo"> e <div class="ws-conteudo">.
 *
 * Caminhos de CSS, JS e links sempre pela constante BASE_URL (config.php → 'base_url'); fontes e ícones locais.
 * Variáveis internas levam o prefixo $ws_ para não esbarrar nas da tela.
 */
require_once __DIR__ . '/formatacao.php';
require_once __DIR__ . '/permissoes.php';
require_once __DIR__ . '/tema.php';
if (!defined('BASE_URL')) {
    require_once __DIR__ . '/../config/database.php';
}

$ws_base = BASE_URL;
$ws_titulo = (isset($tituloPagina) && is_string($tituloPagina) && trim($tituloPagina) !== '') ? trim($tituloPagina) . ' · WareSys' : 'WareSys';
$ws_pagina = (isset($paginaAtual) && is_string($paginaAtual)) ? $paginaAtual : '';

// Menu: [chave da página, arquivo, rótulo, ícone Lucide, permissão exigida (null = todos os perfis)].
// Hoje nenhum item exige permissão; um item restrito usa a mesma checagem das telas (usuarioTemPermissao).
$ws_menu = [
    ['painel',        'index.php',         'Painel',        'layout-dashboard', null],
    ['minha_mesa',    'minha_mesa.php',    'Minha mesa',    'inbox',            null],
    ['livros',        'livros.php',        'Livros',        'book-open',        null],
    ['pessoas',       'pessoas.php',       'Pessoas',       'users',            null],
    ['relatorios',    'relatorios.php',    'Relatórios',    'file-text',        null],
    ['configuracoes', 'configuracoes.php', 'Configurações', 'settings',         null],
];

// Usuário logado: nome, iniciais (1ª letra do primeiro e do último nome; nome único = 2 primeiras letras) e perfil.
$ws_nome = isset($_SESSION['usuario_nome']) && is_string($_SESSION['usuario_nome']) ? trim($_SESSION['usuario_nome']) : '';
$ws_iniciais = iniciaisDoNome($ws_nome);
$ws_perfil = nomePapel(papelAtual());
?>
<!DOCTYPE html>
<html lang="pt-BR" data-theme="<?= escaparHtml(temaDoUsuario()) ?>">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= escaparHtml($ws_titulo) ?></title>
  <!-- Design system WareSys: fonte Inter local primeiro, depois os 4 CSS nesta ordem -->
  <link rel="stylesheet" href="<?= escaparHtml($ws_base) ?>/design-system/css/fontes.css">
  <link rel="stylesheet" href="<?= escaparHtml($ws_base) ?>/design-system/css/tokens.css">
  <link rel="stylesheet" href="<?= escaparHtml($ws_base) ?>/design-system/css/base.css">
  <link rel="stylesheet" href="<?= escaparHtml($ws_base) ?>/design-system/css/layout.css">
  <link rel="stylesheet" href="<?= escaparHtml($ws_base) ?>/design-system/css/components.css">
</head>
<body>
  <a class="ws-pular" href="#conteudo">Pular para o conteúdo</a>

  <div class="ws-app">

    <!-- Barra do celular/tablet (abaixo de 1024px). O link funciona sem JS (:target). -->
    <header class="ws-barra-movel">
      <a class="ws-btn ws-btn--secundario ws-btn--icone ws-btn--peq" href="#ws-sidebar" data-ws-menu-abrir aria-label="Abrir menu">
        <i data-lucide="menu"></i>
      </a>
      <a class="ws-logo ws-logo--assinatura ws-logo--peq" href="<?= escaparHtml($ws_base) ?>/index.php" aria-label="WareSys, ir para o Painel">
        <span class="ws-logo__simbolo ws-logo__simbolo--reservado" aria-hidden="true"></span>
        <span class="ws-logo__wordmark">WareSys</span>
      </a>
    </header>

    <aside class="ws-sidebar" id="ws-sidebar" aria-label="Menu principal">
      <div class="ws-sidebar__marca">
        <a class="ws-logo ws-logo--assinatura" href="<?= escaparHtml($ws_base) ?>/index.php" aria-label="WareSys, ir para o Painel">
          <span class="ws-logo__simbolo ws-logo__simbolo--reservado" aria-hidden="true"></span>
          <span class="ws-logo__wordmark">WareSys</span>
        </a>
        <a class="ws-btn ws-btn--icone ws-btn--peq ws-sidebar__fechar" href="#" data-ws-menu-fechar aria-label="Fechar menu">
          <i data-lucide="x"></i>
        </a>
      </div>
      <nav class="ws-nav">
        <ul class="ws-nav__lista">
<?php foreach ($ws_menu as [$ws_chave, $ws_arquivo, $ws_rotulo, $ws_icone, $ws_permissao]): ?>
<?php if ($ws_permissao !== null && !usuarioTemPermissao($ws_permissao)) continue; ?>
          <li><a class="ws-nav__item" href="<?= escaparHtml($ws_base . '/' . $ws_arquivo) ?>"<?= $ws_chave === $ws_pagina ? ' aria-current="page"' : '' ?>><i data-lucide="<?= escaparHtml($ws_icone) ?>"></i><?= escaparHtml($ws_rotulo) ?></a></li>
<?php endforeach; ?>
        </ul>
      </nav>
      <div class="ws-sidebar__rodape">
        <div class="ws-usuario">
          <span class="ws-usuario__iniciais" aria-hidden="true"><?= escaparHtml($ws_iniciais) ?></span>
          <div style="min-width:0">
            <p class="ws-usuario__nome"><?= escaparHtml($ws_nome) ?></p>
            <p class="ws-usuario__perfil"><?= escaparHtml($ws_perfil) ?></p>
          </div>
        </div>
        <a class="ws-btn ws-btn--link" href="<?= escaparHtml($ws_base) ?>/logout.php"><i data-lucide="log-out" class="ws-icone--peq"></i>Sair</a>
      </div>
    </aside>
    <a class="ws-app__veu" href="#" data-ws-menu-fechar tabindex="-1" aria-hidden="true"></a>

    <main class="ws-principal" id="conteudo">
