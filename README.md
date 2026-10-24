# 📚 WareSys — Controle Editorial

Repositório do grupo de Projeto Interdisciplinar (PI) da FATEC — **Projeto_Interdisciplinar_26-27**

**Nome do projeto:** WareSys

**Integrantes da equipe:** Bruno Vilela, Cristhian, José Pedroso, Matheus Lanzieri, Victor

**Tema escolhido:** Sistema de Controle Editorial

**Tecnologias utilizadas:** PHP, SQL, HTML e CSS

**Wiki do projeto:** [Início do Projeto](https://github.com/VictorRdaSL/Projeto_Interdisciplinar_26-27/wiki)

---

## Sobre o projeto

O **WareSys — Controle Editorial** é um sistema web desenvolvido para auxiliar editoras no acompanhamento da produção de livros, desde o orçamento até o depósito legal na Biblioteca Nacional.

A proposta é oferecer uma solução simples, organizada e intuitiva que mostre em que etapa cada livro está e com quem ele se encontra, reduzindo atrasos, retrabalho e a perda de livros durante o processo, comuns no controle manual.

---

## 🎯 Objetivo

Desenvolver um sistema web capaz de:

- Cadastrar livros e seus contribuidores (autores, organizadores e ilustradores);
- Acompanhar cada livro pelas etapas do fluxo de publicação;
- Identificar o responsável atual por cada livro (mesa);
- Registrar o histórico completo de movimentações entre etapas e responsáveis;
- Localizar livros extraviados por meio do registro de resgate;
- Alertar sobre livros parados há muito tempo na mesma etapa;
- Gerar relatórios que ajudem a identificar gargalos no processo;
- Facilitar a consulta das informações por título, pessoa ou ISBN.

---

## ⚙️ Instalação (XAMPP)

1. Copie o projeto para `C:\xampp\htdocs\` e inicie **Apache** e **MySQL** no XAMPP.
2. No phpMyAdmin (`http://localhost/phpmyadmin`), importe **nesta ordem** os três arquivos da pasta `database/`:
   1. `schema.sql` — cria o banco **`waresys`** e todas as tabelas;
   2. `seed.sql` — etapas, papéis, configuração e o usuário administrador inicial;
   3. `views.sql` — views de leitura (`vw_*`) usadas pelo sistema.
3. Copie `SistemaEditorial/config/config.example.php` para `SistemaEditorial/config/config.php` e ajuste usuário/senha do MySQL, se necessário.
4. Acesse `http://localhost/Projeto_Interdisciplinar_26-27/SistemaEditorial/`.

**Login inicial (senha provisória — trocar antes de publicar):** `admin@waresys.com` / `admin123`. Instruções para trocar a senha estão em `SistemaEditorial/README.txt`.

---

## 👨‍💻 Desenvolvimento

Projeto desenvolvido como parte das atividades acadêmicas da **FATEC**, com foco no desenvolvimento de uma aplicação web para gerenciamento de controle editorial.

![WareSys — Controle Editorial](https://raw.githubusercontent.com/VictorRdaSL/Projeto_Interdisciplinar_26-27/main/img/editora-home.jpg)
