-- migrations/seed_editorial.sql
-- Dados iniciais do fluxo editorial: etapas, papeis e configuracao de dias-limite.
-- Seguro rodar mais de uma vez: se o registro ja existir, ATUALIZA os valores
-- em vez de duplicar (padrao UPDATE + INSERT...WHERE NOT EXISTS, portavel —
-- nao usa ON DUPLICATE KEY UPDATE porque etapas.ordem e papeis.nome nao tem
-- UNIQUE KEY, de proposito, para permitir paralelismo futuro de etapas).
-- Depende de migracao_editorial.sql ja ter sido executado (tabelas etapas, papeis, configuracoes).

USE sistema_estoque;

-- ============================================================
-- ETAPAS (ordem, nome, opcional, repetivel, rodadas_incluidas, finaliza_livro)
-- dias_limite = NULL em todas: usam o padrao global de configuracoes.
-- ============================================================

UPDATE etapas SET nome='Orçamento', opcional=0, repetivel=0, rodadas_incluidas=NULL, dias_limite=NULL, ativa=1, finaliza_livro=0 WHERE ordem=1;
INSERT INTO etapas (nome, ordem, opcional, repetivel, rodadas_incluidas, dias_limite, ativa, finaliza_livro)
SELECT 'Orçamento', 1, 0, 0, NULL, NULL, 1, 0
WHERE NOT EXISTS (SELECT 1 FROM etapas WHERE ordem = 1);

UPDATE etapas SET nome='Contrato', opcional=0, repetivel=0, rodadas_incluidas=NULL, dias_limite=NULL, ativa=1, finaliza_livro=0 WHERE ordem=2;
INSERT INTO etapas (nome, ordem, opcional, repetivel, rodadas_incluidas, dias_limite, ativa, finaliza_livro)
SELECT 'Contrato', 2, 0, 0, NULL, NULL, 1, 0
WHERE NOT EXISTS (SELECT 1 FROM etapas WHERE ordem = 2);

UPDATE etapas SET nome='Envio de material', opcional=0, repetivel=0, rodadas_incluidas=NULL, dias_limite=NULL, ativa=1, finaliza_livro=0 WHERE ordem=3;
INSERT INTO etapas (nome, ordem, opcional, repetivel, rodadas_incluidas, dias_limite, ativa, finaliza_livro)
SELECT 'Envio de material', 3, 0, 0, NULL, NULL, 1, 0
WHERE NOT EXISTS (SELECT 1 FROM etapas WHERE ordem = 3);

UPDATE etapas SET nome='Capa', opcional=0, repetivel=0, rodadas_incluidas=NULL, dias_limite=NULL, ativa=1, finaliza_livro=0 WHERE ordem=4;
INSERT INTO etapas (nome, ordem, opcional, repetivel, rodadas_incluidas, dias_limite, ativa, finaliza_livro)
SELECT 'Capa', 4, 0, 0, NULL, NULL, 1, 0
WHERE NOT EXISTS (SELECT 1 FROM etapas WHERE ordem = 4);

UPDATE etapas SET nome='Ilustração', opcional=1, repetivel=0, rodadas_incluidas=NULL, dias_limite=NULL, ativa=1, finaliza_livro=0 WHERE ordem=5;
INSERT INTO etapas (nome, ordem, opcional, repetivel, rodadas_incluidas, dias_limite, ativa, finaliza_livro)
SELECT 'Ilustração', 5, 1, 0, NULL, NULL, 1, 0
WHERE NOT EXISTS (SELECT 1 FROM etapas WHERE ordem = 5);

UPDATE etapas SET nome='Aprovação da capa', opcional=1, repetivel=1, rodadas_incluidas=NULL, dias_limite=NULL, ativa=1, finaliza_livro=0 WHERE ordem=6;
INSERT INTO etapas (nome, ordem, opcional, repetivel, rodadas_incluidas, dias_limite, ativa, finaliza_livro)
SELECT 'Aprovação da capa', 6, 1, 1, NULL, NULL, 1, 0
WHERE NOT EXISTS (SELECT 1 FROM etapas WHERE ordem = 6);

UPDATE etapas SET nome='Diagramação', opcional=0, repetivel=1, rodadas_incluidas=3, dias_limite=NULL, ativa=1, finaliza_livro=0 WHERE ordem=7;
INSERT INTO etapas (nome, ordem, opcional, repetivel, rodadas_incluidas, dias_limite, ativa, finaliza_livro)
SELECT 'Diagramação', 7, 0, 1, 3, NULL, 1, 0
WHERE NOT EXISTS (SELECT 1 FROM etapas WHERE ordem = 7);

UPDATE etapas SET nome='Revisão', opcional=1, repetivel=1, rodadas_incluidas=2, dias_limite=NULL, ativa=1, finaliza_livro=0 WHERE ordem=8;
INSERT INTO etapas (nome, ordem, opcional, repetivel, rodadas_incluidas, dias_limite, ativa, finaliza_livro)
SELECT 'Revisão', 8, 1, 1, 2, NULL, 1, 0
WHERE NOT EXISTS (SELECT 1 FROM etapas WHERE ordem = 8);

