SISTEMA DE ESTOQUE - XAMPP + PHP + MYSQL

1. Extraia a pasta sistema_estoque_xampp.
2. Copie a pasta para C:\xampp\htdocs\
3. Abra o XAMPP Control Panel.
4. Inicie Apache e MySQL.
5. No navegador, abra http://localhost/phpmyadmin
6. Clique em Importar e selecione o arquivo banco.sql desta pasta.
7. Depois abra http://localhost/sistema_estoque_xampp/

LOGIN DO MYSQL PADRÃO DO XAMPP:
Usuário: root
Senha: vazia

Se o MySQL tiver senha, altere config/database.php.

FUNÇÕES:
- cadastrar produto
- registrar entrada
- registrar saída
- bloquear saída acima do saldo
- consultar estoque
- pesquisar produto
- mostrar estoque baixo
- histórico das últimas movimentações

Tailwind é carregado por CDN. O CSS local mantém o layout mesmo sem o CDN.
