<?php
/**
 * Regra: o Administrador NÃO faz parte do fluxo da editora e não pode ser responsável (mesa) de nenhum
 * livro. Só Gerente e Usuário comum podem. O Admin continua podendo REGISTRAR ações (avançar, repetir,
 * voltar...) — `registrado_por_id` é outra coisa; ele só não pode ser ESCOLHIDO como responsável.
 *
 * Esta é a única fonte da regra: toda lista de "responsável" nas telas e toda validação no backend
 * passam por aqui.
 */

const PERFIS_QUE_PODEM_SER_MESA = ['gerente', 'usuario'];

const MSG_ADMIN_NAO_E_MESA = 'O Administrador não faz parte do fluxo da editora e não pode ser responsável (mesa) de um livro. Escolha um Gerente ou um Usuário.';

/** Usuários que podem ser mesa (Gerente ou Usuário comum), por nome: [['id' => .., 'nome' => ..], ...]. */
function usuariosQuePodemSerMesa(mysqli $conn): array
{
    $marcas = implode(', ', array_fill(0, count(PERFIS_QUE_PODEM_SER_MESA), '?'));
    $stmt = $conn->prepare("SELECT id, nome FROM vw_usuarios_publico WHERE perfil IN ($marcas) ORDER BY nome");
    $perfis = PERFIS_QUE_PODEM_SER_MESA;
    $stmt->bind_param(str_repeat('s', count($perfis)), ...$perfis);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

/** Texto do motivo pelo qual o usuário NÃO pode ser mesa; null se pode. */
function erroResponsavelMesa(mysqli $conn, int $usuarioId): ?string
{
    foreach (usuariosQuePodemSerMesa($conn) as $u) {
        if ((int)$u['id'] === $usuarioId) {
            return null;
        }
    }
    $stmt = $conn->prepare('SELECT perfil FROM vw_usuarios_publico WHERE id = ?');
    $stmt->bind_param('i', $usuarioId);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc() ? MSG_ADMIN_NAO_E_MESA : 'Usuário responsável inválido.';
}

/** Lança InvalidArgumentException (mensagem para a tela) se o usuário não puder ser mesa. */
function validarResponsavelMesa(mysqli $conn, int $usuarioId): void
{
    $erro = erroResponsavelMesa($conn, $usuarioId);
    if ($erro !== null) {
        throw new InvalidArgumentException($erro);
    }
}

/** Livros que estão hoje sob a responsabilidade do usuário (em andamento ou extraviados): [['id', 'titulo'], ...]. */
function livrosSobResponsabilidade(mysqli $conn, int $usuarioId): array
{
    $stmt = $conn->prepare("SELECT id, titulo FROM livros WHERE responsavel_atual_id = ? AND status IN ('em_andamento', 'extraviado') ORDER BY titulo");
    $stmt->bind_param('i', $usuarioId);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}
