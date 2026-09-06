-- Migração: adiciona o papel (role) de acesso aos usuários já existentes.
-- Use este script se a tabela "usuarios" já existir sem a coluna "role".

USE sistema_estoque;

ALTER TABLE usuarios
    ADD COLUMN role ENUM('admin','gerente','usuario') NOT NULL DEFAULT 'usuario' AFTER senha_hash;

-- Todo usuário cadastrado antes desta migração nasce como "usuario".
-- Promova manualmente o primeiro administrador trocando o e-mail abaixo:
-- UPDATE usuarios SET role = 'admin' WHERE email = 'seu-email@exemplo.com';
