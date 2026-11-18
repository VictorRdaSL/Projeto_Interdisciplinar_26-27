-- functions_triggers_rollback.sql — WareSys, Controle Editorial.
-- Desfaz EXATAMENTE o que functions_triggers.sql criou: 2 triggers, 1 function e a tabela registro_rodadas_excedentes.
-- Não toca em nenhuma outra tabela, view ou procedure, nem em nenhum dado dos livros. Pode ser rodado de novo
-- sem erro (DROP ... IF EXISTS). Sem USE: o banco já deve estar selecionado.
--
-- ATENÇÃO: o DROP TABLE apaga os registros guardados em registro_rodadas_excedentes (o limite de rodadas na época
-- de cada rodada cobrável). Os livros, as movimentações e o log não são afetados. Se quiser guardar esses registros,
-- exporte essa tabela no phpMyAdmin antes.
--
-- Rodando pelo phpMyAdmin: este arquivo não usa DELIMITER, é só importar.

DROP TRIGGER IF EXISTS tg_movimentacoes_valida_insercao;
DROP TRIGGER IF EXISTS tg_movimentacoes_registra_excedente;
DROP FUNCTION IF EXISTS fn_dias_na_etapa;
DROP TABLE IF EXISTS registro_rodadas_excedentes;
