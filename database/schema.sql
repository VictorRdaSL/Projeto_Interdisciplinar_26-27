-- schema.sql — WareSys, Controle Editorial.
-- Cria o banco `waresys` e todas as tabelas, do zero, num MariaDB/MySQL vazio.
-- Rode ESTE arquivo primeiro e depois seed.sql. Não depende de nenhum outro script.
--
-- Convenções: tabelas no plural, snake_case, sem acento; InnoDB, utf8mb4_unicode_ci.
-- status/tipo/perfil/tema são VARCHAR validados no PHP (sem ENUM, de propósito).
-- Datas em DATETIME, sempre preenchidas pelo PHP (fuso America/Sao_Paulo fixado em
-- config/database.php) — nenhuma coluna usa o relógio do MySQL como valor padrão.
-- Todas as FKs: ON UPDATE CASCADE ON DELETE RESTRICT.

SET NAMES utf8mb4;

CREATE DATABASE IF NOT EXISTS waresys CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE waresys;

-- ------------------------------------------------------------
-- Tabelas sem dependência
-- ------------------------------------------------------------

-- Usuários do sistema. `perfil` = nível de acesso (admin | gerente | usuario);
-- não confundir com `papeis` (papel de uma pessoa num livro). `ativo` = 0: usuário removido
-- (já aparece em algum histórico, então a linha fica para o nome continuar nos registros, mas
-- ele não entra mais no sistema nem pode ser mesa); quem nunca usou o sistema é apagado de vez.
CREATE TABLE usuarios (
    id          INT NOT NULL AUTO_INCREMENT,
    nome        VARCHAR(120) NOT NULL,
    email       VARCHAR(150) NOT NULL,
    senha_hash  VARCHAR(255) NOT NULL,
    perfil      VARCHAR(20)  NOT NULL DEFAULT 'usuario',
    tema        VARCHAR(10)  NOT NULL DEFAULT 'claro',
    ativo       TINYINT(1)   NOT NULL DEFAULT 1,
    criado_em   DATETIME     NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_usuario_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Papel de uma pessoa num livro (Autor, Organizador, Ilustrador...).
CREATE TABLE papeis (
    id     INT NOT NULL AUTO_INCREMENT,
    nome   VARCHAR(80) NOT NULL,
    ativo  TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Autores, organizadores, ilustradores... NÃO são usuários do sistema.
CREATE TABLE pessoas (
    id         INT NOT NULL AUTO_INCREMENT,
    nome       VARCHAR(120) NOT NULL,
    email      VARCHAR(150) NULL,
    telefone   VARCHAR(30)  NULL,
    criado_em  DATETIME NOT NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Etapas do fluxo editorial. `ordem` não tem UNIQUE de propósito (a reordenação
-- troca valores entre duas linhas). rodadas_incluidas NULL = sem limite;
-- dias_limite NULL = usa o padrão global de `configuracoes`.
CREATE TABLE etapas (
    id                 INT NOT NULL AUTO_INCREMENT,
    nome               VARCHAR(120) NOT NULL,
    ordem              INT NOT NULL,
    opcional           TINYINT(1) NOT NULL DEFAULT 0,
    repetivel          TINYINT(1) NOT NULL DEFAULT 0,
    rodadas_incluidas  INT NULL,
    dias_limite        INT NULL,
    ativa              TINYINT(1) NOT NULL DEFAULT 1,
    finaliza_livro     TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Par chave/valor de configurações globais.
CREATE TABLE configuracoes (
    chave  VARCHAR(80)  NOT NULL,
    valor  VARCHAR(255) NOT NULL,
    PRIMARY KEY (chave)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Entidade central
-- ------------------------------------------------------------

-- status: em_andamento | concluido | extraviado | cancelado.
-- responsavel_atual_id = a "mesa" do livro.
CREATE TABLE livros (
    id                    INT NOT NULL AUTO_INCREMENT,
    titulo                VARCHAR(255) NOT NULL,
    isbn                  VARCHAR(20) NULL,
    tem_copia             TINYINT(1) NOT NULL DEFAULT 0,
    observacoes           TEXT NULL,
    etapa_atual_id        INT NOT NULL,
    responsavel_atual_id  INT NULL,
    status                VARCHAR(20) NOT NULL DEFAULT 'em_andamento',
    criado_em             DATETIME NOT NULL,
    atualizado_em         DATETIME NOT NULL,
    PRIMARY KEY (id),
    CONSTRAINT fk_livro_etapa FOREIGN KEY (etapa_atual_id) REFERENCES etapas (id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_livro_responsavel FOREIGN KEY (responsavel_atual_id) REFERENCES usuarios (id) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Livro x pessoa x papel (único por combinação).
CREATE TABLE contribuicoes (
    id         INT NOT NULL AUTO_INCREMENT,
    livro_id   INT NOT NULL,
    pessoa_id  INT NOT NULL,
    papel_id   INT NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_contribuicao (livro_id, pessoa_id, papel_id),
    CONSTRAINT fk_contrib_livro FOREIGN KEY (livro_id) REFERENCES livros (id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_contrib_pessoa FOREIGN KEY (pessoa_id) REFERENCES pessoas (id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_contrib_papel FOREIGN KEY (papel_id) REFERENCES papeis (id) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Histórico de etapas/mesas do livro — nunca apagado nem sobrescrito.
-- tipo: avanco | repeticao | retrocesso | pulo_opcional | troca_responsavel | resgate.
-- responsavel_id = mesa; registrado_por_id = quem fez a ação.
-- data_saida NULL = movimentação aberta (no máximo uma por livro).
CREATE TABLE movimentacoes (
    id                 INT NOT NULL AUTO_INCREMENT,
    livro_id           INT NOT NULL,
    etapa_id           INT NOT NULL,
    responsavel_id     INT NOT NULL,
    registrado_por_id  INT NOT NULL,
    tipo               VARCHAR(30) NOT NULL,
    rodada             INT NOT NULL DEFAULT 1,
    excedente          TINYINT(1) NOT NULL DEFAULT 0,
    data_entrada       DATETIME NOT NULL,
    data_saida         DATETIME NULL,
    -- Recado opcional de quem fez a ação para quem recebe o livro (Passo 20.8). Faz parte do histórico:
    -- é gravado ao abrir a movimentação e não é editado depois. Máx. 1.000 caracteres (validado no PHP).
    observacao         VARCHAR(1000) NULL,
    PRIMARY KEY (id),
    KEY idx_mov_livro_etapa (livro_id, etapa_id),
    KEY idx_mov_livro_saida (livro_id, data_saida),
    CONSTRAINT fk_mov_livro FOREIGN KEY (livro_id) REFERENCES livros (id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_mov_etapa FOREIGN KEY (etapa_id) REFERENCES etapas (id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_mov_responsavel FOREIGN KEY (responsavel_id) REFERENCES usuarios (id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_mov_registrado_por FOREIGN KEY (registrado_por_id) REFERENCES usuarios (id) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Procedimento de localizar um livro perdido; parte da última movimentação conhecida.
CREATE TABLE resgates (
    id                          INT NOT NULL AUTO_INCREMENT,
    livro_id                    INT NOT NULL,
    movimentacao_referencia_id  INT NOT NULL,
    data_abertura               DATETIME NOT NULL,
    data_conclusao              DATETIME NULL,
    local_encontrado            VARCHAR(255) NULL,
    registrado_por_id           INT NOT NULL,
    PRIMARY KEY (id),
    CONSTRAINT fk_resgate_livro FOREIGN KEY (livro_id) REFERENCES livros (id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_resgate_movimentacao FOREIGN KEY (movimentacao_referencia_id) REFERENCES movimentacoes (id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_resgate_registrado_por FOREIGN KEY (registrado_por_id) REFERENCES usuarios (id) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Auditoria. entidade_tipo + entidade_id apontam para livro, pessoa, usuario, etapa,
-- papel ou configuracao — relação polimórfica, por isso sem FK em entidade_id.
CREATE TABLE log_alteracoes (
    id              INT NOT NULL AUTO_INCREMENT,
    usuario_id      INT NOT NULL,
    acao            VARCHAR(60) NOT NULL,
    entidade_tipo   VARCHAR(30) NOT NULL,
    entidade_id     INT NOT NULL,
    valor_anterior  VARCHAR(255) NULL,
    valor_novo      VARCHAR(255) NULL,
    criado_em       DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_log_criado_em (criado_em),
    CONSTRAINT fk_log_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
