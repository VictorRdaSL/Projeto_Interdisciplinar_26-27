<?php
/**
 * Chamada de stored procedures (database/procedures.sql) com prepared statement.
 */

// ER_SIGNAL_EXCEPTION: erro gerado por SIGNAL SQLSTATE '45000' dentro da procedure. O PHP 8.0 não
// tem mysqli_sql_exception::getSqlState() (só a partir do 8.1.2), então o reconhecimento é pelo código.
const ERRO_MYSQL_SIGNAL = 1644;

/**
 * Executa um "CALL sp_nome(?, ?, ...)" e devolve as linhas do PRIMEIRO conjunto de resultados
 * (lista vazia se a procedure não devolve linhas). Os conjuntos de resultados extras e o pacote
 * final de status do CALL são lidos e descartados, para a consulta seguinte não dar
 * "Commands out of sync".
 *
 * Erro de validação da procedure (SIGNAL) vira InvalidArgumentException com a mensagem em português
 * que a procedure escreveu — os catch das telas já tratam esse tipo. Qualquer outro erro do banco
 * (chave estrangeira, conexão...) continua sendo mysqli_sql_exception.
 */
function chamarProcedure(mysqli $conn, string $chamada, string $tipos, array $parametros): array
{
    $stmt = null;
    try {
        $stmt = $conn->prepare($chamada);
        $stmt->bind_param($tipos, ...$parametros);
        $stmt->execute();

        $linhas = [];
        $resultado = $stmt->get_result();
        if ($resultado instanceof mysqli_result) {
            while ($linha = $resultado->fetch_assoc()) {
                $linhas[] = $linha;
            }
            $resultado->free();
        }
        while ($stmt->more_results()) {
            $stmt->next_result();
            $extra = $stmt->get_result();
            if ($extra instanceof mysqli_result) {
                $extra->free();
            }
        }
        return $linhas;
    } catch (mysqli_sql_exception $e) {
        if ($e->getCode() === ERRO_MYSQL_SIGNAL) {
            throw new InvalidArgumentException($e->getMessage(), 0, $e);
        }
        throw $e;
    } finally {
        if ($stmt instanceof mysqli_stmt) {
            $stmt->close();
        }
    }
}
