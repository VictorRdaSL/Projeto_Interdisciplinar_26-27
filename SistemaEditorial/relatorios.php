<?php
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/relatorios.php';
require_once __DIR__ . '/includes/relatorios_layout.php';

exigirPermissaoRelatorios();

$relatorios = [
    ['relatorio_livros.php', '📚', 'Livros', 'Situação atual de cada livro: pessoas e papéis, etapa, rodada, mesa, status, dias na etapa e observações. Filtra por etapa, status e mesa.'],
    ['relatorio_movimentacoes.php', '🔄', 'Movimentações por período', 'Histórico de movimentações entre etapas e mesas: livro, etapa, rodada, tipo, responsável, entrada, saída e se foi excedente.'],
    ['relatorio_excedentes.php', '💰', 'Rodadas excedentes por período', 'Rodadas cobráveis com livro, pessoas vinculadas, etapa, número da rodada, limite e data — base para a cobrança, feita fora do sistema.'],
    ['relatorio_tempo_etapa.php', '⏱', 'Tempo por etapa', 'Tempo médio que os livros ficaram em cada etapa num intervalo de datas, para achar gargalos. Cada etapa expande para mostrar os livros.'],
    ['relatorio_resgates.php', '🚨', 'Resgates por período', 'Livros extraviados e resgatados: última etapa e mesa conhecidas, quanto tempo levou e onde o livro foi encontrado.'],
];

relatorioAbrir('Relatórios', 'Fluxo editorial');
?>
    <section class="painel">
        <div class="painel-topo">
            <div><h2>Relatórios editoriais</h2><p>Todos exportam CSV (separador ";", abre direto no Excel em português) respeitando os filtros aplicados na tela.</p></div>
        </div>
        <div class="cards">
            <?php foreach ($relatorios as [$arquivo, $icone, $titulo, $descricao]): ?>
            <a href="<?= htmlspecialchars($arquivo) ?>" class="card" style="text-decoration:none;color:inherit;">
                <div><p><?= htmlspecialchars($titulo) ?></p><span style="font-size:13px;"><?= htmlspecialchars($descricao) ?></span></div>
                <div class="card-icon"><?= $icone ?></div>
            </a>
            <?php endforeach; ?>
        </div>
    </section>
<?php relatorioFechar();
