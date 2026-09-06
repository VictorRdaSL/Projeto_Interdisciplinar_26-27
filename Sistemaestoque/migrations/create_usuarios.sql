-- Migração: adiciona a tabela de usuários para login/registro.
-- Use este script se o banco "sistema_estoque" já existir (ex: banco.sql já foi importado antes).
-- Quem for importar o banco.sql pela primeira vez já recebe esta tabela junto.

USE sistema_estoque;

CREATE TABLE IF NOT EXISTS usuarios (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(120) NOT NULL,
    email VARCHAR(150) NOT NULL UNIQUE,
    senha_hash VARCHAR(255) NOT NULL,
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
