<?php
require_once __DIR__ . '/sessao.php';

const PAPEIS_VALIDOS = ['admin', 'gerente', 'usuario'];

const PERMISSOES_POR_PAPEL = [
    'admin'   => ['livros.gerenciar', 'pessoas.gerenciar', 'relatorios.ver', 'config.acessar'],
    'gerente' => ['livros.gerenciar', 'pessoas.gerenciar', 'relatorios.ver'],
    'usuario' => ['livros.gerenciar', 'pessoas.gerenciar'],
];

const NOMES_PAPEIS = [
    'admin'   => 'Administrador',
    'gerente' => 'Gerente',
    'usuario' => 'Usuário',
];

function papelAtual(): string
{
    $papel = $_SESSION['usuario_role'] ?? 'usuario';
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

/**
 * Middleware de página inteira: só deixa passar quem tem um dos papéis informados.
 * Deve ser chamado depois de auth_check.php (que garante que há sessão ativa).
 */
function exigirPapel(array $papeisPermitidos, string $redirecionoPara = 'index.php'): void
{
    if (!in_array(papelAtual(), $papeisPermitidos, true)) {
        $_SESSION['flash'] = [
            'type' => 'erro',
            'message' => 'Acesso restrito a ' . implode(' ou ', array_map('nomePapel', $papeisPermitidos)) . '.',
        ];
        header('Location: ' . $redirecionoPara);
        exit;
    }
}
