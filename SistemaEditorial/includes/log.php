<?php
function registrarLog(mysqli $conn, int $usuarioId, string $acao, string $entidadeTipo, int $entidadeId, ?string $valorAnterior, ?string $valorNovo): void
{
    $stmt = $conn->prepare('INSERT INTO log_alteracoes (usuario_id, acao, entidade_tipo, entidade_id, valor_anterior, valor_novo, criado_em) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $criadoEm = date('Y-m-d H:i:s');
    $stmt->bind_param('ississs', $usuarioId, $acao, $entidadeTipo, $entidadeId, $valorAnterior, $valorNovo, $criadoEm);
    $stmt->execute();
}

// log_alteracoes.valor_anterior/valor_novo são VARCHAR(255) — truncar antes de
// logar texto livre (ex.: observações) evita um erro de truncamento do MySQL
// em modo estrito (mysqli_report(MYSQLI_REPORT_STRICT), já ligado no projeto).
function truncarParaLog(?string $valor, int $limite = 240): ?string
{
    if ($valor === null || $valor === '') return null;
    return mb_strlen($valor) > $limite ? mb_substr($valor, 0, $limite) . '…' : $valor;
}

const NOMES_ACOES_LOG = [
    'usuario.criar'              => 'Criou usuário',
    'usuario.alterar_papel'      => 'Alterou papel de usuário',
    'livro.criar'                => 'Cadastrou livro',
    'livro.editar'                => 'Editou livro', // chave antiga, mantida só para exibir logs históricos
    'livro.editar_titulo'        => 'Editou título do livro',
    'livro.editar_isbn'          => 'Editou ISBN do livro',
    'livro.editar_copia'         => 'Editou "tem cópia" do livro',
    'livro.editar_observacoes'   => 'Editou observações do livro',
    'livro.cancelar'             => 'Cancelou livro',
    'livro.excluir'              => 'Excluiu livro',
    'contribuicao.adicionar'     => 'Adicionou contribuidor',
    'contribuicao.remover'       => 'Removeu contribuidor',
    'pessoa.criar'                => 'Cadastrou pessoa',
    'pessoa.editar'               => 'Editou pessoa',
    'pessoa.excluir'              => 'Excluiu pessoa',
    'movimentacao.avancar'       => 'Avançou etapa',
    'movimentacao.repetir'       => 'Repetiu etapa',
    'movimentacao.voltar'        => 'Voltou etapa',
    'movimentacao.concluir'      => 'Concluiu livro',
    'movimentacao.trocar_responsavel' => 'Trocou responsável (mesa)',
    'resgate.iniciar'             => 'Iniciou resgate',
    'resgate.concluir'            => 'Concluiu resgate',
    // Chaves preparadas para quando existir tela de gerenciar etapas/papéis
    // (fora de escopo no Passo 8.5 — nada ainda emite estas ações).
    'etapa.criar'                 => 'Criou etapa',
    'etapa.editar'                => 'Editou etapa',
    'etapa.desativar'             => 'Desativou etapa',
    'etapa.excluir'               => 'Excluiu etapa',
    'papel.criar'                  => 'Criou papel',
    'papel.editar'                 => 'Editou papel',
    'papel.desativar'              => 'Desativou papel',
    'config.editar_dias_limite'    => 'Editou dias-limite de livro parado',
];

function nomeAcaoLog(string $acao): string
{
    return NOMES_ACOES_LOG[$acao] ?? $acao;
}
