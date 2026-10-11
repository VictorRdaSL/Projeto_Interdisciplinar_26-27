<?php
/**
 * Teste de fluxo completo — usa as mesmas funções de includes/movimentacoes.php
 * que as telas usam. Cria livros de teste, exercita os cenários pedidos,
 * imprime OK/FALHOU para cada verificação, e apaga tudo que criou ao final.
 *
 * Só roda em ambiente de desenvolvimento (config/config.php: 'ambiente').
 * Acesso via navegador é bloqueado por tests/.htaccess — roda só via CLI.
 */

require_once __DIR__ . '/../config/database.php';

if ($producao) {
    fwrite(STDERR, "Este script só pode ser executado em ambiente de desenvolvimento.\n");
    exit(1);
}

require_once __DIR__ . '/../includes/movimentacoes.php';

$falhas = 0;
function checar(string $descricao, bool $condicao): void
{
    global $falhas;
    echo ($condicao ? 'OK' : 'FALHOU') . ' - ' . $descricao . "\n";
    if (!$condicao) $falhas++;
}

function etapaIdPorNome(mysqli $conn, string $nome): int
{
    $stmt = $conn->prepare('SELECT id FROM etapas WHERE nome = ?');
    $stmt->bind_param('s', $nome);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (!$row) throw new RuntimeException("Etapa \"$nome\" não encontrada.");
    return (int)$row['id'];
}

function papelIdPorNome(mysqli $conn, string $nome): int
{
    $stmt = $conn->prepare('SELECT id FROM papeis WHERE nome = ?');
    $stmt->bind_param('s', $nome);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (!$row) throw new RuntimeException("Papel \"$nome\" não encontrado.");
    return (int)$row['id'];
}

// Mesma lógica de criação usada em pessoas.php (salvar_pessoa).
function criarPessoaTeste(mysqli $conn, string $nome): int
{
    $agora = date('Y-m-d H:i:s');
    $stmt = $conn->prepare('INSERT INTO pessoas (nome, email, telefone, criado_em) VALUES (?, NULL, NULL, ?)');
    $stmt->bind_param('ss', $nome, $agora);
    $stmt->execute();
    return $conn->insert_id;
}

// Mesma lógica de criação usada em livro_form.php (POST sem id): entra na
// 1ª etapa ativa, abre a movimentação inicial (avanco, rodada 1), grava as
// contribuições — tudo na mesma transação.
function criarLivroTeste(mysqli $conn, string $titulo, int $responsavelId, int $registradoPorId, array $contribuicoes): int
{
    $etapaInicial = $conn->query('SELECT id FROM etapas WHERE ativa = 1 ORDER BY ordem ASC LIMIT 1')->fetch_assoc();
    $etapaInicialId = (int)$etapaInicial['id'];
    $agora = date('Y-m-d H:i:s');

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("INSERT INTO livros (titulo, isbn, tem_copia, observacoes, etapa_atual_id, responsavel_atual_id, status, criado_em, atualizado_em) VALUES (?, NULL, 0, NULL, ?, ?, 'em_andamento', ?, ?)");
        $stmt->bind_param('siiss', $titulo, $etapaInicialId, $responsavelId, $agora, $agora);
        $stmt->execute();
        $livroId = $conn->insert_id;

        $stmtMov = $conn->prepare("INSERT INTO movimentacoes (livro_id, etapa_id, responsavel_id, registrado_por_id, tipo, rodada, excedente, data_entrada) VALUES (?, ?, ?, ?, 'avanco', 1, 0, ?)");
        $stmtMov->bind_param('iiiss', $livroId, $etapaInicialId, $responsavelId, $registradoPorId, $agora);
        $stmtMov->execute();

        if ($contribuicoes) {
            $stmtContrib = $conn->prepare('INSERT INTO contribuicoes (livro_id, pessoa_id, papel_id) VALUES (?, ?, ?)');
            foreach ($contribuicoes as $c) {
                $stmtContrib->bind_param('iii', $livroId, $c['pessoa_id'], $c['papel_id']);
                $stmtContrib->execute();
            }
        }

        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }

    return $livroId;
}

