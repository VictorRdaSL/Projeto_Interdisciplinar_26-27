<?php
/**
 * Limites de tamanho dos campos de texto e mensagens de erro seguras.
 *
 * Os limites espelham as colunas de database/schema.sql (VARCHAR conta caracteres, em utf8mb4).
 * Sem validar aqui, o MariaDB em modo não estrito (padrão do XAMPP) corta o texto em silêncio e
 * a tela ainda diz "salvo com sucesso".
 */

const LIMITE_TITULO_LIVRO = 255;     // livros.titulo
const LIMITE_NOME_PESSOA = 120;      // pessoas.nome
const LIMITE_EMAIL = 150;            // pessoas.email e usuarios.email
const LIMITE_TELEFONE = 30;          // pessoas.telefone
const LIMITE_NOME_USUARIO = 120;     // usuarios.nome
const LIMITE_NOME_ETAPA = 120;       // etapas.nome
const LIMITE_NOME_PAPEL = 80;        // papeis.nome
const LIMITE_LOCAL_ENCONTRADO = 255; // resgates.local_encontrado
// livros.observacoes é TEXT (65.535 BYTES). Com até 4 bytes por caractere em utf8mb4,
// 16.000 caracteres cabem com folga em qualquer idioma/emoji.
const LIMITE_OBSERVACOES = 16000;

/** Texto de erro se $valor passar de $max caracteres; null se estiver dentro do limite. */
function erroTamanho(string $valor, int $max, string $rotulo): ?string
{
    $tamanho = mb_strlen($valor, 'UTF-8');
    if ($tamanho <= $max) {
        return null;
    }
    return $rotulo . ' deve ter no máximo ' . $max . ' caracteres (foram digitados ' . $tamanho . ').';
}

/**
 * Mensagem que pode ser mostrada ao usuário para uma exceção capturada.
 * InvalidArgumentException é uma regra de negócio lançada de propósito pelo sistema, com texto
 * pensado para a tela. Qualquer outra (erro do banco, TypeError...) leva nomes de tabela e
 * restrição: o detalhe vai só para o log do servidor e a tela recebe um texto genérico.
 */
function mensagemErroSegura(Throwable $e, string $acao): string
{
    if ($e instanceof InvalidArgumentException) {
        return $e->getMessage();
    }
    error_log('[WareSys] Falha ao ' . $acao . ': ' . get_class($e) . ': ' . $e->getMessage() . ' em ' . $e->getFile() . ':' . $e->getLine());
    return 'Não foi possível ' . $acao . '. Tente novamente; se o problema continuar, avise o administrador.';
}

/**
 * Texto vindo de $_GET/$_POST, sem espaços nas pontas. Se o parâmetro não existir ou não for texto
 * (ex.: ?busca[]=x chega como lista), devolve ''. Sem isso, trim() de uma lista derruba a página
 * com TypeError (erro 500) só por alguém alterar a URL.
 */
function entradaTexto(array $origem, string $chave): string
{
    $valor = $origem[$chave] ?? '';
    return is_string($valor) ? trim($valor) : '';
}

/** Igual a entradaTexto(), mas sem trim (senhas: espaços nas pontas fazem parte da senha). */
function entradaBruta(array $origem, string $chave): string
{
    $valor = $origem[$chave] ?? '';
    return is_string($valor) ? $valor : '';
}

const LIMITE_DIAS_MAX = 3650;   // dias-limite (livro parado): 10 anos já é "sem limite" na prática
const LIMITE_RODADAS_MAX = 99;  // rodadas incluídas numa etapa

/**
 * Inteiro positivo escrito só com dígitos, dentro de [1, $max]; qualquer outra coisa devolve null.
 * Rejeita '1e3', '-5', '5.5', '0x1A' e números absurdos (o (int) do PHP aceitaria alguns e estouraria outros).
 */
function inteiroPositivo(string $texto, int $max): ?int
{
    if ($texto === '' || !ctype_digit($texto) || strlen($texto) > 9) {
        return null;
    }
    $n = (int)$texto;
    return ($n >= 1 && $n <= $max) ? $n : null;
}

/** Id numérico de ?id=... (só dígitos); devolve 0 se faltar, vier em branco, negativo ou em formato de lista. */
function idDaUrl(array $origem, string $chave): int
{
    $valor = $origem[$chave] ?? '';
    return (is_string($valor) && ctype_digit($valor)) ? (int)$valor : 0;
}