UPDATE etapas SET nome='Catalogação — ISBN e ficha catalográfica', opcional=0, repetivel=0, rodadas_incluidas=NULL, dias_limite=NULL, ativa=1, finaliza_livro=0 WHERE ordem=9;
INSERT INTO etapas (nome, ordem, opcional, repetivel, rodadas_incluidas, dias_limite, ativa, finaliza_livro)
SELECT 'Catalogação — ISBN e ficha catalográfica', 9, 0, 0, NULL, NULL, 1, 0
WHERE NOT EXISTS (SELECT 1 FROM etapas WHERE ordem = 9);

UPDATE etapas SET nome='Fechamento de arquivo', opcional=0, repetivel=0, rodadas_incluidas=NULL, dias_limite=NULL, ativa=1, finaliza_livro=0 WHERE ordem=10;
INSERT INTO etapas (nome, ordem, opcional, repetivel, rodadas_incluidas, dias_limite, ativa, finaliza_livro)
SELECT 'Fechamento de arquivo', 10, 0, 0, NULL, NULL, 1, 0
WHERE NOT EXISTS (SELECT 1 FROM etapas WHERE ordem = 10);

UPDATE etapas SET nome='Envio do termo de impressão', opcional=0, repetivel=0, rodadas_incluidas=NULL, dias_limite=NULL, ativa=1, finaliza_livro=0 WHERE ordem=11;
INSERT INTO etapas (nome, ordem, opcional, repetivel, rodadas_incluidas, dias_limite, ativa, finaliza_livro)
SELECT 'Envio do termo de impressão', 11, 0, 0, NULL, NULL, 1, 0
WHERE NOT EXISTS (SELECT 1 FROM etapas WHERE ordem = 11);

UPDATE etapas SET nome='Chegada da cópia teste', opcional=0, repetivel=0, rodadas_incluidas=NULL, dias_limite=NULL, ativa=1, finaliza_livro=0 WHERE ordem=12;
INSERT INTO etapas (nome, ordem, opcional, repetivel, rodadas_incluidas, dias_limite, ativa, finaliza_livro)
SELECT 'Chegada da cópia teste', 12, 0, 0, NULL, NULL, 1, 0
WHERE NOT EXISTS (SELECT 1 FROM etapas WHERE ordem = 12);

UPDATE etapas SET nome='Leitura da cópia até aprovação', opcional=0, repetivel=1, rodadas_incluidas=NULL, dias_limite=NULL, ativa=1, finaliza_livro=0 WHERE ordem=13;
INSERT INTO etapas (nome, ordem, opcional, repetivel, rodadas_incluidas, dias_limite, ativa, finaliza_livro)
SELECT 'Leitura da cópia até aprovação', 13, 0, 1, NULL, NULL, 1, 0
WHERE NOT EXISTS (SELECT 1 FROM etapas WHERE ordem = 13);

UPDATE etapas SET nome='Impressão', opcional=0, repetivel=0, rodadas_incluidas=NULL, dias_limite=NULL, ativa=1, finaliza_livro=0 WHERE ordem=14;
INSERT INTO etapas (nome, ordem, opcional, repetivel, rodadas_incluidas, dias_limite, ativa, finaliza_livro)
SELECT 'Impressão', 14, 0, 0, NULL, NULL, 1, 0
WHERE NOT EXISTS (SELECT 1 FROM etapas WHERE ordem = 14);

UPDATE etapas SET nome='Depósito legal — Biblioteca Nacional (RJ)', opcional=0, repetivel=0, rodadas_incluidas=NULL, dias_limite=NULL, ativa=1, finaliza_livro=1 WHERE ordem=15;
INSERT INTO etapas (nome, ordem, opcional, repetivel, rodadas_incluidas, dias_limite, ativa, finaliza_livro)
SELECT 'Depósito legal — Biblioteca Nacional (RJ)', 15, 0, 0, NULL, NULL, 1, 1
WHERE NOT EXISTS (SELECT 1 FROM etapas WHERE ordem = 15);

-- ============================================================
-- PAPEIS
-- ============================================================

UPDATE papeis SET ativo=1 WHERE nome='Autor';
INSERT INTO papeis (nome, ativo)
SELECT 'Autor', 1
WHERE NOT EXISTS (SELECT 1 FROM papeis WHERE nome = 'Autor');

UPDATE papeis SET ativo=1 WHERE nome='Organizador';
INSERT INTO papeis (nome, ativo)
SELECT 'Organizador', 1
WHERE NOT EXISTS (SELECT 1 FROM papeis WHERE nome = 'Organizador');

UPDATE papeis SET ativo=1 WHERE nome='Ilustrador';
INSERT INTO papeis (nome, ativo)
SELECT 'Ilustrador', 1
WHERE NOT EXISTS (SELECT 1 FROM papeis WHERE nome = 'Ilustrador');

-- ============================================================
-- CONFIGURAÇÕES
-- ============================================================

UPDATE configuracoes SET valor='15' WHERE chave='dias_limite_livro_parado';
INSERT INTO configuracoes (chave, valor)
SELECT 'dias_limite_livro_parado', '15'
WHERE NOT EXISTS (SELECT 1 FROM configuracoes WHERE chave = 'dias_limite_livro_parado');
