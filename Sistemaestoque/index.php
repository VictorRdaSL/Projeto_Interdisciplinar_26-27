<?php
session_start();
require_once __DIR__ . '/config/database.php';

function voltar(string $tipo, string $mensagem, string $ancora='dashboard'): never {
    $_SESSION['flash'] = ['type'=>$tipo, 'message'=>$mensagem];
    header('Location: index.php#'.$ancora);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = $_POST['acao'] ?? '';

    if ($acao === 'novo_produto') {
        $nome = trim($_POST['nome'] ?? '');
        $codigo = trim($_POST['codigo'] ?? '');
        $categoria = trim($_POST['categoria'] ?? 'Outros');
        $quantidade = max(0, (int)($_POST['quantidade'] ?? 0));
        $minimo = max(0, (int)($_POST['minimo'] ?? 0));
        $localizacao = trim($_POST['localizacao'] ?? '');
        $observacao = trim($_POST['observacao'] ?? '');
        if ($nome === '') voltar('erro','Informe o nome do produto.','novo-produto');
        if ($codigo === '') $codigo = 'P'.date('ymdHis');

        $stmt=$conn->prepare('INSERT INTO produtos (nome,codigo,categoria,quantidade,estoque_minimo,localizacao,observacao) VALUES (?,?,?,?,?,?,?)');
        $stmt->bind_param('sssiiss',$nome,$codigo,$categoria,$quantidade,$minimo,$localizacao,$observacao);
        try {
            $stmt->execute();
            $produtoId=$conn->insert_id;
            if ($quantidade > 0) {
                $tipo='entrada'; $obs='Estoque inicial';
                $m=$conn->prepare('INSERT INTO movimentacoes (produto_id,tipo,quantidade,observacao) VALUES (?,?,?,?)');
                $m->bind_param('isis',$produtoId,$tipo,$quantidade,$obs); $m->execute();
            }
            voltar('sucesso','Produto cadastrado com sucesso!','produtos');
        } catch (mysqli_sql_exception $e) {
            voltar('erro','Não foi possível cadastrar. Verifique se o código já existe.','novo-produto');
        }
    }

    if ($acao === 'movimentar') {
        $produtoId=(int)($_POST['produto_id'] ?? 0);
        $tipo=$_POST['tipo'] ?? '';
        $quantidade=max(0,(int)($_POST['quantidade'] ?? 0));
        $observacao=trim($_POST['observacao'] ?? '');
        if (!in_array($tipo,['entrada','saida'],true) || $quantidade<=0) voltar('erro','Informe uma movimentação válida.');

        $conn->begin_transaction();
        try {
            $s=$conn->prepare('SELECT quantidade FROM produtos WHERE id=? FOR UPDATE');
            $s->bind_param('i',$produtoId); $s->execute();
            $produto=$s->get_result()->fetch_assoc();
            if (!$produto) throw new Exception('Produto não encontrado.');
            $atual=(int)$produto['quantidade'];
            if ($tipo==='saida' && $quantidade>$atual) throw new Exception('Saída maior que o estoque disponível.');
            $nova=$tipo==='entrada' ? $atual+$quantidade : $atual-$quantidade;
            $u=$conn->prepare('UPDATE produtos SET quantidade=? WHERE id=?');
            $u->bind_param('ii',$nova,$produtoId); $u->execute();
            $m=$conn->prepare('INSERT INTO movimentacoes (produto_id,tipo,quantidade,observacao) VALUES (?,?,?,?)');
            $m->bind_param('isis',$produtoId,$tipo,$quantidade,$observacao); $m->execute();
            $conn->commit();
            voltar('sucesso',ucfirst($tipo).' registrada com sucesso!',$tipo==='entrada'?'entradas':'saidas');
        } catch (Throwable $e) {
            $conn->rollback(); voltar('erro',$e->getMessage(),$tipo==='saida'?'saidas':'entradas');
        }
    }
}

$produtos=[];
$r=$conn->query('SELECT * FROM produtos ORDER BY nome'); while($row=$r->fetch_assoc()) $produtos[]=$row;
$movimentacoes=[];
$r=$conn->query('SELECT m.*, p.nome AS produto_nome FROM movimentacoes m LEFT JOIN produtos p ON p.id=m.produto_id ORDER BY m.data_movimentacao DESC, m.id DESC LIMIT 8'); while($row=$r->fetch_assoc()) $movimentacoes[]=$row;
$flash=$_SESSION['flash'] ?? null; unset($_SESSION['flash']);
$totalProdutos=(int)$conn->query('SELECT COUNT(*) total FROM produtos')->fetch_assoc()['total'];
$totalUnidades=(int)$conn->query('SELECT COALESCE(SUM(quantidade),0) total FROM produtos')->fetch_assoc()['total'];
$estoqueBaixo=(int)$conn->query('SELECT COUNT(*) total FROM produtos WHERE quantidade <= estoque_minimo')->fetch_assoc()['total'];
$movHoje=(int)$conn->query('SELECT COUNT(*) total FROM movimentacoes WHERE DATE(data_movimentacao)=CURDATE()')->fetch_assoc()['total'];
$ultimasMov=$movimentacoes;
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Soft.Ware | Controle de Almoxarifado</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<aside class="sidebar">
    <div class="logo">
        <div class="logo-icon">S</div>
        <div><h1>Soft<span>.Ware</span></h1><p>Controle Inteligente</p></div>
    </div>
    <nav>
        <a href="#dashboard" class="ativo"><span>⌂</span>Dashboard</a>
        <a href="#produtos"><span>📦</span>Produtos</a>
        <a href="#entradas"><span>↓</span>Entradas</a>
        <a href="#saidas"><span>↑</span>Saídas</a>
        <a href="#estoque-baixo"><span>⚠</span>Estoque Baixo</a>
        <a href="#historico"><span>📊</span>Histórico</a>
    </nav>
    <div class="sidebar-bottom"><span class="versao">Versão acadêmica • MySQL / XAMPP</span></div>
