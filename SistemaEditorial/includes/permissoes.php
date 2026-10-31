<?php
require_once __DIR__ . '/sessao.php';

const PAPEIS_VALIDOS = ['admin', 'gerente', 'usuario'];

const PERMISSOES_POR_PAPEL = [
    // log.ver (Passo 20.5): log de alterações só para Gerente e Admin (o Usuário comum não vê a seção nem consulta por URL).
    // config.etapas_papeis é a primeira permissão exclusiva de Admin — Gerente
    // não gerencia etapas/papéis (confirmado no pedido deste passo).
    // config.excluir_etapa (Passo 20.9): excluir uma etapa que nunca foi usada. Gerente e Admin veem o botão e podem usá-lo;
    // o Gerente não ganha mais nada nas etapas (criar, editar, desativar e reordenar continuam só do Admin).
    // livros.excluir (Passo 20.10): excluir livro cadastrado por engano. Mesmos perfis do cancelamento (Gerente e Admin);
    // o Usuário comum vê o botão na edição do livro, mas só recebe o aviso de que precisa de um Gerente ou Administrador.
    // livros.movimentar_outras_mesas: movimentar ou editar um livro cuja mesa é de outra pessoa. O Usuário comum só mexe
    // nos livros da própria mesa (para outro avançar, quem está com o livro precisa passá-lo); Gerente e Admin mexem em qualquer um.
    'admin'   => ['livros.gerenciar', 'livros.movimentar_outras_mesas', 'pessoas.gerenciar', 'relatorios.ver', 'livros.cancelar', 'livros.excluir', 'config.usuarios', 'config.dias_limite', 'config.etapas_papeis', 'config.excluir_etapa', 'log.ver'],
    'gerente' => ['livros.gerenciar', 'livros.movimentar_outras_mesas', 'pessoas.gerenciar', 'relatorios.ver', 'livros.cancelar', 'livros.excluir', 'config.usuarios', 'config.dias_limite', 'config.excluir_etapa', 'log.ver'],
    'usuario' => ['livros.gerenciar', 'pessoas.gerenciar', 'relatorios.ver'],
];

const NOMES_PAPEIS = [
    'admin'   => 'Administrador',
    'gerente' => 'Gerente',
    'usuario' => 'Usuário',
];

function papelAtual(): string
{
    $papel = $_SESSION['usuario_perfil'] ?? 'usuario';
    return in_array($papel, PAPEIS_VALIDOS, true) ? $papel : 'usuario';
}

function nomePapel(string $papel): string
{
    return NOMES_PAPEIS[$papel] ?? 'Usuário';
}

/** Permissão de um papel qualquer (não só o da sessão): serve para conferir quem está agindo pelo id, fora de uma tela. */
function papelTemPermissao(string $papel, string $permissao): bool
{
    return in_array($permissao, PERMISSOES_POR_PAPEL[$papel] ?? [], true);
}

function usuarioTemPermissao(string $permissao): bool
{
    return papelTemPermissao(papelAtual(), $permissao);
}
