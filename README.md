# 📚 WareSys — Controle Editorial

Repositório do grupo de Projeto Interdisciplinar (PI) da FATEC — **Projeto_Interdisciplinar_26-27**

**Nome do projeto:** WareSys

**Integrantes da equipe:** Bruno Vilela, Cristhian, Matheus Lanzieri, Victor

**Tema escolhido:** Sistema de Controle Editorial

**Tecnologias utilizadas:** PHP, MySQL/MariaDB, HTML, CSS e JavaScript (sem framework)

**Wiki do projeto:** [Início do Projeto](https://github.com/VictorRdaSL/Projeto_Interdisciplinar_26-27/wiki)

---

## 📖 Sobre o projeto

O **WareSys** é um **sistema de gestão de controle editorial**: ele acompanha cada livro da editora pelas etapas de produção, do orçamento ao depósito legal na Biblioteca Nacional, mostrando **em que etapa** o livro está e **com quem** ele está (a "mesa"). A editora não estoca livros; ela conduz cada um por um fluxo, e o sistema existe para que nenhum livro se perca e para tornar visíveis os gargalos.

Pensado para uma equipe pequena (cerca de 5 pessoas), em que todos movimentam livros.

---

## 🎯 O que o sistema faz

- Cadastra livros e as pessoas ligadas a eles (autores, organizadores e ilustradores), com ISBN-13 validado e formatado.
- Leva o livro pelas etapas do fluxo (avançar, repetir, voltar, concluir) e passa o livro de uma mesa para outra, com um recado opcional para quem recebe.
- Guarda o **histórico completo** de cada livro: nada é apagado nem sobrescrito.
- Conta as **rodadas** de Diagramação e Revisão e avisa quando uma rodada passa do limite incluído.
- Localiza livros extraviados pelo **resgate**, a partir da última movimentação conhecida.
- Alerta sobre livros **parados** há muito tempo na mesma etapa.
- Mostra um painel e **cinco relatórios** (livros, movimentações, rodadas excedentes, tempo por etapa e resgates), todos com exportação em CSV.
- Registra um **log de alterações** (quem fez o quê e quando).
- Busca por título, pessoa ou ISBN, e tema claro ou escuro por usuário.

---

## 🔄 O fluxo de etapas

O livro passa por 15 etapas, em ordem, com retorno possível:

| # | Etapa | | # | Etapa |
|---|---|---|---|---|
| 1 | Orçamento | | 9 | Catalogação (ISBN e ficha catalográfica) |
| 2 | Contrato | | 10 | Fechamento de arquivo |
| 3 | Envio de material | | 11 | Envio do termo de impressão |
| 4 | Capa | | 12 | Chegada da cópia teste |
| 5 | Ilustração *(opcional)* | | 13 | Leitura da cópia até aprovação |
| 6 | Aprovação da capa *(opcional)* | | 14 | Impressão |
| 7 | Diagramação | | 15 | Depósito legal, Biblioteca Nacional (RJ) |
| 8 | Revisão *(opcional)* | | | **finaliza o livro** |

- **Opcionais:** Ilustração, Aprovação da capa e Revisão. Todas as outras são obrigatórias, e o sistema não deixa pular uma etapa obrigatória.
- **Diagramação e Revisão vão e voltam entre si.** Esse trecho não é estritamente linear.
- **O livro termina na etapa que finaliza** (Depósito legal). Livro **concluído** passa a mostrar "Concluído" no lugar da etapa e **não pode mais ser alterado**.
- As etapas ficam no banco: o Gerente e o Administrador podem criar, renomear, reordenar, ativar/desativar e editar etapas; uma etapa **nunca usada** pode ser excluída, e uma etapa com histórico só é desativada.

---

## 🔁 Regra de rodadas incluídas

Cada vez que o livro **entra** em Diagramação ou Revisão, conta uma **rodada**.

- **Incluídas no serviço:** 3 rodadas de Diagramação e 2 de Revisão. Os limites ficam em cada etapa (campo "Rodadas incluídas") e podem ser mudados pelo Administrador.
- **Rodada excedente:** ao passar do limite, o sistema **avisa antes de confirmar** a movimentação e marca a rodada como **cobrável**. Valores e cobrança ficam fora do sistema: só o sinalizador existe aqui.
- A tela do livro mostra as rodadas por etapa (por exemplo, "R3/3" = terceira de três incluídas, "no limite") e o total de rodadas cobráveis.
- Trocar de mesa na mesma etapa **não** abre rodada nova.

---

## 👥 Perfis de acesso

Há três perfis. Todos veem o painel, Livros, Minha mesa, Pessoas e os relatórios, e todos podem cadastrar livros e pessoas. **Cada Usuário só movimenta e edita os livros da própria mesa**: para outra pessoa avançar um livro, quem está com ele o passa para ela (vale na hora). Gerente e Administrador movimentam e editam livros de qualquer mesa.

| Ação | Usuário | Gerente | Administrador |
|---|:-:|:-:|:-:|
| Cadastrar livros e pessoas, editar pessoas, iniciar e concluir resgate | sim | sim | sim |
| Movimentar e editar livros da própria mesa (inclusive passar para outra mesa) | sim | sim | sim |
| Movimentar e editar livros de outra mesa | não | sim | sim |
| Ver relatórios e exportar CSV | sim | sim | sim |
| Cancelar livro | não | sim | sim |
| Excluir livro cadastrado por engano | não | sim | sim |
| Ver o log de alterações | não | sim | sim |
| Criar usuários, mudar perfis e remover usuários (Gerente não concede, altera nem remove Administrador) | não | sim | sim |
| Definir o limite de dias de "livro parado" | não | sim | sim |
| Excluir etapa que nunca foi usada | não | sim | sim |
| Criar, editar, ativar/desativar e reordenar etapas | não | sim | sim |
| Criar e editar papéis de autoria (Autor, Organizador, Ilustrador) | não | não | sim |

**O Administrador não faz parte do fluxo da editora e não pode ser responsável (mesa) de um livro.** Ele continua podendo registrar ações. Por isso, numa instalação nova é preciso cadastrar um **Gerente ou Usuário** antes de criar o primeiro livro.

---

## ⚙️ Instalação (XAMPP)

1. Copie o projeto para `C:\xampp\htdocs\` e inicie **Apache** e **MySQL** no XAMPP.
2. No phpMyAdmin (`http://localhost/phpmyadmin`), crie um banco chamado **`waresys`** (Novo → nome `waresys`, collation `utf8mb4_unicode_ci`). Depois **clique nesse banco** e, na aba **Importar**, importe **nesta ordem** os quatro arquivos da pasta `database/`:
   1. `schema.sql`: todas as tabelas (inclusive a de tentativas de login);
   2. `seed.sql`: as 15 etapas, os papéis de autoria e a configuração inicial (**não cria usuário nenhum**);
   3. `views.sql`: views de leitura (`vw_*`) usadas pelo sistema;
   4. `procedures.sql`: stored procedures (`sp_*`) chamadas pelo sistema.

   Em linha de comando, para cada arquivo e na mesma ordem: `mysql -u root --default-character-set=utf8mb4 waresys < database/schema.sql`. Os scripts não criam nem escolhem o banco (é o que permite importá-los numa hospedagem compartilhada, onde o banco já existe com outro nome). O `views.sql` e o `procedures.sql` podem ser rodados de novo a qualquer hora; o `schema.sql` e o `seed.sql` são só para um banco novo.
3. Copie `SistemaEditorial/config/config.example.php` para `SistemaEditorial/config/config.php` e ajuste usuário e senha do MySQL, se necessário. Ajuste também o `base_url` (no XAMPP é `/Projeto_Interdisciplinar_26-27/SistemaEditorial`). O `config.php` nunca vai para o Git.
4. Crie o primeiro Administrador (próxima seção).
5. Abra `http://localhost/Projeto_Interdisciplinar_26-27/SistemaEditorial/`.

### Primeiro acesso: criar o Administrador

Nenhum usuário, e-mail ou senha vem no repositório: o primeiro Administrador é criado por você, depois de importar o banco.

**Com terminal (recomendado)**, na pasta do projeto:

```
php ferramentas/criar_admin.php
```

O script pergunta nome, e-mail (será o login) e senha, que é digitada **sem aparecer**, duas vezes, com no mínimo **12 caracteres**. Grava só o hash da senha e nunca a mostra. Só funciona pela linha de comando e se recusa a criar outro Administrador se já existir um (use `--outro` para forçar). Opções: `--config=/caminho/do/config.php`, `--nome=...`, `--email=...`.

**Sem terminal (por exemplo, só com o phpMyAdmin da hospedagem)**: gere o hash **no seu computador**, sem escrever a senha em nenhum arquivo, e insira o usuário pelo phpMyAdmin.

1. No seu computador, rode `php -r "echo password_hash(trim(fgets(STDIN)), PASSWORD_DEFAULT);"`, digite a senha (mínimo de 12 caracteres) e pressione Enter. O terminal mostra o hash (começa com `$2y$`). A senha não fica no histórico de comandos.
2. No phpMyAdmin, no banco do WareSys, aba **SQL**, rode (troque os trechos em maiúsculas):

   ```sql
   INSERT INTO usuarios (nome, email, senha_hash, perfil, tema, ativo, criado_em)
   VALUES ('SEU NOME', 'SEU_EMAIL', 'HASH_GERADO', 'admin', 'claro', 1, 'AAAA-MM-DD HH:MM:SS');
   ```

Depois do primeiro login, cada pessoa pode trocar a própria senha em **Configurações → Alterar minha senha**, e o Administrador cadastra pelo menos um Gerente ou Usuário em **Configurações**: sem isso não há quem escolher como responsável ao criar um livro.

---

## 🔐 Segurança e hospedagem

- Todo acesso é checado no servidor (login e perfil), todo formulário tem proteção CSRF, todo SQL com valor variável usa *prepared statements* e toda saída é escapada.
- **Login:** 5 falhas seguidas para o mesmo e-mail (ou para o mesmo IP) bloqueiam por 15 minutos, com mensagem que não diz se o erro foi no e-mail ou na senha; as falhas ficam registradas e aparecem, para Gerente e Administrador, em Configurações. Senhas novas têm no mínimo 12 caracteres. A sessão expira com 30 minutos sem uso, e o cookie usa `HttpOnly`, `SameSite=Lax` e `Secure` (em produção, com HTTPS).
- **Cabeçalhos e HTTPS** (`SistemaEditorial/.htaccess`): redireciona HTTP para HTTPS, `Strict-Transport-Security`, `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`, `Referrer-Policy: same-origin` e uma Content-Security-Policy; sem listagem de pastas; documentação, SQL, logs e arquivos ocultos respondem 403.
- **Publicar na Hostinger (ou outra hospedagem):** siga o passo a passo do **[DEPLOY.md](DEPLOY.md)**. Em resumo: envie **somente** o conteúdo de `SistemaEditorial/` (sem `tests/` nem `config.php`), use `'ambiente' => 'producao'`, mantenha o arquivo de configuração **fora da pasta pública**, crie um usuário de banco só para o sistema (nunca `root`), importe os quatro `.sql`, crie o Administrador e ative o HTTPS.
- **Configuração fora da pasta pública:** o sistema procura o arquivo de configuração, nesta ordem, em (1) a variável de ambiente `WARESYS_CONFIG`, (2) `waresys-config.php` na pasta acima da pasta do sistema (na Hostinger, a pasta do domínio, ao lado do `public_html`), (3) `waresys-config.php` duas pastas acima e (4) `config/config.php` dentro do sistema. O log de erros também pode ficar fora da pasta pública (chave `log_erros` do config).
- Os scripts de `tests/` só rodam pela linha de comando e só com `'ambiente' => 'desenvolvimento'`.

---

## 🧪 Testes e manutenção

- `php SistemaEditorial/tests/fluxo_completo.php` roda o fluxo de ponta a ponta contra o banco (cria dados de teste e apaga tudo ao final).
- `php SistemaEditorial/tests/isbn_teste.php` testa a validação e a formatação de ISBN.
- `php SistemaEditorial/tests/isbn_conferir_banco.php` confere os ISBNs já gravados (com `--aplicar`, grava só os dígitos nos válidos que ainda tenham traços; nunca apaga).
- Os traços do ISBN vêm da tabela oficial da International ISBN Agency (`includes/isbn_faixas.php`, gerada por `tests/gerar_faixas_isbn.php` a partir do `RangeMessage.xml`). A agência publica faixas novas algumas vezes por ano: baixe o XML de novo e rode o gerador para atualizar.

---

## 🎨 Interface

Toda a interface usa o design system do WareSys, em `SistemaEditorial/assets/` (`css/`, `js/`, `fonts/` e `img/logo/`, com o símbolo da marca; o favicon fica na raiz do sistema): CSS próprio (`fontes.css`, `tokens.css`, `base.css`, `layout.css` e `components.css`), fonte Inter e ícones Lucide **locais** (nada de CDN, funciona sem internet) e tema claro/escuro por usuário. Os caminhos dos estilos, scripts e links do menu partem do `base_url` de `config/config.php`.

---

## 🗂️ Estrutura

```
database/            schema.sql, seed.sql, views.sql e procedures.sql (instalação do banco)
ferramentas/         criar_admin.php (cria o primeiro Administrador; só linha de comando; não vai para o servidor)
DEPLOY.md            passo a passo de publicação (Hostinger) e lista do que enviar e do que não enviar
SistemaEditorial/    o sistema (cada página é um .php; sem framework e sem build)
  config/            config.example.php (modelo) e database.php (conexão)
  includes/          funções compartilhadas: configuração, sessão, permissões, CSRF, validação, login, movimentações, relatórios...
  assets/            CSS, JS, fontes e símbolo da marca (img/logo) do design system
  favicon.svg / favicon.ico / apple-touch-icon.png   ícones da aba e da tela inicial
  tests/             scripts de teste, só pela linha de comando
  logs/              app.log (erros em produção, quando o config não define outro lugar)
```

---

## 👨‍💻 Desenvolvimento

Projeto desenvolvido como parte das atividades acadêmicas da **FATEC**, com foco no desenvolvimento de uma aplicação web para gerenciamento de controle editorial.

![WareSys — Controle Editorial](https://raw.githubusercontent.com/VictorRdaSL/Projeto_Interdisciplinar_26-27/main/img/editora-home.jpg)
