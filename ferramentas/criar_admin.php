<?php
/**
 * Cria o primeiro Administrador do WareSys. SÓ roda pela linha de comando (nunca pelo navegador).
 *
 * Por que existe: o seed.sql não cria usuário nenhum (nada de e-mail, senha ou hash no repositório). Depois de importar
 * schema.sql, seed.sql, views.sql e procedures.sql, rode este script uma vez:
 *
 *     php ferramentas/criar_admin.php
 *
 * Ele pergunta nome, e-mail e senha (a senha é digitada sem aparecer, duas vezes, com no mínimo 12 caracteres), grava com
 * password_hash e nunca mostra nem registra a senha. Se já existir um Administrador ativo, recusa, a menos que se use
 * --outro. Opções:
 *
 *     --config=/caminho/do/config.php   config.php a usar (padrão: o mesmo que o sistema usaria; ver includes/configuracao.php)
 *     --nome="Nome"  --email=voce@exemplo.invalid  pula a pergunta correspondente
 *     --senha-stdin                     lê a senha (uma linha) da entrada padrão, para scripts; no terminal, deixe de fora
 *     --outro                           cria mais um Administrador mesmo já existindo um
 *
 * É independente do resto do sistema (só usa o config.php e o banco), então pode ser copiado sozinho para o servidor,
 * rodado por SSH e apagado em seguida. Sem SSH, veja a alternativa pelo phpMyAdmin no README.md.
 */
if (PHP_SAPI !== 'cli' || php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit;
}

const SENHA_MINIMA_ADMIN = 12;
const SENHA_MAXIMA_BYTES_ADMIN = 72; // o bcrypt ignora o que passar de 72 bytes

function falhar(string $mensagem, int $codigo = 1): void
{
    fwrite(STDERR, "\n" . $mensagem . "\n");
    exit($codigo);
}

// ---- opções
$opcoes = [];
foreach (array_slice($argv, 1) as $argumento) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/s', $argumento, $m)) {
        $opcoes[$m[1]] = $m[2] ?? true;
    } else {
        falhar("Opção desconhecida: $argumento\nUso: php ferramentas/criar_admin.php [--config=...] [--nome=...] [--email=...] [--senha-stdin] [--outro]");
    }
}

// ---- config.php (mesma ordem do sistema: opção, variável de ambiente, ao lado do sistema, dentro do sistema)
$candidatos = [];
if (isset($opcoes['config']) && is_string($opcoes['config'])) {
    $candidatos[] = $opcoes['config'];
}
$doAmbiente = getenv('WARESYS_CONFIG');
if (is_string($doAmbiente) && $doAmbiente !== '') {
    $candidatos[] = $doAmbiente;
}
$pasta = __DIR__;
array_push($candidatos,
    dirname($pasta) . '/waresys-config.php',
    dirname($pasta, 2) . '/waresys-config.php',
    $pasta . '/waresys-config.php',
    dirname($pasta) . '/SistemaEditorial/config/config.php'
);
$config = null;
foreach ($candidatos as $caminho) {
    if (is_file($caminho)) {
        $lido = @include $caminho;
        if (is_array($lido) && isset($lido['db'])) {
            $config = $lido;
            break;
        }
    }
}
if ($config === null) {
    falhar("Não encontrei o config.php. Informe o caminho com --config=/caminho/do/config.php ou defina WARESYS_CONFIG.");
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $db = $config['db'];
    $conn = new mysqli($db['host'], $db['usuario'], $db['senha'], $db['nome'], (int)($db['porta'] ?? 3306));
    $conn->set_charset($db['charset'] ?? 'utf8mb4');
} catch (Throwable $e) {
    falhar('Não foi possível conectar ao banco (confira o config.php e se o banco foi importado). ' . get_class($e));
}

// ---- entrada
function perguntar(string $pergunta): string
{
    fwrite(STDOUT, $pergunta);
    $linha = fgets(STDIN);
    return $linha === false ? '' : trim($linha);
}

/** Lê a senha sem mostrá-la. Devolve null se o terminal não permitir esconder. */
function lerSenhaOculta(string $pergunta): ?string
{
    if (PHP_OS_FAMILY === 'Windows') {
        // O PowerShell esconde a digitação; a saída padrão dele (só a senha) volta para cá.
        $comando = 'powershell -NoProfile -Command "$s = Read-Host -AsSecureString \'' . $pergunta . '\'; '
            . '[Runtime.InteropServices.Marshal]::PtrToStringBSTR([Runtime.InteropServices.Marshal]::SecureStringToBSTR($s))"';
        $processo = @proc_open($comando, [0 => STDIN, 1 => ['pipe', 'w'], 2 => STDERR], $pipes);
        if (!is_resource($processo)) {
            return null;
        }
        $senha = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $codigo = proc_close($processo);
        return ($codigo === 0 && $senha !== false) ? rtrim($senha, "\r\n") : null;
    }
    if (function_exists('posix_isatty') && @posix_isatty(STDIN)) {
        fwrite(STDOUT, $pergunta);
        shell_exec('stty -echo');
        $linha = fgets(STDIN);
        shell_exec('stty echo');
        fwrite(STDOUT, "\n");
        return $linha === false ? '' : rtrim($linha, "\r\n");
    }
    return null;
}

