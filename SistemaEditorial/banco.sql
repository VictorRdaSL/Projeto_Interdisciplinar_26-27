-- banco.sql — instalação nova (schema completo pós-pivô editorial).
-- Equivalente a rodar, em sequência, migrations/migracao_editorial.sql e
-- migrations/seed_editorial.sql sobre um banco já existente — aqui tudo
-- parte de um banco vazio, então as tabelas de estoque nunca são criadas.

CREATE DATABASE IF NOT EXISTS sistema_estoque CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE sistema_estoque;

-- ============================================================
-- Mantidas do sistema antigo, sem alteração.
-- ============================================================

CREATE TABLE IF NOT EXISTS usuarios (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(120) NOT NULL,
    email VARCHAR(150) NOT NULL UNIQUE,
    senha_hash VARCHAR(255) NOT NULL,
    role ENUM('admin','gerente','usuario') NOT NULL DEFAULT 'usuario',
    tema ENUM('claro','escuro') NOT NULL DEFAULT 'claro',
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS role ENUM('admin','gerente','usuario') NOT NULL DEFAULT 'usuario';
ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS tema ENUM('claro','escuro') NOT NULL DEFAULT 'claro';

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
) ENGINE=InnoDB;

-- ============================================================
-- Fluxo editorial (ver migrations/migracao_editorial.sql para o histórico
-- da migração que criou este schema sobre um banco de estoque pré-existente).
-- status/tipo como VARCHAR (validados no PHP), sem ENUM. Datas em DATETIME,
-- sempre preenchidas pelo PHP (sem ON UPDATE CURRENT_TIMESTAMP/DEFAULT CURRENT_TIMESTAMP).
-- ============================================================

