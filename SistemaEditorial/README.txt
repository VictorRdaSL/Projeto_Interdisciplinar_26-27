WARESYS — CONTROLE EDITORIAL (XAMPP + PHP + MYSQL/MARIADB)

Acompanha cada livro pelas etapas de produção da editora (do orçamento ao
depósito legal), mostrando em que etapa ele está e com quem (a "mesa").

INSTALAÇÃO
1. Copie a pasta do projeto para C:\xampp\htdocs\ (ou X:\XAMPP\htdocs\).
2. Abra o XAMPP Control Panel e inicie Apache e MySQL.
3. Abra http://localhost/phpmyadmin e importe, NESTA ORDEM, os quatro arquivos da
   pasta database/ (na raiz do projeto, ao lado de SistemaEditorial):
     a) database/schema.sql  -> cria o banco "waresys" e todas as tabelas
     b) database/seed.sql    -> etapas, papéis, configuração e o usuário admin
     c) database/views.sql   -> views de leitura (vw_*) que o sistema consulta
     d) database/procedures.sql -> stored procedures (sp_*) que o sistema chama
   (Na importação do phpMyAdmin não escolha um banco antes: o schema.sql cria o
   banco sozinho. Em linha de comando, para cada arquivo, na mesma ordem:
   mysql -u root --default-character-set=utf8mb4 < database/schema.sql)
   O views.sql e o procedures.sql podem ser rodados de novo a qualquer momento.
   Sem o views.sql ou o procedures.sql, as telas dão erro (vw_... / sp_... inexistente).
4. Copie config/config.example.php para config/config.php e ajuste usuário e
   senha do MySQL, se necessário. O config.php não vai para o Git.
   Ajuste também o 'base_url': o caminho do sistema na URL, sem barra no final. No XAMPP é
   '/Projeto_Interdisciplinar_26-27/SistemaEditorial'; ao hospedar, use o caminho real
   (ex.: '' se o sistema ficar na raiz do domínio, ou '/sistema'). As telas novas montam os
   caminhos de CSS, JS, imagens e menu a partir dele.
5. Abra http://localhost/Projeto_Interdisciplinar_26-27/SistemaEditorial/

IMPORTANTE: o Administrador não faz parte do fluxo da editora e não pode ser responsável por livros.
Depois do primeiro login, cadastre pelo menos um Gerente ou Usuário em Configurações — sem isso
não há quem escolher como responsável ao criar um livro.

LOGIN INICIAL DO SISTEMA (SENHA PROVISÓRIA — troque antes de publicar):
E-mail: admin@waresys.com
Senha:  a senha provisória está no README.md da raiz do repositório (fica só lá).

COMO TROCAR A SENHA (por enquanto não existe tela para isso — está previsto
para um próximo passo, junto com a gestão de usuários):
1. Gere o hash da nova senha:
     php -r "echo password_hash('NOVA_SENHA', PASSWORD_DEFAULT);"
2. No phpMyAdmin (banco waresys, aba SQL), rode:
     UPDATE usuarios SET senha_hash = 'HASH_GERADO' WHERE email = 'admin@waresys.com';

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
- Configurações: tema, log de alterações, usuários e perfis de acesso,
  limite de livro parado e, para Administrador, etapas e papéis de autoria
- busca por título, pessoa ou ISBN

INTERFACE
Todos os estilos, scripts e ícones da interface vêm de design-system/ (CSS próprio, fonte
Inter e ícones Lucide locais, sem CDN). Os caminhos são montados a partir do 'base_url' do
config/config.php (veja a instalação acima). O tema claro/escuro é salvo por usuário, em
Configurações. O CSS antigo do sistema foi removido.

TESTES
php tests/fluxo_completo.php (somente em ambiente de desenvolvimento; cria e
apaga seus próprios dados de teste).

PUBLICAÇÃO
Publique SOMENTE a pasta SistemaEditorial/ no servidor, nunca a raiz do repositório
(ela contém .git/, database/ e documentos internos). Os arquivos .htaccess bloqueiam
esses itens e os .txt/.md/.sql no Apache, mas o certo é nem enviá-los.
