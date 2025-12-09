<?php
function LimpaPost($valor)
{
    if (empty($valor)) {
        return '';
    }
    $valor = trim($valor);
    $valor = stripslashes($valor);
    $valor = htmlspecialchars($valor, ENT_QUOTES, 'UTF-8');
    return $valor;
}

function conectarBanco()
{
    $servidor = "localhost";
    $banco = "SENAI_LIBERAJA";
    $usuario = "root";
    $senha = "";

    try {
        $pdo = new PDO(
            "mysql:host=$servidor;dbname=$banco;charset=utf8mb4",
            $usuario,
            $senha,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false
            ]
        );

        return $pdo;
    } catch (PDOException $erro) {
        die("Erro ao conectar ao banco de dados: " . $erro->getMessage());
    }
}

// Inicializa conexão global
$conn = conectarBanco();

function validarCPF($cpf)
{
    if (empty($cpf)) {
        return false;
    }

    // Remove caracteres não numéricos
    $cpf = preg_replace('/[^0-9]/', '', $cpf);

    // Verifica se tem 11 dígitos
    if (strlen($cpf) != 11) {
        return false;
    }

    // Verifica se todos os dígitos são iguais (ex: 111.111.111-11)
    if (preg_match('/^(\d)\1{10}$/', $cpf)) {
        return false;
    }

    // Valida primeiro dígito verificador
    $soma = 0;
    for ($i = 0; $i < 9; $i++) {
        $soma += intval($cpf[$i]) * (10 - $i);
    }
    $resto = $soma % 11;
    $digito1 = ($resto < 2) ? 0 : (11 - $resto);

    if (intval($cpf[9]) !== $digito1) {
        return false;
    }

    // Valida segundo dígito verificador
    $soma = 0;
    for ($i = 0; $i < 10; $i++) {
        $soma += intval($cpf[$i]) * (11 - $i);
    }
    $resto = $soma % 11;
    $digito2 = ($resto < 2) ? 0 : (11 - $resto);

    if (intval($cpf[10]) !== $digito2) {
        return false;
    }

    return true;
}

function formatarCPF($cpf)
{
    if (empty($cpf)) {
        return '';
    }

    $cpf = preg_replace('/[^0-9]/', '', $cpf);

    if (strlen($cpf) !== 11) {
        return $cpf;
    }

    return preg_replace('/(\d{3})(\d{3})(\d{3})(\d{2})/', '$1.$2.$3-$4', $cpf);
}

function limparCPF($cpf)
{
    if (empty($cpf)) {
        return '';
    }
    return preg_replace('/[^0-9]/', '', $cpf);
}

function validarEmail($email)
{
    if (empty($email)) {
        return false;
    }

    // Valida formato básico
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    // Verifica se o domínio existe (DNS)
    $dominio = substr(strrchr($email, "@"), 1);

    if (empty($dominio)) {
        return false;
    }

    // Verifica registros MX (servidores de email) ou A (host)
    if (!@checkdnsrr($dominio, "MX") && !@checkdnsrr($dominio, "A")) {
        return false;
    }

    return true;
}

function validarTelefone($telefone)
{
    if (empty($telefone)) {
        return false;
    }

    $telefone = preg_replace('/[^0-9]/', '', $telefone);

    // Aceita telefones com 10 dígitos (fixo) ou 11 dígitos (celular)
    if (strlen($telefone) < 10 || strlen($telefone) > 11) {
        return false;
    }

    // Verifica se o DDD é válido (11 a 99)
    $ddd = intval(substr($telefone, 0, 2));
    if ($ddd < 11 || $ddd > 99) {
        return false;
    }

    return true;
}

function formatarTelefone($telefone)
{
    if (empty($telefone)) {
        return '';
    }

    $telefone = preg_replace('/[^0-9]/', '', $telefone);

    if (strlen($telefone) == 11) {
        // Celular: (XX) XXXXX-XXXX
        return preg_replace('/(\d{2})(\d{5})(\d{4})/', '($1) $2-$3', $telefone);
    } elseif (strlen($telefone) == 10) {
        // Fixo: (XX) XXXX-XXXX
        return preg_replace('/(\d{2})(\d{4})(\d{4})/', '($1) $2-$3', $telefone);
    }

    return $telefone;
}

function validarSenhaForte($senha)
{
    $erros = [];

    if (empty($senha)) {
        $erros[] = "A senha não pode estar vazia";
        return $erros;
    }

    if (strlen($senha) < 8) {
        $erros[] = "A senha deve ter no mínimo 8 caracteres";
    }

    if (!preg_match('/[A-Z]/', $senha)) {
        $erros[] = "A senha deve conter pelo menos uma letra maiúscula";
    }

    if (!preg_match('/[a-z]/', $senha)) {
        $erros[] = "A senha deve conter pelo menos uma letra minúscula";
    }

    if (!preg_match('/[0-9]/', $senha)) {
        $erros[] = "A senha deve conter pelo menos um número";
    }

    // REGEX CORRIGIDO: Fechamento correto dos colchetes
    if (!preg_match('/[!@#$%^&*()\-_=+{}\[\];:,.<>?\/\\\\|`~\'""]/', $senha)) {
        $erros[] = "A senha deve conter pelo menos um caractere especial";
    }

    return $erros;
}

function hashSenha($senha)
{
    if (empty($senha)) {
        return false;
    }
    return password_hash($senha, PASSWORD_BCRYPT, ['cost' => 12]);
}

function verificarSenha($senha, $hash)
{
    if (empty($senha) || empty($hash)) {
        return false;
    }
    return password_verify($senha, $hash);
}

