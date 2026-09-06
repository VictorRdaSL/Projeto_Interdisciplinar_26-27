<?php
function registrarLog(mysqli $conn, int $usuarioId, string $acao, string $entidadeTipo, int $entidadeId, ?string $valorAnterior, ?string $valorNovo): void
{
    $stmt = $conn->prepare('INSERT INTO log_alteracoes (usuario_id, acao, entidade_tipo, entidade_id, valor_anterior, valor_novo) VALUES (?, ?, ?, ?, ?, ?)');
    $stmt->bind_param('ississ', $usuarioId, $acao, $entidadeTipo, $entidadeId, $valorAnterior, $valorNovo);
    $stmt->execute();
}

const NOMES_ACOES_LOG = [
    'produto.nome'          => 'Editou nome do produto',
    'produto.categoria'     => 'Editou categoria do produto',
    'usuario.criar'         => 'Criou usuário',
    'usuario.alterar_papel' => 'Alterou papel de usuário',
];

function nomeAcaoLog(string $acao): string
{
    return NOMES_ACOES_LOG[$acao] ?? $acao;
}
