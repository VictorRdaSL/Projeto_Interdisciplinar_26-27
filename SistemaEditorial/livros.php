<?php
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/permissoes.php';
require_once __DIR__ . '/includes/isbn.php';
require_once __DIR__ . '/includes/validacao.php';
require_once __DIR__ . '/includes/formatacao.php';
require_once __DIR__ . '/includes/alertas.php';

// O flash da sessão é lido, exibido e apagado pelo includes/rodape.php (toast).
$podeGerenciar = usuarioTemPermissao('livros.gerenciar');
// Permite chegar com a busca já preenchida (ex.: a partir do painel) — o
// filtro em si continua sendo client-side, isso só define o valor inicial.
$termoBuscaInicial = entradaTexto($_GET, 'busca');

// Situação do livro (etapa, mesa, movimentação aberta) e pessoas vinculadas vêm das views
// vw_livros_situacao_atual e vw_livros_contribuidores (database/views.sql) — uma linha por
// livro, então não há GROUP BY nem risco de multiplicar linhas.
$sql = "
    SELECT v.livro_id AS id, v.titulo, v.isbn, v.status, v.tem_copia,
           v.etapa_nome, v.mesa_nome AS responsavel_nome,
           v.rodada, v.rodadas_incluidas, v.excedente,
           c.pessoas_nomes,
           EXISTS(SELECT 1 FROM resgates r WHERE r.livro_id = v.livro_id) AS ja_teve_resgate,
           v.etapa_desde
    FROM vw_livros_situacao_atual v
    LEFT JOIN vw_livros_contribuidores c ON c.livro_id = v.livro_id
    ORDER BY v.titulo
";
$livros = [];
$r = $conn->query($sql);
while ($row = $r->fetch_assoc()) $livros[] = $row;

// "Parado" não sai da consulta acima: usa a mesma função do painel (includes/alertas.php).
$diasParado = [];
foreach (buscarLivrosParados($conn) as $lp) $diasParado[(int)$lp['id']] = (int)$lp['dias_parado'];

const NOMES_STATUS_LIVRO = [
    'em_andamento' => 'Em andamento',
    'concluido'    => 'Concluído',
    'extraviado'   => 'Extraviado',
    'cancelado'    => 'Cancelado',
];
function nomeStatusLivro(string $status): string
{
    return NOMES_STATUS_LIVRO[$status] ?? $status;
}

// ---- Apresentação (Passo IV-4)
$totalLivros = count($livros);
$totalEmAndamento = count(array_filter($livros, fn($l) => $l['status'] === 'em_andamento'));

$tituloPagina = 'Livros';
$paginaAtual = 'livros';
require __DIR__ . '/includes/cabecalho.php';
?>
      <header class="ws-topo">
        <div class="ws-topo__titulos">
          <h1 class="ws-topo__titulo">Livros</h1>
          <p class="ws-topo__subtitulo"><?= escaparHtml(qtdTexto($totalLivros, 'livro cadastrado', 'livros cadastrados')) ?> · <?= escaparHtml(qtdTexto($totalEmAndamento, 'em andamento', 'em andamento')) ?></p>
        </div>
<?php if ($podeGerenciar): ?>
        <div class="ws-topo__acoes">
          <a class="ws-btn ws-btn--primario" href="livro_form.php"><i data-lucide="plus"></i>Novo livro</a>
        </div>
<?php endif; ?>
      </header>

      <div class="ws-conteudo">
<?php if (!$livros): ?>
        <section class="ws-secao" aria-labelledby="vazio-titulo">
          <div class="ws-vazio">
            <i class="ws-vazio__icone" data-lucide="book-open"></i>
            <h2 class="ws-h2" id="vazio-titulo">Nenhum livro cadastrado.</h2>
            <p class="ws-vazio__texto">Os livros cadastrados aparecem aqui, com etapa, mesa e rodada.</p>
