<?php
session_start();
date_default_timezone_set('America/Sao_Paulo');
require("db/conexao.php");

// AJAX para carregar turmas por curso
if (isset($_GET['ajax']) && $_GET['ajax'] == 'turmas_curso' && isset($_GET['id_curso'])) {
    $id_curso = $_GET['id_curso'];

    try {
        $stmt = $conn->prepare("SELECT id_turma, nome FROM turma WHERE id_curso = ? ORDER BY nome");
        $stmt->execute([$id_curso]);
        $turmas = $stmt->fetchAll(PDO::FETCH_ASSOC);

        header('Content-Type: application/json');
        echo json_encode($turmas);
        exit;
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Erro ao buscar turmas']);
        exit;
    }
}

if (!isset($_SESSION['id'])) {
    header("Location: login_cadastro.php");
}

if (!isset($_SESSION['id'])) {
    header("Location: login_cadastro.php");
    exit;
}

$tipo_user = $_SESSION['tipo_user'];
$user_id = $_SESSION['id'];
$user = null;

$msg_login = "";
$msg_cad_aluno = "";
$msg_cad_func = "";
$msg_reset = "";
$msg_type = "";
$tela_atual = "login";
$dados_formulario = [];


// FUNÇÕES UTILITÁRIAS


function enviarWhatsApp($numero, $mensagem)
{
    error_log("WhatsApp para $numero: $mensagem");
    return true;
}

function gerarCodigoLiberacao()
{
    $caracteres = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $codigo = '';
    for ($i = 0; $i < 4; $i++) {
        $codigo .= $caracteres[rand(0, strlen($caracteres) - 1)];
    }
    $codigo .= '-';
    for ($i = 0; $i < 3; $i++) {
        $codigo .= $caracteres[rand(0, strlen($caracteres) - 1)];
    }
    return $codigo;
}

function validarCodigoLiberacao($codigo)
{
    return preg_match('/^[A-Z0-9]{4}-[A-Z0-9]{3}$/', $codigo);
}

function logNotificacao($conn, $tipo, $destinatario, $mensagem, $status = 'enviado')
{
    try {
        $stmt = $conn->prepare("INSERT INTO log_notificacoes (tipo, destinatario, mensagem, status, data_envio) VALUES (?, ?, ?, ?, NOW())");
        $stmt->execute([$tipo, $destinatario, $mensagem, $status]);
        return true;
    } catch (PDOException $e) {
        error_log("Erro ao registrar log de notificação: " . $e->getMessage());
        return false;
    }
}

function parseMotivo($string)
{
    $result = ['status' => '', 'codigo' => '', 'motivo' => ''];
    if (preg_match('/STATUS:([^|]+)/', $string, $matches)) $result['status'] = $matches[1];
    if (preg_match('/CODIGO:([^|]+)/', $string, $matches)) $result['codigo'] = $matches[1];
    if (preg_match('/MOTIVO:(.+)$/', $string, $matches)) $result['motivo'] = $matches[1];
    else $result['motivo'] = $string;
    return $result;
}

function buildMotivo($status, $motivo, $codigo = '')
{
    $parts = [];
    if (!empty($status)) $parts[] = "STATUS:" . $status;
    if (!empty($codigo)) $parts[] = "CODIGO:" . $codigo;
    if (!empty($motivo)) $parts[] = "MOTIVO:" . $motivo;
    return implode('|', $parts);
}

function getStatusColor($status, $motivoField = '')
{
    if (!empty($motivoField)) {
        $parsed = parseMotivo($motivoField);
        if (!empty($parsed['status'])) {
            switch ($parsed['status']) {
                case 'aguardando_instrutor':
                    return '#ffc107';
                case 'aguardando_pedagogico':
                    return '#17a2b8';
                case 'aguardando_responsavel':
                    return '#fd7e14';
                case 'aguardando_portaria':
                    return '#28a745';
                case 'recusado_instrutor':
                case 'recusado_responsavel':
                    return '#dc3545';
                case 'concluido':
                    return '#28a745';
            }
        }
    }
    switch ($status) {
        case 'solicitado':
            return '#ffc107';
        case 'autorizado':
            return '#17a2b8';
        case 'liberado':
            return '#28a745';
        case 'rejeitada':
            return '#dc3545';
        default:
            return '#6c757d';
    }
}

function getStatusText($status, $motivoField = '')
{
    if (!empty($motivoField)) {
        $parsed = parseMotivo($motivoField);
        if (!empty($parsed['status'])) {
            switch ($parsed['status']) {
                case 'aguardando_instrutor':
                    return 'Aguardando Instrutor';
                case 'aguardando_pedagogico':
                    return 'Aguardando Pedagógico';
                case 'aguardando_responsavel':
                    return 'Aguardando Responsável';
                case 'aguardando_portaria':
                    return 'Aguardando Portaria';
                case 'recusado_instrutor':
                    return 'Recusado pelo Instrutor';
                case 'recusado_responsavel':
                    return 'Recusado pelo Responsável';
                case 'concluido':
                    return 'Concluído';
            }
        }
    }
    switch ($status) {
        case 'solicitado':
            return 'Aguardando Responsável';
        case 'autorizado':
            return 'Aguardando Instrutor';
        case 'liberado':
            return 'Aguardando Portaria';
        case 'rejeitada':
            return 'Rejeitado';
        case 'concluido':
            return 'Concluído';
        default:
            return $status;
    }
}


// CARREGAR DADOS DO USUÁRIO


