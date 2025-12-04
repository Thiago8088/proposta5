<?php
session_start();
require("db/conexao.php");

$msg_login = "";
$msg_cad_aluno = "";
$msg_cad_func = "";
$msg_reset = "";
$msg_type = "";

$tela_atual = "login";
$dados_formulario = [];

// CADASTRO ALUNO 
if (isset($_POST['cadastrar_aluno'])) {
    $tela_atual = "cadastro_aluno";

    $dados_formulario = [
        'nome' => isset($_POST['nome']) ? LimpaPost($_POST['nome']) : '',
        'matricula' => isset($_POST['matricula']) ? LimpaPost($_POST['matricula']) : '',
        'cpf' => isset($_POST['cpf']) ? $_POST['cpf'] : '',
        'senha' => isset($_POST['senha']) ? $_POST['senha'] : '',
        'data_nascimento' => isset($_POST['data_nascimento']) ? $_POST['data_nascimento'] : '',
        'turma' => isset($_POST['turma']) ? LimpaPost($_POST['turma']) : ''
    ];

    $erros = [];

    if (empty($dados_formulario['nome'])) {
        $erros[] = "O campo Nome é obrigatório";
    }

    if (empty($dados_formulario['matricula'])) {
        $erros[] = "O campo Matrícula é obrigatório";
    }

    if (empty($dados_formulario['cpf'])) {
        $erros[] = "O campo CPF é obrigatório";
    }

    if (empty($dados_formulario['senha'])) {
        $erros[] = "O campo Senha é obrigatório";
    }

    if (empty($dados_formulario['data_nascimento'])) {
        $erros[] = "O campo Data de Nascimento é obrigatório";
    }

    if (empty($dados_formulario['turma'])) {
        $erros[] = "O campo Turma é obrigatório";
    }

    if (!empty($dados_formulario['cpf'])) {
        $cpf_limpo = limparCPF($dados_formulario['cpf']);

        if (!validarCPF($cpf_limpo)) {
            $erros[] = "CPF inválido! Verifique os números digitados";
        } else {
            if (cpfJaExiste($cpf_limpo)) {
                $erros[] = "Este CPF já está cadastrado no sistema";
            }
        }
    }

    if (!empty($dados_formulario['senha'])) {
        $errosSenha = validarSenhaForte($dados_formulario['senha']);
        if (!empty($errosSenha)) {
            $erros = array_merge($erros, $errosSenha);
        }
    }

    if (!empty($dados_formulario['matricula'])) {
        if (matriculaJaExiste($dados_formulario['matricula'], null, 'aluno')) {
            $erros[] = "Esta matrícula já está cadastrada no sistema";
        }
    }

    if (!empty($dados_formulario['data_nascimento'])) {
        if (!validarData($dados_formulario['data_nascimento'])) {
            $erros[] = "Data de nascimento inválida";
        } else {
            $idade = calcularIdade($dados_formulario['data_nascimento']);
            if ($idade < 14) {
                $erros[] = "É necessário ter pelo menos 14 anos para se cadastrar";
            }
        }
    }

    if (empty($erros)) {
        $celular = '00000000000';
        $cpf_formatado = formatarCPF($cpf_limpo);
        $senha_hash = hashSenha($dados_formulario['senha']);

        try {
            $stmt = $conn->prepare("INSERT INTO aluno (nome, matricula, cpf, celular, senha_hash, data_nascimento) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $dados_formulario['nome'],
                $dados_formulario['matricula'],
                $cpf_formatado,
                $celular,
                $senha_hash,
                $dados_formulario['data_nascimento']
            ]);

            $msg_login = "Aluno cadastrado com sucesso! Faça login para acessar o sistema.";
            $msg_type = "success";
            $tela_atual = "login";
            $dados_formulario = [];
        } catch (PDOException $e) {
            $erros[] = "Erro ao cadastrar no banco de dados. Tente novamente.";
            error_log("Erro ao cadastrar aluno: " . $e->getMessage());
        }
    }

    if (!empty($erros)) {
        $msg_cad_aluno = "<strong>Corrija os seguintes erros:</strong><br>• " . implode("<br>• ", $erros);
        $msg_type = "error";
    }
}

