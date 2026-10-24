WARESYS — CONTROLE EDITORIAL (XAMPP + PHP + MYSQL/MARIADB)

Acompanha cada livro pelas etapas de produção da editora (do orçamento ao
depósito legal), mostrando em que etapa ele está e com quem (a "mesa").

INSTALAÇÃO
1. Copie a pasta do projeto para C:\xampp\htdocs\ (ou X:\XAMPP\htdocs\).
2. Abra o XAMPP Control Panel e inicie Apache e MySQL.
3. Abra http://localhost/phpmyadmin e importe, NESTA ORDEM, os três arquivos da
   pasta database/ (na raiz do projeto, ao lado de SistemaEditorial):
     a) database/schema.sql  -> cria o banco "waresys" e todas as tabelas
     b) database/seed.sql    -> etapas, papéis, configuração e o usuário admin
     c) database/views.sql   -> views de leitura (vw_*) que o sistema consulta
   (Na importação do phpMyAdmin não escolha um banco antes: o schema.sql cria o
   banco sozinho. Em linha de comando, para cada arquivo, na mesma ordem:
   mysql -u root --default-character-set=utf8mb4 < database/schema.sql)
   O views.sql pode ser rodado de novo a qualquer momento (CREATE OR REPLACE).
   Sem o views.sql, as telas dão erro de tabela inexistente (vw_...).
4. Copie config/config.example.php para config/config.php e ajuste usuário e
   senha do MySQL, se necessário. O config.php não vai para o Git.
5. Abra http://localhost/Projeto_Interdisciplinar_26-27/SistemaEditorial/

LOGIN INICIAL DO SISTEMA (SENHA PROVISÓRIA — troque antes de publicar):
E-mail: admin@waresys.com
Senha:  admin123

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

TESTES
php tests/fluxo_completo.php (somente em ambiente de desenvolvimento; cria e
apaga seus próprios dados de teste).
