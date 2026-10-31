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
- As etapas ficam no banco: o Administrador pode criar, renomear, reordenar e desativar etapas; uma etapa **nunca usada** pode ser excluída (Gerente ou Administrador), e uma etapa com histórico só é desativada.

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
| Criar, editar, desativar e reordenar etapas e papéis de autoria | não | não | sim |

**O Administrador não faz parte do fluxo da editora e não pode ser responsável (mesa) de um livro.** Ele continua podendo registrar ações. Por isso, numa instalação nova é preciso cadastrar um **Gerente ou Usuário** antes de criar o primeiro livro.

---

## ⚙️ Instalação (XAMPP)

1. Copie o projeto para `C:\xampp\htdocs\` e inicie **Apache** e **MySQL** no XAMPP.
2. No phpMyAdmin (`http://localhost/phpmyadmin`), importe **nesta ordem** os quatro arquivos da pasta `database/` (não escolha um banco antes: o `schema.sql` cria o banco sozinho):
   1. `schema.sql`: cria o banco **`waresys`** e todas as tabelas;
   2. `seed.sql`: as 15 etapas, os papéis de autoria, a configuração inicial e o **usuário Administrador**;
   3. `views.sql`: views de leitura (`vw_*`) usadas pelo sistema;
   4. `procedures.sql`: stored procedures (`sp_*`) chamadas pelo sistema.

   Em linha de comando, para cada arquivo e na mesma ordem: `mysql -u root --default-character-set=utf8mb4 < database/schema.sql`. O `views.sql` e o `procedures.sql` podem ser rodados de novo a qualquer hora; o `schema.sql` e o `seed.sql` são só para um banco novo.
3. Copie `SistemaEditorial/config/config.example.php` para `SistemaEditorial/config/config.php` e ajuste usuário e senha do MySQL, se necessário. Ajuste também o `base_url` (no XAMPP é `/Projeto_Interdisciplinar_26-27/SistemaEditorial`). O `config.php` nunca vai para o Git.
4. Abra `http://localhost/Projeto_Interdisciplinar_26-27/SistemaEditorial/`.

### Primeiro acesso

O `seed.sql` já cria o primeiro usuário **Administrador**. O e-mail e a senha de acesso são repassados pelo responsável pelo projeto e não ficam neste documento. Ainda não existe tela para trocar a senha; use o SQL:

1. Gere o hash da nova senha: `php -r "echo password_hash('NOVA_SENHA', PASSWORD_DEFAULT);"`
2. No phpMyAdmin (banco `waresys`, aba SQL): `UPDATE usuarios SET senha_hash = 'HASH_GERADO' WHERE perfil = 'admin';`

Depois do primeiro login, vá em **Configurações** e cadastre pelo menos um Gerente ou Usuário: sem isso não há quem escolher como responsável ao criar um livro.

---

## 🔐 Segurança e hospedagem

- Todo acesso é checado no servidor (login e perfil), todo formulário tem proteção CSRF, todo SQL com valor variável usa *prepared statements* e toda saída é escapada. O cookie de sessão usa `HttpOnly` e `SameSite=Lax`, e `Secure` em produção com HTTPS.
- **Ao hospedar:** publique **somente** a pasta `SistemaEditorial/` (nunca a raiz do repositório); use `'ambiente' => 'producao'` no `config.php` (os erros deixam de aparecer na tela e vão para `SistemaEditorial/logs/app.log`); ajuste o `base_url`; use HTTPS; **crie um usuário de banco só para o sistema**, com senha, em vez do `root` sem senha do XAMPP; e troque a senha do Administrador.
- As pastas `config/`, `includes/`, `tests/` e `logs/` respondem 403 pelo navegador (`.htaccess`). Os scripts de `tests/` só rodam pela linha de comando e só com `'ambiente' => 'desenvolvimento'`.

---

## 🧪 Testes e manutenção

- `php SistemaEditorial/tests/fluxo_completo.php` roda o fluxo de ponta a ponta contra o banco (cria dados de teste e apaga tudo ao final).
- `php SistemaEditorial/tests/isbn_teste.php` testa a validação e a formatação de ISBN.
- `php SistemaEditorial/tests/isbn_conferir_banco.php` confere os ISBNs já gravados (com `--aplicar`, grava só os dígitos nos válidos que ainda tenham traços; nunca apaga).
- Os traços do ISBN vêm da tabela oficial da International ISBN Agency (`includes/isbn_faixas.php`, gerada por `tests/gerar_faixas_isbn.php` a partir do `RangeMessage.xml`). A agência publica faixas novas algumas vezes por ano: baixe o XML de novo e rode o gerador para atualizar.

---

## 🎨 Interface

Toda a interface usa o design system do WareSys, em `SistemaEditorial/assets/` (`css/`, `js/` e `fonts/`): CSS próprio (`fontes.css`, `tokens.css`, `base.css`, `layout.css` e `components.css`), fonte Inter e ícones Lucide **locais** (nada de CDN, funciona sem internet) e tema claro/escuro por usuário. Os caminhos dos estilos, scripts e links do menu partem do `base_url` de `config/config.php`.

---

## 🗂️ Estrutura

```
database/            schema.sql, seed.sql, views.sql e procedures.sql (instalação do banco)
SistemaEditorial/    o sistema (cada página é um .php; sem framework e sem build)
  config/            config.example.php (modelo) e database.php (conexão)
  includes/          funções compartilhadas: sessão, permissões, CSRF, validação, movimentações, relatórios...
  assets/            CSS, JS e fontes do design system
  tests/             scripts de teste, só pela linha de comando
  logs/              app.log (erros em produção)
```

---

## 👨‍💻 Desenvolvimento

Projeto desenvolvido como parte das atividades acadêmicas da **FATEC**, com foco no desenvolvimento de uma aplicação web para gerenciamento de controle editorial.

![WareSys — Controle Editorial](https://raw.githubusercontent.com/VictorRdaSL/Projeto_Interdisciplinar_26-27/main/img/editora-home.jpg)
