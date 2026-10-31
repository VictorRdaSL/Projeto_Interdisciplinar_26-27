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
// O cenário (e) precisa de um 2º usuário. Se o banco só tem o admin, o teste cria o seu próprio
// (e remove no final) — assim a troca de mesa nunca fica de fora por falta de usuário.
const EMAIL_USUARIO_TESTE_MESA = 'teste_fluxo_mesa@teste.invalid';
$conn->query("DELETE FROM usuarios WHERE email = '" . EMAIL_USUARIO_TESTE_MESA . "'"); // sobra de uma execução interrompida
$outroUsuarioRow = $conn->query("SELECT id, nome FROM usuarios WHERE perfil IN ('gerente', 'usuario') LIMIT 1")->fetch_assoc();
$usuarioTesteCriadoId = null;
if (!$outroUsuarioRow) {
    $agoraTeste = date('Y-m-d H:i:s');
    $stmtU = $conn->prepare("INSERT INTO usuarios (nome, email, senha_hash, perfil, criado_em) VALUES ('Teste Fluxo Mesa', ?, 'sem-login', 'usuario', ?)");
    $emailTeste = EMAIL_USUARIO_TESTE_MESA;
    $stmtU->bind_param('ss', $emailTeste, $agoraTeste);
    $stmtU->execute();
    $usuarioTesteCriadoId = $conn->insert_id;
    $outroUsuarioRow = ['id' => $usuarioTesteCriadoId, 'nome' => 'Teste Fluxo Mesa'];
}
$temSegundoUsuario = (bool)$outroUsuarioRow;
// O Administrador não pode ser mesa (includes/mesa.php): os livros de teste ficam com este usuário; o admin só REGISTRA as ações.
$mesaId = (int)$outroUsuarioRow['id'];

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

