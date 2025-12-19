CREATE DATABASE IF NOT EXISTS SENAI_LIBERAJA;
USE SENAI_LIBERAJA;


CREATE TABLE curso (
    id_curso INT PRIMARY KEY AUTO_INCREMENT,
    nome VARCHAR(100) NOT NULL
);

CREATE TABLE turma (
    id_turma INT PRIMARY KEY AUTO_INCREMENT,
    nome VARCHAR(100) NOT NULL,
    id_curso INT NOT NULL,
    carga_horaria_total INT DEFAULT 0,
    FOREIGN KEY (id_curso) REFERENCES curso(id_curso) 
);

CREATE TABLE unidade_curricular (
    id_curricular INT PRIMARY KEY AUTO_INCREMENT,
    nome VARCHAR(100) NOT NULL,
    carga_horaria INT NOT NULL,
    id_curso INT NOT NULL,
    FOREIGN KEY(id_curso) REFERENCES curso(id_curso)
);

CREATE TABLE aluno (
    id_aluno INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(150) NOT NULL UNIQUE,
    matricula VARCHAR(10) NOT NULL UNIQUE,
    cpf CHAR(15) NOT NULL,
    celular CHAR(13) NOT NULL,
    senha_hash VARCHAR(255) NOT NULL,
    contato_responsavel CHAR(15) NULL,
    contato_empresa CHAR(15) NULL,
    data_nascimento DATE NOT NULL
);

CREATE TABLE matricula(
    id_turma INT NOT NULL,
    id_aluno INT NOT NULL,
    FOREIGN KEY(id_turma) REFERENCES turma(id_turma),
    FOREIGN KEY(id_aluno) REFERENCES aluno(id_aluno)
);

CREATE TABLE funcionario (
    id_funcionario INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(150) NOT NULL,
    cpf CHAR(15) NOT NULL,
    tipo ENUM('pedagógico', 'instrutor', 'portaria'),
    senha_hash VARCHAR(255) NOT NULL
);

CREATE TABLE solicitacao (
    id_solicitacao INT AUTO_INCREMENT PRIMARY KEY,
    id_autorizacao INT,
    id_aluno INT NOT NULL,
    id_curricular INT NULL,
    motivo VARCHAR(150) NOT NULL,
    data_solicitada DATETIME NOT NULL,
    data_autorizada DATETIME NULL,
    data_saida DATETIME NULL,
    status ENUM(
        'solicitado',
        'autorizado',
        'liberado',
        'aguardando_responsavel',
        'liberado_portaria',
        'concluido',
        'rejeitada'
    ) NOT NULL DEFAULT 'autorizado',
    FOREIGN KEY (id_aluno) REFERENCES aluno(id_aluno),
    FOREIGN KEY (id_curricular) REFERENCES unidade_curricular(id_curricular),
    FOREIGN KEY (id_autorizacao) REFERENCES funcionario(id_funcionario) 
);

CREATE TABLE IF NOT EXISTS frequencia (
    id_frequencia INT AUTO_INCREMENT PRIMARY KEY,
    id_aluno INT NOT NULL,
    id_uc INT NOT NULL,
    data_falta DATE NOT NULL,
    hora_saida TIME,
    tipo ENUM('falta', 'saida_antecipada') DEFAULT 'falta',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (id_aluno) REFERENCES aluno(id_aluno),
    FOREIGN KEY (id_uc) REFERENCES unidade_curricular(id_curricular)
);

ALTER TABLE solicitacao ADD COLUMN id_uc INT NULL AFTER id_autorizacao;
ALTER TABLE solicitacao ADD FOREIGN KEY (id_uc) REFERENCES unidade_curricular(id_curricular);
ALTER TABLE solicitacao ADD COLUMN turno VARCHAR(10) DEFAULT 'manhã';
ALTER TABLE frequencia ADD COLUMN turno VARCHAR(10) DEFAULT 'manhã';

INSERT INTO funcionario (nome, cpf, tipo, senha_hash)
VALUES ('Thiago Monechi', '22968899724', 'pedagógico', '(Aalxx_2025)');

INSERT INTO funcionario (nome, cpf, tipo, senha_hash)
VALUES ('Nicolas Menegardo', '16348624730', 'pedagógico', '@Nicolas24');