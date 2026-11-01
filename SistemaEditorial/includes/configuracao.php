<?php
/**
 * Localiza e carrega o config.php (credenciais do banco, ambiente, base_url, caminho do log).
 *
 * O config.php pode ficar FORA da pasta pública do servidor, que é o recomendado em produção (acima do public_html):
 * assim, mesmo que o servidor deixe de interpretar o PHP por um erro de configuração, o arquivo com a senha do banco
 * não está ao alcance de nenhuma URL. O caminho é procurado nesta ordem, e vale o primeiro arquivo que existir:
 *
 *   1) a variável de ambiente WARESYS_CONFIG (caminho completo do arquivo; pode vir de "SetEnv WARESYS_CONFIG ..." no
 *      .htaccess, que não é segredo, ou do painel da hospedagem);
 *   2) waresys-config.php na pasta ACIMA da pasta do sistema (no XAMPP, a raiz do repositório; na Hostinger, a pasta
 *      do domínio, ao lado do public_html);
 *   3) waresys-config.php duas pastas acima (quando o sistema fica numa subpasta do public_html);
 *   4) config/config.php dentro da pasta do sistema (o padrão do desenvolvimento).
 *
 * Este arquivo não depende de nenhum outro: é o primeiro a ser carregado por sessao.php, config/database.php e pelos
 * scripts de tests/.
 */

/** Caminhos onde o config.php é procurado, na ordem de prioridade (só os candidatos; nem todos existem). */
function caminhosDeConfiguracao(): array
{
    $pastaDoSistema = dirname(__DIR__);
    $caminhos = [];

    $doAmbiente = getenv('WARESYS_CONFIG');
    if (!is_string($doAmbiente) || $doAmbiente === '') {
        $doAmbiente = $_SERVER['WARESYS_CONFIG'] ?? $_SERVER['REDIRECT_WARESYS_CONFIG'] ?? '';
    }
    if (is_string($doAmbiente) && $doAmbiente !== '') {
        $caminhos[] = $doAmbiente;
    }

    $caminhos[] = dirname($pastaDoSistema) . '/waresys-config.php';
    $caminhos[] = dirname($pastaDoSistema, 2) . '/waresys-config.php';
    $caminhos[] = $pastaDoSistema . '/config/config.php';
    return $caminhos;
}

/** O config.php já carregado, ou null se nenhum dos caminhos tem um arquivo válido. Lê o arquivo uma vez só por requisição. */
function carregarConfiguracao(): ?array
{
    static $config = false;
    if ($config !== false) {
        return $config;
    }
    $config = null;
    foreach (caminhosDeConfiguracao() as $caminho) {
        if (!is_file($caminho)) {
            continue;
        }
        $lido = @include $caminho;
        if (is_array($lido)) {
            $config = $lido;
            break;
        }
    }
    return $config;
}