function erroDaSenha(string $senha): ?string
{
    if (mb_strlen($senha, 'UTF-8') < SENHA_MINIMA_ADMIN) {
        return 'A senha deve ter pelo menos ' . SENHA_MINIMA_ADMIN . ' caracteres.';
    }
    if (strlen($senha) > SENHA_MAXIMA_BYTES_ADMIN) {
        return 'A senha pode ter no máximo ' . SENHA_MAXIMA_BYTES_ADMIN . ' bytes (cerca de 72 caracteres comuns).';
    }
    return null;
}

// ---- já existe Administrador?
$stmt = $conn->prepare("SELECT COUNT(*) FROM usuarios WHERE perfil = 'admin' AND ativo = 1");
$stmt->execute();
$jaTem = (int)$stmt->get_result()->fetch_row()[0];
if ($jaTem > 0 && empty($opcoes['outro'])) {
    falhar("Já existe $jaTem Administrador(es) ativo(s). Nada foi criado.\nPara criar mais um mesmo assim, rode com --outro.", 2);
}

fwrite(STDOUT, "Criar Administrador do WareSys\n\n");

$nome = isset($opcoes['nome']) && is_string($opcoes['nome']) ? trim($opcoes['nome']) : perguntar('Nome: ');
if ($nome === '' || mb_strlen($nome, 'UTF-8') > 120) {
    falhar('Informe um nome de até 120 caracteres.');
}

$email = isset($opcoes['email']) && is_string($opcoes['email']) ? trim($opcoes['email']) : perguntar('E-mail (será o login): ');
if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || mb_strlen($email, 'UTF-8') > 150) {
    falhar('Informe um e-mail válido de até 150 caracteres.');
}

$stmt = $conn->prepare('SELECT 1 FROM usuarios WHERE email = ?');
$stmt->bind_param('s', $email);
$stmt->execute();
if ($stmt->get_result()->fetch_row() !== null) {
    falhar('Já existe um usuário com esse e-mail. Nada foi criado.', 2);
}

if (!empty($opcoes['senha-stdin'])) {
    $linha = fgets(STDIN);
    // O PowerShell 5 antepõe uma marca BOM (3 bytes invisíveis) ao texto enviado por pipe: tirar, senão ela entraria na senha.
    $senha = $linha === false ? '' : rtrim(preg_replace('/^\xEF\xBB\xBF/', '', $linha), "\r\n");
    if (($erro = erroDaSenha($senha)) !== null) {
        falhar($erro);
    }
} else {
    $senha = null;
    for ($tentativa = 1; $tentativa <= 3; $tentativa++) {
        $primeira = lerSenhaOculta('Senha (mínimo ' . SENHA_MINIMA_ADMIN . ' caracteres): ');
        if ($primeira === null) {
            fwrite(STDOUT, "Aviso: este terminal não permite esconder a senha; ela vai aparecer enquanto você digita.\n");
            $primeira = perguntar('Senha (mínimo ' . SENHA_MINIMA_ADMIN . ' caracteres): ');
            $segunda = perguntar('Repita a senha: ');
        } else {
            $segunda = lerSenhaOculta('Repita a senha: ') ?? '';
        }
        if (($erro = erroDaSenha($primeira)) !== null) {
            fwrite(STDOUT, $erro . "\n");
        } elseif ($primeira !== $segunda) {
            fwrite(STDOUT, "As senhas não coincidem.\n");
        } else {
            $senha = $primeira;
            break;
        }
    }
    if ($senha === null) {
        falhar('Senha não aceita em 3 tentativas. Nada foi criado.');
    }
}

// ---- grava (a senha só existe aqui, na memória, e vai para o banco já em hash)
$hash = password_hash($senha, PASSWORD_DEFAULT);
unset($senha, $primeira, $segunda);
$perfil = 'admin';
$tema = 'claro';
$agora = date('Y-m-d H:i:s');
$stmt = $conn->prepare('INSERT INTO usuarios (nome, email, senha_hash, perfil, tema, ativo, criado_em) VALUES (?, ?, ?, ?, ?, 1, ?)');
$stmt->bind_param('ssssss', $nome, $email, $hash, $perfil, $tema, $agora);
$stmt->execute();

fwrite(STDOUT, "\nAdministrador criado: $email\nGuarde a senha em local seguro (ela não é mostrada nem gravada em lugar nenhum).\n");
exit(0);
