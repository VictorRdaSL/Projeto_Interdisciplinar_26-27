WARESYS — CONTROLE EDITORIAL (XAMPP + PHP + MYSQL)

Acompanha cada livro pelas etapas de produção da editora (do orçamento ao
depósito legal), mostrando em que etapa ele está e com quem (a "mesa").

INSTALAÇÃO
1. Copie a pasta do projeto para C:\xampp\htdocs\ (ou X:\XAMPP\htdocs\).
2. Abra o XAMPP Control Panel e inicie Apache e MySQL.
3. Abra http://localhost/phpmyadmin, clique em Importar e selecione o arquivo
   banco.sql desta pasta (cria o banco e carrega etapas, papéis e o admin).
4. Copie config/config.example.php para config/config.php e ajuste usuário e
   senha do MySQL, se necessário. O config.php não vai para o Git.
5. Abra http://localhost/Projeto_Interdisciplinar_26-27/SistemaEditorial/

LOGIN INICIAL DO SISTEMA (apenas desenvolvimento — troque antes de publicar):
E-mail: admin@waresys.com
Senha:  admin123

FUNÇÕES
- cadastrar livros, pessoas (autores, organizadores, ilustradores) e vínculos
- mover o livro pelas etapas: avançar, repetir, voltar e concluir
- contar rodadas de Diagramação e Revisão e sinalizar rodada excedente (cobrável)
- trocar o livro de mesa e consultar "Minha mesa"
- histórico completo de movimentações de cada livro
- resgate de livros extraviados
- painel com livros por etapa, parados, extraviados e rodadas excedentes
- relatórios (livros, movimentações, rodadas excedentes, tempo por etapa,
  resgates) com exportação CSV
- Configurações: tema, log de alterações, usuários e papéis de acesso,
  limite de livro parado e, para Administrador, etapas e papéis de autoria
- busca por título, pessoa ou ISBN

TESTES
php tests/fluxo_completo.php (somente em ambiente de desenvolvimento; cria e
apaga seus próprios dados de teste).
