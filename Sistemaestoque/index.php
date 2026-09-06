<?php
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/permissoes.php';
require_once __DIR__ . '/includes/log.php';

function voltar(string $tipo, string $mensagem, string $ancora='dashboard'): never {
    $_SESSION['flash'] = ['type'=>$tipo, 'message'=>$mensagem];
    header('Location: index.php#'.$ancora);
    exit;
}

function codigoProduto(int $id): string {
    return str_pad((string)$id, 4, '0', STR_PAD_LEFT);
}

function avisoEstoqueBaixo(int $depois, int $minimo, bool $jaEstavaBaixo): string {
    if (!$jaEstavaBaixo && $depois <= $minimo) {
        return ' ⚠ Atenção: o produto ficou com estoque baixo ('.$depois.' un., mínimo '.$minimo.').';
    }
    return '';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = $_POST['acao'] ?? '';

    if ($acao === 'novo_produto') {
        if (!usuarioTemPermissao('produtos.criar')) {
            voltar('erro','Você não tem permissão para cadastrar produtos. Fale com um gerente ou administrador.','produtos');
        }
        $nome = trim($_POST['nome'] ?? '');
        $categoria = trim($_POST['categoria'] ?? 'Outros');
        $quantidade = max(0, (int)($_POST['quantidade'] ?? 0));
        $minimo = max(0, (int)($_POST['minimo'] ?? 0));
        $localizacao = trim($_POST['localizacao'] ?? '');
        $observacao = trim($_POST['observacao'] ?? '');
        if ($nome === '') voltar('erro','Informe o nome do produto.','novo-produto');

        try {
            $codigoTemp = 'TMP'.bin2hex(random_bytes(6));
            $stmt=$conn->prepare('INSERT INTO produtos (nome,codigo,categoria,quantidade,estoque_minimo,localizacao,observacao) VALUES (?,?,?,?,?,?,?)');
            $stmt->bind_param('sssiiss',$nome,$codigoTemp,$categoria,$quantidade,$minimo,$localizacao,$observacao);
            $stmt->execute();
            $produtoId=$conn->insert_id;

            $codigo = codigoProduto($produtoId);
            $upd=$conn->prepare('UPDATE produtos SET codigo=? WHERE id=?');
            $upd->bind_param('si',$codigo,$produtoId); $upd->execute();

            if ($quantidade > 0) {
                $obs='Estoque inicial'; $usuarioId=(int)$_SESSION['usuario_id'];
                $m=$conn->prepare('INSERT INTO entradas_estoque (produto_id,usuario_id,quantidade,observacao) VALUES (?,?,?,?)');
                $m->bind_param('iiis',$produtoId,$usuarioId,$quantidade,$obs); $m->execute();
            }
            $aviso = avisoEstoqueBaixo($quantidade, $minimo, false);
            voltar('sucesso','Produto cadastrado com sucesso! Código '.$codigo.' gerado automaticamente.'.$aviso,'produtos');
        } catch (mysqli_sql_exception $e) {
            voltar('erro','Não foi possível cadastrar o produto. Tente novamente.','novo-produto');
        }
    }

    if ($acao === 'movimentar') {
        if (!usuarioTemPermissao('movimentacoes.criar')) {
            voltar('erro','Você não tem permissão para registrar movimentações.');
        }
        $produtoId=(int)($_POST['produto_id'] ?? 0);
        $tipo=$_POST['tipo'] ?? '';
        $quantidade=max(0,(int)($_POST['quantidade'] ?? 0));
        $observacao=trim($_POST['observacao'] ?? '');
        if (!in_array($tipo,['entrada','saida'],true) || $quantidade<=0) voltar('erro','Informe uma movimentação válida.');

        $conn->begin_transaction();
        try {
            $s=$conn->prepare('SELECT quantidade, estoque_minimo FROM produtos WHERE id=? FOR UPDATE');
            $s->bind_param('i',$produtoId); $s->execute();
            $produto=$s->get_result()->fetch_assoc();
            if (!$produto) throw new Exception('Produto não encontrado.');
            $atual=(int)$produto['quantidade'];
            $minimo=(int)$produto['estoque_minimo'];
            if ($tipo==='saida' && $quantidade>$atual) throw new Exception('Saída maior que o estoque disponível.');
            $nova=$tipo==='entrada' ? $atual+$quantidade : $atual-$quantidade;
            $u=$conn->prepare('UPDATE produtos SET quantidade=? WHERE id=?');
            $u->bind_param('ii',$nova,$produtoId); $u->execute();
            $usuarioId=(int)$_SESSION['usuario_id'];
            $tabela=$tipo==='entrada' ? 'entradas_estoque' : 'saidas_estoque';
            $m=$conn->prepare("INSERT INTO $tabela (produto_id,usuario_id,quantidade,observacao) VALUES (?,?,?,?)");
            $m->bind_param('iiis',$produtoId,$usuarioId,$quantidade,$observacao); $m->execute();
            $conn->commit();
            $aviso=avisoEstoqueBaixo($nova,$minimo,$atual<=$minimo);
            voltar('sucesso',ucfirst($tipo).' registrada com sucesso!'.$aviso,$tipo==='entrada'?'entradas':'saidas');
        } catch (Throwable $e) {
            $conn->rollback(); voltar('erro',$e->getMessage(),$tipo==='saida'?'saidas':'entradas');
        }
    }

    if ($acao === 'ajustar_produto') {
        if (!usuarioTemPermissao('produtos.ajustar')) {
            voltar('erro','Você não tem permissão para ajustar produtos. Fale com um gerente ou administrador.','produtos');
        }
        $produtoId = (int)($_POST['produto_id'] ?? 0);
        $nome = trim($_POST['nome'] ?? '');
        $categoria = trim($_POST['categoria'] ?? 'Outros');
        $quantidadeNova = max(0, (int)($_POST['quantidade'] ?? 0));
        $minimoNovo = max(0, (int)($_POST['minimo'] ?? 0));
        $motivo = trim($_POST['motivo'] ?? '');
        if ($nome === '') voltar('erro','Informe o nome do produto.','produtos');
        if ($categoria === '') $categoria = 'Outros';
        if ($motivo === '') voltar('erro','Informe o motivo do ajuste.','produtos');

        $conn->begin_transaction();
        try {
            $s=$conn->prepare('SELECT nome, categoria, quantidade, estoque_minimo FROM produtos WHERE id=? FOR UPDATE');
            $s->bind_param('i',$produtoId); $s->execute();
            $produto=$s->get_result()->fetch_assoc();
            if (!$produto) throw new Exception('Produto não encontrado.');
            $atual=(int)$produto['quantidade'];
            $minimoAntigo=(int)$produto['estoque_minimo'];
            $diferenca=$quantidadeNova-$atual;
            $usuarioId=(int)$_SESSION['usuario_id'];

            $u=$conn->prepare('UPDATE produtos SET nome=?, categoria=?, quantidade=?, estoque_minimo=? WHERE id=?');
            $u->bind_param('ssiii',$nome,$categoria,$quantidadeNova,$minimoNovo,$produtoId); $u->execute();

            if ($nome !== $produto['nome']) {
                registrarLog($conn,$usuarioId,'produto.nome','produto',$produtoId,$produto['nome'],$nome);
            }
            if ($categoria !== $produto['categoria']) {
                registrarLog($conn,$usuarioId,'produto.categoria','produto',$produtoId,$produto['categoria'],$categoria);
            }

            if ($diferenca !== 0) {
                $observacao='Ajuste manual: '.$motivo;
                $tabela=$diferenca>0 ? 'entradas_estoque' : 'saidas_estoque';
                $qtdMov=abs($diferenca);
                $m=$conn->prepare("INSERT INTO $tabela (produto_id,usuario_id,quantidade,observacao) VALUES (?,?,?,?)");
                $m->bind_param('iiis',$produtoId,$usuarioId,$qtdMov,$observacao); $m->execute();
            }
            $conn->commit();
            $aviso=avisoEstoqueBaixo($quantidadeNova,$minimoNovo,$atual<=$minimoAntigo);
            voltar('sucesso','Produto ajustado com sucesso!'.$aviso,'produtos');
        } catch (Throwable $e) {
            $conn->rollback(); voltar('erro',$e->getMessage(),'produtos');
        }
    }
}