// CADASTRO FUNCIONÁRIO
if (isset($_POST['cadastrar_funcionario'])) {
    $tela_atual = "cadastro_funcionario";

    $dados_formulario = [
        'nome' => isset($_POST['nome']) ? LimpaPost($_POST['nome']) : '',
        'cpf' => isset($_POST['cpf']) ? $_POST['cpf'] : '',
        'tipo' => isset($_POST['tipo']) ? LimpaPost($_POST['tipo']) : '',
        'senha' => isset($_POST['senha']) ? $_POST['senha'] : ''
    ];

    $erros = [];
    if (empty($dados_formulario['nome'])) {
        $erros[] = "O campo Nome é obrigatório";
    }

    if (empty($dados_formulario['cpf'])) {
        $erros[] = "O campo CPF é obrigatório";
    }

    if (empty($dados_formulario['tipo'])) {
        $erros[] = "Selecione o tipo de funcionário";
    }

    if (empty($dados_formulario['senha'])) {
        $erros[] = "O campo Senha é obrigatório";
    }

    $cpf_limpo = '';
    if (!empty($dados_formulario['cpf'])) {
        $cpf_limpo = limparCPF($dados_formulario['cpf']);

        if (!validarCPF($cpf_limpo)) {
            $erros[] = "CPF inválido! Verifique os números digitados";
        } else {
            if (cpfJaExiste($cpf_limpo)) {
                $erros[] = "Este CPF já está cadastrado no sistema";
            }
        }
    }

    if (!empty($dados_formulario['senha'])) {
        $errosSenha = validarSenhaForte($dados_formulario['senha']);
        if (!empty($errosSenha)) {
            $erros = array_merge($erros, $errosSenha);
        }
    }

    if (empty($erros)) {
        $cpf_formatado = formatarCPF($cpf_limpo);
        $senha_hash = hashSenha($dados_formulario['senha']);

        try {
            $sql = "INSERT INTO funcionario (nome, cpf, tipo, senha_hash) VALUES (?, ?, ?, ?)";
            $params = [
                $dados_formulario['nome'],
                $cpf_formatado,
                $dados_formulario['tipo'],
                $senha_hash
            ];

            $stmt = $conn->prepare($sql);
            $resultado = $stmt->execute($params);

            if ($resultado) {
                $msg_login = "Funcionário cadastrado com sucesso! Faça login para acessar o sistema.";
                $msg_type = "success";
                $tela_atual = "login";
                $dados_formulario = [];
            } else {
                $erros[] = "Erro ao cadastrar no banco de dados. Tente novamente.";
            }
        } catch (PDOException $e) {
            $erros[] = "Erro ao cadastrar no banco de dados. Verifique os dados e tente novamente.";
            error_log("Erro ao cadastrar funcionário: " . $e->getMessage());
        }
    }

    if (!empty($erros)) {
        $msg_cad_func = "<strong>Corrija os seguintes erros:</strong><br>• " . implode("<br>• ", $erros);
        $msg_type = "error";
    }
}

