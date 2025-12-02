<?php
session_start();
require("db/conexao.php");

$empresas = $conn->query("SELECT * FROM empresas ORDER BY nome")->fetchAll();

if (isset($_POST['cadastrar_aluno'])) {
    $nome = LimpaPost($_POST['nome']);
    $cpf = LimpaPost($_POST['cpf']);
    $email = LimpaPost($_POST['email']);
    $senha = password_hash($_POST['senha'], PASSWORD_DEFAULT);
    $data_nascimento = LimpaPost($_POST['data_nascimento']);
    $telefone = null; // Aluno não tem telefone próprio no formulário
    $responsavel_nome = !empty($_POST['responsavel_nome']) ? LimpaPost($_POST['responsavel_nome']) : null;
    $responsavel_telefone = !empty($_POST['responsavel_telefone']) ? LimpaPost($_POST['responsavel_telefone']) : null;
    $curso_tipo = $_POST['curso_tipo'];
    $matricula = LimpaPost($_POST['matricula']);
    $empresa = LimpaPost(isset($_POST['empresa'])) ? LimpaPost($_POST['empresa']) : null;
    $local_estudo = $_POST['local_estudo'];
    $tipo_usuario = 'aluno';

    try {
        $stmt = $conn->prepare("INSERT INTO usuarios (nome, cpf, email, senha, data_nascimento, telefone, responsavel_nome, responsavel_telefone, tipo_usuario, curso_tipo, matricula, empresa, local_estudo) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$nome, $cpf, $email, $senha, $data_nascimento, $telefone, $responsavel_nome, $responsavel_telefone, $tipo_usuario, $curso_tipo, $matricula, $empresa, $local_estudo]);
        $msg = "Cadastro realizado! Agora faça login.";
        $msg_type = "success";
    } catch (PDOException $e) {
        $msg = "Erro no cadastro: " . $e->getMessage();
        $msg_type = "error";
    }
}

if (isset($_POST['cadastrar_admin'])) {
    $nome = LimpaPost($_POST['nome']);
    $cpf = LimpaPost($_POST['cpf']);
    $email = LimpaPost($_POST['email']);
    $senha = password_hash($_POST['senha'], PASSWORD_DEFAULT);
    $data_nascimento = $_POST['data_nascimento'];
    $telefone = LimpaPost($_POST['telefone']);
    $tipo_usuario = 'admin';

    try {
        $stmt = $conn->prepare("INSERT INTO usuarios (nome, cpf, email, senha, data_nascimento, telefone, tipo_usuario) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$nome, $cpf, $email, $senha, $data_nascimento, $telefone, $tipo_usuario]);
        $msg = "Cadastro de administrador realizado! Agora faça login.";
        $msg_type = "success";
    } catch (PDOException $e) {
        $error_code = $e->getCode();
        if ($error_code == 23000) { // Duplicate entry
            if (strpos($e->getMessage(), 'email') !== false) {
                $email_error = "E-mail inválido!";
            } elseif (strpos($e->getMessage(), 'cpf') !== false) {
                $cpf_error = "CPF já cadastrado!";
            } else {
                $msg = "Erro no cadastro: " . $e->getMessage();
                $msg_type = "error";
            }
        } else {
            $msg = "Erro no cadastro: " . $e->getMessage();
            $msg_type = "error";
        }
    }
}

if (isset($_POST['logar'])) {
    $email = LimpaPost($_POST['email']);
    $senha = $_POST['senha'];

    try {
        $stmt = $conn->prepare("SELECT * FROM usuarios WHERE email = ? AND ativo = 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user && password_verify($senha, $user['senha'])) {
            $_SESSION['id'] = $user['id'];
            $_SESSION['tipo_usuario'] = $user['tipo_usuario'];
            $_SESSION['nome'] = $user['nome'];

            // Redirecionar baseado no tipo
            if ($user['tipo_usuario'] == 'aluno') {
                header("Location: index.php");
            } elseif ($user['tipo_usuario'] == 'admin') {
                header("Location: index.php");
            }
            exit;
        } else {
            $msg = "Email ou senha incorretos!";
        }
    } catch (PDOException $e) {
        $msg = "Erro no login: " . $e->getMessage();
    }
}

if (isset($_POST['reset_senha'])) {
    $nome = LimpaPost($_POST['nome']);
    $matricula = LimpaPost($_POST['matricula']);
    $nova_senha = $_POST['nova_senha'];
    $confirmar_senha = $_POST['confirmar_senha'];

    if ($nova_senha !== $confirmar_senha) {
        $reset_msg = "Senhas não coincidem!";
        $reset_msg_type = "error";
    } else {
        try {
            $stmt = $conn->prepare("SELECT id FROM usuarios WHERE nome = ? AND matricula = ? AND tipo_usuario = 'aluno'");
            $stmt->execute([$nome, $matricula]);
            $user = $stmt->fetch();
            if ($user) {
                $senha_hash = password_hash($nova_senha, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("UPDATE usuarios SET senha = ? WHERE id = ?");
                $stmt->execute([$senha_hash, $user['id']]);
                $reset_msg = "Senha resetada com sucesso! Agora faça login.";
                $reset_msg_type = "success";
            } else {
                $reset_msg = "Nome e matrícula não encontrados!";
                $reset_msg_type = "error";
            }
        } catch (PDOException $e) {
            $reset_msg = "Erro: " . $e->getMessage();
            $reset_msg_type = "error";
        }
    }
}
?>
<!DOCTYPE html>
<html>

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="">
    <meta name="keywords" content="">
    <title>Login / Cadastro</title>
    <link rel="stylesheet" href="css/login_cadastro.css">
    <script>
        function mostrar(div) {
            document.getElementById('login').style.display = "none";
            document.getElementById('cadastro_aluno').style.display = "none";
            document.getElementById('cadastro_admin').style.display = "none";
            document.getElementById('esqueceusenha_usuario').style.display = "none";
            document.getElementById(div).style.display = "block";
        }

        function toggleEmpresa(value) {
            var empresaField = document.getElementById('empresa_field');
            if (value === 'aprendiz') {
                empresaField.style.display = 'block';
            } else {
                empresaField.style.display = 'none';
            }
        }

        function validarFormulario() {
            var cpf = document.getElementById('cpf').value;
            if (cpf.length !== 11 || isNaN(cpf)) {
                alert('CPF deve ter 11 dígitos numéricos.');
                return false;
            }
            return true;
        }
    </script>
</head>

<body>
    <div class="esquerdo">
        <div class="card">
            <img src="img/turma.jpg" alt="">
        </div>
    </div>

    <div class="direito">
        <div class="login">
            <div id="login">
                <img src="img/logo-senai.png" alt="">
                <?php if (isset($msg) && !isset($_POST['cadastrar_aluno']) && !isset($_POST['cadastrar_admin'])) {
                    $color = ($msg == 'success') ? 'green' : 'red';
                    echo "<div style='color: $color; text-align: center; margin: 10px 0; font-weight: bold;'>$msg</div>";
                } ?>
                <form method="POST">
                    <input type="email" name="email" placeholder="Email" required>
                    <input type="password" name="senha" placeholder="Senha" required>
                    <button name="logar">Entrar</button>
                </form>
                <p class="toggle" onclick="mostrar('cadastro_aluno')">Cadastrar como Aluno</p>
                <p class="toggle" onclick="mostrar('cadastro_admin')">Cadastrar como Administrador</p>
                <p class="toggle" onclick="mostrar('esqueceusenha_usuario')">esqueceu senha</p>
            </div>
        </div>

        <div id="cadastro_aluno" style="display:none;" class="cadastro">
            <img src="img/logo-senai.png" alt="">
            <?php if (isset($msg) && isset($_POST['cadastrar_aluno'])) {
                $color = ($msg_type == 'success') ? 'green' : 'red';
                echo "<div style='color: $color; text-align: center; margin: 10px 0; font-weight: bold;'>$msg</div>";
            } ?>
            <form method="POST" onsubmit="return validarFormulario()">
                <input type="text" name="nome" placeholder="Seu nome" required>
                <input type="text" id="cpf" name="cpf" placeholder="CPF (somente números)" pattern="\d{11}" required>
                <?php if (isset($cpf_error)) echo "<p style='color: red; font-size: 14px;'>$cpf_error</p>"; ?>
                <input type="email" id="email" name="email" placeholder="Email" required>
                <?php if (isset($email_error)) echo "<p style='color: red; font-size: 14px;'>$email_error</p>"; ?>
                <input type="password" id="senha" name="senha" placeholder="Senha" required>
                <input type="date" name="data_nascimento" required>
                <input type="text" name="responsavel_nome" placeholder="Nome do Responsável (se menor)">
                <input type="tel" name="responsavel_telefone" placeholder="Telefone do Responsável">
                <select name="curso_tipo" required onchange="toggleEmpresa(this.value)">
                    <option value="tecnico">Técnico</option>
                    <option value="aprendiz">Aprendiz</option>
                    <option value="sedu">SEDU</option>
                </select>
                <div id="empresa_field" style="display:none;">
                    <select name="empresa" required>
                        <option value="">Selecione uma empresa</option>
                        <?php
                        foreach ($empresas as $empresa) {
                            echo "<option value='" . $empresa['nome'] . "'>" . $empresa['nome'] . "</option>";
                        }
                        ?>
                    </select>
                </div>
                <select name="local_estudo" required>
                    <option value="SENAI">SENAI</option>
                </select>
                <input type="text" name="matricula" placeholder="Matrícula" required>
                <?php if (isset($matricula_error)) echo "<p style='color: red; font-size: 14px;'>$matricula_error</p>"; ?>
                <button name="cadastrar_aluno">Cadastrar</button>
            </form>
            <p class="toggle" onclick="mostrar('login')">Já tenho conta</p>
        </div>

        <div id="cadastro_admin" style="display:none;" class="cadastro_admin">
            <img src="img/logo-senai.png" alt="">
            <?php if (isset($msgg)) {
                $color = ($msg_type == 'success') ? 'green' : 'red';
                echo "<div style='color: $color; text-align: center; margin: 10px 0; font-weight: bold;'>$msg</div>";
            } ?>
            <form method="POST">
                <input type="text" name="nome" placeholder="Seu nome" required>
                <input type="text" name="cpf" placeholder="CPF (somente números)" pattern="\d{11}" required>
                <?php if (isset($cpf_error)) echo "<p style='color: red; font-size: 14px;'>$cpf_error</p>"; ?>
                <input type="email" id="email" name="email" placeholder="Email" required>
                <?php if (isset($email_error)) echo "<p style='color: red; font-size: 14px;'>$email_error</p>"; ?>
                <input type="password" id="senha" name="senha" placeholder="Senha" required>
                <input type="date" name="data_nascimento" required>
                <input type="tel" name="telefone" placeholder="Telefone">
                <button name="cadastrar_admin">Cadastrar</button>
            </form>
            <p class="toggle" onclick="mostrar('login')">Já tenho conta</p>
        </div>

        <div id="esqueceusenha_usuario" style="display:none;" class="esqueceu">
            <img src="img/logo-senai.png" alt="">
            <?php if (isset($reset_msg)) {
                $color = ($reset_msg_type == 'success') ? 'green' : 'red';
                echo "<div style='color: $color; text-align: center; margin: 10px 0; font-weight: bold;'>$reset_msg</div>";
            } ?>
            <form method="POST">
                <input type="text" name="nome" placeholder="Seu nome" required>
                <input type="text" name="matricula" placeholder="Matrícula" required>
                <input type="password" name="nova_senha" placeholder="Nova senha" required>
                <input type="password" name="confirmar_senha" placeholder="Confirmar nova senha" required>
                <button name="reset_senha">Resetar Senha</button>
            </form>
            <p class="toggle" onclick="mostrar('login')">Voltar ao Login</p>
        </div>
    </div>


</body>

</html>