if ($tipo_user == 'aluno') {
    $stmt = $conn->prepare("SELECT a.*, t.nome as turma_nome, c.nome as curso_nome FROM aluno a LEFT JOIN matricula m ON a.id_aluno = m.id_aluno LEFT JOIN turma t ON m.id_turma = t.id_turma LEFT JOIN curso c ON t.id_curso = c.id_curso WHERE a.id_aluno = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch();
} else {
    $stmt = $conn->prepare("SELECT * FROM funcionario WHERE id_funcionario = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch();
}

if (!$user) {
    session_destroy();
    header("Location: login_cadastro.php");
    exit;
}

$msg = "";
$msg_type = "";


// LÓGICA ALUNO


if ($tipo_user == 'aluno') {
    if (isset($_POST['solicitar_saida'])) {
        $id_curricular = LimpaPost($_POST['id_curricular']);
        $motivo = $_POST['motivo'];
        $motivo_outro = isset($_POST['motivo_outro']) ? LimpaPost($_POST['motivo_outro']) : '';

        if (empty($motivo)) {
            $msg = "Selecione o motivo da solicitação!";
            $msg_type = "error";
        } elseif ($motivo == '10' && empty($motivo_outro)) {
            $msg = "Descreva o motivo da saída!";
            $msg_type = "error";
        } else {
            $motivo_final = $motivo == '10' ? $motivo_outro : $motivo;
            $motivo_completo = buildMotivo('aguardando_instrutor', $motivo_final);

            try {
                $stmt = $conn->prepare("INSERT INTO solicitacao (id_aluno, id_curricular, motivo, data_solicitada, status) VALUES (?, ?, ?, NOW(), 'solicitado')");
                $stmt->execute([$user['id_aluno'], $id_curricular, $motivo_completo]);
                $msg = "Solicitação enviada com sucesso! Aguarde aprovação.";
                $msg_type = "success";

                if (!empty($user['contato_responsavel'])) {
                    enviarWhatsApp($user['contato_responsavel'], "Seu filho(a) " . $user['nome'] . " solicitou saída da escola. Motivo: " . $motivo_final);
                }
            } catch (PDOException $e) {
                $msg = "Erro ao enviar solicitação: " . $e->getMessage();
                $msg_type = "error";
            }
        }
    }

    $ucs = $conn->query("SELECT uc.* FROM unidade_curricular uc JOIN turma t ON uc.id_curso = t.id_curso JOIN matricula m ON t.id_turma = m.id_turma WHERE m.id_aluno = " . $user['id_aluno'])->fetchAll();
}


// LÓGICA PEDAGÓGICO

if ($tipo_user == 'pedagógico') {
    if (isset($_POST['autorizar_saida'])) {
        $id_solicitacao = $_POST['id_solicitacao'];
        $acao = $_POST['acao'];

        // Fetch current status/motivo
        $stmt_current = $conn->prepare("SELECT motivo, status FROM solicitacao WHERE id_solicitacao = ?");
        $stmt_current->execute([$id_solicitacao]);
        $current = $stmt_current->fetch();
        $parsed = parseMotivo($current['motivo']);
        $original_motivo = $parsed['motivo'];
        $current_status_detail = $parsed['status'];

        if ($current_status_detail == 'aguardando_pedagogico') {
            if ($acao == 'autorizar') {
                // Move to aguardando_responsavel
                $new_motivo = buildMotivo('aguardando_responsavel', $original_motivo);
                $stmt = $conn->prepare("UPDATE solicitacao SET status = 'autorizado', motivo = ?, id_autorizacao = ? WHERE id_solicitacao = ?");
                $stmt->execute([$new_motivo, $user['id_funcionario'], $id_solicitacao]);
                $msg = "Solicitação aceita! Aguardando responsável.";
                $msg_type = "success";
            } else {
                // Reject
                $new_motivo = buildMotivo('recusado_pedagogico', $original_motivo);
                $stmt = $conn->prepare("UPDATE solicitacao SET status = 'rejeitada', motivo = ? WHERE id_solicitacao = ?");
                $stmt->execute([$new_motivo, $id_solicitacao]);
                $msg = "Solicitação rejeitada!";
                $msg_type = "error";
            }
        } elseif ($current_status_detail == 'aguardando_responsavel') {
            if ($acao == 'autorizar') {
                // Responsible Accepted -> Generate Code -> aguardando_portaria
                $codigo = gerarCodigoLiberacao();
                $new_motivo = buildMotivo('aguardando_portaria', $original_motivo, $codigo);
                $stmt = $conn->prepare("UPDATE solicitacao SET status = 'liberado', motivo = ? WHERE id_solicitacao = ?");
                $stmt->execute([$new_motivo, $id_solicitacao]);

                // Send WhatsApp with code
                $stmt_aluno = $conn->prepare("SELECT a.* FROM aluno a JOIN solicitacao s ON a.id_aluno = s.id_aluno WHERE s.id_solicitacao = ?");
                $stmt_aluno->execute([$id_solicitacao]);
                $dados_aluno = $stmt_aluno->fetch();
                if (!empty($dados_aluno['contato_responsavel'])) {
                    enviarWhatsApp($dados_aluno['contato_responsavel'], "Saída autorizada! Código de liberação: " . $codigo);
                }

                $msg = "Responsável aceitou! Código gerado: $codigo. Aguardando portaria.";
                $msg_type = "success";
            } else {
                // Responsible Rejected
                $new_motivo = buildMotivo('recusado_responsavel', $original_motivo);
                $stmt = $conn->prepare("UPDATE solicitacao SET status = 'rejeitada', motivo = ? WHERE id_solicitacao = ?");
                $stmt->execute([$new_motivo, $id_solicitacao]);
                $msg = "Responsável recusou! Solicitação encerrada.";
                $msg_type = "error";
            }
        }
    }

    if (isset($_POST['cadastrar_curso'])) {
        $nome_curso = LimpaPost($_POST['nome_curso']);
        if (!empty($nome_curso)) {
            try {
                $stmt = $conn->prepare("INSERT INTO curso (nome) VALUES (?)");
                $stmt->execute([$nome_curso]);
                $msg = "Curso cadastrado com sucesso!";
                $msg_type = "success";
            } catch (PDOException $e) {
                $msg = "Erro ao cadastrar curso: " . $e->getMessage();
                $msg_type = "error";
            }
        }
    }

    if (isset($_POST['editar_curso'])) {
        $id_curso = $_POST['id_curso'];
        $nome_curso = LimpaPost($_POST['nome_curso']);
        try {
            $stmt = $conn->prepare("UPDATE curso SET nome = ? WHERE id_curso = ?");
            $stmt->execute([$nome_curso, $id_curso]);
            $msg = "Curso atualizado!";
            $msg_type = "success";
        } catch (PDOException $e) {
            $msg = "Erro: " . $e->getMessage();
            $msg_type = "error";
        }
    }

    if (isset($_POST['deletar_curso'])) {
        $id_curso = $_POST['id_curso'];
        try {
            $check = $conn->prepare("SELECT COUNT(*) as total FROM turma WHERE id_curso = ?");
            $check->execute([$id_curso]);
            $total_turmas = $check->fetch()['total'];

            if ($total_turmas > 0) {
                $msg = "Não é possível excluir este curso pois existem " . $total_turmas . " turma(s) associada(s). Exclua as turmas primeiro.";
                $msg_type = "error";
            } else {
                $stmt = $conn->prepare("DELETE FROM curso WHERE id_curso = ?");
                $stmt->execute([$id_curso]);
                $msg = "Curso excluído com sucesso!";
                $msg_type = "success";
            }
        } catch (PDOException $e) {
            $msg = "Erro: " . $e->getMessage();
            $msg_type = "error";
        }
    }

    if (isset($_POST['cadastrar_uc'])) {
        $nome_uc = LimpaPost($_POST['nome_uc']);
        $carga_horaria = $_POST['carga_horaria'];
        $id_curso = $_POST['id_curso'];

        if (!empty($nome_uc) && !empty($carga_horaria) && !empty($id_curso)) {
            try {
                $stmt = $conn->prepare("INSERT INTO unidade_curricular (nome, carga_horaria, id_curso) VALUES (?, ?, ?)");
                $stmt->execute([$nome_uc, $carga_horaria, $id_curso]);
                $msg = "Unidade curricular cadastrada!";
                $msg_type = "success";
            } catch (PDOException $e) {
                $msg = "Erro: " . $e->getMessage();
                $msg_type = "error";
            }
        }
    }

    if (isset($_POST['editar_uc'])) {
        $id_curricular = $_POST['id_curricular'];
        $nome_uc = LimpaPost($_POST['nome_uc']);
        $carga_horaria = $_POST['carga_horaria'];
        try {
            $stmt = $conn->prepare("UPDATE unidade_curricular SET nome = ?, carga_horaria = ? WHERE id_curricular = ?");
            $stmt->execute([$nome_uc, $carga_horaria, $id_curricular]);
            $msg = "UC atualizada!";
            $msg_type = "success";
        } catch (PDOException $e) {
            $msg = "Erro: " . $e->getMessage();
            $msg_type = "error";
        }
    }

    if (isset($_POST['deletar_uc'])) {
        $id_curricular = $_POST['id_curricular'];
        try {
            $stmt = $conn->prepare("DELETE FROM unidade_curricular WHERE id_curricular = ?");
            $stmt->execute([$id_curricular]);
            $msg = "UC excluída!";
            $msg_type = "success";
        } catch (PDOException $e) {
            $msg = "Erro: " . $e->getMessage();
            $msg_type = "error";
        }
    }

    if (isset($_POST['cadastrar_turma'])) {
        $nome_turma = LimpaPost($_POST['nome_turma']);
        $id_curso = $_POST['id_curso_turma'];
        $carga_horaria_total = $_POST['carga_horaria_total'];

        if (!empty($nome_turma) && !empty($id_curso)) {
            try {
                $stmt = $conn->prepare("INSERT INTO turma (nome, id_curso, carga_horaria_total) VALUES (?, ?, ?)");
                $stmt->execute([$nome_turma, $id_curso, $carga_horaria_total]);
                $msg = "Turma cadastrada!";
                $msg_type = "success";
            } catch (PDOException $e) {
                $msg = "Erro: " . $e->getMessage();
                $msg_type = "error";
            }
        }
    }

    if (isset($_POST['editar_turma'])) {
        $id_turma = $_POST['id_turma'];
        $nome_turma = LimpaPost($_POST['nome_turma']);
        $carga_horaria_total = $_POST['carga_horaria_total'];
        try {
            $stmt = $conn->prepare("UPDATE turma SET nome = ?, carga_horaria_total = ? WHERE id_turma = ?");
            $stmt->execute([$nome_turma, $carga_horaria_total, $id_turma]);
            $msg = "Turma atualizada!";
            $msg_type = "success";
        } catch (PDOException $e) {
            $msg = "Erro: " . $e->getMessage();
            $msg_type = "error";
        }
    }

    if (isset($_POST['deletar_turma'])) {
        try {
            $id_turma = $_POST['id_turma'];

            $check = $conn->prepare("SELECT COUNT(*) as total FROM matricula WHERE id_turma = ?");
            $check->execute([$id_turma]);
            $total_matriculas = $check->fetch()['total'];

            if ($total_matriculas > 0) {
                $msg = "Não é possível excluir esta turma pois existem " . $total_matriculas . " aluno(s) matriculado(s). Remova as matrículas primeiro.";
                $msg_type = "error";
            } else {
                $stmt = $conn->prepare("DELETE FROM turma WHERE id_turma = ?");
                $stmt->execute([$id_turma]);
                $msg = "Turma excluída com sucesso!";
                $msg_type = "success";
            }
        } catch (PDOException $e) {
            $msg = "Erro: " . $e->getMessage();
            $msg_type = "error";
        }
    }

    if (isset($_POST['completar_aluno'])) {
        $id_aluno = $_POST['id_aluno'];
        $contato_responsavel = LimpaPost($_POST['contato_responsavel']);
        $nome_responsavel = isset($_POST['nome_responsavel']) ? LimpaPost($_POST['nome_responsavel']) : '';
        $empresa = isset($_POST['empresa']) ? LimpaPost($_POST['empresa']) : '';
        $contato_empresa = isset($_POST['contato_empresa']) ? LimpaPost($_POST['contato_empresa']) : '';
        $id_turma = $_POST['id_turma'];

        try {
            $columns = $conn->query("SHOW COLUMNS FROM aluno")->fetchAll(PDO::FETCH_COLUMN);

            $updates = [];
            $params = [];

            $updates[] = "contato_responsavel = ?";
            $params[] = $contato_responsavel;

            if (in_array('nome_responsavel', $columns) && !empty($nome_responsavel)) {
                $updates[] = "nome_responsavel = ?";
                $params[] = $nome_responsavel;
            }

            if (in_array('empresa', $columns) && !empty($empresa)) {
                $updates[] = "empresa = ?";
                $params[] = $empresa;
            }

            if (in_array('contato_empresa', $columns) && !empty($contato_empresa)) {
                $updates[] = "contato_empresa = ?";
                $params[] = $contato_empresa;
            }

            $params[] = $id_aluno;

            $sql = "UPDATE aluno SET " . implode(", ", $updates) . " WHERE id_aluno = ?";
            $stmt = $conn->prepare($sql);
            $stmt->execute($params);

            if (!empty($id_turma)) {
                $check = $conn->prepare("SELECT * FROM matricula WHERE id_aluno = ?");
                $check->execute([$id_aluno]);
                if ($check->rowCount() > 0) {
                    $stmt = $conn->prepare("UPDATE matricula SET id_turma = ? WHERE id_aluno = ?");
                    $stmt->execute([$id_turma, $id_aluno]);
                } else {
                    $stmt = $conn->prepare("INSERT INTO matricula (id_aluno, id_turma) VALUES (?, ?)");
                    $stmt->execute([$id_aluno, $id_turma]);
                }
            }

            $msg = "Dados do aluno atualizados!";
            $msg_type = "success";
        } catch (PDOException $e) {
            $msg = "Erro: " . $e->getMessage();
            $msg_type = "error";
        }
    }

    if (isset($_POST['resetar_senha_aluno'])) {
        $id_aluno = $_POST['id_aluno_reset'];
        $senha_padrao = "Senai@2024";
        $hash_padrao = hashSenha($senha_padrao);

        try {
            $stmt = $conn->prepare("UPDATE aluno SET senha_hash = ? WHERE id_aluno = ?");
            $stmt->execute([$hash_padrao, $id_aluno]);
            $msg = "Senha resetada para: " . $senha_padrao;
            $msg_type = "success";
        } catch (PDOException $e) {
            $msg = "Erro: " . $e->getMessage();
            $msg_type = "error";
        }
    }

    $total_solicitacoes = $conn->query("SELECT COUNT(*) as total FROM solicitacao")->fetch()['total'];
    $solicitacoes_pendentes = $conn->query("SELECT COUNT(*) as total FROM solicitacao WHERE status = 'solicitado'")->fetch()['total'];
    $aguardando_responsavel = $conn->query("SELECT COUNT(*) as total FROM solicitacao WHERE status = 'solicitado'")->fetch()['total'];
    $aguardando_instrutor = $conn->query("SELECT COUNT(*) as total FROM solicitacao WHERE status = 'autorizado'")->fetch()['total'];
    $aguardando_portaria = $conn->query("SELECT COUNT(*) as total FROM solicitacao WHERE status = 'liberado'")->fetch()['total'];
    $solicitacoes_liberadas = $conn->query("SELECT COUNT(*) as total FROM solicitacao WHERE status IN ('liberado', 'concluido')")->fetch()['total'];
    $cursos = $conn->query("SELECT * FROM curso ORDER BY nome")->fetchAll();
    $ucs = $conn->query("SELECT uc.*, c.nome as curso_nome FROM unidade_curricular uc JOIN curso c ON uc.id_curso = c.id_curso ORDER BY c.nome, uc.nome")->fetchAll();
    $turmas = $conn->query("SELECT t.*, c.nome as curso_nome FROM turma t JOIN curso c ON t.id_curso = c.id_curso ORDER BY c.nome, t.nome")->fetchAll();
    $alunos_incompletos = $conn->query("SELECT a.*, t.nome as turma_nome FROM aluno a LEFT JOIN matricula m ON a.id_aluno = m.id_aluno LEFT JOIN turma t ON m.id_turma = t.id_turma WHERE a.contato_responsavel IS NULL OR a.contato_responsavel = '' ORDER BY a.nome")->fetchAll();
}


// LÓGICA INSTRUTOR


if ($tipo_user == 'instrutor') {
    if (isset($_POST['instrutor_acao'])) {
        $id_solicitacao = $_POST['id_solicitacao'];
        $acao = $_POST['acao_instrutor'];

        // Fetch current motive to preserve it
        $stmt_current = $conn->prepare("SELECT motivo FROM solicitacao WHERE id_solicitacao = ?");
        $stmt_current->execute([$id_solicitacao]);
        $current_motivo_raw = $stmt_current->fetchColumn();
        $parsed = parseMotivo($current_motivo_raw);
        $original_motivo = $parsed['motivo'];

        if ($acao == 'autorizar') {
            // Update to aguardando_pedagogico
            $new_motivo = buildMotivo('aguardando_pedagogico', $original_motivo);

            // Use data_autorizada for instrutor time as requested
            $stmt = $conn->prepare("UPDATE solicitacao SET status = 'autorizado', data_autorizada = NOW(), motivo = ? WHERE id_solicitacao = ?");
            $stmt->execute([$new_motivo, $id_solicitacao]);

            $msg = "Solicitação autorizada! Aguardando pedagógico.";
            $msg_type = "success";
        } else {
            // Update to recusado_instrutor
            $new_motivo = buildMotivo('recusado_instrutor', $original_motivo);

            $stmt = $conn->prepare("UPDATE solicitacao SET status = 'rejeitada', motivo = ? WHERE id_solicitacao = ?");
            $stmt->execute([$new_motivo, $id_solicitacao]);
            $msg = "Solicitação rejeitada!";
            $msg_type = "error";
        }
    }
    if (isset($_POST['registrar_falta'])) {
        $id_solicitacao = $_POST['id_solicitacao_falta'];
        $id_aluno = $_POST['id_aluno_falta'];
    }

    $turmas_instrutor = $conn->query("
        SELECT DISTINCT
            t.id_turma,
            t.nome as turma_nome,
            c.nome as curso_nome,
            COUNT(m.id_aluno) as total_alunos
        FROM turma t
        JOIN curso c ON t.id_curso = c.id_curso
        JOIN matricula m ON t.id_turma = m.id_turma
        JOIN aluno a ON m.id_aluno = a.id_aluno
        GROUP BY t.id_turma, t.nome, c.nome
        ORDER BY t.nome
    ")->fetchAll();

    $total_alunos = $conn->query("SELECT COUNT(DISTINCT m.id_aluno) as total FROM matricula m JOIN turma t ON m.id_turma = t.id_turma")->fetch()['total'];
    $solicitacoes_pendentes = $conn->query("SELECT COUNT(*) as total FROM solicitacao WHERE status = 'autorizado'")->fetch()['total'];
}


// LÓGICA PORTARIA


if ($tipo_user == 'portaria') {
    if (isset($_POST['validar_codigo'])) {
        $codigo = strtoupper(LimpaPost($_POST['codigo_liberacao']));

        try {
            $stmt = $conn->prepare("SELECT s.*, a.nome, a.matricula FROM solicitacao s JOIN aluno a ON s.id_aluno = a.id_aluno WHERE s.status = 'liberado' AND s.motivo LIKE ?");
            $stmt->execute(['%CODIGO:' . $codigo . '%']);
            $solicitacao = $stmt->fetch();

            if ($solicitacao) {
                // Check if detailed status is aguardando_portaria
                $parsed = parseMotivo($solicitacao['motivo']);
                if ($parsed['status'] == 'aguardando_portaria') {
                    // Update to concluido
                    $new_motivo = buildMotivo('concluido', $parsed['motivo'], $codigo);

                    $stmt_update = $conn->prepare("UPDATE solicitacao SET data_saida = NOW(), status = 'concluido', motivo = ? WHERE id_solicitacao = ?");
                    $stmt_update->execute([$new_motivo, $solicitacao['id_solicitacao']]);

                    $msg = "Saída registrada para " . $solicitacao['nome'] . " (Matrícula: " . $solicitacao['matricula'] . ")";
                    $msg_type = "success";
                } else {
                    $msg = "Status inválido para liberação!";
                    $msg_type = "error";
                }
            } else {
                $msg = "Código inválido ou já utilizado!";
                $msg_type = "error";
            }
        } catch (PDOException $e) {
            $msg = "Erro: " . $e->getMessage();
            $msg_type = "error";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-br">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Painel SENAI</title>
    <link rel="stylesheet" href="css/estilo.css">
    <script>
        function showSection(section) {
            console.log('Mostrando seção:', section); // Debug

            // Esconde todas as seções
            document.querySelectorAll('.section').forEach(sec => {
                sec.style.display = 'none';
                sec.classList.remove('active');
            });

            // Mostra a seção selecionada
            const sectionElement = document.getElementById(section);
            if (sectionElement) {
                sectionElement.style.display = 'block';
                sectionElement.classList.add('active');
                console.log('Seção encontrada e exibida:', section); // Debug
            } else {
                console.error('Seção não encontrada:', section); // Debug
            }

            // Remove classe active de todos os links
            document.querySelectorAll('.navbar-menu a').forEach(link => {
                link.classList.remove('active');
            });

            // Adiciona classe active no link clicado
            if (event && event.target) {
                event.target.classList.add('active');
            }
        }

        // Nova função para alternar tabs do histórico
        function mostrarHistoricoTab(tipo) {
            console.log('Mostrando tab:', tipo); // Debug

            // Remove active de todos os botões
            document.querySelectorAll('.tab-btn').forEach(btn => {
                btn.classList.remove('active');
            });

            // Adiciona active no botão clicado
            if (event && event.target) {
                event.target.classList.add('active');
            }

            // Esconde todos os conteúdos
            document.querySelectorAll('.historico-content').forEach(el => {
                el.style.display = 'none';
                el.classList.remove('active');
            });

            // Mostra o selecionado
            const elemento = document.getElementById('historico-' + tipo);
            if (elemento) {
                elemento.style.display = 'block';
                elemento.classList.add('active');
                console.log('Tab exibida:', tipo); // Debug
            } else {
                console.error('Tab não encontrada:', 'historico-' + tipo); // Debug
            }
        }

        // Carrega a primeira seção ao iniciar
        window.addEventListener('DOMContentLoaded', function() {
            console.log('DOM carregado'); // Debug

            const firstSection = document.querySelector('.navbar-menu a');
            if (firstSection) {
                firstSection.click();
                console.log('Primeira seção clicada'); // Debug
            }
        });

        function showList(type) {
            document.querySelectorAll('.list').forEach(l => l.style.display = 'none');
            const element = document.getElementById('list-' + type);
            if (element) {
                element.style.display = 'block';
            }
        }

        function carregarMatriculas(idTurma) {
            if (idTurma == '') {
                document.getElementById('matriculas_turma').innerHTML = '';
                return;
            }

            document.querySelectorAll('.lista-alunos-turma').forEach(el => el.style.display = 'none');

            const lista = document.getElementById('alunos-turma-' + idTurma);
            if (lista) {
                lista.style.display = 'block';
            }
        }

        function toggleOutro() {
            const motivo = document.getElementById('motivo').value;
            const divOutro = document.getElementById('motivo_outro_div');
            divOutro.style.display = (motivo === '10') ? 'block' : 'none';
        }

        function calcularCargaTotal() {
            const select = document.getElementById('id_curso_turma');
            const idCurso = select.value;
            if (!idCurso) return;

            fetch('ajax_carga_curso.php?id_curso=' + idCurso)
                .then(response => response.json())
                .then(data => {
                    document.getElementById('carga_horaria_total').value = data.carga_total || 0;
                });
        }

        function mostrarEdicao(tipo, id) {
            document.getElementById('form-editar-' + tipo + '-' + id).style.display = 'block';
            document.getElementById('display-' + tipo + '-' + id).style.display = 'none';
        }

        function ocultarEdicao(tipo, id) {
            document.getElementById('form-editar-' + tipo + '-' + id).style.display = 'none';
            document.getElementById('display-' + tipo + '-' + id).style.display = 'table-row';
        }

        function mostrarSolicitacoes(status) {
            document.querySelectorAll('.solicitacoes-detalhadas').forEach(el => el.style.display = 'none');
            const elemento = document.getElementById('solicitacoes-' + status);
            if (elemento) {
                elemento.style.display = 'block';
            }
        }

        function filtrarSolicitacoesPorTurma() {
            const turmaId = document.getElementById('filtro_turma_solicitacao').value;
            const cards = document.querySelectorAll('.solicitacao-card-instrutor');

            cards.forEach(card => {
                if (turmaId === "" || card.getAttribute('data-turma-id') === turmaId) {
                    card.style.display = 'block';
                } else {
                    card.style.display = 'none';
                }
            });
        }

        function filtrarLiberadosPorTurma() {
            const turmaId = document.getElementById('filtro_turma_liberados').value;
            const cards = document.querySelectorAll('.aluno-liberado-card');

            cards.forEach(card => {
                if (turmaId === "" || card.getAttribute('data-turma-id') === turmaId) {
                    card.style.display = 'block';
                } else {
                    card.style.display = 'none';
                }
            });
        }

        // Carrega a primeira seção ao iniciar
        window.addEventListener('DOMContentLoaded', function() {
            const firstSection = document.querySelector('.navbar-menu a');
            if (firstSection) {
                firstSection.click();
            }
        });

        function carregarTurmasPorCurso(idCurso) {
            const selectTurma = document.getElementById('turma_aluno');

            if (idCurso == '') {
                selectTurma.innerHTML = '<option value="">Primeiro selecione o curso</option>';
                return;
            }

            // Fazer requisição AJAX para o próprio index.php
            fetch('index.php?ajax=turmas_curso&id_curso=' + idCurso)
                .then(response => response.json())
                .then(data => {
                    selectTurma.innerHTML = '<option value="">Selecione a turma</option>';
                    data.forEach(turma => {
                        const option = document.createElement('option');
                        option.value = turma.id_turma;
                        option.textContent = turma.nome;
                        selectTurma.appendChild(option);
                    });
                })
                .catch(error => {
                    console.error('Erro ao carregar turmas:', error);
                    selectTurma.innerHTML = '<option value="">Erro ao carregar turmas</option>';
                });
        }
    </script>
</head>

<body>
    <nav class="navbar">
        <div class="navbar-header">
            <img src="img/senai logo.png" alt="SENAI">
            <div class="navbar-user">
                <?php echo htmlspecialchars($user['nome']); ?>
            </div>
        </div>

        <?php if ($tipo_user != 'portaria'): ?>
            <div class="navbar-menu">
                <?php if ($tipo_user == 'aluno'): ?>
                    <a href="#" onclick="showSection('solicitacao'); return false;">Nova Solicitação</a>
                    <a href="#" onclick="showSection('info'); return false;">Informações</a>
                    <a href="#" onclick="showSection('frequencia'); return false;">Minha Frequência</a>
                <?php elseif ($tipo_user == 'pedagógico'): ?>
                    <a href="#" onclick="showSection('solicitacoes'); return false;">Solicitações</a>
                    <a href="#" onclick="showSection('aguardando_responsavel'); return false;">Aguardando Responsável</a>
                    <a href="#" onclick="showSection('aguardando_portaria'); return false;">Aguardando Portaria</a>
                    <a href="#" onclick="showSection('historico_solicitacoes'); return false;">Histórico de Solicitações</a>
                    <a href="#" onclick="showSection('historico_alunos'); return false;">Histórico por Aluno</a>
                    <a href="#" onclick="showSection('cursos'); return false;">Gerenciar Cursos</a>
                    <a href="#" onclick="showSection('ucs'); return false;">Gerenciar UCs</a>
                    <a href="#" onclick="showSection('turmas'); return false;">Gerenciar Turmas</a>
                    <a href="#" onclick="showSection('gerenciar_alunos'); return false;">Gerenciar Alunos</a>
                    <a href="#" onclick="showSection('gerenciar_funcionarios'); return false;">Gerenciar Funcionários</a>

                <?php elseif ($tipo_user == 'instrutor'): ?>
                    <a href="#" onclick="showSection('turmas_instrutor'); return false;">Minhas Turmas</a>
                    <a href="#" onclick="showSection('solicitacoes_instrutor'); return false;">Solicitações</a>
                    <a href="#" onclick="showSection('alunos_liberados_instrutor'); return false;">Alunos Liberados</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <div class="navbar-footer">
            <a href="sair.php">Sair</a>
        </div>
    </nav>

    <div class="main-content">
        <?php if (!empty($msg)): ?>
            <div class="msg <?php echo $msg_type; ?>">
                <?php echo $msg; ?>
            </div>
        <?php endif; ?>
        <!-- ALUNO -->
        <?php if ($tipo_user == 'aluno'): ?>
            <div id="info" class="section">
                <div class="container">
                    <h3>Informações Pessoais</h3>
                    <p><strong>Nome:</strong> <?php echo htmlspecialchars($user['nome']); ?></p>
                    <p><strong>Matrícula:</strong> <?php echo htmlspecialchars($user['matricula']); ?></p>
                    <p><strong>CPF:</strong> <?php echo htmlspecialchars($user['cpf']); ?></p>
                    <p><strong>Celular:</strong> <?php echo htmlspecialchars($user['celular']); ?></p>
                    <p><strong>Turma:</strong> <?php echo htmlspecialchars($user['turma_nome'] ?? 'Não matriculado'); ?></p>
                    <p><strong>Curso:</strong> <?php echo htmlspecialchars($user['curso_nome'] ?? 'Não matriculado'); ?></p>
                    <?php if (!empty($user['contato_responsavel'])): ?>
                        <p><strong>Contato Responsável:</strong> <?php echo htmlspecialchars($user['contato_responsavel']); ?></p>
                    <?php endif; ?>
                    <?php if (!empty($user['empresa'])): ?>
                        <p><strong>Empresa:</strong> <?php echo htmlspecialchars($user['empresa']); ?></p>
                    <?php endif; ?>
                </div>
            </div>

            <div id="solicitacao" class="section" style="display:none;">
                <div class="container">
                    <h3>Nova Solicitação de Saída</h3>
                    <form method="POST">
                        <label>Curso:</label>
                        <input type="text" value="<?php echo htmlspecialchars($user['curso_nome'] ?? 'Não matriculado'); ?>" readonly>

                        <label>Unidade Curricular:</label>
                        <select name="id_curricular" required>
                            <option value="">Selecione uma UC</option>
                            <?php foreach ($ucs as $uc): ?>
                                <option value="<?php echo $uc['id_curricular']; ?>"><?php echo htmlspecialchars($uc['nome']); ?></option>
                            <?php endforeach; ?>
                        </select>

                        <label>Turno:</label>
                        <select name="turno" required>
                            <option value="">Selecione o turno</option>
                            <option value="manhã">Manhã</option>
                            <option value="tarde">Tarde</option>
                            <option value="noite">Noite</option>
                        </select>

                        <label>Modalidade:</label>
                        <select name="modalidade" required>
                            <option value="">Selecione a modalidade</option>
                            <option value="presencial">Presencial</option>
                            <option value="EAD">EAD</option>
                            <option value="dual">Dual</option>
                        </select>

                        <label>Turma:</label>
                        <input type="text" value="<?php echo htmlspecialchars($user['turma_nome'] ?? 'Não matriculado'); ?>" readonly>

                        <label>Data da Solicitação:</label>
                        <input type="text" value="<?php echo date('d/m/Y'); ?>" readonly>

                        <label>Hora da Solicitação:</label>
                        <input type="text" value="<?php echo date('H:i'); ?>" readonly>

                        <label>Motivo da Saída:</label>
                        <select name="motivo" id="motivo" required onchange="toggleOutro()">
                            <option value="">Selecione o motivo</option>
                            <option value="1">Consulta médica</option>
                            <option value="2">Consulta odontológica</option>
                            <option value="3">Exames médicos</option>
                            <option value="4">Problemas de saúde</option>
                            <option value="5">Solicitação da empresa</option>
                            <option value="6">Solicitação da família</option>
                            <option value="7">Viagem particular</option>
                            <option value="8">Viagem a trabalho</option>
                            <option value="9">Treinamento a trabalho</option>
                            <option value="10">Outro</option>
                        </select>

                        <div id="motivo_outro_div" style="display: none;">
                            <label>Descreva o motivo:</label>
                            <textarea name="motivo_outro" rows="3" placeholder="Descreva detalhadamente o motivo da saída"></textarea>
                        </div>

                        <button type="submit" name="solicitar_saida">Enviar Solicitação</button>
                    </form>
                </div>
            </div>

            <div id="frequencia" class="section" style="display:none;">
                <div class="container">
                    <h3>Minha Frequência</h3>
                    <p>Sistema de frequência em desenvolvimento.</p>
                </div>
            </div>
        <?php endif; ?>

        <!-- PEDAGÓGICO -->
        <?php if ($tipo_user == 'pedagógico'): ?>
            <?php
            $motivos_map = [
                '1' => 'Consulta médica',
                '2' => 'Consulta odontológica',
                '3' => 'Exames médicos',
                '4' => 'Problemas de saúde',
                '5' => 'Solicitação da empresa',
                '6' => 'Solicitação da família',
                '7' => 'Viagem particular',
                '8' => 'Viagem a trabalho',
                '9' => 'Treinamento a trabalho'
            ];
            ?>

            <!-- SOLICITAÇÕES -->
            <div id="solicitacoes" class="section">
                <div class="container">
                    <h3>Todas as Solicitações Pendentes</h3>
                    <?php
                    $todas_solicitacoes = $conn->query("
                    SELECT s.*, a.nome as aluno_nome, a.matricula, t.nome as turma_nome,
                           DATE_FORMAT(s.data_solicitada, '%d/%m/%Y %H:%i') as data_solicitada_fmt
                    FROM solicitacao s
                    JOIN aluno a ON s.id_aluno = a.id_aluno
                    LEFT JOIN matricula m ON a.id_aluno = m.id_aluno
                    LEFT JOIN turma t ON m.id_turma = t.id_turma
                    WHERE (
                        (s.status = 'solicitado' AND s.motivo LIKE '%aguardando_pedagogico%')
                        OR (s.status = 'autorizado' AND s.motivo LIKE '%aguardando_pedagogico%')
                    )
                    ORDER BY s.data_solicitada DESC
                ")->fetchAll();

                    if (empty($todas_solicitacoes)): ?>
                        <p>Nenhuma solicitação pendente no momento.</p>
                    <?php else: ?>
                        <?php foreach ($todas_solicitacoes as $s):
                            $parsed = parseMotivo($s['motivo']);
                            $motivo_texto = (is_numeric($parsed['motivo']) && isset($motivos_map[$parsed['motivo']])) ? $motivos_map[$parsed['motivo']] : $parsed['motivo'];
                            $status_texto = getStatusText($s['status'], $s['motivo']);
                            $status_cor = getStatusColor($s['status'], $s['motivo']);
                        ?>
                            <div class="solicitacao-card" style="border-left: 4px solid <?php echo $status_cor; ?>;">
                                <h4><?php echo htmlspecialchars($s['aluno_nome']); ?> (<?php echo htmlspecialchars($s['matricula']); ?>)</h4>
                                <p><strong>Turma:</strong> <?php echo htmlspecialchars($s['turma_nome'] ?? 'N/A'); ?></p>
                                <p><strong>Motivo:</strong> <?php echo htmlspecialchars($motivo_texto); ?></p>
                                <p><strong>Data Solicitação:</strong> <?php echo $s['data_solicitada_fmt']; ?></p>
                                <p><strong>Status:</strong> <span style="color: <?php echo $status_cor; ?>; font-weight: bold;"><?php echo $status_texto; ?></span></p>
                            </div>
                            <div class="action-buttons">
                                <form method="POST" style="display: inline;">
                                    <input type="hidden" name="id_solicitacao" value="<?php echo $s['id_solicitacao']; ?>">
                                    <input type="hidden" name="acao" value="autorizar">
                                    <button type="submit" name="autorizar_saida" style="padding: 8px 16px; background: #17a2b8; color: white; border: none; border-radius: 5px; cursor: pointer;">Aceitar</button>
                                </form>
                                <form method="POST" style="display: inline;">
                                    <input type="hidden" name="id_solicitacao" value="<?php echo $s['id_solicitacao']; ?>">
                                    <input type="hidden" name="acao" value="rejeitar">
                                    <button type="submit" name="autorizar_saida" style="padding: 8px 16px; background: #6c757d; color: white; border: none; border-radius: 5px; cursor: pointer;">Recusar</button>
                                </form>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- AGUARDANDO RESPONSÁVEL -->
            <div id="aguardando_responsavel" class="section" style="display:none;">
                <div class="container">
                    <h3>Aguardando Responsável</h3>
                    <?php
                    $resp = $conn->query("
                        SELECT s.*, a.nome as aluno_nome, a.matricula, t.nome as turma_nome,
                               DATE_FORMAT(s.data_solicitada, '%d/%m/%Y %H:%i') as data_solicitada_fmt
                        FROM solicitacao s
                        JOIN aluno a ON s.id_aluno = a.id_aluno
                        LEFT JOIN matricula m ON a.id_aluno = m.id_aluno
                        LEFT JOIN turma t ON m.id_turma = t.id_turma
                        WHERE s.status = 'autorizado' AND s.motivo LIKE '%STATUS:aguardando_responsavel%'
                        ORDER BY s.data_solicitada DESC
                    ")->fetchAll();

                    if (empty($resp)): ?>
                        <p>Nenhuma solicitação aguardando responsável.</p>
                    <?php else: ?>
                        <?php foreach ($resp as $s):
                            $parsed = parseMotivo($s['motivo']);
                            $motivo_texto = (is_numeric($parsed['motivo']) && isset($motivos_map[$parsed['motivo']])) ? $motivos_map[$parsed['motivo']] : $parsed['motivo'];
                        ?>
                            <div class="solicitacao-card">
                                <h4><?php echo htmlspecialchars($s['aluno_nome']); ?> (<?php echo htmlspecialchars($s['matricula']); ?>)</h4>
                                <p><strong>Turma:</strong> <?php echo htmlspecialchars($s['turma_nome'] ?? 'N/A'); ?></p>
                                <p><strong>Motivo:</strong> <?php echo htmlspecialchars($motivo_texto); ?></p>
                                <p><strong>Data Solicitação:</strong> <?php echo $s['data_solicitada_fmt']; ?></p>

                                <div class="action-buttons">
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="id_solicitacao" value="<?php echo $s['id_solicitacao']; ?>">
                                        <input type="hidden" name="acao" value="autorizar">
                                        <button type="submit" name="autorizar_saida" style="padding: 8px 16px; background: #17a2b8; color: white; border: none; border-radius: 5px; cursor: pointer;">Responsável Aceitou</button>
                                    </form>
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="id_solicitacao" value="<?php echo $s['id_solicitacao']; ?>">
                                        <input type="hidden" name="acao" value="rejeitar">
                                        <button type="submit" name="autorizar_saida" style="padding: 8px 16px; background: #6c757d; color: white; border: none; border-radius: 5px; cursor: pointer;">Responsável Recusou</button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- AGUARDANDO PORTARIA -->
            <div id="aguardando_portaria" class="section" style="display:none;">
                <div class="container">
                    <h3>Aguardando Portaria</h3>
                    <?php
                    $columns = $conn->query("SHOW COLUMNS FROM aluno")->fetchAll(PDO::FETCH_COLUMN);
                    $port = $conn->query("
                        SELECT s.*, a.nome as aluno_nome, a.matricula, t.nome as turma_nome,
                               a.contato_responsavel,
                               " . (in_array('nome_responsavel', $columns) ? "a.nome_responsavel," : "") . "
                               " . (in_array('empresa', $columns) ? "a.empresa," : "") . "
                               " . (in_array('contato_empresa', $columns) ? "a.contato_empresa," : "") . "
                               DATE_FORMAT(s.data_solicitada, '%d/%m/%Y %H:%i') as data_solicitada_fmt
                        FROM solicitacao s
                        JOIN aluno a ON s.id_aluno = a.id_aluno
                        LEFT JOIN matricula m ON a.id_aluno = m.id_aluno
                        LEFT JOIN turma t ON m.id_turma = t.id_turma
                        WHERE s.status = 'liberado' AND s.motivo LIKE '%STATUS:aguardando_portaria%'
                        ORDER BY s.data_solicitada DESC
                    ")->fetchAll();

                    if (empty($port)): ?>
                        <p>Nenhuma solicitação aguardando portaria.</p>
                    <?php else: ?>
                        <?php foreach ($port as $s):
                            $parsed = parseMotivo($s['motivo']);
                            $motivo_texto = (is_numeric($parsed['motivo']) && isset($motivos_map[$parsed['motivo']])) ? $motivos_map[$parsed['motivo']] : $parsed['motivo'];
                        ?>
                            <div class="solicitacao-card">
                                <h4><?php echo htmlspecialchars($s['aluno_nome']); ?> (<?php echo htmlspecialchars($s['matricula']); ?>)</h4>
                                <p><strong>Turma:</strong> <?php echo htmlspecialchars($s['turma_nome'] ?? 'N/A'); ?></p>
                                <p><strong>Motivo:</strong> <?php echo htmlspecialchars($motivo_texto); ?></p>
                                <p><strong>Data Solicitação:</strong> <?php echo $s['data_solicitada_fmt']; ?></p>
                                <p><strong>Código:</strong> <?php echo htmlspecialchars($parsed['codigo'] ?? 'N/A'); ?></p>

                                <?php if (!empty($s['nome_responsavel']) || !empty($s['contato_responsavel'])): ?>
                                    <p><strong>Responsável:</strong>
                                        <?php echo htmlspecialchars($s['nome_responsavel'] ?? 'N/A'); ?>
                                        <?php if (!empty($s['contato_responsavel'])): ?>
                                            - <?php echo htmlspecialchars($s['contato_responsavel']); ?>
                                        <?php endif; ?>
                                    </p>
                                <?php endif; ?>

                                <?php if (!empty($s['empresa']) || !empty($s['contato_empresa'])): ?>
                                    <p><strong>Empresa:</strong>
                                        <?php echo htmlspecialchars($s['empresa'] ?? 'N/A'); ?>
                                        <?php if (!empty($s['contato_empresa'])): ?>
                                            - <?php echo htmlspecialchars($s['contato_empresa']); ?>
                                        <?php endif; ?>
                                    </p>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- HISTÓRICO DE SOLICITAÇÕES-->
            <div id="historico_solicitacoes" class="section" style="display:none;">
                <div class="container">
                    <h3 style="text-align:center; margin-bottom:30px; color:#004a8f;">Histórico de Solicitações</h3>

                    <div class="tabs-container">
                        <button class="tab-btn" onclick="mostrarHistoricoTab('liberadas')">Liberadas</button>
                        <button class="tab-btn" onclick="mostrarHistoricoTab('recusadas')">Recusadas</button>
                        <button class="tab-btn" onclick="mostrarHistoricoTab('todas')">Todas</button>
                    </div>

                    <script>
                        function mostrarHistoricoTab(tipo) {
                            document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
                            event.target.classList.add('active');
                            document.querySelectorAll('.historico-content').forEach(el => el.classList.remove('active'));
                            document.getElementById('historico-' + tipo).classList.add('active');
                        }
                    </script>

                    <!-- LIBERADAS -->
                    <div id="historico-liberadas" class="historico-content active">
                        <h4 style="text-align:center; margin-bottom:20px; color:#28a745;">Solicitações Liberadas</h4>
                        <?php
                        $columns = $conn->query("SHOW COLUMNS FROM aluno")->fetchAll(PDO::FETCH_COLUMN);
                        $select_fields = "s.*, a.nome as aluno_nome, a.matricula, t.nome as turma_nome, a.contato_responsavel";

                        if (in_array('nome_responsavel', $columns)) {
                            $select_fields .= ", a.nome_responsavel";
                        }
                        if (in_array('empresa', $columns)) {
                            $select_fields .= ", a.empresa";
                        }
                        if (in_array('contato_empresa', $columns)) {
                            $select_fields .= ", a.contato_empresa";
                        }

                        $liberadas = $conn->query("
                           SELECT $select_fields,
                                  DATE_FORMAT(s.data_solicitada, '%d/%m/%Y %H:%i') as data_solicitada_fmt,
                                  DATE_FORMAT(s.data_saida, '%d/%m/%Y %H:%i') as data_saida_fmt
                           FROM solicitacao s
                           JOIN aluno a ON s.id_aluno = a.id_aluno
                           LEFT JOIN matricula m ON a.id_aluno = m.id_aluno
                           LEFT JOIN turma t ON m.id_turma = t.id_turma
                           WHERE (s.status = 'liberado' OR s.status = 'concluido')
                           ORDER BY s.data_solicitada DESC
                       ")->fetchAll();

                        if (empty($liberadas)): ?>
                            <p style="text-align:center;">Nenhuma solicitação liberada.</p>
                            <?php else:
                            $motivos_map = [
                                '1' => 'Consulta médica',
                                '2' => 'Consulta odontológica',
                                '3' => 'Exames médicos',
                                '4' => 'Problemas de saúde',
                                '5' => 'Solicitação da empresa',
                                '6' => 'Solicitação da família',
                                '7' => 'Viagem particular',
                                '8' => 'Viagem a trabalho',
                                '9' => 'Treinamento a trabalho'
                            ];

                            foreach ($liberadas as $s):
                                $parsed = parseMotivo($s['motivo']);
                                $motivo_texto = (is_numeric($parsed['motivo']) && isset($motivos_map[$parsed['motivo']]))
                                    ? $motivos_map[$parsed['motivo']] : $parsed['motivo'];
                                $status_texto = getStatusText($s['status'], $s['motivo']);
                            ?>
                                <div class="solicitacao-card" style="border-left: 4px solid #28a745;">
                                    <h4><?php echo htmlspecialchars($s['aluno_nome']); ?> (<?php echo htmlspecialchars($s['matricula']); ?>)</h4>
                                    <div class="solicitacao-info">
                                        <div class="info-item">
                                            <strong>Turma:</strong>
                                            <?php echo htmlspecialchars($s['turma_nome'] ?? 'N/A'); ?>
                                        </div>
                                        <div class="info-item">
                                            <strong>Motivo:</strong>
                                            <?php echo htmlspecialchars($motivo_texto); ?>
                                        </div>
                                        <div class="info-item">
                                            <strong>Data Solicitação:</strong>
                                            <?php echo $s['data_solicitada_fmt']; ?>
                                        </div>
                                        <?php if (!empty($parsed['codigo'])): ?>
                                            <div class="info-item">
                                                <strong>Código:</strong>
                                                <?php echo htmlspecialchars($parsed['codigo']); ?>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($s['status'] == 'concluido' && !empty($s['data_saida_fmt'])): ?>
                                            <div class="info-item">
                                                <strong>Saída Registrada:</strong>
                                                <?php echo $s['data_saida_fmt']; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <p style="margin-top:10px;"><strong>Status:</strong>
                                        <span style="color: #28a745; font-weight: bold;"><?php echo $status_texto; ?></span>
                                    </p>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>

                    <!-- RECUSADAS -->
                    <div id="historico-recusadas" class="historico-content">
                        <h4 style="text-align:center; margin-bottom:20px; color:#dc3545;">Solicitações Recusadas</h4>
                        <?php
                        $recusadas = $conn->query("
                SELECT s.*, a.nome as aluno_nome, a.matricula, t.nome as turma_nome,
                       DATE_FORMAT(s.data_solicitada, '%d/%m/%Y %H:%i') as data_solicitada_fmt
                FROM solicitacao s
                JOIN aluno a ON s.id_aluno = a.id_aluno
                LEFT JOIN matricula m ON a.id_aluno = m.id_aluno
                LEFT JOIN turma t ON m.id_turma = t.id_turma
                WHERE s.status = 'rejeitada'
                ORDER BY s.data_solicitada DESC
            ")->fetchAll();

                        if (empty($recusadas)): ?>
                            <p style="text-align:center;">Nenhuma solicitação recusada.</p>
                            <?php else:
                            foreach ($recusadas as $s):
                                $parsed = parseMotivo($s['motivo']);
                                $motivo_texto = (is_numeric($parsed['motivo']) && isset($motivos_map[$parsed['motivo']]))
                                    ? $motivos_map[$parsed['motivo']] : $parsed['motivo'];
                                $status_texto = getStatusText($s['status'], $s['motivo']);
                                $quem_recusou = 'N/A';
                                if (strpos($s['motivo'], 'recusado_instrutor') !== false) $quem_recusou = 'Instrutor';
                                elseif (strpos($s['motivo'], 'recusado_pedagogico') !== false) $quem_recusou = 'Pedagógico';
                                elseif (strpos($s['motivo'], 'recusado_responsavel') !== false) $quem_recusou = 'Responsável';
                            ?>
                                <div class="solicitacao-card" style="border-left: 4px solid #dc3545;">
                                    <h4><?php echo htmlspecialchars($s['aluno_nome']); ?> (<?php echo htmlspecialchars($s['matricula']); ?>)</h4>
                                    <div class="solicitacao-info">
                                        <div class="info-item">
                                            <strong>Turma:</strong>
                                            <?php echo htmlspecialchars($s['turma_nome'] ?? 'N/A'); ?>
                                        </div>
                                        <div class="info-item">
                                            <strong>Motivo:</strong>
                                            <?php echo htmlspecialchars($motivo_texto); ?>
                                        </div>
                                        <div class="info-item">
                                            <strong>Recusado por:</strong>
                                            <?php echo $quem_recusou; ?>
                                        </div>
                                        <div class="info-item">
                                            <strong>Data:</strong>
                                            <?php echo $s['data_solicitada_fmt']; ?>
                                        </div>
                                    </div>
                                    <p style="margin-top:10px;"><strong>Status:</strong>
                                        <span style="color: #dc3545; font-weight: bold;"><?php echo $status_texto; ?></span>
                                    </p>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>

                    <!-- TODAS -->
                    <div id="historico-todas" class="historico-content">
                        <h4 style="text-align:center; margin-bottom:20px;">Todas as Solicitações</h4>
                        <?php
                        $todas = $conn->query("
                SELECT s.*, a.nome as aluno_nome, a.matricula, t.nome as turma_nome,
                       DATE_FORMAT(s.data_solicitada, '%d/%m/%Y %H:%i') as data_solicitada_fmt
                FROM solicitacao s
                JOIN aluno a ON s.id_aluno = a.id_aluno
                LEFT JOIN matricula m ON a.id_aluno = m.id_aluno
                LEFT JOIN turma t ON m.id_turma = t.id_turma
                ORDER BY s.data_solicitada DESC
                LIMIT 50
            ")->fetchAll();

                        foreach ($todas as $s):
                            $parsed = parseMotivo($s['motivo']);
                            $motivo_texto = (is_numeric($parsed['motivo']) && isset($motivos_map[$parsed['motivo']]))
                                ? $motivos_map[$parsed['motivo']] : $parsed['motivo'];
                            $status_cor = getStatusColor($s['status'], $s['motivo']);
                            $status_texto = getStatusText($s['status'], $s['motivo']);
                        ?>
                            <div class="solicitacao-card" style="border-left: 4px solid <?php echo $status_cor; ?>;">
                                <h4><?php echo htmlspecialchars($s['aluno_nome']); ?> (<?php echo htmlspecialchars($s['matricula']); ?>)</h4>
                                <div class="solicitacao-info">
                                    <div class="info-item">
                                        <strong>Turma:</strong>
                                        <?php echo htmlspecialchars($s['turma_nome'] ?? 'N/A'); ?>
                                    </div>
                                    <div class="info-item">
                                        <strong>Motivo:</strong>
                                        <?php echo htmlspecialchars($motivo_texto); ?>
                                    </div>
                                    <div class="info-item">
                                        <strong>Data:</strong>
                                        <?php echo $s['data_solicitada_fmt']; ?>
                                    </div>
                                    <div class="info-item">
                                        <strong>Status:</strong>
                                        <span style="color: <?php echo $status_cor; ?>; font-weight: bold;">
                                            <?php echo $status_texto; ?>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <!-- HISTÓRICO POR ALUNO -->
            <div id="historico_alunos" class="section" style="display:none;">
                <div class="container">
                    <h3 style="text-align:center; margin-bottom:30px; color:#004a8f;">Histórico por Aluno/Turma</h3>

                    <div class="filtros-container">
                        <select id="filtro_turma_historico" onchange="filtrarHistoricoAlunos()">
                            <option value="">Todas as Turmas</option>
                            <?php foreach ($turmas as $t): ?>
                                <option value="<?php echo $t['id_turma']; ?>">
                                    <?php echo htmlspecialchars($t['nome'] . ' - ' . $t['curso_nome']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <select id="filtro_status_historico" onchange="filtrarHistoricoAlunos()">
                            <option value="">Todos os Status</option>
                            <option value="liberado,concluido">Liberados</option>
                            <option value="rejeitada">Recusados</option>
                        </select>
                    </div>

                    <script>
                        function filtrarHistoricoAlunos() {
                            const turmaId = document.getElementById('filtro_turma_historico').value;
                            const status = document.getElementById('filtro_status_historico').value;
                            const cards = document.querySelectorAll('.historico-aluno-card');

                            cards.forEach(card => {
                                const cardTurma = card.getAttribute('data-turma-id');
                                const cardStatus = card.getAttribute('data-status');

                                let mostrarTurma = (turmaId === "" || cardTurma === turmaId);
                                let mostrarStatus = true;

                                if (status !== "") {
                                    const statusArray = status.split(',');
                                    mostrarStatus = statusArray.includes(cardStatus);
                                }

                                card.style.display = (mostrarTurma && mostrarStatus) ? 'block' : 'none';
                            });
                        }
                    </script>

                    <?php
                    $historico_alunos = $conn->query("
            SELECT s.*, a.nome as aluno_nome, a.matricula,
                   t.id_turma, t.nome as turma_nome,
                   f.nome as instrutor_nome,
                   DATE_FORMAT(s.data_solicitada, '%d/%m/%Y %H:%i') as data_solicitada_fmt,
                   DATE_FORMAT(s.data_autorizada, '%d/%m/%Y %H:%i') as hora_instrutor,
                   DATE_FORMAT(s.data_saida, '%d/%m/%Y %H:%i') as hora_saida
            FROM solicitacao s
            JOIN aluno a ON s.id_aluno = a.id_aluno
            LEFT JOIN matricula m ON a.id_aluno = m.id_aluno
            LEFT JOIN turma t ON m.id_turma = t.id_turma
            LEFT JOIN funcionario f ON s.id_autorizacao = f.id_funcionario
            WHERE s.status IN ('liberado', 'concluido', 'rejeitada')
            ORDER BY s.data_solicitada DESC
        ")->fetchAll();

                    if (empty($historico_alunos)): ?>
                        <p style="text-align:center;">Nenhum registro encontrado.</p>
                        <?php else:
                        $motivos_map = [
                            '1' => 'Consulta médica',
                            '2' => 'Consulta odontológica',
                            '3' => 'Exames médicos',
                            '4' => 'Problemas de saúde',
                            '5' => 'Solicitação da empresa',
                            '6' => 'Solicitação da família',
                            '7' => 'Viagem particular',
                            '8' => 'Viagem a trabalho',
                            '9' => 'Treinamento a trabalho'
                        ];

                        foreach ($historico_alunos as $al):
                            $parsed = parseMotivo($al['motivo']);
                            $motivo_texto = (is_numeric($parsed['motivo']) && isset($motivos_map[$parsed['motivo']]))
                                ? $motivos_map[$parsed['motivo']] : $parsed['motivo'];
                            $status_texto = getStatusText($al['status'], $al['motivo']);
                            $status_cor = getStatusColor($al['status'], $al['motivo']);

                            $quem_recusou = '';
                            if ($al['status'] == 'rejeitada') {
                                if (strpos($al['motivo'], 'recusado_instrutor') !== false) $quem_recusou = 'Instrutor';
                                elseif (strpos($al['motivo'], 'recusado_pedagogico') !== false) $quem_recusou = 'Pedagógico';
                                elseif (strpos($al['motivo'], 'recusado_responsavel') !== false) $quem_recusou = 'Responsável';
                            }
                        ?>
                            <div class="solicitacao-card historico-aluno-card"
                                data-turma-id="<?php echo $al['id_turma']; ?>"
                                data-status="<?php echo $al['status']; ?>"
                                style="border-left: 4px solid <?php echo $status_cor; ?>;">
                                <div class="solicitacao-header">
                                    <h4><?php echo htmlspecialchars($al['aluno_nome']); ?> (<?php echo htmlspecialchars($al['matricula']); ?>)</h4>
                                    <span class="status-badge" style="background: <?php echo $status_cor; ?>;">
                                        <?php echo $status_texto; ?>
                                    </span>
                                </div>

                                <div class="solicitacao-info">
                                    <div class="info-item">
                                        <strong>Turma:</strong>
                                        <?php echo htmlspecialchars($al['turma_nome'] ?? 'N/A'); ?>
                                    </div>
                                    <div class="info-item">
                                        <strong>Motivo:</strong>
                                        <?php echo htmlspecialchars($motivo_texto); ?>
                                    </div>
                                    <div class="info-item">
                                        <strong>Data Solicitação:</strong>
                                        <?php echo $al['data_solicitada_fmt']; ?>
                                    </div>

                                    <?php if ($al['status'] != 'rejeitada'): ?>
                                        <div class="info-item">
                                            <strong>Instrutor:</strong>
                                            <?php echo htmlspecialchars($al['instrutor_nome'] ?? 'N/A'); ?>
                                        </div>
                                        <?php if (!empty($al['hora_instrutor'])): ?>
                                            <div class="info-item">
                                                <strong>Liberado em:</strong>
                                                <?php echo $al['hora_instrutor']; ?>
                                            </div>
                                        <?php endif; ?>
                                        <?php if (!empty($parsed['codigo'])): ?>
                                            <div class="info-item">
                                                <strong>Código:</strong>
                                                <?php echo htmlspecialchars($parsed['codigo']); ?>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($al['status'] == 'concluido' && !empty($al['hora_saida'])): ?>
                                            <div class="info-item">
                                                <strong>Saída Portaria:</strong>
                                                <?php echo $al['hora_saida']; ?>
                                            </div>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <div class="info-item">
                                            <strong>Recusado por:</strong>
                                            <?php echo $quem_recusou; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- GERENCIAR CURSOS -->
            <div id="cursos" class="section" style="display:none;">
                <div class="container">
                    <h3>Gerenciar Cursos</h3>
                    <form method="POST" style="margin-bottom: 20px;">
                        <input type="text" name="nome_curso" placeholder="Nome do curso" required>
                        <button type="submit" name="cadastrar_curso">Cadastrar Curso</button>
                    </form>
                    <table>
                        <thead>
                            <tr>
                                <th>Nome do Curso</th>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($cursos as $c): ?>
                                <tr id="display-curso-<?php echo $c['id_curso']; ?>">
                                    <td><?php echo htmlspecialchars($c['nome']); ?></td>
                                    <td>
                                        <button onclick="mostrarEdicao('curso', <?php echo $c['id_curso']; ?>)" style="background: #007bff; color: white; padding: 5px 10px; border: none; border-radius: 3px; cursor: pointer;">Editar</button>
                                        <form method="POST" style="display:inline;">
                                            <input type="hidden" name="id_curso" value="<?php echo $c['id_curso']; ?>">
                                            <button type="submit" name="deletar_curso" onclick="return confirm('Deseja excluir este curso?')" style="background: #dc3545; color: white; padding: 5px 10px; border: none; border-radius: 3px; cursor: pointer;">Excluir</button>
                                        </form>
                                    </td>
                                </tr>
                                <tr id="form-editar-curso-<?php echo $c['id_curso']; ?>" style="display:none;">
                                    <td colspan="2">
                                        <form method="POST" style="display:flex; gap:10px; align-items:center;">
                                            <input type="hidden" name="id_curso" value="<?php echo $c['id_curso']; ?>">
                                            <input type="text" name="nome_curso" value="<?php echo htmlspecialchars($c['nome']); ?>" required style="flex:1;">
                                            <button type="submit" name="editar_curso" style="background: #28a745; color: white; padding: 5px 10px; border: none; border-radius: 3px; cursor: pointer;">Salvar</button>
                                            <button type="button" onclick="ocultarEdicao('curso', <?php echo $c['id_curso']; ?>)" style="background: #6c757d; color: white; padding: 5px 10px; border: none; border-radius: 3px; cursor: pointer;">Cancelar</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- GERENCIAR UCs -->
            <div id="ucs" class="section" style="display:none;">
                <div class="container">
                    <h3>Gerenciar Unidades Curriculares</h3>
                    <form method="POST" style="margin-bottom: 20px;">
                        <select name="id_curso" required>
                            <option value="">Selecione o curso</option>
                            <?php foreach ($cursos as $c): ?>
                                <option value="<?php echo $c['id_curso']; ?>"><?php echo htmlspecialchars($c['nome']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <input type="text" name="nome_uc" placeholder="Nome da UC" required>
                        <input type="number" name="carga_horaria" placeholder="Carga horária" required min="1">
                        <button type="submit" name="cadastrar_uc">Cadastrar UC</button>
                    </form>
                    <table>
                        <thead>
                            <tr>
                                <th>Curso</th>
                                <th>Nome da UC</th>
                                <th>Carga Horária</th>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($ucs as $uc): ?>
                                <tr id="display-uc-<?php echo $uc['id_curricular']; ?>">
                                    <td><?php echo htmlspecialchars($uc['curso_nome']); ?></td>
                                    <td><?php echo htmlspecialchars($uc['nome']); ?></td>
                                    <td><?php echo $uc['carga_horaria']; ?>h</td>
                                    <td>
                                        <button onclick="mostrarEdicao('uc', <?php echo $uc['id_curricular']; ?>)" style="background: #007bff; color: white; padding: 5px 10px; border: none; border-radius: 3px; cursor: pointer;">Editar</button>
                                        <form method="POST" style="display:inline;">
                                            <input type="hidden" name="id_curricular" value="<?php echo $uc['id_curricular']; ?>">
                                            <button type="submit" name="deletar_uc" onclick="return confirm('Deseja excluir esta UC?')" style="background: #dc3545; color: white; padding: 5px 10px; border: none; border-radius: 3px; cursor: pointer;">Excluir</button>
                                        </form>
                                    </td>
                                </tr>
                                <tr id="form-editar-uc-<?php echo $uc['id_curricular']; ?>" style="display:none;">
                                    <td colspan="4">
                                        <form method="POST" style="display:flex; gap:10px; align-items:center;">
                                            <input type="hidden" name="id_curricular" value="<?php echo $uc['id_curricular']; ?>">
                                            <input type="text" name="nome_uc" value="<?php echo htmlspecialchars($uc['nome']); ?>" required style="flex:2;">
                                            <input type="number" name="carga_horaria" value="<?php echo $uc['carga_horaria']; ?>" required min="1" style="flex:1;">
                                            <button type="submit" name="editar_uc" style="background: #28a745; color: white; padding: 5px 10px; border: none; border-radius: 3px; cursor: pointer;">Salvar</button>
                                            <button type="button" onclick="ocultarEdicao('uc', <?php echo $uc['id_curricular']; ?>)" style="background: #6c757d; color: white; padding: 5px 10px; border: none; border-radius: 3px; cursor: pointer;">Cancelar</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- GERENCIAR TURMAS -->
            <div id="turmas" class="section" style="display:none;">
                <div class="container">
                    <h3>Gerenciar Turmas</h3>
                    <div class="form-cadastro-centralizado">
                        <form method="POST">
                            <select name="id_curso_turma" id="id_curso_turma" required onchange="calcularCargaTotal()">
                                <option value="">Selecione o curso</option>
                                <?php foreach ($cursos as $c): ?>
                                    <option value="<?php echo $c['id_curso']; ?>">
                                        <?php echo htmlspecialchars($c['nome']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>

                            <input type="text" name="nome_turma" placeholder="Nome da turma" required>

                            <input type="number" name="carga_horaria_total" id="carga_horaria_total"
                                placeholder="Carga horária total" style="background:#f0f0f0;">

                            <button type="submit" name="cadastrar_turma">Cadastrar Turma</button>
                        </form>
                    </div>
                    <h4 style="text-align:center; margin: 30px 0 20px 0; color:#004a8f;">
                        Lista de Turmas Cadastradas
                    </h4>

                    <table>
                        <thead>
                            <tr>
                                <th>Curso</th>
                                <th>Nome da Turma</th>
                                <th>Carga Horária Total</th>
                                <th>Alunos Matriculados</th>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($turmas as $t): ?>
                                <tr id="display-turma-<?php echo $t['id_turma']; ?>">
                                    <td><?php echo htmlspecialchars($t['curso_nome']); ?></td>
                                    <td><?php echo htmlspecialchars($t['nome']); ?></td>
                                    <td><?php echo $t['carga_horaria_total'] ?? 0; ?>h</td>
                                    <td>
                                        <?php
                                        echo $conn->query("SELECT COUNT(*) as total FROM matricula WHERE id_turma = " . $t['id_turma'])->fetch()['total'];
                                        ?>
                                    </td>
                                    <td>
                                        <button onclick="mostrarEdicao('turma', <?php echo $t['id_turma']; ?>)"
                                            style="background: #007bff; color: white; padding: 5px 10px; border: none; border-radius: 3px; cursor: pointer;">
                                            Editar
                                        </button>
                                        <form method="POST" style="display:inline;">
                                            <input type="hidden" name="id_turma" value="<?php echo $t['id_turma']; ?>">
                                            <button type="submit" name="deletar_turma"
                                                onclick="return confirm('Deseja excluir esta turma?')"
                                                style="background: #dc3545; color: white; padding: 5px 10px; border: none; border-radius: 3px; cursor: pointer;">
                                                Excluir
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                                <tr id="form-editar-turma-<?php echo $t['id_turma']; ?>" style="display:none;">
                                    <td colspan="5">
                                        <form method="POST" style="display:flex; gap:10px; align-items:center;">
                                            <input type="hidden" name="id_turma" value="<?php echo $t['id_turma']; ?>">
                                            <input type="text" name="nome_turma"
                                                value="<?php echo htmlspecialchars($t['nome']); ?>"
                                                placeholder="Nome da Turma"
                                                required style="flex:2; color: black; border-radius: 3px;">
                                            <input type="number" name="carga_horaria_total"
                                                value="<?php echo $t['carga_horaria_total'] ?? 0; ?>"
                                                placeholder="Carga Horária"
                                                required min="0" style="flex:1; color: black; border-radius: 3px;">
                                            <button type="submit" name="editar_turma"
                                                style="background: #28a745; color: white; padding: 8px 16px; border: none; border-radius: 3px; cursor: pointer; width: auto;">
                                                Salvar
                                            </button>
                                            <button type="button" onclick="ocultarEdicao('turma', <?php echo $t['id_turma']; ?>)"
                                                style="background: #6c757d; color: white; padding: 8px 16px; border: none; border-radius: 3px; cursor: pointer; width: auto;">
                                                Cancelar
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
    </div>
<?php endif; ?>

<!-- GERENCIAR ALUNOS -->
<div id="gerenciar_alunos" class="section" style="display:none;">
    <div class="container">
        <div class="form-cadastro-centralizado">
            <h4 style="text-align:center; margin-bottom:20px;">Cadastrar Novo Aluno</h4>
            <form method="POST">
                <div class="form-row">
                    <input type="text" name="nome" placeholder="Nome Completo"
                        value="<?= isset($dados_formulario['nome']) ? htmlspecialchars($dados_formulario['nome']) : '' ?>" required>

                    <input type="text" name="matricula" placeholder="Matrícula"
                        value="<?= isset($dados_formulario['matricula']) ? htmlspecialchars($dados_formulario['matricula']) : '' ?>" required>
                </div>

                <div class="form-row">
                    <input type="text" name="cpf" placeholder="CPF" onkeyup="formatarCPFInput(this)"
                        maxlength="14" value="<?= isset($dados_formulario['cpf']) ? htmlspecialchars($dados_formulario['cpf']) : '' ?>" required>

                    <input type="date" name="data_nascimento" placeholder="Data de Nascimento"
                        value="<?= isset($dados_formulario['data_nascimento']) ? htmlspecialchars($dados_formulario['data_nascimento']) : '' ?>" required>
                </div>

                <label style="font-size:0.9em; color:#666; margin-top:10px;">Curso e Turma:</label>
                <div class="form-row">
                    <select name="id_curso" id="curso_aluno" required onchange="carregarTurmasPorCurso(this.value)">
                        <option value="">Selecione o Curso</option>
                        <?php foreach ($cursos as $c): ?>
                            <option value="<?php echo $c['id_curso']; ?>"
                                <?= (isset($dados_formulario['id_curso']) && $dados_formulario['id_curso'] == $c['id_curso']) ? 'selected' : '' ?>>
                                <?php echo htmlspecialchars($c['nome']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <select name="id_turma" id="turma_aluno" required>
                        <option value="">Primeiro selecione o curso</option>
                    </select>
                </div>

                <label style="font-size:0.9em; color:#666; margin-top:10px;">Dados do Responsável:</label>
                <div class="form-row">
                    <input type="text" name="nome_responsavel" placeholder="Nome do Responsável"
                        value="<?= isset($dados_formulario['nome_responsavel']) ? htmlspecialchars($dados_formulario['nome_responsavel']) : '' ?>">

                    <input type="text" name="contato_responsavel" placeholder="Telefone do Responsável"
                        value="<?= isset($dados_formulario['contato_responsavel']) ? htmlspecialchars($dados_formulario['contato_responsavel']) : '' ?>">
                </div>

                <label style="font-size:0.9em; color:#666; margin-top:10px;">Dados da Empresa:</label>
                <div class="form-row">
                    <input type="text" name="empresa" placeholder="Nome da Empresa"
                        value="<?= isset($dados_formulario['empresa']) ? htmlspecialchars($dados_formulario['empresa']) : '' ?>">

                    <input type="text" name="contato_empresa" placeholder="Telefone da Empresa"
                        value="<?= isset($dados_formulario['contato_empresa']) ? htmlspecialchars($dados_formulario['contato_empresa']) : '' ?>">
                </div>

                <input type="password" name="senha" placeholder="Senha"
                    onkeyup="validarSenhaVisual(this.value, 'requisitos-senha-aluno')" required>
                <div id="requisitos-senha-aluno"></div>

                <button type="submit" name="cadastrar_aluno">Cadastrar Aluno</button>
            </form>
        </div>

        <!-- Lista de Alunos Incompletos -->
        <?php if (!empty($alunos_incompletos)): ?>
            <div style="margin-top:40px;">
                <h4 style="text-align:center; margin-bottom:20px; color:#dc3545;">Alunos com Dados Incompletos</h4>
                <div class="alunos-incompletos-grid">
                    <?php foreach ($alunos_incompletos as $aluno): ?>
                        <div class="aluno-card-incompleto">
                            <h5 style="margin-bottom:15px;"><?php echo htmlspecialchars($aluno['nome']); ?></h5>
                            <p><strong>Matrícula:</strong> <?php echo htmlspecialchars($aluno['matricula']); ?></p>

                            <form method="POST" style="margin-top:15px;">
                                <input type="hidden" name="id_aluno" value="<?php echo $aluno['id_aluno']; ?>">

                                <label>Nome do Responsável:</label>
                                <input type="text" name="nome_responsavel"
                                    value="<?php echo htmlspecialchars($aluno['nome_responsavel'] ?? ''); ?>"
                                    placeholder="Digite Nome do Responsável">

                                <label>Telefone do Responsável:</label>
                                <input type="text" name="contato_responsavel"
                                    value="<?php echo htmlspecialchars($aluno['contato_responsavel'] ?? ''); ?>"
                                    placeholder="Digite Telefone" required>

                                <label>Empresa:</label>
                                <input type="text" name="empresa"
                                    value="<?php echo htmlspecialchars($aluno['empresa'] ?? ''); ?>"
                                    placeholder="Nome da Empresa">

                                <label>Telefone da Empresa:</label>
                                <input type="text" name="contato_empresa"
                                    value="<?php echo htmlspecialchars($aluno['contato_empresa'] ?? ''); ?>"
                                    placeholder="Telefone da Empresa">

                                <label>Turma:</label>
                                <select name="id_turma" required>
                                    <option value="">Selecione a turma</option>
                                    <?php foreach ($turmas as $t): ?>
                                        <option value="<?php echo $t['id_turma']; ?>">
                                            <?php echo htmlspecialchars($t['nome'] . ' - ' . $t['curso_nome']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>

                                <button type="submit" name="completar_aluno">Completar Cadastro</button>
                            </form>

                            <form method="POST" style="margin-top:10px;">
                                <input type="hidden" name="id_aluno_reset" value="<?php echo $aluno['id_aluno']; ?>">
                                <button type="submit" name="resetar_senha_aluno"
                                    style="background:#6c757d;">Resetar Senha</button>
                            </form>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- Lista de Alunos por Turma -->
        <div style="margin-top:50px;">
            <h4 style="text-align:center; margin-bottom:20px;">Listar Alunos por Turma</h4>
            <select onchange="carregarMatriculas(this.value)" style="max-width:400px; margin:0 auto 20px; display:block;">
                <option value="">Selecione uma turma</option>
                <?php foreach ($turmas as $t): ?>
                    <option value="<?php echo $t['id_turma']; ?>">
                        <?php echo htmlspecialchars($t['nome'] . ' - ' . $t['curso_nome']); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <?php foreach ($turmas as $t): ?>
                <div id="alunos-turma-<?php echo $t['id_turma']; ?>" class="lista-alunos-turma" style="display:none;">
                    <h5 style="text-align:center; margin-bottom:15px;">Alunos: <?php echo htmlspecialchars($t['nome']); ?></h5>
                    <?php
                    $stmt_alunos = $conn->prepare("SELECT a.nome, a.matricula, a.celular FROM aluno a JOIN matricula m ON a.id_aluno = m.id_aluno WHERE m.id_turma = ? ORDER BY a.nome");
                    $stmt_alunos->execute([$t['id_turma']]);
                    $alunos_turma = $stmt_alunos->fetchAll();

                    if (empty($alunos_turma)): ?>
                        <p style="text-align:center;">Nenhum aluno matriculado.</p>
                    <?php else: ?>
                        <table>
                            <thead>
                                <tr>
                                    <th>Nome</th>
                                    <th>Matrícula</th>
                                    <th>Celular</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($alunos_turma as $aluno): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($aluno['nome']); ?></td>
                                        <td><?php echo htmlspecialchars($aluno['matricula']); ?></td>
                                        <td><?php echo htmlspecialchars($aluno['celular']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!--CADASTRO ALUNO -->
<?php
if (isset($_POST['cadastrar_aluno'])) {
    $tela_atual = "cadastro_aluno";

    $dados_formulario = [
        'nome' => isset($_POST['nome']) ? LimpaPost($_POST['nome']) : '',
        'matricula' => isset($_POST['matricula']) ? LimpaPost($_POST['matricula']) : '',
        'cpf' => isset($_POST['cpf']) ? $_POST['cpf'] : '',
        'senha' => isset($_POST['senha']) ? $_POST['senha'] : '',
        'data_nascimento' => isset($_POST['data_nascimento']) ? $_POST['data_nascimento'] : '',
        'id_curso' => isset($_POST['id_curso']) ? $_POST['id_curso'] : '',
        'id_turma' => isset($_POST['id_turma']) ? $_POST['id_turma'] : '',
        'nome_responsavel' => isset($_POST['nome_responsavel']) ? LimpaPost($_POST['nome_responsavel']) : '',
        'contato_responsavel' => isset($_POST['contato_responsavel']) ? LimpaPost($_POST['contato_responsavel']) : '',
        'empresa' => isset($_POST['empresa']) ? LimpaPost($_POST['empresa']) : '',
        'contato_empresa' => isset($_POST['contato_empresa']) ? LimpaPost($_POST['contato_empresa']) : ''
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

    if (empty($dados_formulario['id_curso'])) {
        $erros[] = "Selecione o curso";
    }

    if (empty($dados_formulario['id_turma'])) {
        $erros[] = "Selecione a turma";
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
            // Verificar colunas disponíveis
            $columns = $conn->query("SHOW COLUMNS FROM aluno")->fetchAll(PDO::FETCH_COLUMN);

            $sql_fields = ['nome', 'matricula', 'cpf', 'celular', 'senha_hash', 'data_nascimento'];
            $sql_values = ['?', '?', '?', '?', '?', '?'];
            $params = [
                $dados_formulario['nome'],
                $dados_formulario['matricula'],
                $cpf_formatado,
                $celular,
                $senha_hash,
                $dados_formulario['data_nascimento']
            ];

            // Adicionar campos opcionais se existirem na tabela
            if (in_array('nome_responsavel', $columns) && !empty($dados_formulario['nome_responsavel'])) {
                $sql_fields[] = 'nome_responsavel';
                $sql_values[] = '?';
                $params[] = $dados_formulario['nome_responsavel'];
            }

            if (in_array('contato_responsavel', $columns) && !empty($dados_formulario['contato_responsavel'])) {
                $sql_fields[] = 'contato_responsavel';
                $sql_values[] = '?';
                $params[] = $dados_formulario['contato_responsavel'];
            }

            if (in_array('empresa', $columns) && !empty($dados_formulario['empresa'])) {
                $sql_fields[] = 'empresa';
                $sql_values[] = '?';
                $params[] = $dados_formulario['empresa'];
            }

            if (in_array('contato_empresa', $columns) && !empty($dados_formulario['contato_empresa'])) {
                $sql_fields[] = 'contato_empresa';
                $sql_values[] = '?';
                $params[] = $dados_formulario['contato_empresa'];
            }

            $sql = "INSERT INTO aluno (" . implode(', ', $sql_fields) . ") VALUES (" . implode(', ', $sql_values) . ")";
            $stmt = $conn->prepare($sql);
            $stmt->execute($params);

            $id_aluno = $conn->lastInsertId();

            // Criar matrícula na turma
            if (!empty($dados_formulario['id_turma'])) {
                $stmt_matricula = $conn->prepare("INSERT INTO matricula (id_aluno, id_turma) VALUES (?, ?)");
                $stmt_matricula->execute([$id_aluno, $dados_formulario['id_turma']]);
            }

            $msg_cad_aluno = "Aluno cadastrado com sucesso!";
            $msg_type = "success";
            $dados_formulario = [];

            // Recarregar lista de alunos incompletos
            $alunos_incompletos = $conn->query("SELECT a.*, t.nome as turma_nome FROM aluno a LEFT JOIN matricula m ON a.id_aluno = m.id_aluno LEFT JOIN turma t ON m.id_turma = t.id_turma WHERE a.contato_responsavel IS NULL OR a.contato_responsavel = '' ORDER BY a.nome")->fetchAll();
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
?>

<!-- GERENCIAR FUNCIONÁRIOS -->
<div id="gerenciar_funcionarios" class="section" style="display:none;">
    <div class="container">
        <div class="form-cadastro-centralizado">
            <h4 style="text-align:center; margin-bottom:20px;">Cadastrar Novo Funcionário</h4>

            <?php if (!empty($msg_cad_func)) : ?>
                <div class="msg <?= $msg_type ?>"><?= $msg_cad_func ?></div>
            <?php endif; ?>

            <form method="POST">
                <input type="text" name="nome" placeholder="Nome Completo"
                    value="<?= isset($dados_formulario['nome']) ? htmlspecialchars($dados_formulario['nome']) : '' ?>" required>

                <input type="text" name="cpf" placeholder="CPF" onkeyup="formatarCPFInput(this)"
                    maxlength="14" value="<?= isset($dados_formulario['cpf']) ? htmlspecialchars($dados_formulario['cpf']) : '' ?>" required>

                <select name="tipo" required>
                    <option value="">Selecione o Tipo</option>
                    <option value="pedagógico" <?= (isset($dados_formulario['tipo']) && $dados_formulario['tipo'] == 'pedagógico') ? 'selected' : '' ?>>Pedagógico</option>
                    <option value="instrutor" <?= (isset($dados_formulario['tipo']) && $dados_formulario['tipo'] == 'instrutor') ? 'selected' : '' ?>>Instrutor</option>
                    <option value="portaria" <?= (isset($dados_formulario['tipo']) && $dados_formulario['tipo'] == 'portaria') ? 'selected' : '' ?>>Portaria</option>
                </select>

                <input type="password" name="senha" placeholder="Senha"
                    onkeyup="validarSenhaVisual(this.value, 'requisitos-senha-func')" required>
                <div id="requisitos-senha-func"></div>

                <button type="submit" name="cadastrar_funcionario">Cadastrar Funcionário</button>
            </form>
        </div>
    </div>
</div>

<?php
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
            }
        } catch (PDOException $e) {
            error_log("Erro ao buscar aluno: " . $e->getMessage());
        }

        if (!$usuario_encontrado && $aluno === false) {
            try {
                $stmt = $conn->prepare("SELECT * FROM funcionario WHERE cpf = ? OR cpf = ? LIMIT 1");
                $stmt->execute([$cpf_formatado, $cpf_limpo]);

                $func = $stmt->fetch();

                if ($func) {
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
?>

<!-- INSTRUTOR -->
<?php if ($tipo_user == 'instrutor'): ?>
    <div id="turmas_instrutor" class="section">
        <div class="container">
            <h3>Minhas Turmas - Detalhes</h3>
            <select onchange="showList('turma-' + this.value)">
                <option value="">Selecione uma turma</option>
                <?php foreach ($turmas_instrutor as $turma): ?>
                    <option value="<?php echo $turma['id_turma']; ?>"><?php echo htmlspecialchars($turma['turma_nome']); ?> - <?php echo htmlspecialchars($turma['curso_nome']); ?></option>
                <?php endforeach; ?>
            </select>
            <?php foreach ($turmas_instrutor as $turma): ?>
                <div id="list-turma-<?php echo $turma['id_turma']; ?>" class="list" style="display:none;">
                    <h4>Alunos da Turma: <?php echo htmlspecialchars($turma['turma_nome']); ?></h4>
                    <table>
                        <thead>
                            <tr>
                                <th>Nome</th>
                                <th>Matrícula</th>
                                <th>Telefone</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $alunos_turma = $conn->prepare("SELECT a.nome, a.matricula, a.celular FROM aluno a JOIN matricula m ON a.id_aluno = m.id_aluno WHERE m.id_turma = ? ORDER BY a.nome");
                            $alunos_turma->execute([$turma['id_turma']]);
                            foreach ($alunos_turma->fetchAll() as $aluno) {
                                echo "<tr>";
                                echo "<td>" . htmlspecialchars($aluno['nome']) . "</td>";
                                echo "<td>" . htmlspecialchars($aluno['matricula']) . "</td>";
                                echo "<td>" . htmlspecialchars($aluno['celular']) . "</td>";
                                echo "</tr>";
                            }
                            ?>
                        </tbody>
                    </table>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- SOLICITAÇÕES DO INSTRUTOR -->
    <div id="solicitacoes_instrutor" class="section" style="display:none;">
        <div class="container">
            <h3>Solicitações Pendentes</h3>

            <div style="margin-bottom: 20px;">
                <label for="filtro_turma_solicitacao">Filtrar por Turma:</label>
                <select id="filtro_turma_solicitacao" onchange="filtrarSolicitacoesPorTurma()">
                    <option value="">Todas as Turmas</option>
                    <?php foreach ($turmas_instrutor as $turma): ?>
                        <option value="<?php echo $turma['id_turma']; ?>"><?php echo htmlspecialchars($turma['turma_nome']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <?php
            $motivos_map = [
                '1' => 'Consulta médica',
                '2' => 'Consulta odontológica',
                '3' => 'Exames médicos',
                '4' => 'Problemas de saúde',
                '5' => 'Solicitação da empresa',
                '6' => 'Solicitação da família',
                '7' => 'Viagem particular',
                '8' => 'Viagem a trabalho',
                '9' => 'Treinamento a trabalho'
            ];

            $solicitacoes_instrutor = $conn->query("
            SELECT s.*, a.nome as aluno_nome, a.matricula, 
                   t.id_turma, t.nome as turma_nome,
                   DATE_FORMAT(s.data_solicitada, '%d/%m/%Y %H:%i') as data_solicitada_fmt
            FROM solicitacao s 
            JOIN aluno a ON s.id_aluno = a.id_aluno 
            JOIN matricula m ON a.id_aluno = m.id_aluno
            JOIN turma t ON m.id_turma = t.id_turma
            WHERE s.status = 'solicitado' AND s.motivo LIKE '%STATUS:aguardando_instrutor%'
            ORDER BY s.data_solicitada DESC
        ")->fetchAll();

            if (empty($solicitacoes_instrutor)): ?>
                <p>Nenhuma solicitação pendente no momento.</p>
            <?php else: ?>
                <?php foreach ($solicitacoes_instrutor as $s):
                    $parsed = parseMotivo($s['motivo']);
                    $motivo_texto = (is_numeric($parsed['motivo']) && isset($motivos_map[$parsed['motivo']])) ? $motivos_map[$parsed['motivo']] : $parsed['motivo'];
                    $status_cor = getStatusColor($s['status'], $s['motivo']);
                ?>
                    <div class="solicitacao-card solicitacao-card-instrutor"
                        data-turma-id="<?php echo $s['id_turma']; ?>"
                        style="border-left: 4px solid <?php echo $status_cor; ?>;">
                        <h4><?php echo htmlspecialchars($s['aluno_nome']); ?> (<?php echo htmlspecialchars($s['matricula']); ?>)</h4>
                        <p><strong>Turma:</strong> <?php echo htmlspecialchars($s['turma_nome']); ?></p>
                        <p><strong>Motivo:</strong> <?php echo htmlspecialchars($motivo_texto); ?></p>
                        <p><strong>Data Solicitação:</strong> <?php echo $s['data_solicitada_fmt']; ?></p>

                        <div class="action-buttons">
                            <form method="POST" style="display: inline;">
                                <input type="hidden" name="id_solicitacao" value="<?php echo $s['id_solicitacao']; ?>">
                                <input type="hidden" name="acao_instrutor" value="autorizar">
                                <button type="submit" name="instrutor_acao" style="padding: 8px 16px; background: #28a745; color: white; border: none; border-radius: 5px; cursor: pointer;">Autorizar</button>
                            </form>
                            <form method="POST" style="display: inline;">
                                <input type="hidden" name="id_solicitacao" value="<?php echo $s['id_solicitacao']; ?>">
                                <input type="hidden" name="acao_instrutor" value="rejeitar">
                                <button type="submit" name="instrutor_acao" style="padding: 8px 16px; background: #dc3545; color: white; border: none; border-radius: 5px; cursor: pointer;">Rejeitar</button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- ALUNOS LIBERADOS - SEÇÃO SEPARADA -->
    <div id="alunos_liberados_instrutor" class="section" style="display:none;">
        <div class="container">
            <h3>Alunos Liberados - Registro de Faltas</h3>

            <div style="margin-bottom: 20px;">
                <label for="filtro_turma_liberados">Filtrar por Turma:</label>
                <select id="filtro_turma_liberados" onchange="filtrarLiberadosPorTurma()">
                    <option value="">Todas as Turmas</option>
                    <?php foreach ($turmas_instrutor as $turma): ?>
                        <option value="<?php echo $turma['id_turma']; ?>"><?php echo htmlspecialchars($turma['turma_nome']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <script>
                function filtrarLiberadosPorTurma() {
                    const turmaId = document.getElementById('filtro_turma_liberados').value;
                    const cards = document.querySelectorAll('.aluno-liberado-card');

                    cards.forEach(card => {
                        if (turmaId === "" || card.getAttribute('data-turma-id') === turmaId) {
                            card.style.display = 'block';
                        } else {
                            card.style.display = 'none';
                        }
                    });
                }
            </script>

            <?php
            $alunos_liberados_instrutor = $conn->query("
                SELECT s.*, a.nome as aluno_nome, a.matricula, 
                       t.id_turma, t.nome as turma_nome,
                       DATE_FORMAT(s.data_solicitada, '%d/%m/%Y %H:%i') as data_solicitada_fmt,
                       DATE_FORMAT(s.data_autorizada, '%d/%m/%Y %H:%i') as data_autorizada_fmt,
                       DATE_FORMAT(s.data_saida, '%d/%m/%Y %H:%i') as data_saida_fmt
                FROM solicitacao s 
                JOIN aluno a ON s.id_aluno = a.id_aluno 
                JOIN matricula m ON a.id_aluno = m.id_aluno
                JOIN turma t ON m.id_turma = t.id_turma
                WHERE (s.status = 'liberado' OR s.status = 'concluido')
                ORDER BY s.data_solicitada DESC
            ")->fetchAll();

            if (empty($alunos_liberados_instrutor)): ?>
                <p>Nenhum aluno liberado no momento.</p>
            <?php else: ?>
                <?php
                $motivos_map = [
                    '1' => 'Consulta médica',
                    '2' => 'Consulta odontológica',
                    '3' => 'Exames médicos',
                    '4' => 'Problemas de saúde',
                    '5' => 'Solicitação da empresa',
                    '6' => 'Solicitação da família',
                    '7' => 'Viagem particular',
                    '8' => 'Viagem a trabalho',
                    '9' => 'Treinamento a trabalho'
                ];

                foreach ($alunos_liberados_instrutor as $al):
                    $parsed = parseMotivo($al['motivo']);
                    $motivo_texto = (is_numeric($parsed['motivo']) && isset($motivos_map[$parsed['motivo']])) ? $motivos_map[$parsed['motivo']] : $parsed['motivo'];
                    $status_texto = getStatusText($al['status'], $al['motivo']);
                ?>
                    <div class="solicitacao-card aluno-liberado-card" data-turma-id="<?php echo $al['id_turma']; ?>" style="border-left: 4px solid #28a745;">
                        <div class="solicitacao-header">
                            <h4><?php echo htmlspecialchars($al['aluno_nome']); ?> (<?php echo htmlspecialchars($al['matricula']); ?>)</h4>
                        </div>
                        <p><strong>Turma:</strong> <?php echo htmlspecialchars($al['turma_nome']); ?></p>
                        <p><strong>Motivo:</strong> <?php echo htmlspecialchars($motivo_texto); ?></p>
                        <p><strong>Data Solicitação:</strong> <?php echo $al['data_solicitada_fmt']; ?></p>
                        <p><strong>Liberado em:</strong> <?php echo $al['data_autorizada_fmt'] ?? 'N/A'; ?></p>

                        <?php if ($al['status'] == 'concluido' && !empty($al['data_saida_fmt'])): ?>
                            <p><strong>Saída Registrada:</strong> <?php echo $al['data_saida_fmt']; ?></p>
                        <?php endif; ?>

                        <p><strong>Status:</strong> <span style="color: #28a745; font-weight: bold;"><?php echo $status_texto; ?></span></p>

                        <div class="action-buttons" style="margin-top:15px;">
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="id_solicitacao_falta" value="<?php echo $al['id_solicitacao']; ?>">
                                <input type="hidden" name="id_aluno_falta" value="<?php echo $al['id_aluno']; ?>">
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<!-- PORTARIA -->
<?php if ($tipo_user == 'portaria'): ?>
    <div class="section" style="max-width: 600px; margin: 50px auto;">
        <div class="container">
            <h2 style="text-align: center; color: #007bff; margin-bottom: 30px;">Controle de Saídas</h2>
            <form method="POST">
                <label style="font-weight: bold; display: block; margin-bottom: 10px;">Código de Liberação:</label>
                <input type="text" name="codigo_liberacao" placeholder="Ex: AB12-3CD" required
                    style="width: 100%; padding: 15px; font-size: 1.2em; border: 2px solid #ddd; border-radius: 5px; margin-bottom: 20px; text-align: center; text-transform: uppercase;"
                    maxlength="8" pattern="[A-Z0-9]{4}-[A-Z0-9]{3}">
                <button type="submit" name="validar_codigo"
                    style="width: 100%; padding: 15px; font-size: 1.1em; background: #28a745; color: white; border: none; border-radius: 5px; cursor: pointer; font-weight: bold;">
                    Validar Saída
                </button>
            </form>

            <div style="margin-top:30px; padding:20px; background:#f8f9fa; border-radius:5px;">
                <h4>Últimas Saídas Registradas</h4>
                <?php
                $ultimas_saidas = $conn->query("
                            SELECT s.*, a.nome, a.matricula, 
                                   DATE_FORMAT(s.data_saida, '%d/%m/%Y %H:%i') as saida_fmt
                            FROM solicitacao s
                            JOIN aluno a ON s.id_aluno = a.id_aluno
                            WHERE s.status = 'concluido'
                            ORDER BY s.data_saida DESC
                            LIMIT 5
                        ")->fetchAll();

                if (empty($ultimas_saidas)): ?>
                    <p>Nenhuma saída registrada ainda.</p>
                <?php else: ?>
                    <ul style="list-style:none; padding:0;">
                        <?php foreach ($ultimas_saidas as $saida): ?>
                            <li style="padding:10px; border-bottom:1px solid #ddd;">
                                <strong><?php echo htmlspecialchars($saida['nome']); ?></strong> (<?php echo htmlspecialchars($saida['matricula']); ?>)<br>
                                <small>Saída: <?php echo $saida['saida_fmt']; ?></small>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>
<?php endif; ?>
</div>
</body>

</html>