if (isset($_POST['logar'])) {
    $tela_atual = "login";

    $login_input = isset($_POST['login_input']) ? LimpaPost($_POST['login_input']) : '';
    $senha = isset($_POST['senha']) ? $_POST['senha'] : '';

    if (empty($login_input) || empty($senha)) {
        $msg_login = "Preencha todos os campos para fazer login!";
        $msg_type = "error";
    } else {
        $usuario_encontrado = false;
        $cpf_limpo = limparCPF($login_input);
        $cpf_formatado = formatarCPF($cpf_limpo);



        try {
            $stmt = $conn->prepare("SELECT * FROM aluno WHERE matricula = ? OR cpf = ? OR cpf = ? LIMIT 1");
            $stmt->execute([$login_input, $cpf_formatado, $cpf_limpo]);
            $aluno = $stmt->fetch();

            if ($aluno) {
                echo "Debug: Aluno encontrado: " . $aluno['nome'] . "<br>";
                if (password_verify($senha, $aluno['senha_hash'])) {
                    $_SESSION['id'] = $aluno['id_aluno'];
                    $_SESSION['nome'] = $aluno['nome'];
                    $_SESSION['tipo_user'] = 'aluno';
                    header("Location: index.php");
                    exit;
                } else {
                    $msg_login = "Senha incorreta! Verifique e tente novamente.";
                    $msg_type = "error";
                    $usuario_encontrado = true;
                }
            } else {
                echo "Debug: Nenhum aluno encontrado<br>";
            }
        } catch (PDOException $e) {
            error_log("Erro ao buscar aluno: " . $e->getMessage());
        }

        if (!$usuario_encontrado && $aluno === false) {
            echo "Debug: Procurando funcionário<br>";
            try {
                $stmt = $conn->prepare("SELECT * FROM funcionario WHERE cpf = ? OR cpf = ? LIMIT 1");
                $stmt->execute([$cpf_formatado, $cpf_limpo]);

                $func = $stmt->fetch();

                if ($func) {
                    echo "Debug: Funcionário encontrado: " . $func['nome'] . "<br>";
                    if (password_verify($senha, $func['senha_hash'])) {
                        $_SESSION['id'] = $func['id_funcionario'];
                        $_SESSION['nome'] = $func['nome'];
                        $_SESSION['tipo_user'] = $func['tipo'];
                        header("Location: index.php");
                        exit;
                    } else {
                        $msg_login = "Senha incorreta! Verifique e tente novamente.";
                        $msg_type = "error";
                        $usuario_encontrado = true;
                    }
                } else {
                    echo "Debug: Nenhum funcionário encontrado<br>";
                }
            } catch (PDOException $e) {
                error_log("Erro ao buscar funcionário: " . $e->getMessage());
            }
        }

        if (!$usuario_encontrado && empty($msg_login)) {
            $msg_login = "Usuário não encontrado. Verifique sua matrícula ou CPF.";
            $msg_type = "error";
        }
    }
}

