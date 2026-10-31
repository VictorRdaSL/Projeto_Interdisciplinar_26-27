-- seed.sql — WareSys, Controle Editorial.
-- Dados iniciais. Rode DEPOIS de schema.sql, uma única vez, no banco recém-criado.
-- (Não é idempotente: numa segunda execução os INSERTs falham por chave duplicada.)

SET NAMES utf8mb4;
USE waresys;

-- ATENÇÃO (Passo 20.9): Gerente e Administrador podem EXCLUIR uma etapa que nunca foi usada, inclusive uma destas 15.
-- Por isso este arquivo só serve para um banco recém-criado: num banco em uso ele falha por chave duplicada (os ids 1..15
-- são fixos e o INSERT é um comando só) e, se falhasse de outro jeito, recriaria etapas excluídas de propósito.
-- Não rode o seed de novo para "consertar" um banco em uso.
--
-- Etapas do fluxo editorial (ids fixos 1..15; a ordem do fluxo é a coluna `ordem`).
-- Opcionais: Ilustração, Aprovação da capa e Revisão. Limites incluídos: 3 rodadas de
-- Diagramação e 2 de Revisão. dias_limite NULL = usa o padrão global (configuracoes).
INSERT INTO etapas (id, nome, ordem, opcional, repetivel, rodadas_incluidas, dias_limite, ativa, finaliza_livro) VALUES
    ( 1, 'Orçamento',                                 1, 0, 0, NULL, NULL, 1, 0),
    ( 2, 'Contrato',                                  2, 0, 0, NULL, NULL, 1, 0),
    ( 3, 'Envio de material',                         3, 0, 0, NULL, NULL, 1, 0),
    ( 4, 'Capa',                                      4, 0, 0, NULL, NULL, 1, 0),
    ( 5, 'Ilustração',                                5, 1, 0, NULL, NULL, 1, 0),
    ( 6, 'Aprovação da capa',                         6, 1, 1, NULL, NULL, 1, 0),
    ( 7, 'Diagramação',                               7, 0, 1, 3,    NULL, 1, 0),
    ( 8, 'Revisão',                                   8, 1, 1, 2,    NULL, 1, 0),
    ( 9, 'Catalogação — ISBN e ficha catalográfica',  9, 0, 0, NULL, NULL, 1, 0),
    (10, 'Fechamento de arquivo',                    10, 0, 0, NULL, NULL, 1, 0),
    (11, 'Envio do termo de impressão',              11, 0, 0, NULL, NULL, 1, 0),
    (12, 'Chegada da cópia teste',                   12, 0, 0, NULL, NULL, 1, 0),
    (13, 'Leitura da cópia até aprovação',           13, 0, 1, NULL, NULL, 1, 0),
    (14, 'Impressão',                                14, 0, 0, NULL, NULL, 1, 0),
    (15, 'Depósito legal — Biblioteca Nacional (RJ)', 15, 0, 0, NULL, NULL, 1, 1);

-- Papéis de uma pessoa num livro.
INSERT INTO papeis (id, nome, ativo) VALUES
    (1, 'Autor', 1),
    (2, 'Organizador', 1),
    (3, 'Ilustrador', 1);

-- Padrão global de "livro parado" (dias na mesma etapa antes de entrar no alerta do painel).
INSERT INTO configuracoes (chave, valor) VALUES
    ('dias_limite_livro_parado', '15');

-- Administrador inicial.
-- SENHA PROVISÓRIA: admin123 (hash bcrypt gerado por password_hash do PHP).
-- Troque-a antes de publicar o sistema — ver README.txt.
INSERT INTO usuarios (nome, email, senha_hash, perfil, tema, criado_em) VALUES
    ('Administrador', 'admin@waresys.com', '$2y$10$zkCwrMm5mDVcAtPFUIjJZO9erEr.KFexqJSML2JHf0U/ZSTb2eSKO', 'admin', 'claro', NOW());
