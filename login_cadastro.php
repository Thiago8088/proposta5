<?php
session_start();
require("db/conexao.php");

$msg = "";
$msg_type = "";

// --- CADASTRO ALUNO ---
if (isset($_POST['cadastrar_aluno'])) {
    $nome = LimpaPost($_POST['nome']);
    $matricula = LimpaPost($_POST['matricula']);
    $cpf = LimpaPost($_POST['cpf']);
    $senha = $_POST['senha'];
    $data_nascimento = $_POST['data_nascimento'];
    $turma = LimpaPost($_POST['turma']);

    // Valor padrão para celular para satisfazer constraint NOT NULL
    $celular = '00000000000';

    if (empty($nome) || empty($matricula) || empty($cpf) || empty($senha) || empty($data_nascimento) || empty($turma)) {
        $msg = "Preencha todos os campos obrigatórios!";
        $msg_type = "error";
    } else {
        $senha_hash = password_hash($senha, PASSWORD_DEFAULT);
        try {
            // Inserindo com valores padrão para campos obrigatórios não solicitados no form
            $stmt = $conn->prepare("INSERT INTO aluno (nome, matricula, cpf, celular, senha_hash, data_nascimento) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$nome, $matricula, $cpf, $celular, $senha_hash, $data_nascimento]);
        } catch (PDOException $e) {
            if ($e->getCode() == 23000) {
                $msg = "Erro: Matrícula ou CPF já cadastrados.";
            } else {
                $msg = "Erro ao cadastrar: " . $e->getMessage();
            }
            $msg_type = "error";
        }
    }
}

// --- CADASTRO FUNCIONÁRIO ---
if (isset($_POST['cadastrar_funcionario'])) {
    $nome = LimpaPost($_POST['nome']);
    $cpf = LimpaPost($_POST['cpf']);
    $tipo = $_POST['tipo']; // pedagógico, instrutor, portaria
    $senha = $_POST['senha'];

    if (empty($nome) || empty($cpf) || empty($tipo) || empty($senha)) {
        $msg = "Preencha todos os campos obrigatórios!";
    } else {
        $senha_hash = password_hash($senha, PASSWORD_DEFAULT);
        try {
            $stmt = $conn->prepare("INSERT INTO funcionario (nome, cpf, tipo, senha_hash) VALUES (?, ?, ?, ?)");
            $stmt->execute([$nome, $cpf, $tipo, $senha_hash]);
        } catch (PDOException $e) {
            $msg = "Erro ao cadastrar: " . $e->getMessage();
        }
    }
}

// --- LOGIN UNIFICADO ---
if (isset($_POST['logar'])) {
    $login_input = LimpaPost($_POST['login_input']); // Pode ser matrícula ou CPF
    $senha = $_POST['senha'];

    $usuario_encontrado = false;

    // 1. Tentar achar como Aluno (pela matrícula OU CPF)
    try {
        $stmt = $conn->prepare("SELECT * FROM aluno WHERE matricula = ? OR cpf = ?");
        $stmt->execute([$login_input, $login_input]);
        $aluno = $stmt->fetch();

        if ($aluno) {
            if (password_verify($senha, $aluno['senha_hash'])) {
                $_SESSION['id'] = $aluno['id_aluno'];
                $_SESSION['nome'] = $aluno['nome'];
                $_SESSION['tipo_user'] = 'aluno';
                header("Location: index.php");
                exit;
            } else {
                $msg = "Senha incorreta!";
                $msg_type = "error";
                $usuario_encontrado = true; // Usuário existe, só a senha tá errada
            }
        }
    } catch (PDOException $e) {
        // Erro silencioso ou logar se necessário
    }

    // 2. Se não achou aluno (ou senha errada de aluno, mas vamos checar funcionario se não for aluno), tentar Funcionario (pelo CPF)
    if (!$usuario_encontrado && !$aluno) {
        try {
            $stmt = $conn->prepare("SELECT * FROM funcionario WHERE cpf = ?");
            $stmt->execute([$login_input]);
            $func = $stmt->fetch();

            if ($func) {
                if (password_verify($senha, $func['senha_hash'])) {
                    $_SESSION['id'] = $func['id_funcionario'];
                    $_SESSION['nome'] = $func['nome'];
                    $_SESSION['tipo_user'] = $func['tipo'];
                    header("Location: index.php");
                    exit;
                } else {
                    $msg = "Senha incorreta!";
                    $msg_type = "error";
                    $usuario_encontrado = true;
                }
            }
        } catch (PDOException $e) {
            // Erro silencioso
        }
    }

    if (!$usuario_encontrado && empty($msg)) {
        $msg = "Usuário não encontrado (Matrícula ou CPF inválidos).";
        $msg_type = "error";
    }
}

// --- RESET SENHA ---
if (isset($_POST['reset_senha'])) {
    $identificador = LimpaPost($_POST['identificador']); // Matrícula ou CPF
    $nova_senha = $_POST['nova_senha'];
    $repetir_senha = $_POST['repetir_senha'];

    if ($nova_senha !== $repetir_senha) {
        $msg = "As senhas não coincidem!";
        $msg_type = "error";
    } else {
        $senha_hash = password_hash($nova_senha, PASSWORD_DEFAULT);
        $resetado = false;

        // Tenta resetar aluno (Matrícula OU CPF)
        try {
            $stmt = $conn->prepare("UPDATE aluno SET senha_hash = ? WHERE matricula = ? OR cpf = ?");
            $stmt->execute([$senha_hash, $identificador, $identificador]);
            if ($stmt->rowCount() > 0) {
                $resetado = true;
            }
        } catch (PDOException $e) {
        }

        // Se não foi aluno, tenta funcionário (CPF)
        if (!$resetado) {
            try {
                $stmt = $conn->prepare("UPDATE funcionario SET senha_hash = ? WHERE cpf = ?");
                $stmt->execute([$senha_hash, $identificador]);
                if ($stmt->rowCount() > 0) {
                    $resetado = true;
                }
            } catch (PDOException $e) {
            }
        }

        if ($resetado) {
            $msg = "Senha redefinida com sucesso! Faça login.";
            $msg_type = "success";
        } else {
            $msg = "Usuário não encontrado.";
            $msg_type = "error";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-br">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login / Cadastro</title>
    <link rel="stylesheet" href="css/login_cadastro.css">
    <script>
        function mostrar(id) {
            document.querySelectorAll('.form-section').forEach(div => div.style.display = 'none');
            document.getElementById(id).style.display = 'block';
        }
    </script>
</head>

<body>

    <div class="container-main">
        <div class="esquerdo">
            <img src="img/senai logo.png" alt="SENAI">
        </div>

        <div class="direito">
            <img src="img/logo-senai.png" alt="Logo SENAI" class="logo-senai">

            <?php if (!empty($msg)) : ?>
                <div class="msg <?= $msg_type ?>"><?= $msg ?></div>
            <?php endif; ?>

            <!-- LOGIN -->
            <div id="login" class="form-section">
                <form method="POST">
                    <input type="text" name="login_input" placeholder="Matrícula ou CPF" required>
                    <input type="password" name="senha" placeholder="Senha" required>
                    <button type="submit" name="logar">Entrar</button>
                </form>
                <p class="toggle" onclick="mostrar('cadastro_aluno')">Cadastrar Aluno</p>
                <p class="toggle" onclick="mostrar('cadastro_funcionario')">Cadastrar Funcionário</p>
                <p class="toggle" onclick="mostrar('reset_senha')">Esqueci minha senha</p>
            </div>

            <!-- CADASTRO ALUNO -->
            <div id="cadastro_aluno" class="form-section" style="display: none;">
                <form method="POST">
                    <input type="text" name="nome" placeholder="Nome Completo" required>
                    <input type="text" name="cpf" placeholder="CPF" required>
                    <input type="password" name="senha" placeholder="Senha" required>
                    <label style="font-size:0.9em; color:#666;">Data de Nascimento:</label>
                    <input type="date" name="data_nascimento" required>
                    <input type="text" name="turma" placeholder="Turma" required>
                    <input type="text" name="matricula" placeholder="Matrícula" required>
                    <button type="submit" name="cadastrar_aluno">Cadastrar</button>
                </form>
                <p class="toggle" onclick="mostrar('login')">Voltar ao Login</p>
            </div>

            <!-- CADASTRO FUNCIONÁRIO -->
            <div id="cadastro_funcionario" class="form-section" style="display: none;">
                <form method="POST">
                    <input type="text" name="nome" placeholder="Nome Completo" required>
                    <input type="text" name="cpf" placeholder="CPF" required>
                    <select name="tipo" required>
                        <option value="">Selecione o Tipo</option>
                        <option value="pedagógico">Pedagógico</option>
                        <option value="instrutor">Instrutor</option>
                        <option value="portaria">Portaria</option>
                    </select>
                    <input type="password" name="senha" placeholder="Senha" required>
                    <button type="submit" name="cadastrar_funcionario">Cadastrar</button>
                </form>
                <p class="toggle" onclick="mostrar('login')">Voltar ao Login</p>
            </div>

            <!-- RESET SENHA -->
            <div id="reset_senha" class="form-section" style="display: none;">
                <form method="POST">
                    <input type="text" name="identificador" placeholder="Matrícula ou CPF" required>
                    <input type="password" name="nova_senha" placeholder="Nova Senha" required>
                    <input type="password" name="repetir_senha" placeholder="Repetir Nova Senha" required>
                    <button type="submit" name="reset_senha">Salvar Nova Senha</button>
                </form>
                <p class="toggle" onclick="mostrar('login')">Voltar ao Login</p>
            </div>

        </div>
    </div>

</body>

</html>