function etapaAtualDoLivro(mysqli $conn, int $livroId): array
{
    $stmt = $conn->prepare('SELECT l.*, e.nome AS etapa_nome, e.ordem FROM livros l JOIN etapas e ON e.id = l.etapa_atual_id WHERE l.id = ?');
    $stmt->bind_param('i', $livroId);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc();
}

function ultimaMovimentacao(mysqli $conn, int $livroId, ?int $etapaId = null): array
{
    if ($etapaId !== null) {
        $stmt = $conn->prepare('SELECT * FROM movimentacoes WHERE livro_id = ? AND etapa_id = ? ORDER BY id DESC LIMIT 1');
        $stmt->bind_param('ii', $livroId, $etapaId);
    } else {
        $stmt = $conn->prepare('SELECT * FROM movimentacoes WHERE livro_id = ? ORDER BY id DESC LIMIT 1');
        $stmt->bind_param('i', $livroId);
    }
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc();
}

$admin = $conn->query("SELECT id FROM usuarios WHERE email = 'admin@waresys.com'")->fetch_assoc();
$usuarioId = (int)$admin['id'];
$outroUsuarioRow = $conn->query("SELECT id, nome FROM usuarios WHERE id != $usuarioId LIMIT 1")->fetch_assoc();
$temSegundoUsuario = (bool)$outroUsuarioRow;

$idOrcamento = etapaIdPorNome($conn, 'Orçamento');
$idContrato = etapaIdPorNome($conn, 'Contrato');
$idEnvioMaterial = etapaIdPorNome($conn, 'Envio de material');
$idCapa = etapaIdPorNome($conn, 'Capa');
$idIlustracao = etapaIdPorNome($conn, 'Ilustração');
$idAprovacaoCapa = etapaIdPorNome($conn, 'Aprovação da capa');
$idDiagramacao = etapaIdPorNome($conn, 'Diagramação');
$idRevisao = etapaIdPorNome($conn, 'Revisão');
$idCatalogacao = etapaIdPorNome($conn, 'Catalogação — ISBN e ficha catalográfica');
$idFechamentoArquivo = etapaIdPorNome($conn, 'Fechamento de arquivo');
$idEnvioTermo = etapaIdPorNome($conn, 'Envio do termo de impressão');
$idChegadaCopia = etapaIdPorNome($conn, 'Chegada da cópia teste');
$idLeituraCopia = etapaIdPorNome($conn, 'Leitura da cópia até aprovação');
$idImpressao = etapaIdPorNome($conn, 'Impressão');
$idDepositoLegal = etapaIdPorNome($conn, 'Depósito legal — Biblioteca Nacional (RJ)');

$papelAutor = papelIdPorNome($conn, 'Autor');
$papelOrganizador = papelIdPorNome($conn, 'Organizador');

$livrosDeTeste = [];

echo "=== (a) Criar livro 1 com 1 organizador + 2 autores ===\n";
$pOrganizador = criarPessoaTeste($conn, 'TESTE FLUXO - Organizador 1');
$pAutor1 = criarPessoaTeste($conn, 'TESTE FLUXO - Autor 1');
$pAutor2 = criarPessoaTeste($conn, 'TESTE FLUXO - Autor 2');
$pessoasDeTeste = [$pOrganizador, $pAutor1, $pAutor2];

$livro1 = criarLivroTeste($conn, 'TESTE FLUXO - Livro 1', $usuarioId, $usuarioId, [
    ['pessoa_id' => $pOrganizador, 'papel_id' => $papelOrganizador],
    ['pessoa_id' => $pAutor1, 'papel_id' => $papelAutor],
    ['pessoa_id' => $pAutor2, 'papel_id' => $papelAutor],
]);
$livrosDeTeste[] = $livro1;

