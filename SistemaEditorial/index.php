<?php
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/permissoes.php';
require_once __DIR__ . '/includes/alertas.php';

// Painel inicial pós-pivô editorial — Passo 11 refez o dashboard por completo:
// livros por etapa, parados, extraviados/resgate, rodada excedente em aberto,
// concluídos no mês, atalho pra Minha mesa e busca (que delega pra livros.php).
// O flash da sessão é lido, exibido e apagado pelo includes/rodape.php (toast).

$totalLivros = (int)$conn->query('SELECT COUNT(*) total FROM livros')->fetch_assoc()['total'];
$livrosEmAndamento = (int)$conn->query("SELECT COUNT(*) total FROM livros WHERE status = 'em_andamento'")->fetch_assoc()['total'];
$livrosConcluidos = (int)$conn->query("SELECT COUNT(*) total FROM livros WHERE status = 'concluido'")->fetch_assoc()['total'];
$totalPessoas = (int)$conn->query('SELECT COUNT(*) total FROM pessoas')->fetch_assoc()['total'];
$livrosParados = buscarLivrosParados($conn);

// Limites do mês calculados em PHP (fuso America/Sao_Paulo já fixo em
// config/database.php) — nunca com MONTH()/NOW() do MySQL direto, mesma
// cautela de fuso já documentada em outras telas.
$inicioMes = (new DateTime('first day of this month 00:00:00'))->format('Y-m-d H:i:s');
$inicioProximoMes = (new DateTime('first day of next month 00:00:00'))->format('Y-m-d H:i:s');
$stmtMes = $conn->prepare("SELECT COUNT(*) total FROM livros WHERE status = 'concluido' AND atualizado_em >= ? AND atualizado_em < ?");
$stmtMes->bind_param('ss', $inicioMes, $inicioProximoMes);
$stmtMes->execute();
$livrosConcluidosNoMes = (int)$stmtMes->get_result()->fetch_assoc()['total'];

