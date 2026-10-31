WARESYS — CONTROLE EDITORIAL (XAMPP + PHP + MYSQL/MARIADB)

A documentação completa (o que é o sistema, o fluxo de etapas, a regra de rodadas, os perfis de acesso,
a instalação, a troca da senha do Administrador e a hospedagem) está no README.md, na raiz do repositório.

Resumo da instalação:
1. Copie o projeto para C:\xampp\htdocs\ e inicie Apache e MySQL no XAMPP.
2. No phpMyAdmin, importe NESTA ORDEM os arquivos da pasta database/ (na raiz do repositório, ao lado desta
   pasta): schema.sql, seed.sql, views.sql e procedures.sql.
3. Copie config/config.example.php para config/config.php e ajuste o MySQL e o 'base_url'.
4. Abra http://localhost/Projeto_Interdisciplinar_26-27/SistemaEditorial/

AO HOSPEDAR: publique SOMENTE esta pasta (SistemaEditorial/), use 'ambiente' => 'producao' no config.php,
HTTPS, um usuário de banco próprio com senha, e troque a senha provisória do Administrador.
