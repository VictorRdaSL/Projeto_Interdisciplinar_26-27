<?php
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/relatorios.php';
require_once __DIR__ . '/includes/relatorios_layout.php';

exigirPermissaoRelatorios();

// [arquivo, ícone Lucide, título, descrição]
$relatorios = [
    ['relatorio_livros.php', 'book-open', 'Livros', 'Situação atual de cada livro: pessoas e papéis, etapa, rodada, mesa, status, dias na etapa e observações. Filtra por etapa, status e mesa.'],
    ['relatorio_movimentacoes.php', 'repeat', 'Movimentações por período', 'Histórico de movimentações entre etapas e mesas: livro, etapa, rodada, tipo, responsável, entrada, saída e se foi excedente.'],
    ['relatorio_excedentes.php', 'circle-dollar-sign', 'Rodadas excedentes por período', 'Rodadas cobráveis com livro, pessoas vinculadas, etapa, número da rodada, limite e data — base para a cobrança, feita fora do sistema.'],
    ['relatorio_tempo_etapa.php', 'timer', 'Tempo por etapa', 'Tempo médio que os livros ficaram em cada etapa num intervalo de datas, para achar gargalos. Cada etapa expande para mostrar os livros.'],
    ['relatorio_resgates.php', 'life-buoy', 'Resgates por período', 'Livros extraviados e resgatados: última etapa e mesa conhecidas, quanto tempo levou e onde o livro foi encontrado.'],
];

relatorioAbrir('Relatórios', 'Fluxo editorial', true);
?>
        <section class="ws-secao" aria-labelledby="relatorios-titulo">
          <div class="ws-secao__cabecalho">
            <div>
              <h2 id="relatorios-titulo">Relatórios editoriais</h2>
              <p>Todos exportam CSV (separador ";", abre direto no Excel em português) respeitando os filtros aplicados na tela.</p>
            </div>
          </div>
          <div class="ws-grade">
<?php foreach ($relatorios as [$arquivo, $icone, $titulo, $descricao]): ?>
            <a class="ws-col-6 ws-card ws-card--superficie ws-link-limpo" href="<?= escaparHtml($arquivo) ?>">
              <i data-lucide="<?= escaparHtml($icone) ?>"></i>
              <h3 class="ws-card__titulo"><?= escaparHtml($titulo) ?></h3>
              <p class="ws-card__texto"><?= escaparHtml($descricao) ?></p>
            </a>
<?php endforeach; ?>
          </div>
        </section>
<?php relatorioFechar();