function emailJaExiste($email, $excluir_id = null, $tipo_tabela = null)
{
    global $conn;

    if (empty($email)) {
        return false;
    }

    $tabelas = ["aluno", "funcionario"];

    foreach ($tabelas as $tbl) {
        try {
            // Verifica se a coluna email existe na tabela
            $checkColumn = $conn->prepare("SHOW COLUMNS FROM `{$tbl}` LIKE 'email'");
            $checkColumn->execute();

            if ($checkColumn->fetch()) {
                // Monta query considerando exclusão de ID (para edição)
                if ($excluir_id && $tipo_tabela == $tbl) {
                    if ($tbl == 'aluno') {
                        $stmt = $conn->prepare("SELECT 1 FROM `{$tbl}` WHERE email = ? AND id_aluno != ? LIMIT 1");
                    } else {
                        $stmt = $conn->prepare("SELECT 1 FROM `{$tbl}` WHERE email = ? AND id_funcionario != ? LIMIT 1");
                    }
                    $stmt->execute([$email, $excluir_id]);
                } else {
                    $stmt = $conn->prepare("SELECT 1 FROM `{$tbl}` WHERE email = ? LIMIT 1");
                    $stmt->execute([$email]);
                }

                if ($stmt->fetch()) {
                    return true;
                }
            }
        } catch (PDOException $e) {
            // Log do erro (em produção, usar sistema de log apropriado)
            error_log("Erro ao verificar email em {$tbl}: " . $e->getMessage());
            continue;
        }
    }

    return false;
}

function cpfJaExiste($cpf, $excluir_id = null, $tipo_tabela = null)
{
    global $conn;

    if (empty($cpf)) {
        return false;
    }

    // Garante que o CPF está formatado
    $cpf_limpo = limparCPF($cpf);
    $cpf_formatado = formatarCPF($cpf_limpo);

    $tabelas = ["aluno", "funcionario"];

    foreach ($tabelas as $tbl) {
        try {
            // Monta query considerando exclusão de ID (para edição)
            if ($excluir_id && $tipo_tabela == $tbl) {
                if ($tbl == 'aluno') {
                    $stmt = $conn->prepare("SELECT 1 FROM `{$tbl}` WHERE (cpf = ? OR cpf = ?) AND id_aluno != ? LIMIT 1");
                } else {
                    $stmt = $conn->prepare("SELECT 1 FROM `{$tbl}` WHERE (cpf = ? OR cpf = ?) AND id_funcionario != ? LIMIT 1");
                }
                $stmt->execute([$cpf_formatado, $cpf_limpo, $excluir_id]);
            } else {
                $stmt = $conn->prepare("SELECT 1 FROM `{$tbl}` WHERE cpf = ? OR cpf = ? LIMIT 1");
                $stmt->execute([$cpf_formatado, $cpf_limpo]);
            }

            if ($stmt->fetch()) {
                return true;
            }
        } catch (PDOException $e) {
            // Log do erro
            error_log("Erro ao verificar CPF em {$tbl}: " . $e->getMessage());
            continue;
        }
    }

    return false;
}

function matriculaJaExiste($matricula, $excluir_id = null, $tipo_tabela = 'aluno')
{
    global $conn;

    if (empty($matricula)) {
        return false;
    }

    try {
        // Verifica em ALUNO
        if ($tipo_tabela == 'aluno') {
            if ($excluir_id) {
                $stmt = $conn->prepare("SELECT 1 FROM `aluno` WHERE matricula = ? AND id_aluno != ? LIMIT 1");
                $stmt->execute([$matricula, $excluir_id]);
            } else {
                $stmt = $conn->prepare("SELECT 1 FROM `aluno` WHERE matricula = ? LIMIT 1");
                $stmt->execute([$matricula]);
            }
        }
        // Verifica em FUNCIONÁRIO
        else {
            // Verifica se a coluna matricula existe na tabela funcionario
            $checkColumn = $conn->prepare("SHOW COLUMNS FROM `funcionario` LIKE 'matricula'");
            $checkColumn->execute();

            if ($checkColumn->fetch()) {
                if ($excluir_id) {
                    $stmt = $conn->prepare("SELECT 1 FROM `funcionario` WHERE matricula = ? AND id_funcionario != ? LIMIT 1");
                    $stmt->execute([$matricula, $excluir_id]);
                } else {
                    $stmt = $conn->prepare("SELECT 1 FROM `funcionario` WHERE matricula = ? LIMIT 1");
                    $stmt->execute([$matricula]);
                }

                return $stmt->fetch() ? true : false;
            }
        }

        return $stmt->fetch() ? true : false;
    } catch (PDOException $e) {
        error_log("Erro ao verificar matrícula: " . $e->getMessage());
        return false;
    }
}

function limparString($string)
{
    if (empty($string)) {
        return '';
    }
    $string = trim($string);
    $string = stripslashes($string);
    $string = strip_tags($string);
    return $string;
}

function validarData($data)
{
    if (empty($data)) {
        return false;
    }

    try {
        $d = DateTime::createFromFormat('Y-m-d', $data);
        return $d && $d->format('Y-m-d') === $data;
    } catch (Exception $e) {
        return false;
    }
}

function calcularIdade($data_nascimento)
{
    if (empty($data_nascimento)) {
        return 0;
    }

    try {
        $nascimento = new DateTime($data_nascimento);
        $hoje = new DateTime();
        $idade = $hoje->diff($nascimento);
        return $idade->y;
    } catch (Exception $e) {
        return 0;
    }
}