$produtos=[];
$r=$conn->query('SELECT * FROM produtos ORDER BY nome'); while($row=$r->fetch_assoc()) $produtos[]=$row;
$produtosBaixo=array_values(array_filter($produtos, fn($p)=>(int)$p['quantidade'] <= (int)$p['estoque_minimo']));
$categoriasExistentes=[];
$r=$conn->query('SELECT DISTINCT categoria FROM produtos ORDER BY categoria'); while($row=$r->fetch_assoc()) $categoriasExistentes[]=$row['categoria'];
$movimentacoes=[];
$sqlHistorico = "
    SELECT e.criado_em AS data_movimentacao, 'entrada' AS tipo, e.quantidade, e.observacao,
           p.nome AS produto_nome, u.nome AS usuario_nome
    FROM entradas_estoque e
    LEFT JOIN produtos p ON p.id = e.produto_id
    LEFT JOIN usuarios u ON u.id = e.usuario_id
    UNION ALL
    SELECT s.criado_em, 'saida', s.quantidade, s.observacao,
           p.nome, u.nome
    FROM saidas_estoque s
    LEFT JOIN produtos p ON p.id = s.produto_id
    LEFT JOIN usuarios u ON u.id = s.usuario_id
    ORDER BY data_movimentacao DESC LIMIT 50