// Livros por etapa, na ordem do fluxo — só livros em andamento (extraviados
// aparecem na seção própria, concluídos/cancelados não "estão" em etapa nenhuma).
$livrosPorEtapa = [];
$maxPorEtapa = 1;
$r = $conn->query("
    SELECT e.id, e.nome, e.ordem, e.opcional, COUNT(l.id) AS total
    FROM etapas e
    LEFT JOIN livros l ON l.etapa_atual_id = e.id AND l.status = 'em_andamento'
    WHERE e.ativa = 1
    GROUP BY e.id, e.nome, e.ordem, e.opcional
    ORDER BY e.ordem
");
while ($row = $r->fetch_assoc()) {
    $row['total'] = (int)$row['total'];
    $livrosPorEtapa[] = $row;
    if ($row['total'] > $maxPorEtapa) $maxPorEtapa = $row['total'];
}

// Livros extraviados, com resgate em aberto.
$livrosExtraviados = [];
$r = $conn->query("
    SELECT l.id, l.titulo, e.nome AS ultima_etapa_nome, r.data_abertura
    FROM livros l
    JOIN resgates r ON r.livro_id = l.id AND r.data_conclusao IS NULL
    LEFT JOIN movimentacoes m ON m.id = r.movimentacao_referencia_id
    LEFT JOIN etapas e ON e.id = m.etapa_id
    WHERE l.status = 'extraviado'
    ORDER BY r.data_abertura ASC
");
$agora = new DateTime();
while ($row = $r->fetch_assoc()) {
    $row['dias_perdido'] = $agora->diff(new DateTime($row['data_abertura']))->days;
    $livrosExtraviados[] = $row;
}

// Livros com rodada excedente em aberto — cobráveis agora (só Diagramação/
// Revisão têm rodadas_incluidas definido, então só elas podem ter excedente=1).
$livrosExcedentes = [];
$r = $conn->query("
    SELECT v.livro_id AS id, v.titulo, v.etapa_nome, v.rodada, v.rodadas_incluidas, v.mesa_nome AS responsavel_nome
    FROM vw_livros_situacao_atual v
    WHERE v.excedente = 1 AND v.movimentacao_id IS NOT NULL AND v.status NOT IN ('concluido', 'cancelado')
    ORDER BY v.rodada DESC
");
while ($row = $r->fetch_assoc()) $livrosExcedentes[] = $row;

// ---- Só apresentação (Passo IV-3): nada abaixo muda as consultas acima.
$mesesPt = ['janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];
$mesAtual = $mesesPt[(int)date('n') - 1];
$podeNovoLivro = usuarioTemPermissao('livros.gerenciar');
$podeNovaPessoa = usuarioTemPermissao('pessoas.gerenciar');

// qtdTexto() e rotuloRodada() ficam em includes/formatacao.php (compartilhadas com as outras telas).
$tituloPagina = 'Painel editorial';
$paginaAtual = 'painel';
require __DIR__ . '/includes/cabecalho.php';
?>
      <header class="ws-topo">
        <div class="ws-topo__titulos">
          <h1 class="ws-topo__titulo">Painel editorial</h1>
          <p class="ws-topo__subtitulo">Livros em produção · <?= escaparHtml($mesAtual) ?></p>
        </div>
        <div class="ws-topo__acoes">
          <form class="ws-busca" role="search" method="get" action="livros.php">
            <label class="ws-sr-only" for="busca-painel">Buscar livro, por título, pessoa vinculada ou ISBN</label>
            <i class="ws-busca__icone ws-icone--peq" data-lucide="search"></i>
            <input class="ws-input" id="busca-painel" type="search" name="busca" placeholder="Buscar livro ou ISBN" title="Busca por título, pessoa vinculada ou ISBN" aria-label="Buscar por título, pessoa vinculada ou ISBN">
          </form>
<?php if ($podeNovaPessoa): ?>
          <a class="ws-btn ws-btn--secundario" href="pessoas.php"><i data-lucide="user-plus"></i>Nova pessoa</a>
<?php endif; ?>
<?php if ($podeNovoLivro): ?>
          <a class="ws-btn ws-btn--primario" href="livro_form.php"><i data-lucide="plus"></i>Novo livro</a>
<?php endif; ?>
        </div>
      </header>

      <div class="ws-conteudo">

<?php if ($livrosExtraviados): $maisAntigo = $livrosExtraviados[0]; ?>
        <!-- Alerta: livros em resgate (extraviados) -->
        <div class="ws-alerta ws-alerta--cobrado" role="status">
          <i class="ws-alerta__icone" data-lucide="circle-alert"></i>
          <div class="ws-alerta__corpo">
            <p class="ws-alerta__titulo"><?= escaparHtml(count($livrosExtraviados) === 1 ? 'Livro em resgate desde ' . date('d/m', strtotime($maisAntigo['data_abertura'])) . '.' : qtdTexto(count($livrosExtraviados), 'livro', 'livros') . ' em resgate; o mais antigo desde ' . date('d/m', strtotime($maisAntigo['data_abertura'])) . '.') ?></p>
            <p><?= escaparHtml($maisAntigo['titulo']) ?> · última etapa conhecida: <?= escaparHtml($maisAntigo['ultima_etapa_nome'] ?: '—') ?>.</p>
            <div class="ws-alerta__acoes"><a href="#livros-extraviados">Ver livros em resgate</a></div>
          </div>
        </div>
<?php endif; ?>
<?php if ($livrosExcedentes): ?>
        <!-- Alerta: rodada acima do limite (cobrada) -->
        <div class="ws-alerta ws-alerta--cobrado" role="status">
          <i class="ws-alerta__icone" data-lucide="circle-alert"></i>
          <div class="ws-alerta__corpo">
            <p class="ws-alerta__titulo"><?= escaparHtml(qtdTexto(count($livrosExcedentes), 'livro', 'livros')) ?> em rodada acima do limite.</p>
            <p>A rodada aberta é cobrada, em Diagramação ou Revisão.</p>
            <div class="ws-alerta__acoes"><a href="#livros-excedentes">Ver rodadas cobradas</a></div>
          </div>
        </div>
<?php endif; ?>
<?php if ($livrosParados): ?>
        <!-- Alerta: livros parados além do limite de dias -->
        <div class="ws-alerta ws-alerta--limite" role="status">
          <i class="ws-alerta__icone" data-lucide="clock"></i>
          <div class="ws-alerta__corpo">
            <p class="ws-alerta__titulo"><?= escaparHtml(qtdTexto(count($livrosParados), 'livro parado', 'livros parados')) ?> além do limite de dias da etapa.</p>
            <p>O mais antigo está há <?= escaparHtml(qtdTexto((int)$livrosParados[0]['dias_parado'], 'dia', 'dias')) ?> em <?= escaparHtml($livrosParados[0]['etapa_nome']) ?>.</p>
            <div class="ws-alerta__acoes"><a href="#livros-parados">Ver livros parados</a></div>
          </div>
        </div>
<?php endif; ?>

        <!-- Indicadores: só "Livros parados" é status e só ganha cor quando há livros parados -->
        <section class="ws-secao" aria-labelledby="kpis-titulo">
          <h2 class="ws-sr-only" id="kpis-titulo">Indicadores</h2>
          <div class="ws-kpis">
            <div class="ws-kpi">
              <span class="ws-kpi__rotulo">Total de livros</span>
              <span class="ws-kpi__valor"><?= (int)$totalLivros ?> <span class="ws-kpi__unidade">livros</span></span>
              <span class="ws-kpi__nota">Cadastrados</span>
            </div>
            <div class="ws-kpi">
              <span class="ws-kpi__rotulo">Em andamento</span>
              <span class="ws-kpi__valor"><?= (int)$livrosEmAndamento ?> <span class="ws-kpi__unidade">livros</span></span>
              <span class="ws-kpi__nota">No fluxo editorial</span>
            </div>
            <div class="ws-kpi">
              <span class="ws-kpi__rotulo">Concluídos</span>
              <span class="ws-kpi__valor"><?= (int)$livrosConcluidos ?> <span class="ws-kpi__unidade">livros</span></span>
              <span class="ws-kpi__nota">Finalizados</span>
            </div>
            <div class="ws-kpi">
              <span class="ws-kpi__rotulo">Concluídos no mês</span>
              <span class="ws-kpi__valor"><?= (int)$livrosConcluidosNoMes ?> <span class="ws-kpi__unidade">livros</span></span>
              <span class="ws-kpi__nota">Depósito legal feito</span>
            </div>
            <div class="ws-kpi">
              <span class="ws-kpi__rotulo">Pessoas cadastradas</span>
              <span class="ws-kpi__valor"><?= (int)$totalPessoas ?> <span class="ws-kpi__unidade">pessoas</span></span>
              <span class="ws-kpi__nota">Autores, organizadores, ilustradores</span>
            </div>
            <div class="ws-kpi<?= $livrosParados ? ' ws-kpi--limite' : '' ?>">
              <span class="ws-kpi__rotulo">Livros parados</span>
              <span class="ws-kpi__valor"><?= count($livrosParados) ?> <span class="ws-kpi__unidade">livros</span></span>
              <span class="ws-kpi__nota"><?= $livrosParados ? 'Precisam de atenção' : 'Tudo em dia' ?></span>
            </div>
          </div>
        </section>

<?php if ($livrosExtraviados): ?>
        <!-- Extraviados / em resgate -->
        <section class="ws-secao" id="livros-extraviados" aria-labelledby="extraviados-titulo">
          <div class="ws-secao__cabecalho">
            <div>
              <h2 id="extraviados-titulo">Livros em resgate</h2>
              <p>Livros com resgate em aberto, a partir da última movimentação conhecida.</p>
            </div>
          </div>
          <div class="ws-tabela-wrap" tabindex="0" role="region" aria-labelledby="extraviados-titulo">
            <table class="ws-tabela ws-tabela--empilhar">
              <thead>
                <tr>
                  <th scope="col">Livro</th>
                  <th scope="col">Última etapa conhecida</th>
                  <th scope="col">Em resgate desde</th>
                  <th scope="col">Status</th>
                  <th scope="col"><span class="ws-sr-only">Ações</span></th>
                </tr>
              </thead>
              <tbody>
<?php foreach ($livrosExtraviados as $le): ?>
                <tr>
                  <td class="ws-tabela__titulo" data-rotulo="Livro"><a href="livro_detalhes.php?id=<?= (int)$le['id'] ?>"><?= escaparHtml($le['titulo']) ?></a></td>
                  <td data-rotulo="Última etapa"><?= escaparHtml($le['ultima_etapa_nome'] ?: '—') ?></td>
                  <td data-rotulo="Desde"><?= escaparHtml(date('d/m/Y', strtotime($le['data_abertura']))) ?></td>
                  <td data-rotulo="Status"><span class="ws-status ws-status--extraviado">Extraviado <span class="ws-status__detalhe">· <?= escaparHtml(qtdTexto((int)$le['dias_perdido'], 'dia', 'dias')) ?></span></span></td>
                  <td class="ws-tabela__acoes"><a class="ws-btn ws-btn--secundario ws-btn--peq" href="resgate.php?id=<?= (int)$le['id'] ?>">Ver resgate</a></td>
                </tr>
<?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </section>
<?php endif; ?>

<?php if ($livrosExcedentes): ?>
        <!-- Rodada excedente em aberto (cobrada) -->
        <section class="ws-secao" id="livros-excedentes" aria-labelledby="excedentes-titulo">
          <div class="ws-secao__cabecalho">
            <div>
              <h2 id="excedentes-titulo">Rodadas acima do limite</h2>
              <p>Livros agora numa rodada cobrada de Diagramação ou Revisão.</p>
            </div>
          </div>
          <div class="ws-tabela-wrap" tabindex="0" role="region" aria-labelledby="excedentes-titulo">
            <table class="ws-tabela ws-tabela--empilhar">
              <thead>
                <tr>
                  <th scope="col">Livro</th>
                  <th scope="col">Etapa</th>
                  <th scope="col">Responsável</th>
                  <th scope="col" class="ws-tabela__num">Rodada</th>
                  <th scope="col">Status</th>
                </tr>
              </thead>
              <tbody>
<?php foreach ($livrosExcedentes as $lx): ?>
                <tr>
                  <td class="ws-tabela__titulo" data-rotulo="Livro"><a href="livro_detalhes.php?id=<?= (int)$lx['id'] ?>"><?= escaparHtml($lx['titulo']) ?></a></td>
                  <td data-rotulo="Etapa"><?= escaparHtml($lx['etapa_nome']) ?></td>
                  <td data-rotulo="Responsável"><?= escaparHtml($lx['responsavel_nome'] ?: '—') ?></td>
                  <td class="ws-tabela__num" data-rotulo="Rodada"><?= escaparHtml(rotuloRodada((int)$lx['rodada'], $lx['rodadas_incluidas'])) ?></td>
                  <td data-rotulo="Status"><span class="ws-status ws-status--cobrado">Cobrado</span></td>
                </tr>
<?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </section>
<?php endif; ?>

<?php if ($livrosParados): ?>
        <!-- Livros parados além do limite de dias -->
        <section class="ws-secao" id="livros-parados" aria-labelledby="parados-titulo">
          <div class="ws-secao__cabecalho">
            <div>
              <h2 id="parados-titulo">Livros parados</h2>
              <p>Movimentação aberta além do limite de dias da etapa (ou do padrão global, em Configurações, quando a etapa não tem limite próprio).</p>
            </div>
          </div>
          <div class="ws-tabela-wrap" tabindex="0" role="region" aria-labelledby="parados-titulo">
            <table class="ws-tabela ws-tabela--empilhar">
              <thead>
                <tr>
                  <th scope="col">Livro</th>
                  <th scope="col">Etapa</th>
                  <th scope="col">Responsável</th>
                  <th scope="col" class="ws-tabela__num">Rodada</th>
                  <th scope="col">Status</th>
                </tr>
              </thead>
              <tbody>
<?php foreach ($livrosParados as $lp):
    $dias = (int)$lp['dias_parado'];
    // Segunda dimensão (rodada), só quando a etapa tem limite de rodadas e a rodada já chegou nele.
    $statusRodada = null;
    if ((int)$lp['excedente'] === 1) $statusRodada = ['cobrado', 'Cobrado'];
    elseif ($lp['rodadas_incluidas'] !== null && (int)$lp['rodada'] === (int)$lp['rodadas_incluidas']) $statusRodada = ['limite', 'No limite'];
?>
                <tr>
                  <td class="ws-tabela__titulo" data-rotulo="Livro"><a href="livro_detalhes.php?id=<?= (int)$lp['id'] ?>"><?= escaparHtml($lp['titulo']) ?></a></td>
                  <td data-rotulo="Etapa"><?= escaparHtml($lp['etapa_nome']) ?></td>
                  <td data-rotulo="Responsável"><?= escaparHtml($lp['responsavel_nome'] ?: '—') ?></td>
                  <td class="ws-tabela__num" data-rotulo="Rodada"><?= escaparHtml(rotuloRodada((int)$lp['rodada'], $lp['rodadas_incluidas'])) ?></td>
                  <td data-rotulo="Status">
                    <span class="ws-status ws-status--parado">Parado <span class="ws-status__detalhe">· <?= escaparHtml(qtdTexto($dias, 'dia', 'dias')) ?> · limite <?= (int)$lp['limite_aplicado'] ?></span></span>
<?php if ($statusRodada): ?>
                    <span class="ws-status ws-status--<?= escaparHtml($statusRodada[0]) ?>"><?= escaparHtml($statusRodada[1]) ?></span>
<?php endif; ?>
                  </td>
                </tr>
<?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </section>
<?php endif; ?>

        <!-- Livros por etapa: etapas ativas do banco, na ordem do fluxo -->
        <section class="ws-secao" aria-labelledby="etapas-titulo">
          <div class="ws-secao__cabecalho">
            <div>
              <h2 id="etapas-titulo">Livros por etapa</h2>
              <p>Em andamento, na ordem do fluxo.</p>
            </div>
          </div>
          <div class="ws-tabela-wrap" tabindex="0" role="region" aria-labelledby="etapas-titulo">
            <table class="ws-tabela">
              <thead><tr><th scope="col">Etapa</th><th scope="col">Livros</th></tr></thead>
              <tbody>
<?php if (!$livrosPorEtapa): ?>
                <tr><td colspan="2" class="ws-tabela__vazia">Nenhuma etapa ativa cadastrada.</td></tr>
<?php endif; ?>
<?php foreach ($livrosPorEtapa as $ep):
    $larguraBarra = $ep['total'] > 0 ? max(4, round($ep['total'] / $maxPorEtapa * 100)) : 0;
?>
                <tr>
                  <td><?= escaparHtml($ep['nome']) ?><?php if ((int)$ep['opcional'] === 1): ?> <span class="ws-tag ws-tag--opcional ws-tag--peq">Opcional</span><?php endif; ?></td>
                  <td>
                    <div class="ws-barra">
                      <span class="ws-barra__trilho" aria-hidden="true"><span class="ws-barra__preenchimento" style="--ws-barra-valor: <?= (int)$larguraBarra ?>%"></span></span>
                      <span class="ws-barra__valor"><?= (int)$ep['total'] ?></span>
                    </div>
                  </td>
                </tr>
<?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </section>

      </div>
<?php require __DIR__ . '/includes/rodape.php'; ?>