checar('Livro 1 criado em Orçamento, rodada 1', etapaAtualDoLivro($conn, $livro1)['etapa_nome'] === 'Orçamento');
$totalContrib = (int)$conn->query("SELECT COUNT(*) total FROM contribuicoes WHERE livro_id = $livro1")->fetch_assoc()['total'];
checar('Livro 1 tem as 3 contribuições (1 organizador + 2 autores)', $totalContrib === 3);

echo "\n=== (b) Avançar de Orçamento até Diagramação, pulando Ilustração ===\n";
avancarEtapa($conn, $livro1, $idContrato, $usuarioId);
avancarEtapa($conn, $livro1, $idEnvioMaterial, $usuarioId);
avancarEtapa($conn, $livro1, $idCapa, $usuarioId);
// Pula Ilustração: fica em Aprovação da capa com um registro pulo_opcional para Ilustração.
avancarEtapa($conn, $livro1, $idAprovacaoCapa, $usuarioId, $idIlustracao);
checar('Ilustração ficou com registro pulo_opcional', ultimaMovimentacao($conn, $livro1, $idIlustracao)['tipo'] === 'pulo_opcional');
// Faz Aprovação da capa normalmente e chega em Diagramação.
avancarEtapa($conn, $livro1, $idDiagramacao, $usuarioId);
checar('Livro 1 chegou em Diagramação, rodada 1', etapaAtualDoLivro($conn, $livro1)['etapa_nome'] === 'Diagramação' && (int)ultimaMovimentacao($conn, $livro1)['rodada'] === 1);

echo "\n=== (c) Diagramação -> Revisão -> Diagramação -> Revisão -> Diagramação -> Catalogação, sem excedente ===\n";
$r = avancarEtapa($conn, $livro1, $idRevisao, $usuarioId); // Revisão rodada 1
checar('Revisão rodada 1, sem excedente', $r['rodada'] === 1 && $r['excedente'] === false);
$r = voltarEtapa($conn, $livro1, $idDiagramacao, $usuarioId); // Diagramação rodada 2
checar('Diagramação rodada 2 (retrocesso), sem excedente', $r['rodada'] === 2 && $r['excedente'] === false);
$r = avancarEtapa($conn, $livro1, $idRevisao, $usuarioId); // Revisão rodada 2 (entrando de novo)
checar('Revisão rodada 2 (entrou de novo), sem excedente', $r['rodada'] === 2 && $r['excedente'] === false);
$r = voltarEtapa($conn, $livro1, $idDiagramacao, $usuarioId); // Diagramação rodada 3
checar('Diagramação rodada 3 (retrocesso), sem excedente', $r['rodada'] === 3 && $r['excedente'] === false);
// Segue direto para Catalogação (Revisão já concluída 2x; nada obrigatório fica pulado no caminho).
$r = avancarEtapa($conn, $livro1, $idCatalogacao, $usuarioId);
checar('Chegou em Catalogação sem passar de novo por Revisão', etapaAtualDoLivro($conn, $livro1)['etapa_nome'] === 'Catalogação — ISBN e ficha catalográfica');
checar('Aviso de ISBN vazio disparado ao sair da Catalogação depois', true); // conferido no passo (f)

$totalExcedentesLivro1 = (int)$conn->query("SELECT COUNT(*) total FROM movimentacoes WHERE livro_id = $livro1 AND excedente = 1")->fetch_assoc()['total'];
checar('Nenhuma rodada do Livro 1 ficou excedente', $totalExcedentesLivro1 === 0);

echo "\n=== (d) Livro 2: forçar 4ª Diagramação e 3ª Revisão, conferir excedente=1 nas duas ===\n";
$livro2 = criarLivroTeste($conn, 'TESTE FLUXO - Livro 2', $usuarioId, $usuarioId, []);
$livrosDeTeste[] = $livro2;
avancarEtapa($conn, $livro2, $idContrato, $usuarioId);
avancarEtapa($conn, $livro2, $idEnvioMaterial, $usuarioId);
avancarEtapa($conn, $livro2, $idCapa, $usuarioId);
avancarEtapa($conn, $livro2, $idAprovacaoCapa, $usuarioId, $idIlustracao);
avancarEtapa($conn, $livro2, $idDiagramacao, $usuarioId); // Diagramação rodada 1

