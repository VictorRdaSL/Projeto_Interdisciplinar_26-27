# Publicar o WareSys na Hostinger

Passo a passo para colocar o sistema no ar numa hospedagem compartilhada (Hostinger, plano Premium Web Hosting, com PHP e MySQL/MariaDB). Este documento **não contém nenhuma senha, e-mail ou dado de acesso**: onde aparece `SEU_...` ou `uXXXXXXXX`, use os valores da sua conta. Nunca escreva credenciais neste arquivo nem em nenhum arquivo do repositório.

## 1. O que enviar e o que NÃO enviar

O servidor recebe **somente o conteúdo da pasta `SistemaEditorial/`**, e mesmo dela nem tudo.

**Enviar** (conteúdo de `SistemaEditorial/`):

- as páginas `.php` (`index.php`, `login.php`, `livros.php`, `configuracoes.php`, `relatorio_*.php` e as demais);
- `includes/`, `assets/` (CSS, JS, fontes e `img/logo/`, o símbolo da marca), `config/database.php`, `envio_unico.js`;
- os ícones da aba do navegador, na raiz do sistema: `favicon.svg`, `favicon.ico` e `apple-touch-icon.png`;
- os arquivos `.htaccess` (o da raiz do sistema e os das pastas `config/`, `includes/`, `logs/`).

**Não enviar de dentro de `SistemaEditorial/`:**

| Não enviar | Por quê |
|---|---|
| `tests/` | scripts de teste que criam e apagam dados |
| `logs/` (conteúdo) | o log de erros fica fora da pasta pública (passo 5) |
| `config/config.php` (se existir no seu computador) | é a configuração de desenvolvimento, com a senha do seu banco local |
| `config/config.example.php` | só é modelo |
| `README.txt` | documentação |

**Nunca enviar (ficam fora de `SistemaEditorial/`, então nem entram no envio):**

| Item | Por quê |
|---|---|
| `.git/` | todo o histórico do projeto |
| `database/` (`.sql`) | os scripts do banco se importam pelo phpMyAdmin, a partir do seu computador |
| `ferramentas/` | só se usa o `criar_admin.php`, e temporariamente (passo 7) |
| `README.md`, `DEPLOY.md`, `CLAUDE.md`, `PENDENCIAS.md`, `.gitignore` | documentação e anotações |
| `img/` da raiz do repositório | não faz parte do sistema |
| qualquer `.sql`, `.log`, `.env`, `.bak`, `.zip`, `.dump` | cópias de segurança e dumps não ficam no servidor |

Mesmo assim, o `.htaccess` do sistema responde 403 para `.md`, `.sql`, `.log`, `.txt`, `.bak`, `.env`, arquivos que começam com ponto e outras extensões de cópia: é a segunda barreira, não a primeira.

## 2. Compatibilidade (confira antes)

