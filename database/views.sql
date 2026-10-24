-- views.sql — WareSys, Controle Editorial.
-- Views SOMENTE LEITURA. Rode DEPOIS de schema.sql e seed.sql (ordem: schema, seed, views).
-- Pode ser rodado de novo quando quiser: usa CREATE OR REPLACE VIEW, só nas views abaixo.
--
-- Regras: prefixo vw_, snake_case, sem ORDER BY dentro da view (a tela ordena), nenhuma
-- tela grava dados através de view, e nenhuma view calcula "dias" — datas em dias são
-- sempre calculadas em PHP (DateTime::diff), porque NOW()/DATEDIFF() do MySQL já
-- causaram divergência de fuso no projeto.
-- Segurança: as views usam SQL SECURITY DEFINER (padrão), o que permite, no futuro, dar a
-- um usuário de banco só SELECT nas views, sem acesso direto às tabelas. O definer será o
-- usuário que rodar este arquivo (hoje root@localhost) — ajustar ao publicar.
--
-- Funções exclusivas de MySQL/MariaDB usadas neste arquivo:
--   GROUP_CONCAT(... DISTINCT / ORDER BY / SEPARATOR) — só em vw_livros_contribuidores.
--   (O próprio CREATE OR REPLACE VIEW também é sintaxe de MySQL/MariaDB.)

SET NAMES utf8mb4;
USE waresys;

-- ------------------------------------------------------------
-- vw_usuarios_publico — usuários SEM a coluna senha_hash.
-- Tabelas: usuarios.
-- Uso: listagens, listas suspensas e consultas de nome/perfil/tema. Só o login (que precisa
-- conferir a senha) e as gravações (criar usuário, trocar perfil/tema) usam a tabela direta.
-- ------------------------------------------------------------
CREATE OR REPLACE VIEW vw_usuarios_publico AS
SELECT
    u.id,
    u.nome,
    u.email,
    u.perfil,
    u.tema,
    u.criado_em
FROM usuarios u;

-- ------------------------------------------------------------
-- vw_livros_situacao_atual — uma linha por livro: etapa atual, mesa e movimentação aberta.
-- Tabelas: livros (inclui observacoes), etapas, usuarios (só o nome da mesa), movimentacoes (a aberta, data_saida IS NULL).
-- Livros concluídos/cancelados não têm movimentação aberta: movimentacao_id, rodada, excedente
-- e etapa_desde vêm NULL (cada tela filtra o status que quiser).
-- etapa_desde = data_entrada da movimentação ABERTA; ela é reaberta a cada troca de mesa,
-- então não é a data em que o livro entrou na etapa (divergência já documentada no projeto).
-- ------------------------------------------------------------
CREATE OR REPLACE VIEW vw_livros_situacao_atual AS
SELECT
    l.id                    AS livro_id,
    l.titulo                AS titulo,
    l.isbn                  AS isbn,
    l.tem_copia             AS tem_copia,
    l.status                AS status,
    l.observacoes           AS observacoes,
    e.id                    AS etapa_id,
    e.nome                  AS etapa_nome,
    e.ordem                 AS etapa_ordem,
    e.rodadas_incluidas     AS rodadas_incluidas,
    e.dias_limite           AS etapa_dias_limite,
    l.responsavel_atual_id  AS mesa_id,
    u.nome                  AS mesa_nome,
    m.id                    AS movimentacao_id,
    m.rodada                AS rodada,
    m.excedente             AS excedente,
    m.data_entrada          AS etapa_desde
FROM livros l
JOIN etapas e ON e.id = l.etapa_atual_id
LEFT JOIN usuarios u ON u.id = l.responsavel_atual_id
LEFT JOIN movimentacoes m ON m.livro_id = l.id AND m.data_saida IS NULL;

-- ------------------------------------------------------------
-- vw_livros_contribuidores — uma linha por livro COM contribuições: as pessoas vinculadas.
-- Tabelas: contribuicoes, pessoas, papeis.
-- pessoas_nomes  = "Ana, Beto" (nomes distintos, em ordem alfabética).
-- pessoas_papeis = "Ana (Autor), Beto (Organizador)" (uma entrada por contribuição).
-- Livros sem contribuições não aparecem; num LEFT JOIN as colunas vêm NULL.
-- Usa GROUP_CONCAT (exclusivo de MySQL/MariaDB).
-- ------------------------------------------------------------
CREATE OR REPLACE VIEW vw_livros_contribuidores AS
SELECT
    c.livro_id AS livro_id,
    GROUP_CONCAT(DISTINCT pe.nome ORDER BY pe.nome SEPARATOR ', ') AS pessoas_nomes,
    GROUP_CONCAT(CONCAT(pe.nome, ' (', pa.nome, ')') ORDER BY pe.nome SEPARATOR ', ') AS pessoas_papeis
FROM contribuicoes c
JOIN pessoas pe ON pe.id = c.pessoa_id
JOIN papeis pa ON pa.id = c.papel_id
GROUP BY c.livro_id;
