-- Migração: adiciona a preferência de tema (claro/escuro) por usuário.
-- Use este script se a tabela "usuarios" já existir sem a coluna "tema".

USE sistema_estoque;

ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS tema ENUM('claro','escuro') NOT NULL DEFAULT 'claro' AFTER role;
