-- migrations/seed_editorial.sql
-- Dados iniciais do fluxo editorial: etapas, papeis e configuracao de dias-limite.
-- Seguro rodar mais de uma vez — cada INSERT só executa se o registro ainda nao existir.
-- Depende de migracao_editorial.sql ja ter sido executado (tabelas etapas, papeis, configuracoes).

USE sistema_estoque;

-- ============================================================
-- ETAPAS (ordem, nome, opcional, repetivel, rodadas_incluidas, finaliza_livro)
-- dias_limite = NULL em todas: usam o padrao global de configuracoes.
-- ============================================================

INSERT INTO etapas (nome, ordem, opcional, repetivel, rodadas_incluidas, dias_limite, ativa, finaliza_livro)
SELECT 'Orçamento', 1, 0, 0, NULL, NULL, 1, 0
WHERE NOT EXISTS (SELECT 1 FROM etapas WHERE ordem = 1);

INSERT INTO etapas (nome, ordem, opcional, repetivel, rodadas_incluidas, dias_limite, ativa, finaliza_livro)
SELECT 'Contrato', 2, 0, 0, NULL, NULL, 1, 0
WHERE NOT EXISTS (SELECT 1 FROM etapas WHERE ordem = 2);

INSERT INTO etapas (nome, ordem, opcional, repetivel, rodadas_incluidas, dias_limite, ativa, finaliza_livro)
SELECT 'Envio de material', 3, 0, 0, NULL, NULL, 1, 0
WHERE NOT EXISTS (SELECT 1 FROM etapas WHERE ordem = 3);

INSERT INTO etapas (nome, ordem, opcional, repetivel, rodadas_incluidas, dias_limite, ativa, finaliza_livro)
SELECT 'Capa', 4, 0, 0, NULL, NULL, 1, 0
WHERE NOT EXISTS (SELECT 1 FROM etapas WHERE ordem = 4);

INSERT INTO etapas (nome, ordem, opcional, repetivel, rodadas_incluidas, dias_limite, ativa, finaliza_livro)
SELECT 'Ilustração', 5, 1, 0, NULL, NULL, 1, 0
WHERE NOT EXISTS (SELECT 1 FROM etapas WHERE ordem = 5);

INSERT INTO etapas (nome, ordem, opcional, repetivel, rodadas_incluidas, dias_limite, ativa, finaliza_livro)
SELECT 'Aprovação da capa', 6, 0, 1, NULL, NULL, 1, 0
WHERE NOT EXISTS (SELECT 1 FROM etapas WHERE ordem = 6);

INSERT INTO etapas (nome, ordem, opcional, repetivel, rodadas_incluidas, dias_limite, ativa, finaliza_livro)
SELECT 'Diagramação', 7, 0, 1, 3, NULL, 1, 0
WHERE NOT EXISTS (SELECT 1 FROM etapas WHERE ordem = 7);

INSERT INTO etapas (nome, ordem, opcional, repetivel, rodadas_incluidas, dias_limite, ativa, finaliza_livro)
SELECT 'Revisão', 8, 0, 1, 2, NULL, 1, 0
WHERE NOT EXISTS (SELECT 1 FROM etapas WHERE ordem = 8);

INSERT INTO etapas (nome, ordem, opcional, repetivel, rodadas_incluidas, dias_limite, ativa, finaliza_livro)
SELECT 'Catalogação — ISBN e ficha catalográfica', 9, 0, 0, NULL, NULL, 1, 0
WHERE NOT EXISTS (SELECT 1 FROM etapas WHERE ordem = 9);

INSERT INTO etapas (nome, ordem, opcional, repetivel, rodadas_incluidas, dias_limite, ativa, finaliza_livro)
SELECT 'Fechamento de arquivo', 10, 0, 0, NULL, NULL, 1, 0
WHERE NOT EXISTS (SELECT 1 FROM etapas WHERE ordem = 10);

INSERT INTO etapas (nome, ordem, opcional, repetivel, rodadas_incluidas, dias_limite, ativa, finaliza_livro)
SELECT 'Envio do termo de impressão', 11, 0, 0, NULL, NULL, 1, 0
WHERE NOT EXISTS (SELECT 1 FROM etapas WHERE ordem = 11);

INSERT INTO etapas (nome, ordem, opcional, repetivel, rodadas_incluidas, dias_limite, ativa, finaliza_livro)
SELECT 'Chegada da cópia teste', 12, 0, 0, NULL, NULL, 1, 0
WHERE NOT EXISTS (SELECT 1 FROM etapas WHERE ordem = 12);

INSERT INTO etapas (nome, ordem, opcional, repetivel, rodadas_incluidas, dias_limite, ativa, finaliza_livro)
SELECT 'Leitura da cópia até aprovação', 13, 0, 1, NULL, NULL, 1, 0
WHERE NOT EXISTS (SELECT 1 FROM etapas WHERE ordem = 13);

INSERT INTO etapas (nome, ordem, opcional, repetivel, rodadas_incluidas, dias_limite, ativa, finaliza_livro)
SELECT 'Impressão', 14, 0, 0, NULL, NULL, 1, 0
WHERE NOT EXISTS (SELECT 1 FROM etapas WHERE ordem = 14);

INSERT INTO etapas (nome, ordem, opcional, repetivel, rodadas_incluidas, dias_limite, ativa, finaliza_livro)
SELECT 'Depósito legal — Biblioteca Nacional (RJ)', 15, 0, 0, NULL, NULL, 1, 1
WHERE NOT EXISTS (SELECT 1 FROM etapas WHERE ordem = 15);

-- ============================================================
-- PAPEIS
-- ============================================================

INSERT INTO papeis (nome, ativo)
SELECT 'Autor', 1
WHERE NOT EXISTS (SELECT 1 FROM papeis WHERE nome = 'Autor');

INSERT INTO papeis (nome, ativo)
SELECT 'Organizador', 1
WHERE NOT EXISTS (SELECT 1 FROM papeis WHERE nome = 'Organizador');

INSERT INTO papeis (nome, ativo)
SELECT 'Ilustrador', 1
WHERE NOT EXISTS (SELECT 1 FROM papeis WHERE nome = 'Ilustrador');

-- ============================================================
-- CONFIGURAÇÕES
-- ============================================================

INSERT INTO configuracoes (chave, valor)
SELECT 'dias_limite_livro_parado', '15'
WHERE NOT EXISTS (SELECT 1 FROM configuracoes WHERE chave = 'dias_limite_livro_parado');