$livro1 = criarLivroTeste($conn, 'TESTE FLUXO - Livro 1', $mesaId, $usuarioId, [
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
$livro2 = criarLivroTeste($conn, 'TESTE FLUXO - Livro 2', $mesaId, $usuarioId, []);
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
    // (O livro continua com $mesaId: devolver ao admin seria recusado pela regra do cenário (i).)

    // sp_trocar_mesa: falhas de validação chegam ao PHP como InvalidArgumentException em português.
    foreach ([
        'livro inexistente' => [fn() => passarParaOutraMesa($conn, 999999999, $outroUsuarioId, $usuarioId), 'Livro não encontrado.'],
        'usuário responsável inexistente' => [fn() => passarParaOutraMesa($conn, $livro2, 999999999, $usuarioId), 'Usuário responsável inválido.'],
        'Administrador como responsável' => [fn() => passarParaOutraMesa($conn, $livro2, $usuarioId, $usuarioId), MSG_ADMIN_NAO_E_MESA],
    ] as $descricao => [$acao, $mensagemEsperada]) {
        try {
            $acao();
            checar("Troca de mesa com $descricao foi REJEITADA", false);
        } catch (InvalidArgumentException $e) {
            checar("Troca de mesa com $descricao rejeitada com a mensagem certa", $e->getMessage() === $mensagemEsperada);
        }
    }

    // Atomicidade: o registrado_por inexistente só falha no meio da procedure (chave estrangeira,
    // depois de ela ter fechado a movimentação aberta) — o ROLLBACK tem que devolver tudo.
    $abertasAntes = (int)$conn->query("SELECT COUNT(*) c FROM movimentacoes WHERE livro_id=$livro2 AND data_saida IS NULL")->fetch_assoc()['c'];
    $mesaAntes = (int)$conn->query("SELECT responsavel_atual_id FROM livros WHERE id=$livro2")->fetch_assoc()['responsavel_atual_id'];
    $movsAntes = (int)$conn->query("SELECT COUNT(*) c FROM movimentacoes WHERE livro_id=$livro2")->fetch_assoc()['c'];
    $logsAntes = (int)$conn->query("SELECT COUNT(*) c FROM log_alteracoes WHERE entidade_tipo='livro' AND entidade_id=$livro2")->fetch_assoc()['c'];
    try {
        passarParaOutraMesa($conn, $livro2, $outroUsuarioId, 999999999);
        checar('Troca de mesa com registrado_por inexistente foi REJEITADA', false);
    } catch (Throwable $e) {
        checar('Troca de mesa com registrado_por inexistente foi rejeitada (erro do banco)', true);
    }
    checar('Falha no meio da troca de mesa: ROLLBACK manteve 1 movimentação aberta', (int)$conn->query("SELECT COUNT(*) c FROM movimentacoes WHERE livro_id=$livro2 AND data_saida IS NULL")->fetch_assoc()['c'] === $abertasAntes);
    checar('Falha no meio da troca de mesa: ROLLBACK não mudou a mesa do livro', (int)$conn->query("SELECT responsavel_atual_id FROM livros WHERE id=$livro2")->fetch_assoc()['responsavel_atual_id'] === $mesaAntes);
    checar('Falha no meio da troca de mesa: ROLLBACK não deixou movimentação nem log novos', (int)$conn->query("SELECT COUNT(*) c FROM movimentacoes WHERE livro_id=$livro2")->fetch_assoc()['c'] === $movsAntes
        && (int)$conn->query("SELECT COUNT(*) c FROM log_alteracoes WHERE entidade_tipo='livro' AND entidade_id=$livro2")->fetch_assoc()['c'] === $logsAntes);
    // A conexão segue utilizável depois do erro (sem "Commands out of sync").
    checar('Conexão utilizável depois de uma procedure que falhou', (int)$conn->query('SELECT 1 AS um')->fetch_assoc()['um'] === 1);
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
    'passarParaOutraMesa' => fn() => passarParaOutraMesa($conn, $livro2, $mesaId, $usuarioId),
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

$r = concluirResgate($conn, $livro2, $idDiagramacao, $mesaId, 'Na mesa errada, na sala ao lado', $usuarioId);
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
    concluirResgate($conn, $livro2, $idCatalogacao, $mesaId, 'teste', $usuarioId);
    checar('Concluir resgate de livro que não está extraviado foi REJEITADO', false);
} catch (InvalidArgumentException $e) {
    checar('Concluir resgate de livro que não está extraviado foi rejeitado: ' . $e->getMessage(), true);
}

echo "\n=== (h) Cancelar livro: muda status e bloqueia movimentações ===\n";
$livro3 = criarLivroTeste($conn, 'TESTE FLUXO - Livro 3 (cancelamento)', $mesaId, $usuarioId, []);
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

echo "\n=== (i) Regra: o Administrador não pode ser mesa (mas continua registrando ações) ===\n";
$idsMesa = array_map('intval', array_column(usuariosQuePodemSerMesa($conn), 'id'));
checar('Lista de mesas possíveis não inclui nenhum Administrador', !in_array($usuarioId, $idsMesa, true)
    && (int)$conn->query("SELECT COUNT(*) c FROM vw_usuarios_publico WHERE perfil = 'admin' AND id IN (" . implode(',', $idsMesa ?: [0]) . ")")->fetch_assoc()['c'] === 0);
checar('Lista de mesas possíveis inclui o usuário de teste (não-Admin)', in_array($mesaId, $idsMesa, true));

$livro4 = criarLivroTeste($conn, 'TESTE FLUXO - Livro 4 (regra do Admin)', $mesaId, $usuarioId, []);
$livrosDeTeste[] = $livro4;
// O Admin REGISTRA ações normalmente (registrado_por = admin), com a mesa escolhida entre os válidos.
$r = avancarEtapa($conn, $livro4, $idContrato, $usuarioId, null, $mesaId);
checar('Admin registrou "avançar" com um responsável válido', $r['etapa_nome'] === 'Contrato');
checar('...e a movimentação guarda o admin como quem registrou e o outro como mesa', (int)ultimaMovimentacao($conn, $livro4)['registrado_por_id'] === $usuarioId && (int)ultimaMovimentacao($conn, $livro4)['responsavel_id'] === $mesaId);
avancarEtapa($conn, $livro4, $idEnvioMaterial, $usuarioId);
avancarEtapa($conn, $livro4, $idCapa, $usuarioId);
avancarEtapa($conn, $livro4, $idAprovacaoCapa, $usuarioId, $idIlustracao);
avancarEtapa($conn, $livro4, $idDiagramacao, $usuarioId);

$antes = [
    'etapa' => etapaAtualDoLivro($conn, $livro4)['etapa_nome'],
    'mesa' => (int)$conn->query("SELECT responsavel_atual_id FROM livros WHERE id=$livro4")->fetch_assoc()['responsavel_atual_id'],
    'movs' => (int)$conn->query("SELECT COUNT(*) c FROM movimentacoes WHERE livro_id=$livro4")->fetch_assoc()['c'],
    'abertas' => (int)$conn->query("SELECT COUNT(*) c FROM movimentacoes WHERE livro_id=$livro4 AND data_saida IS NULL")->fetch_assoc()['c'],
];
foreach ([
    'avancarEtapa' => fn() => avancarEtapa($conn, $livro4, $idRevisao, $usuarioId, null, $usuarioId),
    'repetirEtapa' => fn() => repetirEtapa($conn, $livro4, $usuarioId, $usuarioId),
    'voltarEtapa' => fn() => voltarEtapa($conn, $livro4, $idCapa, $usuarioId, $usuarioId),
    'passarParaOutraMesa' => fn() => passarParaOutraMesa($conn, $livro4, $usuarioId, $usuarioId),
] as $nomeAcao => $acao) {
    try {
        $acao();
        checar("$nomeAcao com o Administrador como responsável foi REJEITADO", false);
    } catch (InvalidArgumentException $e) {
        checar("$nomeAcao com o Administrador como responsável rejeitado com a mensagem clara", $e->getMessage() === MSG_ADMIN_NAO_E_MESA);
    }
}
$depois = [
    'etapa' => etapaAtualDoLivro($conn, $livro4)['etapa_nome'],
    'mesa' => (int)$conn->query("SELECT responsavel_atual_id FROM livros WHERE id=$livro4")->fetch_assoc()['responsavel_atual_id'],
    'movs' => (int)$conn->query("SELECT COUNT(*) c FROM movimentacoes WHERE livro_id=$livro4")->fetch_assoc()['c'],
    'abertas' => (int)$conn->query("SELECT COUNT(*) c FROM movimentacoes WHERE livro_id=$livro4 AND data_saida IS NULL")->fetch_assoc()['c'],
];
checar('As quatro recusas não alteraram nada (etapa, mesa, movimentações, 1 aberta)', $antes === $depois && $depois['abertas'] === 1);
try {
    avancarEtapa($conn, $livro4, $idRevisao, $usuarioId, null, 999999999);
    checar('Responsável inexistente foi REJEITADO', false);
} catch (InvalidArgumentException $e) {
    checar('Responsável inexistente continua com a mensagem própria', $e->getMessage() === 'Usuário responsável inválido.');
}

// Resgate: concluir com o Admin como "novo responsável" é recusado e o livro continua extraviado.
iniciarResgate($conn, $livro4, $usuarioId);
try {
    concluirResgate($conn, $livro4, $idDiagramacao, $usuarioId, 'Gaveta 3', $usuarioId);
    checar('Concluir resgate com o Administrador como responsável foi REJEITADO', false);
} catch (InvalidArgumentException $e) {
    checar('Concluir resgate com o Administrador como responsável rejeitado com a mensagem clara', $e->getMessage() === MSG_ADMIN_NAO_E_MESA);
}
checar('...e o livro continua extraviado, com o resgate em aberto', $conn->query("SELECT status FROM livros WHERE id=$livro4")->fetch_assoc()['status'] === 'extraviado' && buscarResgateAbertoPorLivro($conn, $livro4) !== null);
concluirResgate($conn, $livro4, $idDiagramacao, $mesaId, 'Gaveta 3', $usuarioId);
checar('Concluir resgate com um responsável válido (registrado pelo Admin) funciona', $conn->query("SELECT status FROM livros WHERE id=$livro4")->fetch_assoc()['status'] === 'em_andamento');

// Livro de ANTES da regra, com um Administrador como mesa (gravado direto no banco): nada é alterado sozinho,
// mas movimentar sem escolher responsável é recusado e escolhendo um válido a mesa é corrigida.
$livro5 = criarLivroTeste($conn, 'TESTE FLUXO - Livro 5 (mesa Admin antiga)', $usuarioId, $usuarioId, []);
$livrosDeTeste[] = $livro5;
try {
    avancarEtapa($conn, $livro5, $idContrato, $usuarioId);
    checar('Avançar livro com Admin como mesa, sem escolher responsável, foi REJEITADO', false);
} catch (InvalidArgumentException $e) {
    checar('Avançar livro com Admin como mesa, sem escolher responsável, rejeitado: ' . $e->getMessage(), str_contains($e->getMessage(), 'Administrador como responsável'));
}
checar('...e o livro não foi alterado (continua em Orçamento, com o Admin)', etapaAtualDoLivro($conn, $livro5)['etapa_nome'] === 'Orçamento' && (int)etapaAtualDoLivro($conn, $livro5)['responsavel_atual_id'] === $usuarioId);
avancarEtapa($conn, $livro5, $idContrato, $usuarioId, null, $mesaId);
checar('Escolhendo um responsável válido a mesa passa do Admin para ele', (int)etapaAtualDoLivro($conn, $livro5)['responsavel_atual_id'] === $mesaId);
$livro6 = criarLivroTeste($conn, 'TESTE FLUXO - Livro 6 (Admin como mesa, cancelar)', $usuarioId, $usuarioId, []);
$livrosDeTeste[] = $livro6;
cancelarLivro($conn, $livro6, $usuarioId);
checar('Cancelar livro que está com o Admin como mesa continua possível', $conn->query("SELECT status FROM livros WHERE id=$livro6")->fetch_assoc()['status'] === 'cancelado');

// Trava de promoção a Admin: livros em andamento/extraviados sob a responsabilidade do usuário.
$titulosSob = array_column(livrosSobResponsabilidade($conn, $mesaId), 'id');
checar('livrosSobResponsabilidade lista os livros em andamento do usuário (livro 4 e 5)', in_array((string)$livro4, array_map('strval', $titulosSob), true) && in_array((string)$livro5, array_map('strval', $titulosSob), true));
checar('livrosSobResponsabilidade não lista livros cancelados (livro 3)', !in_array((string)$livro3, array_map('strval', $titulosSob), true));
cancelarLivro($conn, $livro4, $usuarioId);
checar('Depois de cancelado, o livro 4 deixa de contar como "sob responsabilidade"', !in_array((string)$livro4, array_map('strval', array_column(livrosSobResponsabilidade($conn, $mesaId), 'id')), true));
echo "\n=== (j) Observação nas movimentações (Passo 20.8) ===\n";
$livro6 = criarLivroTeste($conn, 'TESTE FLUXO - Livro 6 (observações)', $mesaId, $usuarioId, []);
$livrosDeTeste[] = $livro6;
$obsDaUltima = fn(int $livroId): ?string => ultimaMovimentacao($conn, $livroId)['observacao'];
$totalMov = fn(int $livroId): int => (int)$conn->query("SELECT COUNT(*) c FROM movimentacoes WHERE livro_id=$livroId")->fetch_assoc()['c'];
checar('Livro recém-criado: a movimentação inicial não tem observação (NULL)', $obsDaUltima($livro6) === null);

avancarEtapa($conn, $livro6, $idContrato, $usuarioId, null, $mesaId, "Contrato enviado.\nAguardando assinatura.");
checar('Avançar grava a observação, com a quebra de linha', $obsDaUltima($livro6) === "Contrato enviado.\nAguardando assinatura.");
avancarEtapa($conn, $livro6, $idEnvioMaterial, $usuarioId);
checar('Avançar sem observação grava NULL', $obsDaUltima($livro6) === null);
avancarEtapa($conn, $livro6, $idCapa, $usuarioId, null, null, "   \n  ");
checar('Observação só com espaços e quebras vira NULL', $obsDaUltima($livro6) === null);
avancarEtapa($conn, $livro6, $idAprovacaoCapa, $usuarioId, $idIlustracao, null, "Sem ilustração.\r\nSeguir direto.");
checar('Pular etapa opcional: a observação vai na linha do avanço (\\r\\n vira \\n)', $obsDaUltima($livro6) === "Sem ilustração.\nSeguir direto." && ultimaMovimentacao($conn, $livro6)['tipo'] === 'avanco');
$obsPulo = $conn->query("SELECT observacao FROM movimentacoes WHERE livro_id=$livro6 AND tipo='pulo_opcional'")->fetch_assoc()['observacao'];
checar('...e a linha do pulo_opcional fica sem observação', $obsPulo === null);
avancarEtapa($conn, $livro6, $idDiagramacao, $usuarioId);

repetirEtapa($conn, $livro6, $usuarioId, null, 'Autora pediu nova prova.');
checar('Repetir grava a observação', $obsDaUltima($livro6) === 'Autora pediu nova prova.' && ultimaMovimentacao($conn, $livro6)['tipo'] === 'repeticao');
avancarEtapa($conn, $livro6, $idRevisao, $usuarioId);
voltarEtapa($conn, $livro6, $idDiagramacao, $usuarioId, null, 'Voltou por mudança de capa.');
checar('Voltar grava a observação', $obsDaUltima($livro6) === 'Voltou por mudança de capa.' && ultimaMovimentacao($conn, $livro6)['tipo'] === 'retrocesso');

// Troca de mesa (stored procedure com o 5º parâmetro).
passarParaOutraMesa($conn, $livro6, $mesaId, $usuarioId, "Cuidado com as notas de rodapé.\nVer pasta compartilhada.");
checar('Passar para outra mesa (procedure) grava a observação', $obsDaUltima($livro6) === "Cuidado com as notas de rodapé.\nVer pasta compartilhada." && ultimaMovimentacao($conn, $livro6)['tipo'] === 'troca_responsavel');
passarParaOutraMesa($conn, $livro6, $mesaId, $usuarioId);
checar('Passar para outra mesa sem observação grava NULL', $obsDaUltima($livro6) === null);
passarParaOutraMesa($conn, $livro6, $mesaId, $usuarioId, '');
checar('Passar para outra mesa com texto vazio grava NULL', $obsDaUltima($livro6) === null);

// Limite: exatamente 1.000 caracteres grava inteiro (acentuados, 4 bytes no pior caso); 1.001 é recusado sem gravar nada.
$mil = str_repeat('é', LIMITE_OBSERVACAO_MOVIMENTACAO);
repetirEtapa($conn, $livro6, $usuarioId, null, $mil);
checar('1.000 caracteres: gravado inteiro', mb_strlen($obsDaUltima($livro6), 'UTF-8') === 1000 && $obsDaUltima($livro6) === $mil);
$movAntes = $totalMov($livro6);
$etapaAntes = etapaAtualDoLivro($conn, $livro6)['etapa_nome'];
$mil1 = $mil . 'x';
foreach ([
    'avançar' => fn() => avancarEtapa($conn, $livro6, $idRevisao, $usuarioId, null, null, $mil1),
    'repetir' => fn() => repetirEtapa($conn, $livro6, $usuarioId, null, $mil1),
    'voltar' => fn() => voltarEtapa($conn, $livro6, $idOrcamento, $usuarioId, null, $mil1),
    'passar para outra mesa' => fn() => passarParaOutraMesa($conn, $livro6, $mesaId, $usuarioId, $mil1),
] as $acao => $executar) {
    try {
        $executar();
        checar("1.001 caracteres em \"$acao\" foi REJEITADO", false);
    } catch (InvalidArgumentException $e) {
        checar("1.001 caracteres em \"$acao\" rejeitado: " . $e->getMessage(), str_contains($e->getMessage(), '1000'));
    }
}
checar('...e nada foi gravado (mesma contagem de movimentações, mesma etapa, uma só aberta)', $totalMov($livro6) === $movAntes
    && etapaAtualDoLivro($conn, $livro6)['etapa_nome'] === $etapaAntes
    && (int)$conn->query("SELECT COUNT(*) c FROM movimentacoes WHERE livro_id=$livro6 AND data_saida IS NULL")->fetch_assoc()['c'] === 1);

// Resgate: concluir grava a observação na linha do tipo 'resgate'.
iniciarResgate($conn, $livro6, $usuarioId);
concluirResgate($conn, $livro6, $idDiagramacao, $mesaId, 'Gaveta do diretor', $usuarioId, 'Achado na gaveta; conferir o arquivo final.');
checar('Concluir resgate grava a observação na movimentação de resgate', $obsDaUltima($livro6) === 'Achado na gaveta; conferir o arquivo final.' && ultimaMovimentacao($conn, $livro6)['tipo'] === 'resgate');
try {
    iniciarResgate($conn, $livro6, $usuarioId);
    concluirResgate($conn, $livro6, $idDiagramacao, $mesaId, 'Gaveta', $usuarioId, $mil1);
    checar('Concluir resgate com 1.001 caracteres foi REJEITADO', false);
} catch (InvalidArgumentException $e) {
    checar('Concluir resgate com 1.001 caracteres rejeitado e o livro segue extraviado', (string)$conn->query("SELECT status FROM livros WHERE id=$livro6")->fetch_assoc()['status'] === 'extraviado');
    concluirResgate($conn, $livro6, $idDiagramacao, $mesaId, 'Gaveta', $usuarioId);
}
checar('Resgate concluído sem observação grava NULL', $obsDaUltima($livro6) === null);

$comObs = (int)$conn->query("SELECT COUNT(*) c FROM movimentacoes WHERE livro_id <> $livro6 AND observacao IS NOT NULL AND livro_id IN (" . implode(',', array_diff($livrosDeTeste, [$livro6])) . ")")->fetch_assoc()['c'];
checar('Os outros livros de teste (cenários anteriores) não ganharam observação', $comObs === 0);

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
if ($usuarioTesteCriadoId !== null) {
    $conn->query("DELETE FROM log_alteracoes WHERE usuario_id=$usuarioTesteCriadoId");
    $conn->query("DELETE FROM usuarios WHERE id=$usuarioTesteCriadoId");
    echo "Usuário de teste removido: $usuarioTesteCriadoId\n";
}
echo "Livros de teste removidos: " . implode(', ', $livrosDeTeste) . "\n";
echo "Pessoas de teste removidas: " . implode(', ', $pessoasDeTeste) . "\n";

echo "\n" . ($falhas === 0 ? "TODAS AS VERIFICAÇÕES PASSARAM" : "$falhas VERIFICAÇÃO(ÕES) FALHARAM") . "\n";
exit($falhas === 0 ? 0 : 1);
