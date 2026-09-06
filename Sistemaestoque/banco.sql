CREATE DATABASE IF NOT EXISTS sistema_estoque CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE sistema_estoque;

CREATE TABLE IF NOT EXISTS produtos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(120) NOT NULL,
    codigo VARCHAR(40) NOT NULL UNIQUE,
    categoria VARCHAR(80) NOT NULL DEFAULT 'Outros',
    quantidade INT NOT NULL DEFAULT 0,
    estoque_minimo INT NOT NULL DEFAULT 0,
    localizacao VARCHAR(120) NULL,
    observacao TEXT NULL,
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS usuarios (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(120) NOT NULL,
    email VARCHAR(150) NOT NULL UNIQUE,
    senha_hash VARCHAR(255) NOT NULL,
    role ENUM('admin','gerente','usuario') NOT NULL DEFAULT 'usuario',
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS role ENUM('admin','gerente','usuario') NOT NULL DEFAULT 'usuario';

CREATE TABLE IF NOT EXISTS entradas_estoque (
    id INT AUTO_INCREMENT PRIMARY KEY,
    produto_id INT NOT NULL,
    usuario_id INT NOT NULL,
    quantidade INT NOT NULL,
    observacao VARCHAR(255) NULL,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_entrada_produto FOREIGN KEY (produto_id) REFERENCES produtos(id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_entrada_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS saidas_estoque (
    id INT AUTO_INCREMENT PRIMARY KEY,
    produto_id INT NOT NULL,
    usuario_id INT NOT NULL,
    quantidade INT NOT NULL,
    observacao VARCHAR(255) NULL,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_saida_produto FOREIGN KEY (produto_id) REFERENCES produtos(id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_saida_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS log_alteracoes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT NOT NULL,
    acao VARCHAR(60) NOT NULL,
    entidade_tipo VARCHAR(30) NOT NULL,
    entidade_id INT NOT NULL,
    valor_anterior VARCHAR(255) NULL,
    valor_novo VARCHAR(255) NULL,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_log_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB;

INSERT IGNORE INTO produtos (id,nome,codigo,categoria,quantidade,estoque_minimo,localizacao,observacao) VALUES
(1,'Notebook Dell','0001','Informática',15,5,'Prateleira A','Equipamento'),
(2,'Toner HP','0002','Impressão',4,5,'Prateleira B','Suprimento'),
(3,'Mouse USB','0003','Informática',38,10,'Prateleira A','Periférico'),
(4,'Papel A4','0004','Escritório',120,20,'Prateleira C','Material de escritório');

-- Usuário admin semente (login inicial). Senha: admin123 — troque depois de entrar!
INSERT IGNORE INTO usuarios (nome,email,senha_hash,role) VALUES
('Administrador','admin@waresys.com','$2y$10$eEsJGiUXKpevnHy5Rrqt.el1tR3JmCJwSkCJj6LwiZE7rG31M3K06','admin');
