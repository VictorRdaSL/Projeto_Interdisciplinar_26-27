<?php
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/permissoes.php';
require_once __DIR__ . '/includes/formatacao.php';
require_once __DIR__ . '/includes/isbn.php';
require_once __DIR__ . '/includes/alertas.php';

$usuarioId = (int)$_SESSION['usuario_id'];

$stmt = $conn->prepare('
    SELECT v.livro_id AS id, v.titulo, v.isbn, v.etapa_nome, v.rodadas_incluidas,
           v.rodada, v.excedente, v.etapa_desde AS data_entrada
    FROM vw_livros_situacao_atual v
    WHERE v.mesa_id = ? AND v.status = \'em_andamento\' AND v.movimentacao_id IS NOT NULL
    ORDER BY v.etapa_desde ASC
');
$stmt->bind_param('i', $usuarioId);
$stmt->execute();
$livros = [];
$r = $stmt->get_result();
$agora = new DateTime();
while ($row = $r->fetch_assoc()) {
    $row['dias_parado'] = $agora->diff(new DateTime($row['data_entrada']))->days;
    $livros[] = $row;
}

// O flash da sessão é lido, exibido e apagado pelo includes/rodape.php (toast).

// "Parado": mesma função do painel e da lista de livros (movimentação aberta além do limite de dias).
$diasParado = [];
foreach (buscarLivrosParados($conn) as $lp) $diasParado[(int)$lp['id']] = (int)$lp['dias_parado'];

$ehAdmin = papelAtual() === 'admin';

$tituloPagina = 'Minha mesa';
$paginaAtual = 'minha_mesa';
require __DIR__ . '/includes/cabecalho.php';
?>
      <header class="ws-topo">
        <div class="ws-topo__titulos">
          <h1 class="ws-topo__titulo">Minha mesa</h1>
          <p class="ws-topo__subtitulo"><?= $livros ? escaparHtml(qtdTexto(count($livros), 'livro com você', 'livros com você')) : 'Nenhum livro com você' ?></p>
        </div>
      </header>

      <div class="ws-conteudo">
<?php if (!$livros): ?>
        <section class="ws-secao" aria-labelledby="vazio-titulo">
          <div class="ws-vazio">
            <i class="ws-vazio__icone" data-lucide="inbox"></i>
            <h2 class="ws-h2" id="vazio-titulo">Nenhum livro na sua mesa.</h2>
<?php if ($ehAdmin): ?>
            <p class="ws-vazio__texto">O Administrador não faz parte do fluxo e não recebe livros em mesa.</p>
<?php else: ?>
            <p class="ws-vazio__texto">Quando um livro for passado para você, ele aparece aqui, com etapa e rodada.</p>
<?php endif; ?>
            <a class="ws-btn ws-btn--secundario" href="livros.php">Ver todos os livros</a>
          </div>
        </section>
<?php else: ?>
        <section class="ws-secao" id="minha-mesa" aria-labelledby="lista-titulo">
          <h2 class="ws-sr-only" id="lista-titulo">Livros em andamento com você</h2>
          <!-- No celular as linhas viram cartões (.ws-tabela--empilhar + data-rotulo) -->
          <div class="ws-tabela-wrap" tabindex="0" role="region" aria-labelledby="lista-titulo">
            <table class="ws-tabela ws-tabela--empilhar">
              <thead>
                <tr>
                  <th scope="col">Livro</th>
                  <th scope="col">Título</th>
                  <th scope="col">ISBN</th>
                  <th scope="col">Etapa</th>
                  <th scope="col" class="ws-tabela__num">Rodada</th>
                  <th scope="col">Na etapa há</th>
                  <th scope="col">Status</th>
                </tr>
              </thead>
              <tbody>
<?php foreach ($livros as $l):
    $id = (int)$l['id'];
    $limiteRodadas = $l['rodadas_incluidas'];
    $dias = (int)$l['dias_parado'];
    // Duas dimensões (livro: parado; rodada: no limite/cobrado). Sem nenhuma delas, "No prazo".
    $parado = $diasParado[$id] ?? null;
    $statusRodada = null;
    if ((int)$l['excedente'] === 1) $statusRodada = ['cobrado', 'Cobrado'];
    elseif ($limiteRodadas !== null && (int)$l['rodada'] === (int)$limiteRodadas) $statusRodada = ['limite', 'No limite'];
?>
                <tr>
                  <td class="ws-tabela__codigo" data-rotulo="Livro"><?= escaparHtml(codigoLivro($id)) ?></td>
                  <td class="ws-tabela__titulo" data-rotulo="Título"><a href="livro_detalhes.php?id=<?= $id ?>"><?= escaparHtml($l['titulo']) ?></a></td>
                  <td data-rotulo="ISBN"><?php if ($l['isbn']): ?><span class="ws-tabela__codigo"><?= escaparHtml(formatarIsbn($l['isbn'])) ?></span><?php else: ?><span class="ws-tabela__suave">—</span><?php endif; ?></td>
                  <td data-rotulo="Etapa"><?= escaparHtml($l['etapa_nome'] ?: '—') ?></td>
                  <td class="ws-tabela__num" data-rotulo="Rodada"><?= escaparHtml(rotuloRodada((int)$l['rodada'], $limiteRodadas)) ?></td>
                  <td data-rotulo="Na etapa há"><?= $dias === 0 ? 'menos de 1 dia' : escaparHtml(qtdTexto($dias, 'dia', 'dias')) ?></td>
                  <td data-rotulo="Status">
<?php if ($parado !== null): ?>
                    <span class="ws-status ws-status--parado">Parado <span class="ws-status__detalhe">· <?= escaparHtml(qtdTexto($parado, 'dia', 'dias')) ?></span></span>
<?php endif; ?>
<?php if ($statusRodada): ?>
                    <span class="ws-status ws-status--<?= escaparHtml($statusRodada[0]) ?>"><?= escaparHtml($statusRodada[1]) ?></span>
<?php endif; ?>
<?php if ($parado === null && !$statusRodada): ?>
                    <span class="ws-status ws-status--ok">No prazo</span>
<?php endif; ?>
                  </td>
                </tr>
<?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </section>
<?php endif; ?>
      </div>
<?php require __DIR__ . '/includes/rodape.php'; ?>
