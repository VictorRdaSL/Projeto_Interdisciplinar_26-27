-- functions_triggers.sql — WareSys, Controle Editorial.
-- 1 FUNCTION e 2 TRIGGERS (um simples e um avançado) + 1 tabela nova e pequena.
-- Rode DEPOIS de schema.sql, seed.sql, views.sql e procedures.sql. Pode ser rodado de novo quando quiser:
-- faz DROP ... IF EXISTS só dos objetos definidos AQUI e os recria. Para desfazer: functions_triggers_rollback.sql.
--
-- Não altera nenhuma tabela existente e não mexe em nenhum dado existente: os triggers só agem em INSERT NOVO
-- em `movimentacoes`. O PHP não foi alterado e continua fazendo tudo o que fazia (log, rodadas, mesa, livro parado).
-- Sem USE (o banco já deve estar selecionado) e sem DEFINER (na hospedagem compartilhada o objeto fica em nome de
-- quem importa). Datas nunca vêm de NOW(): ou chegam por parâmetro (p_agora) ou já estão na linha inserida.
--
-- Importando pelo phpMyAdmin: o DELIMITER abaixo é entendido pelo importador (o procedures.sql também o usa).

SET NAMES utf8mb4;

-- ------------------------------------------------------------
-- registro_rodadas_excedentes — tabela NOVA, preenchida pelo trigger tg_movimentacoes_registra_excedente.
-- Guarda, no momento em que uma rodada cobrável é aberta, o limite de rodadas que a etapa tinha NAQUELE dia
-- (limite_na_epoca). O relatório de excedentes mostra o limite ATUAL da etapa; se o limite for editado depois,
-- esta tabela ainda diz qual valia quando a rodada foi cobrada.
-- Sem FOREIGN KEY de propósito: uma FK com RESTRICT impediria excluir um livro recém-cadastrado (excluirLivro apaga
-- as movimentações dele). Mesma lógica do log_alteracoes, que também guarda ids sem FK.
-- CREATE TABLE IF NOT EXISTS (sem DROP): rodar o script de novo NÃO apaga o que já foi registrado.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS registro_rodadas_excedentes (
    id               INT NOT NULL AUTO_INCREMENT,
    movimentacao_id  INT NOT NULL,
    livro_id         INT NOT NULL,
    etapa_id         INT NOT NULL,
    rodada           INT NOT NULL,
    limite_na_epoca  INT NULL,
    registrado_em    DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_registro_excedente_mov (movimentacao_id),
    KEY idx_registro_excedente_livro (livro_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DELIMITER $$

-- ------------------------------------------------------------
-- fn_dias_na_etapa — FUNCTION básica, SOMENTE LEITURA.
-- Quantos dias inteiros o livro está na movimentação aberta (etapa atual), contados até p_agora.
-- Parâmetros: p_livro_id (livro) e p_agora (data/hora de referência, vinda do PHP ou digitada: nunca NOW()).
-- Devolve INT, ou NULL se o livro não tem movimentação aberta (concluído, cancelado ou inexistente).
-- READS SQL DATA: só lê `movimentacoes`; não grava nada. Não é DETERMINISTIC porque o resultado depende dos dados.
-- Atenção: a movimentação aberta é reaberta a cada troca de mesa, então os dias contam desde a última troca
-- (mesma regra do alerta de livro parado, vw_livros_situacao_atual.etapa_desde).
-- Uso: SELECT id, titulo, fn_dias_na_etapa(id, '2026-11-17 12:00:00') AS dias FROM livros;
-- ------------------------------------------------------------
DROP FUNCTION IF EXISTS fn_dias_na_etapa$$
CREATE FUNCTION fn_dias_na_etapa(p_livro_id INT, p_agora DATETIME)
RETURNS INT
READS SQL DATA
SQL SECURITY INVOKER
COMMENT 'Dias que o livro esta na movimentacao aberta (etapa atual) ate p_agora; NULL se nao ha movimentacao aberta. So le dados.'
BEGIN
    DECLARE v_entrada DATETIME;

    SET v_entrada = (SELECT data_entrada
                       FROM movimentacoes
                      WHERE livro_id = p_livro_id
                        AND data_saida IS NULL
                      ORDER BY data_entrada DESC, id DESC
                      LIMIT 1);

    IF v_entrada IS NULL THEN
        RETURN NULL;
    END IF;

    RETURN TIMESTAMPDIFF(DAY, v_entrada, p_agora);
END$$

-- ------------------------------------------------------------
-- tg_movimentacoes_registra_excedente — TRIGGER SIMPLES (AFTER INSERT em movimentacoes). Não bloqueia nada.
-- Quando uma rodada COBRÁVEL é aberta (excedente = 1 por avanço, repetição ou retrocesso), grava uma linha em
-- registro_rodadas_excedentes com o limite que a etapa tinha na hora. A troca de mesa copia o excedente da linha
-- anterior, então fica de fora (contaria a mesma cobrança duas vezes) — o mesmo filtro do relatório de excedentes.
-- Não repete o log_alteracoes (o PHP segue gravando o log) nem recalcula a rodada (o PHP segue calculando).
-- Só vale para movimentações NOVAS: o que já existe não é copiado.
-- ------------------------------------------------------------
DROP TRIGGER IF EXISTS tg_movimentacoes_registra_excedente$$
CREATE TRIGGER tg_movimentacoes_registra_excedente
AFTER INSERT ON movimentacoes
FOR EACH ROW
BEGIN
    IF NEW.excedente = 1 AND NEW.tipo IN ('avanco', 'repeticao', 'retrocesso') THEN
        INSERT INTO registro_rodadas_excedentes
            (movimentacao_id, livro_id, etapa_id, rodada, limite_na_epoca, registrado_em)
        VALUES
            (NEW.id, NEW.livro_id, NEW.etapa_id, NEW.rodada,
             (SELECT e.rodadas_incluidas FROM etapas e WHERE e.id = NEW.etapa_id),
             NEW.data_entrada);
    END IF;
END$$

-- ------------------------------------------------------------
-- tg_movimentacoes_valida_insercao — TRIGGER AVANÇADO (BEFORE INSERT em movimentacoes). Valida e pode BLOQUEAR.
-- Duas regras de negócio, com IF/ELSEIF e SIGNAL SQLSTATE '45000' (mensagem em português):
--   1) a data de saída não pode ser anterior à data de entrada;
--   2) um livro não pode ter duas movimentações abertas (data_saida NULL) ao mesmo tempo. A regra "no máximo uma
--      aberta por livro" estava só no PHP; aqui o banco também a garante. Quem vai abrir outra precisa fechar a atual
--      antes (é o que o sistema já faz em avançar, repetir, voltar, passar de mesa e cadastrar livro).
-- Vale só para INSERT novo; nenhuma linha existente é lida nem alterada. O PHP continua validando por conta própria:
-- este trigger é uma segunda trava (quem insere direto no banco, sem passar pelo sistema, também é barrado).
-- ------------------------------------------------------------
DROP TRIGGER IF EXISTS tg_movimentacoes_valida_insercao$$
CREATE TRIGGER tg_movimentacoes_valida_insercao
BEFORE INSERT ON movimentacoes
FOR EACH ROW
BEGIN
    IF NEW.data_saida IS NOT NULL AND NEW.data_saida < NEW.data_entrada THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'A data de saída da movimentação não pode ser anterior à data de entrada.';
    ELSEIF NEW.data_saida IS NULL
       AND EXISTS (SELECT 1 FROM movimentacoes m WHERE m.livro_id = NEW.livro_id AND m.data_saida IS NULL) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Este livro já tem uma movimentação aberta. Feche a atual antes de abrir outra.';
    END IF;
END$$

DELIMITER ;
