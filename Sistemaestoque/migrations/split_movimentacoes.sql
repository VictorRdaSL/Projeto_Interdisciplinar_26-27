-- Migração: separa o histórico único "movimentacoes" em "entradas_estoque" e "saidas_estoque".
-- Execute uma única vez, em um banco que já tenha a tabela "movimentacoes" antiga.

USE sistema_estoque;

CREATE TABLE IF NOT EXISTS entradas_estoque (
    id INT AUTO_INCREMENT PRIMARY KEY,
    produto_id INT NOT NULL,
    usuario_id INT NOT NULL,
    quantidade INT NOT NULL,
    observacao VARCHAR(255) NULL,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_entrada_produto FOREIGN KEY (produto_id) REFERENCES produtos(id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_entrada_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS saidas_estoque (
    id INT AUTO_INCREMENT PRIMARY KEY,
    produto_id INT NOT NULL,
    usuario_id INT NOT NULL,
    quantidade INT NOT NULL,
    observacao VARCHAR(255) NULL,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_saida_produto FOREIGN KEY (produto_id) REFERENCES produtos(id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_saida_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- O histórico antigo não registrava quem fez a movimentação.
-- Por decisão do responsável pelo sistema, todo o histórico migrado é atribuído ao admin (id 1).
INSERT INTO entradas_estoque (produto_id, usuario_id, quantidade, observacao, criado_em)
SELECT produto_id, 1, quantidade, observacao, data_movimentacao
FROM movimentacoes
WHERE tipo = 'entrada';

INSERT INTO saidas_estoque (produto_id, usuario_id, quantidade, observacao, criado_em)
SELECT produto_id, 1, quantidade, observacao, data_movimentacao
FROM movimentacoes
WHERE tipo = 'saida';

-- Mantém o histórico bruto original como backup, sem apagar nada.
RENAME TABLE movimentacoes TO movimentacoes_legacy;
