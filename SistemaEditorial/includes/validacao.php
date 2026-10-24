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
