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
- Alerta sobre livros **parados** há muito tempo na mesma etapa.
- Mostra um painel e **quatro relatórios** (livros, movimentações, rodadas excedentes e tempo por etapa), todos com exportação em CSV.
- Registra um **log de alterações** (quem fez o quê e quando).
- Busca por título, pessoa ou ISBN, e tema claro ou escuro por usuário.

---

## 🗂️ Estrutura

```
database/            schema.sql, seed.sql, views.sql e procedures.sql (instalação do banco)
ferramentas/         criar_admin.php (cria o primeiro Administrador; só linha de comando; não vai para o servidor)
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
