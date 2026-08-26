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

CREATE TABLE IF NOT EXISTS movimentacoes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    produto_id INT NOT NULL,
    tipo ENUM('entrada','saida') NOT NULL,
    quantidade INT NOT NULL,
    observacao VARCHAR(255) NULL,
    data_movimentacao TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_mov_produto FOREIGN KEY (produto_id) REFERENCES produtos(id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB;

INSERT IGNORE INTO produtos (id,nome,codigo,categoria,quantidade,estoque_minimo,localizacao,observacao) VALUES
(1,'Notebook Dell','0001','Informática',15,5,'Prateleira A','Equipamento'),
(2,'Toner HP','0002','Impressão',4,5,'Prateleira B','Suprimento'),
(3,'Mouse USB','0003','Informática',38,10,'Prateleira A','Periférico'),
(4,'Papel A4','0004','Escritório',120,20,'Prateleira C','Material de escritório');
