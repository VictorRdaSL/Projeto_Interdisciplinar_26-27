-- Migração: cria a tabela de log de alterações (auditoria).
-- Use este script se o banco "sistema_estoque" já existir sem essa tabela.

USE sistema_estoque;

CREATE TABLE IF NOT EXISTS log_alteracoes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT NOT NULL,
    acao VARCHAR(60) NOT NULL,
    entidade_tipo VARCHAR(30) NOT NULL,
    entidade_id INT NOT NULL,
    valor_anterior VARCHAR(255) NULL,
    valor_novo VARCHAR(255) NULL,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_log_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