CREATE TABLE IF NOT EXISTS papeis (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(80) NOT NULL,
    ativo BOOLEAN NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS etapas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(120) NOT NULL,
    ordem INT NOT NULL,
    opcional BOOLEAN NOT NULL DEFAULT 0,
    repetivel BOOLEAN NOT NULL DEFAULT 0,
    rodadas_incluidas INT NULL,
    dias_limite INT NULL,
    ativa BOOLEAN NOT NULL DEFAULT 1,
    finaliza_livro BOOLEAN NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pessoas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(120) NOT NULL,
    email VARCHAR(150) NULL,
    telefone VARCHAR(30) NULL,
    criado_em DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS livros (
    id INT AUTO_INCREMENT PRIMARY KEY,
    titulo VARCHAR(255) NOT NULL,
    isbn VARCHAR(20) NULL,
    tem_copia BOOLEAN NOT NULL DEFAULT 0,
    observacoes TEXT NULL,
    etapa_atual_id INT NOT NULL,
    responsavel_atual_id INT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'em_andamento',
    criado_em DATETIME NOT NULL,
    atualizado_em DATETIME NOT NULL,
    CONSTRAINT fk_livro_etapa FOREIGN KEY (etapa_atual_id) REFERENCES etapas(id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_livro_responsavel FOREIGN KEY (responsavel_atual_id) REFERENCES usuarios(id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS contribuicoes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    livro_id INT NOT NULL,
    pessoa_id INT NOT NULL,
    papel_id INT NOT NULL,
    CONSTRAINT fk_contrib_livro FOREIGN KEY (livro_id) REFERENCES livros(id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_contrib_pessoa FOREIGN KEY (pessoa_id) REFERENCES pessoas(id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_contrib_papel FOREIGN KEY (papel_id) REFERENCES papeis(id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT uk_contribuicao UNIQUE (livro_id, pessoa_id, papel_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS movimentacoes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    livro_id INT NOT NULL,
    etapa_id INT NOT NULL,
    responsavel_id INT NOT NULL,
    registrado_por_id INT NOT NULL,
    tipo VARCHAR(30) NOT NULL,
    rodada INT NOT NULL DEFAULT 1,
    excedente BOOLEAN NOT NULL DEFAULT 0,
    data_entrada DATETIME NOT NULL,
    data_saida DATETIME NULL,
    CONSTRAINT fk_mov_livro FOREIGN KEY (livro_id) REFERENCES livros(id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_mov_etapa FOREIGN KEY (etapa_id) REFERENCES etapas(id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_mov_responsavel FOREIGN KEY (responsavel_id) REFERENCES usuarios(id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_mov_registrado_por FOREIGN KEY (registrado_por_id) REFERENCES usuarios(id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_mov_livro_etapa ON movimentacoes (livro_id, etapa_id);
CREATE INDEX idx_mov_data_saida ON movimentacoes (data_saida);

CREATE TABLE IF NOT EXISTS resgates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    livro_id INT NOT NULL,
    movimentacao_referencia_id INT NOT NULL,
    data_abertura DATETIME NOT NULL,
    data_conclusao DATETIME NULL,
    local_encontrado VARCHAR(255) NULL,
    registrado_por_id INT NOT NULL,
    CONSTRAINT fk_resgate_livro FOREIGN KEY (livro_id) REFERENCES livros(id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_resgate_movimentacao FOREIGN KEY (movimentacao_referencia_id) REFERENCES movimentacoes(id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_resgate_registrado_por FOREIGN KEY (registrado_por_id) REFERENCES usuarios(id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS configuracoes (
    chave VARCHAR(80) PRIMARY KEY,
    valor VARCHAR(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Seed: usuário admin + etapas/papeis/configuracao
-- (ver migrations/seed_editorial.sql — mesmos valores)
-- ============================================================

-- Usuário admin semente (login inicial). Senha: admin123 — troque antes de hospedar (ver Passo 16 no CLAUDE.md)!
INSERT IGNORE INTO usuarios (nome,email,senha_hash,role) VALUES
('Administrador','admin@waresys.com','$2y$10$eEsJGiUXKpevnHy5Rrqt.el1tR3JmCJwSkCJj6LwiZE7rG31M3K06','admin');

INSERT INTO etapas (id, nome, ordem, opcional, repetivel, rodadas_incluidas, dias_limite, ativa, finaliza_livro) VALUES
(1,  'Orçamento', 1, 0, 0, NULL, NULL, 1, 0),
(2,  'Contrato', 2, 0, 0, NULL, NULL, 1, 0),
(3,  'Envio de material', 3, 0, 0, NULL, NULL, 1, 0),
(4,  'Capa', 4, 0, 0, NULL, NULL, 1, 0),
(5,  'Ilustração', 5, 1, 0, NULL, NULL, 1, 0),
(6,  'Aprovação da capa', 6, 1, 1, NULL, NULL, 1, 0),
(7,  'Diagramação', 7, 0, 1, 3, NULL, 1, 0),
(8,  'Revisão', 8, 1, 1, 2, NULL, 1, 0),
(9,  'Catalogação — ISBN e ficha catalográfica', 9, 0, 0, NULL, NULL, 1, 0),
(10, 'Fechamento de arquivo', 10, 0, 0, NULL, NULL, 1, 0),
(11, 'Envio do termo de impressão', 11, 0, 0, NULL, NULL, 1, 0),
(12, 'Chegada da cópia teste', 12, 0, 0, NULL, NULL, 1, 0),
(13, 'Leitura da cópia até aprovação', 13, 0, 1, NULL, NULL, 1, 0),
(14, 'Impressão', 14, 0, 0, NULL, NULL, 1, 0),
(15, 'Depósito legal — Biblioteca Nacional (RJ)', 15, 0, 0, NULL, NULL, 1, 1);

INSERT INTO papeis (id, nome, ativo) VALUES
(1, 'Autor', 1),
(2, 'Organizador', 1),
(3, 'Ilustrador', 1);

INSERT INTO configuracoes (chave, valor) VALUES
('dias_limite_livro_parado', '15');