";
$r=$conn->query($sqlHistorico); while($row=$r->fetch_assoc()) $movimentacoes[]=$row;
$flash=$_SESSION['flash'] ?? null; unset($_SESSION['flash']);
$totalProdutos=(int)$conn->query('SELECT COUNT(*) total FROM produtos')->fetch_assoc()['total'];
$totalUnidades=(int)$conn->query('SELECT COALESCE(SUM(quantidade),0) total FROM produtos')->fetch_assoc()['total'];
$estoqueBaixo=count($produtosBaixo);
$movHoje=(int)$conn->query("
    SELECT
        (SELECT COUNT(*) FROM entradas_estoque WHERE DATE(criado_em)=CURDATE()) +
        (SELECT COUNT(*) FROM saidas_estoque WHERE DATE(criado_em)=CURDATE()) AS total
")->fetch_assoc()['total'];
$ultimasMov=$movimentacoes;
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>WareSys | Controle de Almoxarifado</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<aside class="sidebar">
    <div class="logo">
        <div class="logo-icon">S</div>
        <div><h1>Ware<span>Sys</span></h1><p>Controle Inteligente</p></div>
    </div>
    <nav>
        <a href="#dashboard" class="ativo"><span>⌂</span>Dashboard</a>
        <a href="#produtos"><span>📦</span>Produtos</a>
        <a href="#entradas"><span>↓</span>Entradas</a>
        <a href="#saidas"><span>↑</span>Saídas</a>
        <a href="#estoque-baixo"><span>⚠</span>Estoque Baixo</a>
        <a href="#historico"><span>📊</span>Histórico</a>
        <?php if (usuarioTemPermissao('relatorios.ver')): ?>
        <a href="relatorios.php"><span>📄</span>Relatórios</a>
        <?php endif; ?>
        <?php if (usuarioTemPermissao('config.acessar')): ?>
        <a href="configuracoes.php"><span>⚙</span>Configurações</a>
        <?php endif; ?>
    </nav>
    <div class="sidebar-bottom"><span class="versao">Versão acadêmica • MySQL / XAMPP</span></div>
</aside>

<main id="dashboard">
    <header class="topo">
        <div><p class="bem-vindo">Bem-vindo ao</p><h2>Controle de Almoxarifado</h2></div>
        <div class="topo-direita">
            <div class="notificacoes-wrap">
                <button type="button" class="notificacao-btn" onclick="toggleNotificacoes()" title="Notificações">
                    🔔
                    <?php if ($produtosBaixo): ?><span class="notificacao-badge"><?= count($produtosBaixo) ?></span><?php endif; ?>
                </button>
                <div id="painel-notificacoes" class="painel-notificacoes">
                    <h4>Estoque baixo</h4>
                    <?php if (!$produtosBaixo): ?>
                        <div class="notificacao-vazio">Nenhum produto com estoque baixo. ✓</div>
                    <?php else: foreach ($produtosBaixo as $p): ?>
                        <a href="#estoque-baixo" class="notificacao-item" onclick="document.getElementById('painel-notificacoes').classList.remove('aberto')">
                            <span><strong><?= htmlspecialchars($p['nome']) ?></strong><br><?= (int)$p['quantidade'] ?> un. (mín. <?= (int)$p['estoque_minimo'] ?>)</span>
                            <span>⚠</span>
                        </a>
                    <?php endforeach; endif; ?>
                </div>
            </div>
            <div class="perfil">
                <div class="avatar"><?= htmlspecialchars(strtoupper(substr($_SESSION['usuario_nome'] ?? 'U', 0, 1))) ?></div>
                <div><strong><?= htmlspecialchars($_SESSION['usuario_nome'] ?? 'Usuário') ?></strong><p><span class="papel-badge papel-<?= htmlspecialchars(papelAtual()) ?>"><?= htmlspecialchars(nomePapel(papelAtual())) ?></span></p></div>
                <a href="logout.php" class="btn btn-sair">Sair</a>
            </div>
        </div>
    </header>

    <?php if ($flash): ?>
        <div class="flash <?= htmlspecialchars($flash['type']) ?>"><?= htmlspecialchars($flash['message']) ?></div>
    <?php endif; ?>

    <section class="cards">
        <div class="card"><div><p>Total de Produtos</p><h3><?= $totalProdutos ?></h3><span class="positivo">itens cadastrados</span></div><div class="card-icon">📦</div></div>
        <div class="card"><div><p>Estoque Disponível</p><h3><?= $totalUnidades ?></h3><span class="positivo">unidades totais</span></div><div class="card-icon">✓</div></div>
        <div class="card" id="estoque-baixo"><div><p>Estoque Baixo</p><h3><?= $estoqueBaixo ?></h3><span class="alerta">atenção necessária</span></div><div class="card-icon">⚠</div></div>
        <div class="card"><div><p>Movimentações Hoje</p><h3><?= $movHoje ?></h3><span class="positivo">entradas e saídas</span></div><div class="card-icon">↔</div></div>
    </section>

    <section class="acoes">
        <?php if (usuarioTemPermissao('produtos.criar')): ?>
        <a href="#novo-produto" class="btn btn-principal">+ Novo Produto</a>
        <?php endif; ?>
        <a href="#entradas" class="btn">↓ Registrar Entrada</a>
        <a href="#saidas" class="btn">↑ Registrar Saída</a>
    </section>

    <section class="painel" id="produtos">
        <div class="painel-topo">
            <div><h2>Produtos em Estoque</h2><p>Consulte as quantidades disponíveis</p></div>
            <div class="pesquisa"><span>🔍</span><input id="busca" type="text" placeholder="Pesquisar produto..." oninput="filtrarProdutos()"></div>
        </div>
        <?php $podeAjustar = usuarioTemPermissao('produtos.ajustar'); ?>
        <div class="tabela-container">
            <table id="tabela-produtos">
                <thead><tr><th>Produto</th><th>Código</th><th>Categoria</th><th>Quantidade</th><th>Mínimo</th><th>Localização</th><th>Status</th><?php if ($podeAjustar): ?><th>Ações</th><?php endif; ?></tr></thead>
                <tbody>
                <?php if (!$produtos): ?>
                    <tr><td colspan="<?= $podeAjustar ? 8 : 7 ?>" class="vazio">Nenhum produto cadastrado.</td></tr>
                <?php else: foreach ($produtos as $p): $baixo=(int)$p['quantidade'] <= (int)$p['estoque_minimo']; ?>
                    <tr>
                        <td class="produto"><div class="produto-icon">📦</div><div><strong><?= htmlspecialchars($p['nome']) ?></strong><p><?= htmlspecialchars($p['observacao'] ?: 'Produto cadastrado') ?></p></div></td>
                        <td>#<?= htmlspecialchars($p['codigo']) ?></td>
                        <td><?= htmlspecialchars($p['categoria']) ?></td>
                        <td><strong><?= (int)$p['quantidade'] ?></strong> unidades</td>
                        <td><?= (int)$p['estoque_minimo'] ?></td>
                        <td><?= htmlspecialchars($p['localizacao'] ?: '—') ?></td>
                        <td><span class="status <?= $baixo ? 'baixo' : 'disponivel' ?>"><?= $baixo ? 'Estoque Baixo' : 'Disponível' ?></span></td>
                        <?php if ($podeAjustar): ?>
                        <td>
                            <button type="button" class="acao-btn" title="Ajustar produto"
                                data-id="<?= (int)$p['id'] ?>"
                                data-nome="<?= htmlspecialchars($p['nome'], ENT_QUOTES) ?>"
                                data-categoria="<?= htmlspecialchars($p['categoria'], ENT_QUOTES) ?>"
                                data-quantidade="<?= (int)$p['quantidade'] ?>"
                                data-minimo="<?= (int)$p['estoque_minimo'] ?>"
                                onclick="abrirAjuste(this)">✏️</button>
                        </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="grid-mov">
        <div class="secao-simples" id="entradas">
            <h2>Registrar Entrada</h2><p>Adicione unidades a um produto existente.</p>
            <form method="post" class="form-mov">
                <input type="hidden" name="acao" value="movimentar"><input type="hidden" name="tipo" value="entrada">
                <div class="campo full"><label>Produto</label><select name="produto_id" required><option value="">Selecione...</option><?php foreach($produtos as $p): ?><option value="<?= (int)$p['id'] ?>"><?= htmlspecialchars($p['nome']) ?> — <?= (int)$p['quantidade'] ?> un.</option><?php endforeach; ?></select></div>
                <div class="campo"><label>Quantidade</label><input type="number" name="quantidade" min="1" required></div>
                <div class="campo"><label>Observação</label><input type="text" name="observacao" placeholder="Ex: compra do fornecedor"></div>
                <button class="btn-salvar full" type="submit">Registrar Entrada</button>
            </form>
        </div>

        <div class="secao-simples" id="saidas">
            <h2>Registrar Saída</h2><p>Retire unidades do estoque sem permitir saldo negativo.</p>
            <form method="post" class="form-mov">
                <input type="hidden" name="acao" value="movimentar"><input type="hidden" name="tipo" value="saida">
                <div class="campo full"><label>Produto</label><select name="produto_id" required><option value="">Selecione...</option><?php foreach($produtos as $p): ?><option value="<?= (int)$p['id'] ?>"><?= htmlspecialchars($p['nome']) ?> — <?= (int)$p['quantidade'] ?> un.</option><?php endforeach; ?></select></div>
                <div class="campo"><label>Quantidade</label><input type="number" name="quantidade" min="1" required></div>
                <div class="campo"><label>Observação</label><input type="text" name="observacao" placeholder="Ex: uso no setor administrativo"></div>
                <button class="btn-salvar full" type="submit">Registrar Saída</button>
            </form>
        </div>
    </section>

    <section class="secao-simples" id="historico">
        <div class="painel-topo">
            <div><h2>Movimentações</h2><p>Histórico de entradas e saídas de estoque.</p></div>
            <div class="tabs-historico">
                <button type="button" class="tab-btn ativo" data-filtro="todas" onclick="filtrarHistorico('todas',this)">Entrada e Saída</button>
                <button type="button" class="tab-btn" data-filtro="entrada" onclick="filtrarHistorico('entrada',this)">Entrada</button>
                <button type="button" class="tab-btn" data-filtro="saida" onclick="filtrarHistorico('saida',this)">Saída</button>
            </div>
        </div>
        <div class="tabela-container historico"><table id="tabela-historico"><thead><tr><th>Data</th><th>Produto</th><th>Tipo</th><th>Quantidade</th><th>Responsável</th><th>Observação</th></tr></thead><tbody>
        <?php if (!$ultimasMov): ?><tr><td colspan="6" class="vazio">Nenhuma movimentação registrada.</td></tr>
        <?php else: foreach($ultimasMov as $m): ?><tr data-tipo="<?= htmlspecialchars($m['tipo']) ?>"><td><?= date('d/m/Y H:i', strtotime($m['data_movimentacao'])) ?></td><td><?= htmlspecialchars($m['produto_nome']) ?></td><td><span class="badge-tipo <?= $m['tipo'] ?>"><?= strtoupper($m['tipo']) ?></span></td><td><?= (int)$m['quantidade'] ?></td><td><?= htmlspecialchars($m['usuario_nome'] ?: '—') ?></td><td><?= htmlspecialchars($m['observacao'] ?: '—') ?></td></tr><?php endforeach; endif; ?>
        </tbody></table></div>
    </section>

    <footer><p>WareSys © 2026 — Sistema acadêmico de Controle de Almoxarifado</p></footer>
</main>

<?php if (usuarioTemPermissao('produtos.criar')): ?>
<div class="modal" id="novo-produto"><div class="modal-box"><a href="#" class="fechar">×</a><div class="modal-topo"><h2>Novo Produto</h2><p>Cadastre um novo item no estoque.</p></div>
<form method="post"><input type="hidden" name="acao" value="novo_produto">
    <div class="campo full"><label for="nome">Nome do Produto</label><input type="text" id="nome" name="nome" required></div>
    <div class="campo"><label for="categoria">Categoria</label><select id="categoria" name="categoria"><option>Informática</option><option>Escritório</option><option>Limpeza</option><option>Equipamento</option><option>Outros</option></select></div>
    <div class="campo"><label for="quantidade">Quantidade inicial</label><input type="number" id="quantidade" name="quantidade" min="0" value="0" required></div>
    <div class="campo"><label for="minimo">Estoque Mínimo</label><input type="number" id="minimo" name="minimo" min="0" value="0"></div>
    <div class="campo"><label for="localizacao">Localização</label><input type="text" id="localizacao" name="localizacao" placeholder="Ex: Prateleira A"></div>
    <div class="campo full"><label for="observacao">Observações</label><textarea id="observacao" name="observacao"></textarea></div>
    <div class="form-botoes"><a href="#" class="btn-cancelar">Cancelar</a><button type="submit" class="btn-salvar">Salvar Produto</button></div>
</form></div></div>
<?php endif; ?>

<datalist id="lista-categorias">
    <?php foreach ($categoriasExistentes as $c): ?><option value="<?= htmlspecialchars($c) ?>"><?php endforeach; ?>
</datalist>

<?php if ($podeAjustar): ?>
<div class="modal" id="modal-ajustar" style="display:none;">
    <div class="modal-box">
        <a href="javascript:void(0)" class="fechar" onclick="fecharAjuste()">×</a>
        <div class="modal-topo"><h2>Ajustar Produto</h2><p>Corrija o cadastro e a quantidade em estoque diretamente.</p></div>
        <form method="post">
            <input type="hidden" name="acao" value="ajustar_produto">
            <input type="hidden" name="produto_id" id="ajuste_produto_id">
            <div class="campo full"><label for="ajuste_nome">Nome do Produto</label><input type="text" id="ajuste_nome" name="nome" required></div>
            <div class="campo"><label for="ajuste_categoria">Categoria</label><input type="text" id="ajuste_categoria" name="categoria" list="lista-categorias" required></div>
            <div class="campo"><label for="ajuste_quantidade">Quantidade em estoque</label><input type="number" id="ajuste_quantidade" name="quantidade" min="0" required></div>
            <div class="campo"><label for="ajuste_minimo">Estoque Mínimo</label><input type="number" id="ajuste_minimo" name="minimo" min="0" required></div>
            <div class="campo full"><label for="ajuste_motivo">Motivo do ajuste</label><textarea id="ajuste_motivo" name="motivo" placeholder="Ex: contagem de inventário, produto danificado, correção de lançamento..." required></textarea></div>
            <div class="form-botoes"><a href="javascript:void(0)" class="btn-cancelar" onclick="fecharAjuste()">Cancelar</a><button type="submit" class="btn-salvar">Salvar Ajuste</button></div>
        </form>
    </div>
</div>
<?php endif; ?>

<script>
function abrirAjuste(botao){
  document.getElementById('ajuste_produto_id').value = botao.dataset.id;
  document.getElementById('ajuste_nome').value = botao.dataset.nome;
  document.getElementById('ajuste_categoria').value = botao.dataset.categoria;
  document.getElementById('ajuste_quantidade').value = botao.dataset.quantidade;
  document.getElementById('ajuste_minimo').value = botao.dataset.minimo;
  document.getElementById('ajuste_motivo').value = '';
  document.getElementById('modal-ajustar').style.display = 'flex';
}
function fecharAjuste(){
  document.getElementById('modal-ajustar').style.display = 'none';
}

function toggleNotificacoes(){
  document.getElementById('painel-notificacoes').classList.toggle('aberto');
}
document.addEventListener('click', function(e){
  const wrap = document.querySelector('.notificacoes-wrap');
  if (wrap && !wrap.contains(e.target)) {
    document.getElementById('painel-notificacoes').classList.remove('aberto');
  }
});

function filtrarProdutos(){
  const termo=document.getElementById('busca').value.toLowerCase();
  document.querySelectorAll('#tabela-produtos tbody tr').forEach(linha=>{
    linha.style.display=linha.innerText.toLowerCase().includes(termo)?'':'none';
  });
}

function filtrarHistorico(tipo, botao){
  document.querySelectorAll('.tabs-historico .tab-btn').forEach(b=>b.classList.remove('ativo'));
  botao.classList.add('ativo');
  document.querySelectorAll('#tabela-historico tbody tr[data-tipo]').forEach(linha=>{
    linha.style.display=(tipo==='todas' || linha.dataset.tipo===tipo)?'':'none';
  });
}
</script>
</body>
</html>
