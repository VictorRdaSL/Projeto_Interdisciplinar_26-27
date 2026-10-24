-- procedures.sql — WareSys, Controle Editorial.
-- Stored procedures. Rode DEPOIS de schema.sql, seed.sql e views.sql (ordem: schema, seed, views,
-- procedures). Pode ser rodado de novo quando quiser: faz DROP PROCEDURE IF EXISTS só das
-- procedures definidas AQUI e as recria.
--
-- Regras: prefixo sp_, parâmetros com prefixo p_, minúsculas e snake_case. Procedure de operação
-- usa START TRANSACTION / COMMIT e, em qualquer erro, ROLLBACK (EXIT HANDLER) + RESIGNAL. Validações
-- usam SIGNAL SQLSTATE '45000' com mensagem em português (o PHP converte em InvalidArgumentException).
-- A checagem de login e de perfil continua SENDO FEITA NO PHP — a procedure não controla acesso.
-- Datas vêm do PHP (parâmetro p_agora, fuso America/Sao_Paulo): nunca NOW() do MySQL.
-- Quem chama NÃO pode estar dentro de uma transação aberta: o START TRANSACTION daqui faria
-- COMMIT implícito dela.
--
-- Importando pelo phpMyAdmin: o DELIMITER abaixo é entendido pelo importador.

SET NAMES utf8mb4;
USE waresys;

DELIMITER $$

-- ------------------------------------------------------------
-- sp_trocar_mesa — passa um livro para outra mesa (outro responsável) sem mudar de etapa.
-- Parâmetros:
--   p_livro_id             livro que muda de mesa
--   p_novo_responsavel_id  usuário que passa a ser a mesa
--   p_registrado_por_id    usuário que fez a ação (vai para movimentacoes e log_alteracoes)
--   p_agora                data/hora da ação, calculada no PHP
-- Tabelas: livros, movimentacoes, log_alteracoes (grava); vw_usuarios_publico (lê nomes).
-- Faz, numa transação: trava o livro; valida; fecha a movimentação aberta; abre outra do tipo
-- 'troca_responsavel' NA MESMA etapa copiando rodada e excedente (trocar de mesa nunca abre
-- rodada nova); atualiza livros.responsavel_atual_id; grava o log 'movimentacao.trocar_responsavel'.
-- Devolve UMA linha: movimentacao_id, etapa_id, etapa_nome, rodada, excedente.
-- ------------------------------------------------------------
DROP PROCEDURE IF EXISTS sp_trocar_mesa$$
CREATE PROCEDURE sp_trocar_mesa(
    IN p_livro_id INT,
    IN p_novo_responsavel_id INT,
    IN p_registrado_por_id INT,
    IN p_agora DATETIME
)
COMMENT 'Passa um livro para outra mesa (mesma etapa e rodada). Grava livros, movimentacoes e log_alteracoes numa transacao.'
BEGIN
    -- sp_trocar_mesa: ver o cabeçalho em database/procedures.sql (parâmetros, tabelas e regras).
    DECLARE v_status VARCHAR(20);
    DECLARE v_responsavel_antigo_id INT;
    DECLARE v_nome_antigo VARCHAR(120);
    DECLARE v_nome_novo VARCHAR(120);
    DECLARE v_mov_aberta_id INT;
    DECLARE v_etapa_id INT;
    DECLARE v_rodada INT;
    DECLARE v_excedente TINYINT(1);
    DECLARE v_nova_mov_id INT;

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;

    START TRANSACTION;

    -- Trava a linha do livro: duas trocas simultâneas do mesmo livro passam a ser sequenciais,
    -- então nunca sobram duas movimentações abertas.
    SELECT status, responsavel_atual_id
      INTO v_status, v_responsavel_antigo_id
      FROM livros
     WHERE id = p_livro_id
       FOR UPDATE;

    IF v_status IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Livro não encontrado.';
    END IF;
    IF v_status <> 'em_andamento' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Este livro não está em andamento — não é possível movimentá-lo.';
    END IF;

    SELECT nome INTO v_nome_novo FROM vw_usuarios_publico WHERE id = p_novo_responsavel_id;
    IF v_nome_novo IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Usuário responsável inválido.';
    END IF;

    SELECT id, etapa_id, rodada, excedente
      INTO v_mov_aberta_id, v_etapa_id, v_rodada, v_excedente
      FROM movimentacoes
     WHERE livro_id = p_livro_id
       AND data_saida IS NULL
     ORDER BY data_entrada DESC, id DESC
     LIMIT 1
       FOR UPDATE;

    IF v_mov_aberta_id IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Não há movimentação aberta para este livro.';
    END IF;

    UPDATE movimentacoes SET data_saida = p_agora WHERE id = v_mov_aberta_id;

    INSERT INTO movimentacoes
        (livro_id, etapa_id, responsavel_id, registrado_por_id, tipo, rodada, excedente, data_entrada, data_saida)
    VALUES
        (p_livro_id, v_etapa_id, p_novo_responsavel_id, p_registrado_por_id, 'troca_responsavel', v_rodada, v_excedente, p_agora, NULL);
    SET v_nova_mov_id = LAST_INSERT_ID();

    UPDATE livros
       SET responsavel_atual_id = p_novo_responsavel_id, atualizado_em = p_agora
     WHERE id = p_livro_id;

    SELECT nome INTO v_nome_antigo FROM vw_usuarios_publico WHERE id = v_responsavel_antigo_id;
    IF v_nome_antigo IS NULL THEN
        SET v_nome_antigo = CONCAT('Usuário #', IFNULL(v_responsavel_antigo_id, 0));
    END IF;

    INSERT INTO log_alteracoes
        (usuario_id, acao, entidade_tipo, entidade_id, valor_anterior, valor_novo, criado_em)
    VALUES
        (p_registrado_por_id, 'movimentacao.trocar_responsavel', 'livro', p_livro_id, v_nome_antigo, v_nome_novo, p_agora);

    COMMIT;

    SELECT v_nova_mov_id AS movimentacao_id,
           v_etapa_id    AS etapa_id,
           e.nome        AS etapa_nome,
           v_rodada      AS rodada,
           v_excedente   AS excedente
      FROM etapas e
     WHERE e.id = v_etapa_id;
END$$

DELIMITER ;