<?php if ($podeGerenciar): ?>
            <a class="ws-btn ws-btn--secundario" href="livro_form.php">Cadastrar o primeiro livro</a>
<?php endif; ?>
          </div>
        </section>
<?php else: ?>
        <section class="ws-secao" aria-labelledby="filtros-titulo" id="livros">
          <h2 class="ws-sr-only" id="filtros-titulo">Busca</h2>
          <!-- A busca filtra a lista na própria página (com atraso de 300 ms ao digitar); o Enter mantém o termo na URL (?busca=). -->
          <form class="ws-filtros" method="get" action="livros.php" role="search">
            <div class="ws-campo">
              <label class="ws-campo__rotulo" for="busca">Buscar</label>
              <div class="ws-busca">
                <i class="ws-busca__icone ws-icone--peq" data-lucide="search"></i>
                <input class="ws-input" id="busca" type="search" name="busca" placeholder="Título, ISBN ou pessoa" value="<?= escaparHtml($termoBuscaInicial) ?>" oninput="agendarFiltroLivros()" autocomplete="off">
              </div>
            </div>
          </form>
        </section>

        <section class="ws-secao" aria-labelledby="lista-titulo">
          <h2 class="ws-sr-only" id="lista-titulo">Livros cadastrados</h2>
          <!-- No celular as linhas viram cartões (.ws-tabela--empilhar + data-rotulo) -->
          <div class="ws-tabela-wrap" id="livros-tabela-wrap" tabindex="0" role="region" aria-labelledby="lista-titulo">
            <table class="ws-tabela ws-tabela--empilhar" id="tabela-livros">
              <thead>
                <tr>
                  <th scope="col">Livro</th>
                  <th scope="col">Título</th>
                  <th scope="col">ISBN</th>
                  <th scope="col">Etapa</th>
                  <th scope="col">Na etapa desde</th>
                  <th scope="col">Mesa</th>
                  <th scope="col" class="ws-tabela__num">Rodadas</th>
                  <th scope="col">Cópia</th>
                  <th scope="col">Status</th>
                </tr>
              </thead>
              <tbody>
<?php foreach ($livros as $l):
    $id = (int)$l['id'];
    $busca = strtolower($l['titulo'] . ' ' . ($l['isbn'] ?? '') . ' ' . ($l['pessoas_nomes'] ?? ''));
    $temRodada = $l['rodada'] !== null;
    $limiteRodadas = $l['rodadas_incluidas'];
?>
                <tr data-busca="<?= escaparHtml($busca) ?>">
                  <td class="ws-tabela__codigo" data-rotulo="Livro"><?= escaparHtml(codigoLivro($id)) ?></td>
                  <td class="ws-tabela__titulo" data-rotulo="Título"><a href="livro_detalhes.php?id=<?= $id ?>"><?= escaparHtml($l['titulo']) ?></a><span class="ws-tabela__sub"><?= escaparHtml($l['pessoas_nomes'] ?: 'Sem pessoas vinculadas') ?></span></td>
                  <td data-rotulo="ISBN"><?php if ($l['isbn']): ?><span class="ws-tabela__codigo"><?= escaparHtml($l['isbn']) ?></span><?php else: ?><span class="ws-tabela__suave">—</span><?php endif; ?></td>
                  <td data-rotulo="Etapa"><?= escaparHtml($l['etapa_nome'] ?: '—') ?></td>
                  <td class="ws-tabela__codigo" data-rotulo="Na etapa desde"><?= escaparHtml(formatarDataHora($l['etapa_desde'])) ?></td>
                  <td data-rotulo="Mesa"><?= escaparHtml($l['responsavel_nome'] ?: '—') ?></td>
                  <td class="ws-tabela__num" data-rotulo="Rodadas"><?= $temRodada ? escaparHtml(rotuloRodada((int)$l['rodada'], $limiteRodadas)) : '—' ?></td>
                  <td data-rotulo="Cópia"><?= ((int)$l['tem_copia'] === 1) ? 'Sim' : 'Não' ?></td>
                  <td data-rotulo="Status">