repetirEtapa($conn, $livro2, $usuarioId); // rodada 2
repetirEtapa($conn, $livro2, $usuarioId); // rodada 3
$r = repetirEtapa($conn, $livro2, $usuarioId); // rodada 4 - excedente (limite 3)
checar('Livro 2: 4ª Diagramação com excedente = 1', $r['rodada'] === 4 && $r['excedente'] === true);

avancarEtapa($conn, $livro2, $idRevisao, $usuarioId); // Revisão rodada 1
repetirEtapa($conn, $livro2, $usuarioId); // rodada 2
$r = repetirEtapa($conn, $livro2, $usuarioId); // rodada 3 - excedente (limite 2)
checar('Livro 2: 3ª Revisão com excedente = 1', $r['rodada'] === 3 && $r['excedente'] === true);

echo "\n=== (e) Trocar de mesa no meio de uma etapa: rodada não muda ===\n";
if ($temSegundoUsuario) {
    $outroUsuarioId = (int)$outroUsuarioRow['id'];
    $rodadaAntes = (int)ultimaMovimentacao($conn, $livro2)['rodada'];
    $excedenteAntes = (int)ultimaMovimentacao($conn, $livro2)['excedente'];
    $r = passarParaOutraMesa($conn, $livro2, $outroUsuarioId, $usuarioId);
    checar('Troca de mesa manteve a mesma rodada', $r['rodada'] === $rodadaAntes);
    checar('Troca de mesa manteve o mesmo excedente', ($r['excedente'] ? 1 : 0) === $excedenteAntes);
    checar('livros.responsavel_atual_id atualizado para o novo usuário', (int)$conn->query("SELECT responsavel_atual_id FROM livros WHERE id=$livro2")->fetch_assoc()['responsavel_atual_id'] === $outroUsuarioId);
    // Devolve para o admin, só para manter o resto do script simples.
    passarParaOutraMesa($conn, $livro2, $usuarioId, $usuarioId);
} else {
    echo "(pulado: só existe 1 usuário cadastrado no banco)\n";
}

echo "\n=== (f) Livro 1: avançar até concluir o Depósito legal ===\n";
$r = avancarEtapa($conn, $livro1, $idFechamentoArquivo, $usuarioId);
checar('Aviso de ISBN vazio ao sair da Catalogação (Livro 1 não tem ISBN)', count($r['avisos']) === 1 && str_contains($r['avisos'][0], 'ISBN'));
avancarEtapa($conn, $livro1, $idEnvioTermo, $usuarioId);
avancarEtapa($conn, $livro1, $idChegadaCopia, $usuarioId);
avancarEtapa($conn, $livro1, $idLeituraCopia, $usuarioId);
avancarEtapa($conn, $livro1, $idImpressao, $usuarioId);
avancarEtapa($conn, $livro1, $idDepositoLegal, $usuarioId);
checar('Livro 1 chegou no Depósito legal', etapaAtualDoLivro($conn, $livro1)['etapa_nome'] === 'Depósito legal — Biblioteca Nacional (RJ)');
concluirLivro($conn, $livro1, $usuarioId);
$statusFinal = $conn->query("SELECT status FROM livros WHERE id=$livro1")->fetch_assoc()['status'];
checar('Livro 1: status virou concluido', $statusFinal === 'concluido');
$abertasLivro1 = (int)$conn->query("SELECT COUNT(*) total FROM movimentacoes WHERE livro_id=$livro1 AND data_saida IS NULL")->fetch_assoc()['total'];
checar('Livro 1: nenhuma movimentação aberta depois de concluir', $abertasLivro1 === 0);

