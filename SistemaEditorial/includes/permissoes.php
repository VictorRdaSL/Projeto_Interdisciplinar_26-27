<?php
require_once __DIR__ . '/sessao.php';

const PAPEIS_VALIDOS = ['admin', 'gerente', 'usuario'];

const PERMISSOES_POR_PAPEL = [
    // log.ver (Passo 20.5): log de alterações só para Gerente e Admin (o Usuário comum não vê a seção nem consulta por URL).
    // config.etapas_papeis é a primeira permissão exclusiva de Admin — Gerente
    // não gerencia etapas/papéis (confirmado no pedido deste passo).
    // config.excluir_etapa (Passo 20.9): excluir uma etapa que nunca foi usada. Gerente e Admin veem o botão e podem usá-lo;
    // o Gerente não ganha mais nada nas etapas (criar, editar, desativar e reordenar continuam só do Admin).
    'admin'   => ['livros.gerenciar', 'pessoas.gerenciar', 'relatorios.ver', 'livros.cancelar', 'config.usuarios', 'config.dias_limite', 'config.etapas_papeis', 'config.excluir_etapa', 'log.ver'],
    'gerente' => ['livros.gerenciar', 'pessoas.gerenciar', 'relatorios.ver', 'livros.cancelar', 'config.usuarios', 'config.dias_limite', 'config.excluir_etapa', 'log.ver'],
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

function usuarioTemPermissao(string $permissao): bool
{
    $papel = papelAtual();
    return in_array($permissao, PERMISSOES_POR_PAPEL[$papel] ?? [], true);
}
