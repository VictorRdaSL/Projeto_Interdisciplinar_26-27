-- migrations/migracao_editorial.sql
-- Pivô de domínio: estoque -> controle editorial.
-- Dados de estoque sao de teste; nenhum backup foi gerado, conforme solicitado.

USE sistema_estoque;

-- ============================================================
-- PARTE 1: remove as tabelas de estoque classificadas como
-- "Remover depois" no mapeamento do Passo 0, mais movimentacoes_legacy
-- (confirmado sem nenhuma FK apontando para ela).
-- Ordem: tabelas filhas (com FK para produtos) antes da tabela pai.
-- ============================================================

DROP TABLE IF EXISTS entradas_estoque;
DROP TABLE IF EXISTS saidas_estoque;
DROP TABLE IF EXISTS movimentacoes_legacy;
DROP TABLE IF EXISTS produtos;

-- Tabelas mantidas, sem alteração: usuarios (inclui coluna tema), log_alteracoes.

-- ============================================================
-- PARTE 2: novo modelo de dados do fluxo editorial.
-- status/tipo como VARCHAR (validados no PHP), sem ENUM.
-- Datas em DATETIME, sempre preenchidas pelo PHP (sem ON UPDATE
-- CURRENT_TIMESTAMP e sem DEFAULT CURRENT_TIMESTAMP).
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
