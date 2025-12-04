CREATE DATABASE IF NOT EXISTS db_5;
USE db_5;


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
    carga_horaria TIME NOT NULL,
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
    contato_responsavel CHAR(11) NULL,
    contato_empresa CHAR(11) NULL,
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
    status ENUM('solicitado','autorizado','liberado') NOT NULL,
    FOREIGN KEY (id_aluno) REFERENCES aluno(id_aluno),
    FOREIGN KEY (id_curricular) REFERENCES unidade_curricular(id_curricular),
    FOREIGN KEY (id_autorizacao) REFERENCES funcionario(id_funcionario) 
);