//RESETAR SENHA
if (isset($_POST['reset_senha'])) {
    $tela_atual = "reset_senha";

    $dados_formulario = [
        'identificador' => isset($_POST['identificador']) ? LimpaPost($_POST['identificador']) : '',
        'nova_senha' => isset($_POST['nova_senha']) ? $_POST['nova_senha'] : '',
        'repetir_senha' => isset($_POST['repetir_senha']) ? $_POST['repetir_senha'] : ''
    ];

    $erros = [];

    if (empty($dados_formulario['identificador'])) {
        $erros[] = "Digite sua matrícula ou CPF";
    }

    if (empty($dados_formulario['nova_senha'])) {
        $erros[] = "Digite a nova senha";
    }

    if (empty($dados_formulario['repetir_senha'])) {
        $erros[] = "Confirme a nova senha";
    }

    if (!empty($dados_formulario['nova_senha']) && !empty($dados_formulario['repetir_senha'])) {
        if ($dados_formulario['nova_senha'] !== $dados_formulario['repetir_senha']) {
            $erros[] = "As senhas não coincidem!";
        }
    }

    if (!empty($dados_formulario['nova_senha'])) {
        $errosSenha = validarSenhaForte($dados_formulario['nova_senha']);
        if (!empty($errosSenha)) {
            $erros = array_merge($erros, $errosSenha);
        }
    }

    if (empty($erros)) {
        $identificador_limpo = limparCPF($dados_formulario['identificador']);
        $cpf_formatado = formatarCPF($identificador_limpo);

        $senha_hash = hashSenha($dados_formulario['nova_senha']);
        $resetado = false;
        try {
            $stmt = $conn->prepare("UPDATE aluno SET senha_hash = ? WHERE matricula = ? OR cpf = ? OR cpf = ?");
            $stmt->execute([$senha_hash, $dados_formulario['identificador'], $cpf_formatado, $identificador_limpo]);
            if ($stmt->rowCount() > 0) {
                $resetado = true;
            }
        } catch (PDOException $e) {
            error_log("Erro ao resetar senha aluno: " . $e->getMessage());
        }

        if (!$resetado) {
            try {
                $stmt = $conn->prepare("UPDATE funcionario SET senha_hash = ? WHERE cpf = ? OR cpf = ?");
                $stmt->execute([$senha_hash, $cpf_formatado, $identificador_limpo]);

                if ($stmt->rowCount() > 0) {
                    $resetado = true;
                }
            } catch (PDOException $e) {
                error_log("Erro ao resetar senha funcionário: " . $e->getMessage());
            }
        }

        if ($resetado) {
            $msg_login = "Senha redefinida com sucesso! Faça login com sua nova senha.";
            $msg_type = "success";
            $tela_atual = "login";
            $dados_formulario = [];
        } else {
            $erros[] = "Usuário não encontrado. Verifique a matrícula ou CPF digitado.";
        }
    }

    if (!empty($erros)) {
        $msg_reset = "<strong>Corrija os seguintes erros:</strong><br>• " . implode("<br>• ", $erros);
        $msg_type = "error";
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
    <style>
        .msg {
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 5px;
            font-size: 14px;
            line-height: 1.6;
        }

        .msg.error {
            background-color: #fee;
            border: 1px solid #fcc;
            color: #c33;
        }

        .msg.success {
            background-color: #efe;
            border: 1px solid #cfc;
            color: #3c3;
        }

        .requisitos-senha {
            text-align: left;
            font-size: 0.85em;
            margin: 10px 0;
            padding: 10px;
            background: #f9f9f9;
            border-radius: 5px;
        }

        .requisitos-senha ul {
            margin: 5px 0;
            padding-left: 20px;
        }

        .requisitos-senha li {
            margin: 3px 0;
        }

        .req-ok {
            color: green;
        }

        .req-erro {
            color: red;
        }
    </style>
    <script>
        function mostrar(id) {
            document.querySelectorAll('.form-section').forEach(div => div.style.display = 'none');
            document.getElementById(id).style.display = 'block';
        }

        function formatarCPFInput(input) {
            let valor = input.value.replace(/\D/g, '');
            if (valor.length <= 11) {
                valor = valor.replace(/(\d{3})(\d)/, '$1.$2');
                valor = valor.replace(/(\d{3})(\d)/, '$1.$2');
                valor = valor.replace(/(\d{3})(\d{1,2})$/, '$1-$2');
            }
            input.value = valor;
        }

        function validarSenhaVisual(senha, containerId) {
            const container = document.getElementById(containerId);
            if (!container) return;

            let html = '<div class="requisitos-senha"><strong>Requisitos da senha:</strong><ul>';
            html += '<li class="' + (senha.length >= 8 ? 'req-ok' : 'req-erro') + '">Mínimo 8 caracteres</li>';
            html += '<li class="' + (/[A-Z]/.test(senha) ? 'req-ok' : 'req-erro') + '">Uma letra maiúscula</li>';
            html += '<li class="' + (/[a-z]/.test(senha) ? 'req-ok' : 'req-erro') + '">Uma letra minúscula</li>';
            html += '<li class="' + (/[0-9]/.test(senha) ? 'req-ok' : 'req-erro') + '">Um número</li>';
            html += '<li class="' + (/[!@#$%^&*()\-_=+\[\]{};:,.<>?\/\\|`~]/.test(senha) ? 'req-ok' : 'req-erro') + '">Um caractere especial</li>';
            html += '</ul></div>';
            container.innerHTML = html;
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

            <!-- LOGIN -->
            <div id="login" class="form-section" style="display: <?= $tela_atual == 'login' ? 'block' : 'none' ?>;">
                <?php if (!empty($msg_login)) : ?>
                    <div class="msg <?= $msg_type ?>"><?= $msg_login ?></div>
                <?php endif; ?>

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
            <div id="cadastro_aluno" class="form-section" style="display: <?= $tela_atual == 'cadastro_aluno' ? 'block' : 'none' ?>;">
                <?php if (!empty($msg_cad_aluno)) : ?>
                    <div class="msg <?= $msg_type ?>"><?= $msg_cad_aluno ?></div>
                <?php endif; ?>

                <form method="POST">
                    <input type="text" name="nome" placeholder="Nome Completo" value="<?= isset($dados_formulario['nome']) ? htmlspecialchars($dados_formulario['nome']) : '' ?>" required>

                    <input type="text" name="cpf" placeholder="CPF" onkeyup="formatarCPFInput(this)" maxlength="14" value="<?= isset($dados_formulario['cpf']) ? htmlspecialchars($dados_formulario['cpf']) : '' ?>" required>

                    <input type="text" name="matricula" placeholder="Matrícula" value="<?= isset($dados_formulario['matricula']) ? htmlspecialchars($dados_formulario['matricula']) : '' ?>" required>

                    <input type="text" name="turma" placeholder="Turma" value="<?= isset($dados_formulario['turma']) ? htmlspecialchars($dados_formulario['turma']) : '' ?>" required>

                    <label style="font-size:0.9em; color:#666;">Data de Nascimento:</label>
                    <input type="date" name="data_nascimento" value="<?= isset($dados_formulario['data_nascimento']) ? htmlspecialchars($dados_formulario['data_nascimento']) : '' ?>" required>

                    <input type="password" name="senha" placeholder="Senha" onkeyup="validarSenhaVisual(this.value, 'requisitos-senha-aluno')" required>
                    <div id="requisitos-senha-aluno"></div>

                    <button type="submit" name="cadastrar_aluno">Cadastrar</button>
                </form>
                <p class="toggle" onclick="mostrar('login')">Voltar ao Login</p>
            </div>

            <!-- CADASTRO FUNCIONÁRIO -->
            <div id="cadastro_funcionario" class="form-section" style="display: <?= $tela_atual == 'cadastro_funcionario' ? 'block' : 'none' ?>;">
                <?php if (!empty($msg_cad_func)) : ?>
                    <div class="msg <?= $msg_type ?>"><?= $msg_cad_func ?></div>
                <?php endif; ?>

                <form method="POST">
                    <input type="text" name="nome" placeholder="Nome Completo" value="<?= isset($dados_formulario['nome']) ? htmlspecialchars($dados_formulario['nome']) : '' ?>" required>

                    <input type="text" name="cpf" placeholder="CPF" onkeyup="formatarCPFInput(this)" maxlength="14" value="<?= isset($dados_formulario['cpf']) ? htmlspecialchars($dados_formulario['cpf']) : '' ?>" required>

                    <select name="tipo" required>
                        <option value="">Selecione o Tipo</option>
                        <option value="pedagógico" <?= (isset($dados_formulario['tipo']) && $dados_formulario['tipo'] == 'pedagógico') ? 'selected' : '' ?>>Pedagógico</option>
                        <option value="instrutor" <?= (isset($dados_formulario['tipo']) && $dados_formulario['tipo'] == 'instrutor') ? 'selected' : '' ?>>Instrutor</option>
                        <option value="portaria" <?= (isset($dados_formulario['tipo']) && $dados_formulario['tipo'] == 'portaria') ? 'selected' : '' ?>>Portaria</option>
                    </select>

                    <input type="password" name="senha" placeholder="Senha" onkeyup="validarSenhaVisual(this.value, 'requisitos-senha-func')" required>
                    <div id="requisitos-senha-func"></div>

                    <button type="submit" name="cadastrar_funcionario">Cadastrar</button>
                </form>
                <p class="toggle" onclick="mostrar('login')">Voltar ao Login</p>
            </div>

            <!-- RESETARUsuário não encontrado. Verifique sua matrícula ou CPF. SENHA -->
            <div id="reset_senha" class="form-section" style="display: <?= $tela_atual == 'reset_senha' ? 'block' : 'none' ?>;">
                <?php if (!empty($msg_reset)) : ?>
                    <div class="msg <?= $msg_type ?>"><?= $msg_reset ?></div>
                <?php endif; ?>

                <form method="POST">
                    <input type="text" name="identificador" placeholder="Matrícula ou CPF" value="<?= isset($dados_formulario['identificador']) ? htmlspecialchars($dados_formulario['identificador']) : '' ?>" required>

                    <input type="password" name="nova_senha" placeholder="Nova Senha" onkeyup="validarSenhaVisual(this.value, 'requisitos-senha-reset')" required>
                    <div id="requisitos-senha-reset"></div>

                    <input type="password" name="repetir_senha" placeholder="Repetir Nova Senha" required>

                    <button type="submit" name="reset_senha">Salvar Nova Senha</button>
                </form>
                <p class="toggle" onclick="mostrar('login')">Voltar ao Login</p>
            </div>

        </div>
    </div>

</body>

</html>