- **PHP:** o sistema exige **PHP 8.0 ou superior** e foi testado em **8.0 e 8.3**. A Hostinger oferece, para sites novos, as versões 8.2 em diante ([página oficial](https://www.hostinger.com/support/1575755-how-to-change-the-php-version-of-your-hosting-plan)). Escolha **8.3** (ou a mais nova que a Hostinger oferecer). Extensões necessárias: `mysqli` e `mbstring` (ligadas por padrão).
- **Banco:** MariaDB ou MySQL com suporte a views, stored procedures (`SIGNAL`, `RESIGNAL`) e `GROUP_CONCAT`. O desenvolvimento usa MariaDB 10.4. No hPanel, em Bancos de dados, confira a versão do seu plano.
- **Servidor web:** a Hostinger usa LiteSpeed, que lê o `.htaccess` (o redirecionamento para HTTPS e os cabeçalhos dependem dele).

## 3. Criar o banco e o usuário do banco

No hPanel, em **Bancos de dados → Bancos de dados MySQL**:

1. Crie um banco novo. O nome real ganha um prefixo da conta, por exemplo `uXXXXXXXX_waresys`.
2. Crie um **usuário de banco novo, só para o WareSys**, com uma **senha forte e única** (gere uma aleatória; guarde-a em um gerenciador de senhas) e dê acesso **somente a esse banco**. **Nunca use o usuário principal da conta nem `root`.**
3. Anote o nome do banco, o usuário e o host (geralmente `localhost`). Esses valores vão só no arquivo de configuração do passo 5.

## 4. Importar o banco

No hPanel, abra o **phpMyAdmin** do banco recém-criado, **selecione o banco** na lista da esquerda e use a aba **Importar**. Importe, **nesta ordem**, os arquivos da pasta `database/` do seu computador:

1. `schema.sql` (tabelas)
2. `seed.sql` (etapas, papéis de autoria e configuração inicial; **não cria usuário**)
3. `views.sql`
4. `procedures.sql`

Os scripts não criam nem escolhem o banco e não têm cláusula `DEFINER` (views e procedure usam `SQL SECURITY INVOKER`), por isso importam com o usuário da própria conta. Se algum arquivo falhar:

- **Erro `#1227` / "SUPER" / "DEFINER"**: o arquivo importado tem um `DEFINER` de outro usuário. Os scripts do repositório não têm; use os originais.
- **Erro de permissão ao criar procedure** (`CREATE ROUTINE`): peça ao suporte da Hostinger para liberar, ou avise para avaliarmos uma alternativa. Sem a procedure, "passar de mesa" não funciona.

## 5. Configuração (senha do banco fora da pasta pública)

O arquivo de configuração **não fica dentro do `public_html`**. A estrutura da Hostinger é parecida com:

```
/home/uXXXXXXXX/domains/SEU_DOMINIO/
    public_html/          <- pasta pública: o conteúdo de SistemaEditorial/ vai aqui
    waresys-config.php    <- configuração (FORA da pasta pública)
    waresys-logs/         <- log de erros (FORA da pasta pública)
```

1. No gerenciador de arquivos ou por SSH, crie a pasta `waresys-logs/` ao lado do `public_html`.
2. Crie o arquivo `waresys-config.php` ao lado do `public_html` (não dentro) com o conteúdo de `SistemaEditorial/config/config.example.php`, ajustado:
   - `'ambiente' => 'producao'`;
   - `'base_url' => ''` se o sistema fica na raiz do domínio, ou `'/subpasta'` se ficar numa subpasta do `public_html`;
   - `'log_erros' => '/home/uXXXXXXXX/domains/SEU_DOMINIO/waresys-logs/app.log'`;
   - `'confiar_proxy'`: deixe `false`. Se depois do primeiro uso todos os IPs em *Configurações → Tentativas de login recusadas* aparecerem iguais, o site está atrás de um proxy: então troque para `true`;
   - o bloco `'db'` com o host, o nome do banco, o usuário e a senha do passo 3.
3. Permissões do arquivo de configuração: `chmod 640 waresys-config.php`.

O sistema encontra o arquivo sozinho nesta ordem: variável de ambiente `WARESYS_CONFIG`, `waresys-config.php` na pasta acima da pasta do sistema (o caso acima), duas pastas acima (se o sistema estiver numa subpasta do `public_html`), e por último `config/config.php` dentro do sistema (só desenvolvimento). Se preferir outro lugar, defina `SetEnv WARESYS_CONFIG /caminho/completo/waresys-config.php` no `.htaccess` do `public_html` (o caminho não é segredo).

## 6. Enviar os arquivos

**Por SSH (plano com SSH, como o Premium).** No hPanel, em **Avançado → Acesso SSH**, veja o host, a porta e o usuário. No seu computador, na pasta do repositório:

```
rsync -avz -e "ssh -p PORTA" \
  --exclude='tests/' --exclude='logs/' --exclude='config/config.php' \
  --exclude='config/config.example.php' --exclude='README.txt' \
  SistemaEditorial/ uXXXXXXXX@HOST:domains/SEU_DOMINIO/public_html/
```

Sem `rsync`, use `scp -P PORTA -r` pasta a pasta, ou o gerenciador de arquivos do hPanel / FTP, respeitando as exclusões da tabela do passo 1. Se o sistema ficar numa subpasta, use `public_html/subpasta/`.

**Permissões** (644 para arquivos, 755 para pastas), por SSH:

```
cd ~/domains/SEU_DOMINIO/public_html
find . -type d -exec chmod 755 {} \;
find . -type f -exec chmod 644 {} \;
```

## 7. Criar o primeiro Administrador

**Com SSH:** copie `ferramentas/criar_admin.php` para a pasta da conta (fora do `public_html`), rode e apague em seguida:

```
scp -P PORTA ferramentas/criar_admin.php uXXXXXXXX@HOST:domains/SEU_DOMINIO/
ssh -p PORTA uXXXXXXXX@HOST
cd domains/SEU_DOMINIO
php criar_admin.php --config=$PWD/waresys-config.php
rm criar_admin.php
```

O script pergunta nome, e-mail e a senha (digitada sem aparecer, duas vezes, no mínimo 12 caracteres). Escolha uma senha **nova**, que nunca foi usada em desenvolvimento.

**Sem SSH:** use a alternativa pelo phpMyAdmin descrita em **README.md → Primeiro acesso** (hash gerado no seu computador, `INSERT` no banco).

## 8. HTTPS

1. No hPanel, ative o **certificado SSL** do domínio (incluído no plano) e, se houver, a opção **Forçar HTTPS**.
2. O `.htaccess` do sistema também redireciona HTTP para HTTPS e envia o cabeçalho `Strict-Transport-Security`. O cookie de sessão só ganha o atributo `Secure` com HTTPS e `'ambiente' => 'producao'`.

## 9. Depois de publicar

- Faça o **roteiro de teste** abaixo.
- Crie o primeiro Gerente ou Usuário em **Configurações** (sem isso não há quem escolher como responsável ao cadastrar um livro).
- Agende **backup** do banco (exemplo, por SSH, guardando o arquivo **fora** do `public_html`): `mysqldump -u USUARIO_DO_BANCO -p NOME_DO_BANCO > ~/backups/waresys-AAAA-MM-DD.sql`. Os backups do hPanel também servem; confira a frequência e a retenção do seu plano.

## 10. Roteiro de teste depois de publicar

1. Abra `http://SEU_DOMINIO` (sem https): deve redirecionar sozinho para `https://`.
2. Entre com o Administrador criado no passo 7. Deve abrir o painel.
3. Confira, nas ferramentas do navegador (aba Rede), os cabeçalhos da página: `Strict-Transport-Security`, `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`, `Referrer-Policy: same-origin` e `Content-Security-Policy`; e, na aba Aplicativo/Armazenamento, o cookie `PHPSESSID` com `Secure`, `HttpOnly` e `SameSite=Lax`.
4. Nenhuma tela mostra erro técnico; abra o Console do navegador: não deve haver mensagens de bloqueio da Content-Security-Policy.
5. Tente abrir pelo navegador, um por um (todos devem dar **403 ou 404**): `/config/database.php`, `/includes/`, `/tests/`, `/logs/`, `/assets/` (a pasta, não os arquivos), `/README.md`, `/schema.sql`, `/.htaccess`, `/ferramentas/criar_admin.php`, `/waresys-config.php`.
6. Em uma janela anônima, erre a senha **5 vezes** seguidas no login: a tentativa seguinte mostra "Muitas tentativas sem sucesso. Aguarde 15 minutos...", mesmo com a senha certa. Entre com outro navegador/conta depois e veja as tentativas em **Configurações → Tentativas de login recusadas**.
7. Entre, fique 30 minutos sem usar nenhuma página e atualize: deve voltar ao login com o aviso de sessão expirada. Faça logout e confira que voltar pelo botão do navegador não reabre o painel.
8. Em **Configurações → Alterar minha senha**, troque a senha (a nova com 12 caracteres ou mais) e entre de novo com ela.
9. Cadastre uma pessoa, um livro e movimente-o uma etapa. Passe-o para outra mesa (exercita a stored procedure).
10. Abra um relatório e exporte o CSV.
11. Confira que o arquivo de log de erros (`waresys-logs/app.log`) existe e **não** está acessível por nenhuma URL.