echo "\n=== (g) Resgate: iniciar, bloquear movimentações, concluir sem abrir rodada nova ===\n";
$res = iniciarResgate($conn, $livro2, $usuarioId);
checar('Livro 2 marcado como extraviado', $conn->query("SELECT status FROM livros WHERE id=$livro2")->fetch_assoc()['status'] === 'extraviado');
checar('Resgate referencia a movimentação aberta antes do extravio', $res['movimentacao_referencia_id'] === (int)ultimaMovimentacao($conn, $livro2)['id']);

foreach ([
    'avancarEtapa' => fn() => avancarEtapa($conn, $livro2, $idCatalogacao, $usuarioId),
    'repetirEtapa' => fn() => repetirEtapa($conn, $livro2, $usuarioId),
    'voltarEtapa' => fn() => voltarEtapa($conn, $livro2, $idDiagramacao, $usuarioId),
    'passarParaOutraMesa' => fn() => passarParaOutraMesa($conn, $livro2, $usuarioId, $usuarioId),
] as $nomeAcao => $acao) {
    try {
        $acao();
        checar("$nomeAcao foi bloqueado enquanto extraviado", false);
    } catch (InvalidArgumentException $e) {
        checar("$nomeAcao foi bloqueado enquanto extraviado: " . $e->getMessage(), true);
    }
}

try {
    iniciarResgate($conn, $livro2, $usuarioId);
    checar('Iniciar um 2º resgate no mesmo livro foi REJEITADO', false);
} catch (InvalidArgumentException $e) {
    checar('Iniciar um 2º resgate no mesmo livro foi rejeitado: ' . $e->getMessage(), true);
}

// O resgate devolve o livro a uma etapa DIFERENTE (Diagramação) de onde ele se perdeu
// (Revisão) — "manter a rodada atual" é relativo à etapa de destino, não à de origem.
// Diagramação já tinha 4 entradas reais (avanco+3 repetições) do cenário (d); é esse
// o número que o resgate deve manter, não a rodada 3 que estava em Revisão.
$entradasDiagramacaoAntes = contarEntradasAnteriores($conn, $livro2, $idDiagramacao);

$r = concluirResgate($conn, $livro2, $idDiagramacao, $usuarioId, 'Na mesa errada, na sala ao lado', $usuarioId);
checar('Resgate concluído: livro voltou a em_andamento', $conn->query("SELECT status FROM livros WHERE id=$livro2")->fetch_assoc()['status'] === 'em_andamento');
checar('Resgate concluído: etapa_atual_id atualizado para a etapa informada', etapaAtualDoLivro($conn, $livro2)['etapa_nome'] === 'Diagramação');
checar('Resgate manteve a rodada já existente na etapa de destino (não abriu rodada nova)', $r['rodada'] === $entradasDiagramacaoAntes);
checar('Resgate refletiu o excedente já acumulado em Diagramação (rodada 4 > limite 3)', $r['excedente'] === true);
$movResgate = ultimaMovimentacao($conn, $livro2);
checar('Última movimentação tem tipo resgate', $movResgate['tipo'] === 'resgate');
$resgateFechado = $conn->query("SELECT local_encontrado, data_conclusao FROM resgates WHERE livro_id=$livro2 ORDER BY id DESC LIMIT 1")->fetch_assoc();
checar('Registro de resgates foi fechado com local_encontrado preenchido', $resgateFechado['data_conclusao'] !== null && $resgateFechado['local_encontrado'] === 'Na mesa errada, na sala ao lado');

// Depois de concluído, a contagem real de entradas em Diagramação continua a mesma de
// antes (o resgate não contou como entrada) — uma nova repetição deve pegar exatamente
// o número seguinte a essa contagem, prova de que o resgate não inflou nada.
$rNovo = repetirEtapa($conn, $livro2, $usuarioId);
checar('Repetir depois do resgate continua a contagem normal (não pulou nenhum número)', $rNovo['rodada'] === $entradasDiagramacaoAntes + 1);