</aside>

<main id="dashboard">
    <header class="topo">
        <div><p class="bem-vindo">Bem-vindo ao</p><h2>Controle de Almoxarifado</h2></div>
        <div class="perfil"><div class="avatar">A</div><div><strong>Administrador</strong><p>Execução local</p></div></div>
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
        <a href="#novo-produto" class="btn btn-principal">+ Novo Produto</a>
        <a href="#entradas" class="btn">↓ Registrar Entrada</a>
        <a href="#saidas" class="btn">↑ Registrar Saída</a>
    </section>

    <section class="painel" id="produtos">
        <div class="painel-topo">
            <div><h2>Produtos em Estoque</h2><p>Consulte as quantidades disponíveis</p></div>
            <div class="pesquisa"><span>🔍</span><input id="busca" type="text" placeholder="Pesquisar produto..." oninput="filtrarProdutos()"></div>
        </div>
        <div class="tabela-container">
            <table id="tabela-produtos">
                <thead><tr><th>Produto</th><th>Código</th><th>Categoria</th><th>Quantidade</th><th>Mínimo</th><th>Localização</th><th>Status</th></tr></thead>
                <tbody>
                <?php if (!$produtos): ?>
                    <tr><td colspan="7" class="vazio">Nenhum produto cadastrado.</td></tr>
                <?php else: foreach ($produtos as $p): $baixo=(int)$p['quantidade'] <= (int)$p['estoque_minimo']; ?>
                    <tr>
                        <td class="produto"><div class="produto-icon">📦</div><div><strong><?= htmlspecialchars($p['nome']) ?></strong><p><?= htmlspecialchars($p['observacao'] ?: 'Produto cadastrado') ?></p></div></td>
                        <td>#<?= htmlspecialchars($p['codigo']) ?></td>
                        <td><?= htmlspecialchars($p['categoria']) ?></td>
                        <td><strong><?= (int)$p['quantidade'] ?></strong> unidades</td>
                        <td><?= (int)$p['estoque_minimo'] ?></td>
                        <td><?= htmlspecialchars($p['localizacao'] ?: '—') ?></td>
                        <td><span class="status <?= $baixo ? 'baixo' : 'disponivel' ?>"><?= $baixo ? 'Estoque Baixo' : 'Disponível' ?></span></td>
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
        <h2>Últimas movimentações</h2><p>Histórico simplificado das entradas e saídas.</p>
        <div class="tabela-container historico"><table><thead><tr><th>Data</th><th>Produto</th><th>Tipo</th><th>Quantidade</th><th>Observação</th></tr></thead><tbody>
        <?php if (!$ultimasMov): ?><tr><td colspan="5" class="vazio">Nenhuma movimentação registrada.</td></tr>
        <?php else: foreach($ultimasMov as $m): ?><tr><td><?= date('d/m/Y H:i', strtotime($m['data_movimentacao'])) ?></td><td><?= htmlspecialchars($m['produto_nome']) ?></td><td><span class="badge-tipo <?= $m['tipo'] ?>"><?= strtoupper($m['tipo']) ?></span></td><td><?= (int)$m['quantidade'] ?></td><td><?= htmlspecialchars($m['observacao'] ?: '—') ?></td></tr><?php endforeach; endif; ?>
        </tbody></table></div>
    </section>

    <footer><p>Soft.Ware © 2026 — Sistema acadêmico de Controle de Almoxarifado</p></footer>
</main>

<div class="modal" id="novo-produto"><div class="modal-box"><a href="#" class="fechar">×</a><div class="modal-topo"><h2>Novo Produto</h2><p>Cadastre um novo item no estoque.</p></div>
<form method="post"><input type="hidden" name="acao" value="novo_produto">
    <div class="campo"><label for="nome">Nome do Produto</label><input type="text" id="nome" name="nome" required></div>
    <div class="campo"><label for="codigo">Código</label><input type="text" id="codigo" name="codigo" placeholder="Ex: 0005"></div>
    <div class="campo"><label for="categoria">Categoria</label><select id="categoria" name="categoria"><option>Informática</option><option>Escritório</option><option>Limpeza</option><option>Equipamento</option><option>Outros</option></select></div>
    <div class="campo"><label for="quantidade">Quantidade inicial</label><input type="number" id="quantidade" name="quantidade" min="0" value="0" required></div>
    <div class="campo"><label for="minimo">Estoque Mínimo</label><input type="number" id="minimo" name="minimo" min="0" value="0"></div>
    <div class="campo"><label for="localizacao">Localização</label><input type="text" id="localizacao" name="localizacao" placeholder="Ex: Prateleira A"></div>
    <div class="campo full"><label for="observacao">Observações</label><textarea id="observacao" name="observacao"></textarea></div>
    <div class="form-botoes"><a href="#" class="btn-cancelar">Cancelar</a><button type="submit" class="btn-salvar">Salvar Produto</button></div>
</form></div></div>

<script>
function filtrarProdutos(){
  const termo=document.getElementById('busca').value.toLowerCase();
  document.querySelectorAll('#tabela-produtos tbody tr').forEach(linha=>{
    linha.style.display=linha.innerText.toLowerCase().includes(termo)?'':'none';
  });
}
</script>
</body>
</html>