<?php if ($l['status'] === 'concluido' || $l['status'] === 'cancelado'): ?>
                    <span class="ws-tag ws-tag--neutra"><?= escaparHtml(nomeStatusLivro($l['status'])) ?></span>
<?php elseif ($l['status'] === 'extraviado'): ?>
                    <span class="ws-status ws-status--extraviado">Extraviado</span>
<?php else:
    // Em andamento: duas dimensões (livro: parado; rodada: no limite/cobrado). Sem nenhuma delas, "No prazo".
    $statusLivro = isset($diasParado[$id]) ? $diasParado[$id] : null;
    $statusRodada = null;
    if ((int)$l['excedente'] === 1) $statusRodada = ['cobrado', 'Cobrado'];
    elseif ($limiteRodadas !== null && $temRodada && (int)$l['rodada'] === (int)$limiteRodadas) $statusRodada = ['limite', 'No limite'];
?>
<?php if ($statusLivro !== null): ?>
                    <span class="ws-status ws-status--parado">Parado <span class="ws-status__detalhe">· <?= escaparHtml(qtdTexto($statusLivro, 'dia', 'dias')) ?></span></span>
<?php endif; ?>
<?php if ($statusRodada): ?>
                    <span class="ws-status ws-status--<?= escaparHtml($statusRodada[0]) ?>"><?= escaparHtml($statusRodada[1]) ?></span>
<?php endif; ?>
<?php if ($statusLivro === null && !$statusRodada): ?>
                    <span class="ws-status ws-status--ok">No prazo</span>
<?php endif; ?>
<?php endif; ?>
<?php if ((int)$l['ja_teve_resgate'] === 1): ?>
                    <span class="ws-tag ws-tag--neutra ws-tag--peq" title="Já passou por um resgate">Já resgatado</span>
<?php endif; ?>
                  </td>
                </tr>
<?php endforeach; ?>
              </tbody>
            </table>
          </div>

          <!-- Busca sem resultado (mostrado pelo script abaixo) -->
          <div class="ws-vazio" id="busca-sem-resultado" style="display:none;">
            <i class="ws-vazio__icone" data-lucide="search"></i>
            <h2 class="ws-h2">Nenhum livro encontrado para essa busca.</h2>
            <p class="ws-vazio__texto">Confira o título, o ISBN ou o nome da pessoa vinculada.</p>
            <button class="ws-btn ws-btn--secundario" type="button" onclick="limparBuscaLivros()">Limpar busca</button>
          </div>
        </section>

        <script>
        let filtroLivrosTimeout = null;
        function agendarFiltroLivros(){
          clearTimeout(filtroLivrosTimeout);
          filtroLivrosTimeout = setTimeout(aplicarFiltroLivros, 300);
        }
        function aplicarFiltroLivros(){
          const termo = document.getElementById('busca').value.trim().toLowerCase();
          let visiveis = 0;
          document.querySelectorAll('#tabela-livros tbody tr[data-busca]').forEach(linha=>{
            const mostra = termo === '' || linha.dataset.busca.includes(termo);
            linha.style.display = mostra ? '' : 'none';
            if (mostra) visiveis++;
          });
          const semResultado = visiveis === 0 && termo !== '';
          document.getElementById('busca-sem-resultado').style.display = semResultado ? '' : 'none';
          document.getElementById('livros-tabela-wrap').style.display = semResultado ? 'none' : '';
        }
        function limparBuscaLivros(){
          const campo = document.getElementById('busca');
          campo.value = '';
          aplicarFiltroLivros();
          campo.focus();
        }
        if (document.getElementById('busca').value.trim() !== '') {
          aplicarFiltroLivros();
        }
        </script>
<?php endif; ?>
      </div>
<?php require __DIR__ . '/includes/rodape.php'; ?>