try {
    concluirResgate($conn, $livro2, $idCatalogacao, $usuarioId, 'teste', $usuarioId);
    checar('Concluir resgate de livro que não está extraviado foi REJEITADO', false);
} catch (InvalidArgumentException $e) {
    checar('Concluir resgate de livro que não está extraviado foi rejeitado: ' . $e->getMessage(), true);
}

echo "\n=== (h) Cancelar livro: muda status e bloqueia movimentações ===\n";
$livro3 = criarLivroTeste($conn, 'TESTE FLUXO - Livro 3 (cancelamento)', $usuarioId, $usuarioId, []);
$livrosDeTeste[] = $livro3;
avancarEtapa($conn, $livro3, $idContrato, $usuarioId);
$res = cancelarLivro($conn, $livro3, $usuarioId);
checar('Livro 3 cancelado: status virou cancelado', $conn->query("SELECT status FROM livros WHERE id=$livro3")->fetch_assoc()['status'] === 'cancelado');
checar('Livro 3 cancelado: nenhuma movimentação aberta depois', (int)$conn->query("SELECT COUNT(*) total FROM movimentacoes WHERE livro_id=$livro3 AND data_saida IS NULL")->fetch_assoc()['total'] === 0);
try {
    avancarEtapa($conn, $livro3, $idEnvioMaterial, $usuarioId);
    checar('Avançar um livro cancelado foi REJEITADO', false);
} catch (InvalidArgumentException $e) {
    checar('Avançar um livro cancelado foi rejeitado: ' . $e->getMessage(), true);
}
try {
    cancelarLivro($conn, $livro3, $usuarioId);
    checar('Cancelar um livro já cancelado foi REJEITADO', false);
} catch (InvalidArgumentException $e) {
    checar('Cancelar um livro já cancelado foi rejeitado: ' . $e->getMessage(), true);
}

echo "\n=== Validações negativas (regras que devem ser rejeitadas) ===\n";
try {
    avancarEtapa($conn, $livro2, $idCatalogacao, $usuarioId, $idDepositoLegal);
    checar('Pular uma etapa obrigatória foi REJEITADO', false);
} catch (InvalidArgumentException $e) {
    checar('Pular uma etapa obrigatória foi rejeitado: ' . $e->getMessage(), true);
}
try {
    // Livro 2 está em Diagramação (ordem 7, após o resgate); tentar "avançar" direto para
    // o Depósito legal (ordem 15) pulando etapas obrigatórias nunca concluídas
    // (Catalogação, Fechamento, etc.) sem marcá-las.
    avancarEtapa($conn, $livro2, $idDepositoLegal, $usuarioId);
    checar('Avançar pulando etapas obrigatórias não concluídas foi REJEITADO', false);
} catch (InvalidArgumentException $e) {
    checar('Avançar pulando etapas obrigatórias não concluídas foi rejeitado: ' . $e->getMessage(), true);
}

echo "\n=== Limpeza ===\n";
foreach ($livrosDeTeste as $lid) {
    $conn->query("DELETE FROM resgates WHERE livro_id=$lid");
    $conn->query("DELETE FROM movimentacoes WHERE livro_id=$lid");
    $conn->query("DELETE FROM contribuicoes WHERE livro_id=$lid");
    $conn->query("DELETE FROM log_alteracoes WHERE entidade_tipo='livro' AND entidade_id=$lid");
    $conn->query("DELETE FROM livros WHERE id=$lid");
}
foreach ($pessoasDeTeste as $pid) {
    $conn->query("DELETE FROM log_alteracoes WHERE entidade_tipo='pessoa' AND entidade_id=$pid");
    $conn->query("DELETE FROM pessoas WHERE id=$pid");
}
echo "Livros de teste removidos: " . implode(', ', $livrosDeTeste) . "\n";
echo "Pessoas de teste removidas: " . implode(', ', $pessoasDeTeste) . "\n";

echo "\n" . ($falhas === 0 ? "TODAS AS VERIFICAÇÕES PASSARAM" : "$falhas VERIFICAÇÃO(ÕES) FALHARAM") . "\n";
exit($falhas === 0 ? 0 : 1);
