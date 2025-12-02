<?php
function LimpaPost($valor)
{
    $valor = trim($valor);
    $valor = stripslashes($valor);
    $valor = htmlspecialchars($valor, ENT_QUOTES, 'UTF-8');
    return $valor;
}

$servidor = "localhost";
$banco = "db_5";
$usuario = "root";
$senha = "";

try {
    $conn = new PDO(
        "mysql:host=$servidor;dbname=$banco;charset=utf8mb4",
        $usuario,
        $senha,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, // Exibir erros do PDO
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, // Padrão fetch associativo
            PDO::ATTR_EMULATE_PREPARES => false // Usar prepared statements reais
        ]
    );
} catch (PDOException $erro) {
    die("Erro ao conectar ao banco de dados: " . $erro->getMessage());
}
