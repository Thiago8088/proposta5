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


if (isset($_POST['solicitar_saida'])) {
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
            // CORRIGIDO: Removido data_solicitada do VALUES
            $stmt = $conn->prepare("INSERT INTO solicitacao (id_aluno, motivo, data_solicitada, status) VALUES (?, ?, NOW(), 'solicitado')");
            $stmt->execute([$user['id_aluno'], $motivo_completo]);
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

    if (isset($_POST['cancelar_saida'])) {
        $id_solicitacao = $_POST['id_solicitacao'];

        $stmt_current = $conn->prepare("SELECT motivo FROM solicitacao WHERE id_solicitacao = ?");
        $stmt_current->execute([$id_solicitacao]);
        $current = $stmt_current->fetch();
        $parsed = parseMotivo($current['motivo']);

        $new_motivo = buildMotivo('cancelado', $parsed['motivo']);

        $stmt = $conn->prepare("UPDATE solicitacao SET status = 'cancelado', motivo = ? WHERE id_solicitacao = ?");
        $stmt->execute([$new_motivo, $id_solicitacao]);

        $msg = "Solicitação cancelada!";
        $msg_type = "success";
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
        $acao = $_POST['instrutor_acao'];
        $id_aluno = $_POST['id_aluno'];
        $id_uc = isset($_POST['id_uc']) ? $_POST['id_uc'] : null;

        $stmt_current = $conn->prepare("SELECT motivo FROM solicitacao WHERE id_solicitacao = ?");
        $stmt_current->execute([$id_solicitacao]);
        $current_motivo_raw = $stmt_current->fetchColumn();
        $parsed = parseMotivo($current_motivo_raw);
        $original_motivo = $parsed['motivo'];

        if ($acao == 'autorizar') {
            if (empty($id_uc)) {
                $msg = "Selecione a Unidade Curricular!";
                $msg_type = "error";
            } else {
                $new_motivo = buildMotivo('aguardando_pedagogico', $original_motivo);

                $stmt = $conn->prepare("UPDATE solicitacao SET status = 'autorizado', data_autorizada = NOW(), motivo = ?, id_uc = ? WHERE id_solicitacao = ?");
                $stmt->execute([$new_motivo, $id_uc, $id_solicitacao]);

                // Registrar falta
                $hora_saida = date('H:i:s');

                // Verificar se a tabela frequencia existe
                try {
                    $stmt_falta = $conn->prepare("INSERT INTO frequencia (id_aluno, id_uc, data_falta, hora_saida, tipo) VALUES (?, ?, CURDATE(), ?, 'saida_antecipada')");
                    $stmt_falta->execute([$id_aluno, $id_uc, $hora_saida]);
                } catch (PDOException $e) {
                    error_log("Erro ao registrar falta: " . $e->getMessage());
                }

                $msg = "Solicitação autorizada! Aguardando pedagógico.";
                $msg_type = "success";
            }
        } else {
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

    // CARREGAR TURMAS DO INSTRUTOR
    try {
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
    } catch (PDOException $e) {
        error_log("Erro ao carregar turmas do instrutor: " . $e->getMessage());
        $turmas_instrutor = [];
    }

    // ESTATÍSTICAS
    try {
        $total_alunos = $conn->query("SELECT COUNT(DISTINCT m.id_aluno) as total FROM matricula m JOIN turma t ON m.id_turma = t.id_turma")->fetch()['total'];
    } catch (PDOException $e) {
        $total_alunos = 0;
    }

    try {
        $solicitacoes_pendentes = $conn->query("SELECT COUNT(*) as total FROM solicitacao WHERE status = 'solicitado' AND motivo LIKE '%aguardando_instrutor%'")->fetch()['total'];
    } catch (PDOException $e) {
        $solicitacoes_pendentes = 0;
    }
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
    <script src="js/index.js" defer></script>
</head>

<body>
    <nav class="navbar">
        <div class="navbar-header">
            <img src="img/senai logo.png" alt="SENAI">
            <div class="navbar-user">
                <?php echo htmlspecialchars($user['nome']); ?>
            </div>
        </div>

        <?php if ($tipo_user == 'portaria'): ?>
            <style>
                .main-content {
                    margin-left: 0 !important;
                    padding: 30px;
                }
            </style>
        <?php endif; ?>
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
                <a href="#" onclick="showSection('alunos_frequentes'); return false;">Alunos mais liberados</a>
                <a href="#" onclick="showSection('instrutores_liberacoes'); return false;">Estatísticas Instrutores</a>
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
        <?php if ($tipo_user == 'aluno'):
            $mostrar_alerta_frequencia = false;
            $msg_alerta_frequencia = '';

            if ($tipo_user == 'aluno') {
                $stmt_alerta = $conn->prepare("
                    SELECT uc.carga_horaria,
                           COUNT(f.id_frequencia) as total_faltas,
                           uc.nome
                    FROM unidade_curricular uc
                    JOIN turma t ON uc.id_curso = t.id_curso
                    JOIN matricula m ON t.id_turma = m.id_turma
                    LEFT JOIN frequencia f ON uc.id_curricular = f.id_uc AND f.id_aluno = m.id_aluno
                    WHERE m.id_aluno = ?
                    GROUP BY uc.id_curricular
                ");
                $stmt_alerta->execute([$user['id_aluno']]);
                $ucs_alerta = $stmt_alerta->fetchAll();

                foreach ($ucs_alerta as $uc) {
                    if ($uc['carga_horaria'] > 0) {
                        $perc_freq = (1 - ($uc['total_faltas'] / $uc['carga_horaria'])) * 100;
                        $perc_com_mais_uma_falta = (1 - (($uc['total_faltas'] + 1) / $uc['carga_horaria'])) * 100;

                        if ($perc_freq < 75) {
                            $mostrar_alerta_frequencia = true;
                            $msg_alerta_frequencia = "⚠️ Atenção! Sua frequência está abaixo de 75%. Você corre risco de reprovação.";
                            break;
                        }

                        if ($perc_freq >= 75 && $perc_com_mais_uma_falta < 75) {
                            $mostrar_alerta_frequencia = true;
                            $msg_alerta_frequencia = "⚠️ Atenção! Você está no limite de faltas. A próxima falta pode causar reprovação.";
                            break;
                        }
                    }
                }
            }
        ?>

            <?php if ($mostrar_alerta_frequencia): ?>
                <div class="alerta-frequencia-topo">
                    <?php echo $msg_alerta_frequencia; ?>
                </div>
            <?php endif; ?>

            <div id="info" class="section">
                <div class="container">
                    <h3>Informações Pessoais</h3>
                    <p><strong>Nome:</strong> <?php echo htmlspecialchars($user['nome']); ?></p>
                    <p><strong>Matrícula:</strong> <?php echo htmlspecialchars($user['matricula']); ?></p>
                    <p><strong>CPF:</strong> <?php echo htmlspecialchars($user['cpf']); ?></p>
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

                        <label>Turno:</label>
                        <select name="turno" required>
                            <option value="">Selecione o turno</option>
                            <option value="manhã">Matutino</option>
                            <option value="tarde">Vespertino</option>
                            <option value="noite">Noturno</option>
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

                    <?php
                    $stmt_ucs_aluno = $conn->prepare("
    SELECT uc.id_curricular, uc.nome, uc.carga_horaria,
           COUNT(f.id_frequencia) as total_faltas
    FROM unidade_curricular uc
    JOIN turma t ON uc.id_curso = t.id_curso
    JOIN matricula m ON t.id_turma = m.id_turma
    LEFT JOIN frequencia f ON uc.id_curricular = f.id_uc AND f.id_aluno = m.id_aluno
    WHERE m.id_aluno = ?
    GROUP BY uc.id_curricular
");
                    $stmt_ucs_aluno->execute([$user['id_aluno']]);
                    $ucs_aluno = $stmt_ucs_aluno->fetchAll();

                    if (empty($ucs_aluno)): ?>
                        <p>Você ainda não possui unidades curriculares cadastradas.</p>
                    <?php else: ?>
                        <div style="margin-bottom: 30px;">
                            <label style="font-weight: bold; display: block; margin-bottom: 10px;">Selecione a Unidade Curricular:</label>
                            <select id="select_uc_frequencia" onchange="mostrarFrequenciaUC()" style="width: 100%; max-width: 500px; padding: 12px;">
                                <option value="">Selecione uma UC</option>
                                <?php foreach ($ucs_aluno as $uc): ?>
                                    <option value="<?php echo $uc['id_curricular']; ?>">
                                        <?php echo htmlspecialchars($uc['nome']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <?php foreach ($ucs_aluno as $uc):
                            $total_faltas = $uc['total_faltas'];
                            $carga_horaria = $uc['carga_horaria'];
                            $perc_freq = $carga_horaria > 0 ? (1 - ($total_faltas / $carga_horaria)) * 100 : 100;
                            $perc_freq = max(0, min(100, $perc_freq));

                            $pode_sair = $perc_freq >= 75;
                            $proxima_falta_reprova = false;

                            if ($carga_horaria > 0) {
                                $perc_com_mais_uma_falta = (1 - (($total_faltas + 1) / $carga_horaria)) * 100;
                                $proxima_falta_reprova = $perc_com_mais_uma_falta < 75;
                            }

                            $cor_grafico = $perc_freq >= 75 ? '#28a745' : '#dc3545';
                            if ($pode_sair && $proxima_falta_reprova) $cor_grafico = '#ffc107';
                        ?>
                            <div class="frequencia-uc-card" data-uc-id="<?php echo $uc['id_curricular']; ?>" style="display: none; border: 2px solid #ddd; border-radius: 8px; padding: 20px; background: white;">
                                <h4 style="margin-bottom: 15px; color: #333;"><?php echo htmlspecialchars($uc['nome']); ?></h4>

                                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 20px;">
                                    <div>
                                        <p><strong>Carga Horária:</strong> <?php echo $carga_horaria; ?>h</p>
                                        <p><strong>Total de Faltas:</strong> <?php echo $total_faltas; ?></p>
                                    </div>
                                    <div>
                                        <p><strong>Frequência:</strong>
                                            <span style="font-size: 2em; font-weight: bold; color: <?php echo $cor_grafico; ?>;">
                                                <?php echo number_format($perc_freq, 1); ?>%
                                            </span>
                                        </p>
                                    </div>
                                </div>

                                <div style="background: #e9ecef; border-radius: 10px; height: 30px; overflow: hidden; margin-bottom: 15px;">
                                    <div style="background: <?php echo $cor_grafico; ?>; height: 100%; width: <?php echo $perc_freq; ?>%; transition: width 0.3s ease;"></div>
                                </div>

                                <?php if ($pode_sair && !$proxima_falta_reprova): ?>
                                    <div style="padding: 15px; background: #d4edda; border-left: 4px solid #28a745; border-radius: 4px;">
                                        <p style="margin: 0; color: #155724;">
                                            ✓ <strong>Você pode sair!</strong> Sua frequência está acima de 75%.
                                        </p>
                                    </div>
                                <?php elseif ($pode_sair && $proxima_falta_reprova): ?>
                                    <div style="padding: 15px; background: #fff3cd; border-left: 4px solid #ffc107; border-radius: 4px;">
                                        <p style="margin: 0; color: #856404;">
                                            ⚠ <strong>Atenção!</strong> Se você sair agora, sua frequência cairá para
                                            <?php echo number_format($perc_com_mais_uma_falta, 1); ?>% e você será reprovado!
                                        </p>
                                    </div>
                                <?php else: ?>
                                    <div style="padding: 15px; background: #f8d7da; border-left: 4px solid #dc3545; border-radius: 4px;">
                                        <p style="margin: 0; color: #721c24;">
                                            ✗ <strong>Você não pode sair!</strong> Sua frequência já está abaixo de 75%.
                                        </p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>

                        <script>
                            function mostrarFrequenciaUC() {
                                const ucId = document.getElementById('select_uc_frequencia').value;
                                document.querySelectorAll('.frequencia-uc-card').forEach(card => {
                                    card.style.display = 'none';
                                });
                                if (ucId) {
                                    const card = document.querySelector(`.frequencia-uc-card[data-uc-id="${ucId}"]`);
                                    if (card) card.style.display = 'block';
                                }
                            }
                        </script>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
    </div>

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
                   a.contato_responsavel, a.contato_empresa,
                   f.nome as instrutor_nome,
                   DATE_FORMAT(s.data_solicitada, '%d/%m/%Y %H:%i') as data_solicitada_fmt
            FROM solicitacao s
            JOIN aluno a ON s.id_aluno = a.id_aluno
            LEFT JOIN matricula m ON a.id_aluno = m.id_aluno
            LEFT JOIN turma t ON m.id_turma = t.id_turma
            LEFT JOIN funcionario f ON s.id_autorizacao = f.id_funcionario
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

                            <?php if (!empty($s['contato_responsavel'])): ?>
                                <p><strong>Telefone Responsável:</strong> <?php echo htmlspecialchars($s['contato_responsavel']); ?></p>
                            <?php endif; ?>

                            <?php if (!empty($s['contato_empresa'])): ?>
                                <p><strong>Telefone Empresa:</strong> <?php echo htmlspecialchars($s['contato_empresa']); ?></p>
                            <?php endif; ?>

                            <?php if (!empty($s['instrutor_nome'])): ?>
                                <p><strong>Instrutor:</strong> <?php echo htmlspecialchars($s['instrutor_nome']); ?></p>
                            <?php endif; ?>

                            <p><strong>Status:</strong> <span style="color: <?php echo $status_cor; ?>; font-weight: bold;"><?php echo $status_texto; ?></span></p>
                        </div>
                        <div class="action-buttons">
                            <form method="POST" style="display: inline;">
                                <input type="hidden" name="id_solicitacao" value="<?php echo $s['id_solicitacao']; ?>">
                                <input type="hidden" name="acao" value="autorizar">
                                <button type="submit" name="autorizar_saida">Aceitar</button>
                            </form>
                            <form method="POST" style="display: inline;">
                                <input type="hidden" name="id_solicitacao" value="<?php echo $s['id_solicitacao']; ?>">
                                <input type="hidden" name="acao" value="rejeitar">
                                <button type="submit" name="autorizar_saida">Recusar</button>
                            </form>
                            <!-- NOVO BOTÃO -->
                            <form method="POST" style="display: inline;">
                                <input type="hidden" name="id_solicitacao" value="<?php echo $s['id_solicitacao']; ?>">
                                <button type="submit" name="cancelar_saida"
                                    onclick="return confirm('Tem certeza que deseja cancelar esta solicitação?')"
                                    style="background: #6c757d;">Cancelar</button>
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
            SELECT s.*, a.nome as aluno_nome, a.matricula, a.data_nascimento,
                   t.nome as turma_nome, a.contato_responsavel, a.contato_empresa,
                   f.nome as instrutor_nome,
                   DATE_FORMAT(s.data_solicitada, '%d/%m/%Y %H:%i') as data_solicitada_fmt
            FROM solicitacao s
            JOIN aluno a ON s.id_aluno = a.id_aluno
            LEFT JOIN matricula m ON a.id_aluno = m.id_aluno
            LEFT JOIN turma t ON m.id_turma = t.id_turma
            LEFT JOIN funcionario f ON s.id_autorizacao = f.id_funcionario
            WHERE s.status = 'autorizado' AND s.motivo LIKE '%STATUS:aguardando_responsavel%'
            ORDER BY s.data_solicitada DESC
        ")->fetchAll();

                if (empty($resp)): ?>
                    <p>Nenhuma solicitação aguardando responsável.</p>
                <?php else: ?>
                    <?php foreach ($resp as $s):
                        $parsed = parseMotivo($s['motivo']);
                        $motivo_texto = (is_numeric($parsed['motivo']) && isset($motivos_map[$parsed['motivo']])) ? $motivos_map[$parsed['motivo']] : $parsed['motivo'];

                        // Calcular idade
                        $idade = null;
                        $eh_maior = false;
                        if (!empty($s['data_nascimento'])) {
                            $data_nasc = new DateTime($s['data_nascimento']);
                            $hoje = new DateTime();
                            $idade = $hoje->diff($data_nasc)->y;
                            $eh_maior = $idade >= 18;
                        }
                    ?>
                        <div class="solicitacao-card">
                            <h4><?php echo htmlspecialchars($s['aluno_nome']); ?> (<?php echo htmlspecialchars($s['matricula']); ?>)</h4>
                            <p><strong>Turma:</strong> <?php echo htmlspecialchars($s['turma_nome'] ?? 'N/A'); ?></p>
                            <p><strong>Motivo:</strong> <?php echo htmlspecialchars($motivo_texto); ?></p>
                            <p><strong>Data Solicitação:</strong> <?php echo $s['data_solicitada_fmt']; ?></p>

                            <?php if (!empty($s['contato_responsavel'])): ?>
                                <p><strong>Telefone Responsável:</strong> <?php echo htmlspecialchars($s['contato_responsavel']); ?></p>
                            <?php endif; ?>

                            <?php if (!empty($s['contato_empresa'])): ?>
                                <p><strong>Telefone Empresa:</strong> <?php echo htmlspecialchars($s['contato_empresa']); ?></p>
                            <?php endif; ?>

                            <?php if (!empty($s['instrutor_nome'])): ?>
                                <p><strong>Instrutor:</strong> <?php echo htmlspecialchars($s['instrutor_nome']); ?></p>
                            <?php endif; ?>

                            <div class="action-buttons">
                                <?php if ($eh_maior): ?>
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="id_solicitacao" value="<?php echo $s['id_solicitacao']; ?>">
                                        <input type="hidden" name="acao" value="autorizar">
                                        <button type="submit" name="autorizar_saida" style="padding: 8px 16px; background: #17a2b8; color: white; border: none; border-radius: 5px; cursor: pointer;">Aluno é Maior de Idade</button>
                                    </form>
                                <?php else: ?>
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="id_solicitacao" value="<?php echo $s['id_solicitacao']; ?>">
                                        <input type="hidden" name="acao" value="autorizar">
                                        <button type="submit" name="autorizar_saida" style="padding: 8px 16px; background: #17a2b8; color: white; border: none; border-radius: 5px; cursor: pointer;">Responsável Aceitou</button>
                                    </form>
                                <?php endif; ?>
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

                            <?php if (!empty($s['contato_responsavel'])): ?>
                                <p><strong>Telefone Responsável:</strong> <?php echo htmlspecialchars($s['contato_responsavel']); ?></p>
                            <?php endif; ?>

                            <?php if (!empty($s['contato_empresa'])): ?>
                                <p><strong>Telefone Empresa:</strong> <?php echo htmlspecialchars($s['contato_empresa']); ?></p>
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
                <h3 style="text-align:center; margin-bottom:30px; color:#004a8f;">Histórico por Aluno</h3>

                <div class="filtros-container" style="margin-bottom: 20px;">
                    <select id="filtro_turma_historico_sol" onchange="filtrarHistoricoSolicitacoes()">
                        <option value="">Todas as Turmas</option>
                        <?php foreach ($turmas as $t): ?>
                            <option value="<?php echo $t['id_turma']; ?>">
                                <?php echo htmlspecialchars($t['nome'] . ' - ' . $t['curso_nome']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php
                    $liberadas = $conn->query("
             SELECT $select_fields,
             f.nome as instrutor_nome,
             DATE_FORMAT(s.data_solicitada, '%d/%m/%Y %H:%i') as data_solicitada_fmt,
             DATE_FORMAT(s.data_saida, '%d/%m/%Y %H:%i') as data_saida_fmt
             FROM solicitacao s
             JOIN aluno a ON s.id_aluno = a.id_aluno
             LEFT JOIN matricula m ON a.id_aluno = m.id_aluno
             LEFT JOIN turma t ON m.id_turma = t.id_turma
             LEFT JOIN funcionario f ON s.id_autorizacao = f.id_funcionario
             WHERE (s.status = 'liberado' OR s.status = 'concluido')
             ORDER BY s.data_solicitada DESC
             ")->fetchAll();
                    ?>
                </div>

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

        <!-- ALUNOS MAIS LIBERADOS -->
        <div id="alunos_frequentes" class="section" style="display:none;">
            <div class="container">
                <h3 style="text-align:center; margin-bottom:30px; color:#004a8f;">Alunos com Saídas Frequentes</h3>

                <div class="filtros-container">
                    <select id="filtro_risco" onchange="filtrarAlunosRisco()">
                        <option value="">Todos</option>
                        <option value="frequente">Saem com Frequência</option>
                        <option value="risco">Risco de Reprovação</option>
                        <option value="reprovado">Já Reprovados</option>
                    </select>
                </div>

                <?php
                // Buscar alunos com múltiplas saídas
                $alunos_frequentes = $conn->query("
            SELECT a.id_aluno, a.nome, a.matricula, t.nome as turma_nome,
                   COUNT(s.id_solicitacao) as total_saidas,
                   GROUP_CONCAT(DISTINCT uc.id_curricular) as ucs_ids
            FROM aluno a
            JOIN solicitacao s ON a.id_aluno = s.id_aluno
            LEFT JOIN matricula m ON a.id_aluno = m.id_aluno
            LEFT JOIN turma t ON m.id_turma = t.id_turma
            LEFT JOIN unidade_curricular uc ON s.id_uc = uc.id_curricular
            WHERE s.status IN ('liberado', 'concluido')
            GROUP BY a.id_aluno
            HAVING total_saidas >= 3
            ORDER BY total_saidas DESC
        ")->fetchAll();

                foreach ($alunos_frequentes as $aluno):
                    // Calcular frequência
                    $frequencia_status = 'ok';
                    $uc_ids = explode(',', $aluno['ucs_ids']);

                    foreach ($uc_ids as $uc_id) {
                        if (empty($uc_id)) continue;

                        $stmt_freq = $conn->prepare("
                    SELECT uc.carga_horaria, COUNT(f.id_frequencia) as total_faltas
                    FROM unidade_curricular uc
                    LEFT JOIN frequencia f ON uc.id_curricular = f.id_uc AND f.id_aluno = ?
                    WHERE uc.id_curricular = ?
                ");
                        $stmt_freq->execute([$aluno['id_aluno'], $uc_id]);
                        $freq_data = $stmt_freq->fetch();

                        if ($freq_data) {
                            $perc_freq = $freq_data['carga_horaria'] > 0
                                ? (1 - ($freq_data['total_faltas'] / $freq_data['carga_horaria'])) * 100
                                : 100;

                            if ($perc_freq < 75) {
                                $frequencia_status = 'reprovado';
                                break;
                            } elseif ($perc_freq < 80) {
                                $frequencia_status = 'risco';
                            }
                        }
                    }
                ?>
                    <div class="aluno-frequente-card"
                        data-risco="<?php echo $frequencia_status; ?>"
                        data-frequencia="frequente"
                        style="background: white; padding: 20px; margin-bottom: 15px; border-radius: 8px; 
                        border-left: 4px solid <?php echo $frequencia_status == 'reprovado' ? '#dc3545' : ($frequencia_status == 'risco' ? '#ffc107' : '#28a745'); ?>;">
                        <h4><?php echo htmlspecialchars($aluno['nome']); ?> (<?php echo htmlspecialchars($aluno['matricula']); ?>)</h4>
                        <p><strong>Turma:</strong> <?php echo htmlspecialchars($aluno['turma_nome']); ?></p>
                        <p><strong>Total de Saídas:</strong> <?php echo $aluno['total_saidas']; ?></p>
                        <p><strong>Status:</strong>
                            <span style="color: <?php echo $frequencia_status == 'reprovado' ? '#dc3545' : ($frequencia_status == 'risco' ? '#ffc107' : '#28a745'); ?>; font-weight: bold;">
                                <?php
                                echo $frequencia_status == 'reprovado' ? 'Reprovado' : ($frequencia_status == 'risco' ? 'Risco de Reprovação' : 'Normal');
                                ?>
                            </span>
                        </p>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <script>
            function filtrarAlunosRisco() {
                const filtro = document.getElementById('filtro_risco').value;
                const cards = document.querySelectorAll('.aluno-frequente-card');

                cards.forEach(card => {
                    let mostrar = false;

                    if (filtro === "") {
                        mostrar = true;
                    } else if (filtro === "frequente") {
                        mostrar = card.getAttribute('data-frequencia') === 'frequente';
                    } else {
                        mostrar = card.getAttribute('data-risco') === filtro;
                    }

                    card.style.display = mostrar ? 'block' : 'none';
                });
            }
        </script>

        <!-- INSTRUTORES MAIS LIBERAÇÕES -->
        <div id="instrutores_liberacoes" class="section" style="display:none;">
            <div class="container">
                <h3 style="text-align:center; margin-bottom:30px; color:#004a8f;">Instrutores com Mais Alunos Liberados</h3>

                <div class="filtros-container">
                    <select id="filtro_instrutor" onchange="filtrarPorInstrutor()">
                        <option value="">Todos os Instrutores</option>
                        <?php
                        $instrutores = $conn->query("SELECT DISTINCT f.id_funcionario, f.nome 
                    FROM funcionario f 
                    JOIN solicitacao s ON f.id_funcionario = s.id_autorizacao 
                    WHERE f.tipo = 'instrutor' AND s.status IN ('liberado', 'concluido')
                    ORDER BY f.nome")->fetchAll();

                        foreach ($instrutores as $inst): ?>
                            <option value="<?php echo $inst['id_funcionario']; ?>">
                                <?php echo htmlspecialchars($inst['nome']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <?php
                $stats_instrutores = $conn->query("
            SELECT f.id_funcionario, f.nome, COUNT(s.id_solicitacao) as total_liberacoes
            FROM funcionario f
            JOIN solicitacao s ON f.id_funcionario = s.id_autorizacao
            WHERE f.tipo = 'instrutor' AND s.status IN ('liberado', 'concluido')
            GROUP BY f.id_funcionario
            ORDER BY total_liberacoes DESC
        ")->fetchAll();

                $total_geral = array_sum(array_column($stats_instrutores, 'total_liberacoes'));

                foreach ($stats_instrutores as $stat):
                    $percentual = $total_geral > 0 ? ($stat['total_liberacoes'] / $total_geral) * 100 : 0;
                ?>
                    <div class="instrutor-stat-card" data-instrutor-id="<?php echo $stat['id_funcionario']; ?>"
                        style="background: white; padding: 20px; margin-bottom: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                        <h4><?php echo htmlspecialchars($stat['nome']); ?></h4>
                        <p><strong>Total de Liberações:</strong> <?php echo $stat['total_liberacoes']; ?></p>
                        <p><strong>Percentual:</strong> <?php echo number_format($percentual, 1); ?>%</p>

                        <div style="background: #e9ecef; border-radius: 10px; height: 30px; overflow: hidden; margin-top: 10px;">
                            <div style="background: #007bff; height: 100%; width: <?php echo $percentual; ?>%; transition: width 0.3s ease;"></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <script>
            function filtrarPorInstrutor() {
                const instrutorId = document.getElementById('filtro_instrutor').value;
                const cards = document.querySelectorAll('.instrutor-stat-card');

                cards.forEach(card => {
                    const mostrar = (instrutorId === "" || card.getAttribute('data-instrutor-id') === instrutorId);
                    card.style.display = mostrar ? 'block' : 'none';
                });
            }
        </script>

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

                if (!empty($dados_formulario['id_turma'])) {
                    $stmt_matricula = $conn->prepare("INSERT INTO matricula (id_aluno, id_turma) VALUES (?, ?)");
                    $stmt_matricula->execute([$id_aluno, $dados_formulario['id_turma']]);
                }

                $msg_cad_aluno = "Aluno cadastrado com sucesso!";
                $msg_type = "success";
                $dados_formulario = [];

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

    <!-- PORTARIA -->
    <?php if ($tipo_user == 'portaria'): ?>
        <div id="controle_portaria" class="section" style="display: block;">
            <div class="container" style="max-width: 600px; margin: 50px auto;">
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

    <!-- INSTRUTOR -->

    <?php if ($tipo_user == 'instrutor'): ?>
        <div id="turmas_instrutor" class="section">
            <div class="container">
                <h3>Minhas Turmas - Detalhes</h3>

                <?php if (empty($turmas_instrutor)): ?>
                    <p style="text-align: center; padding: 20px; background: #f8f9fa; border-radius: 5px;">
                        Nenhuma turma encontrada no momento.
                    </p>
                <?php else: ?>
                    <select onchange="showList('turma-' + this.value)">
                        <option value="">Selecione uma turma</option>
                        <?php foreach ($turmas_instrutor as $turma): ?>
                            <option value="<?php echo $turma['id_turma']; ?>">
                                <?php echo htmlspecialchars($turma['turma_nome']); ?> - <?php echo htmlspecialchars($turma['curso_nome']); ?>
                            </option>
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
                                        <th>Telefone do Responsável</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    try {
                                        $alunos_turma = $conn->prepare("
                                        SELECT a.nome, a.matricula, a.contato_responsavel 
                                        FROM aluno a 
                                        JOIN matricula m ON a.id_aluno = m.id_aluno 
                                        WHERE m.id_turma = ? 
                                        ORDER BY a.nome
                                    ");
                                        $alunos_turma->execute([$turma['id_turma']]);
                                        $alunos = $alunos_turma->fetchAll();

                                        if (empty($alunos)): ?>
                                            <tr>
                                                <td colspan="3" style="text-align: center;">Nenhum aluno matriculado nesta turma.</td>
                                            </tr>
                                            <?php else:
                                            foreach ($alunos as $aluno): ?>
                                                <tr>
                                                    <td><?php echo htmlspecialchars($aluno['nome']); ?></td>
                                                    <td><?php echo htmlspecialchars($aluno['matricula']); ?></td>
                                                    <td><?php echo htmlspecialchars($aluno['contato_responsavel'] ?? 'Não cadastrado'); ?></td>
                                                </tr>
                                    <?php endforeach;
                                        endif;
                                    } catch (PDOException $e) {
                                        error_log("Erro ao buscar alunos da turma: " . $e->getMessage());
                                        echo '<tr><td colspan="3" style="text-align: center; color: #dc3545;">Erro ao carregar alunos.</td></tr>';
                                    }
                                    ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- SOLICITAÇÕES DO INSTRUTOR -->

        <div id="solicitacoes_instrutor" class="section" style="display:none;">
            <div class="container">
                <h3>Solicitações Pendentes</h3>

                <?php if (!empty($turmas_instrutor)): ?>
                    <div style="margin-bottom: 20px;">
                        <label for="filtro_turma_solicitacao">Filtrar por Turma:</label>
                        <select id="filtro_turma_solicitacao" onchange="filtrarSolicitacoesPorTurma()">
                            <option value="">Todas as Turmas</option>
                            <?php foreach ($turmas_instrutor as $turma): ?>
                                <option value="<?php echo $turma['id_turma']; ?>">
                                    <?php echo htmlspecialchars($turma['turma_nome']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>

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

                try {
                    $solicitacoes_instrutor = $conn->query("
                    SELECT s.*, a.nome as aluno_nome, a.matricula, 
                           t.id_turma, t.nome as turma_nome, t.id_curso,
                           DATE_FORMAT(s.data_solicitada, '%d/%m/%Y %H:%i') as data_solicitada_fmt
                    FROM solicitacao s 
                    JOIN aluno a ON s.id_aluno = a.id_aluno 
                    JOIN matricula m ON a.id_aluno = m.id_aluno
                    JOIN turma t ON m.id_turma = t.id_turma
                    WHERE s.status = 'solicitado' AND s.motivo LIKE '%STATUS:aguardando_instrutor%'
                    ORDER BY s.data_solicitada DESC
                ")->fetchAll();
                } catch (PDOException $e) {
                    error_log("Erro ao buscar solicitações: " . $e->getMessage());
                    $solicitacoes_instrutor = [];
                }

                if (empty($solicitacoes_instrutor)): ?>
                    <p>Nenhuma solicitação pendente no momento.</p>
                <?php else: ?>
                    <?php foreach ($solicitacoes_instrutor as $s):
                        $parsed = parseMotivo($s['motivo']);
                        $motivo_texto = (is_numeric($parsed['motivo']) && isset($motivos_map[$parsed['motivo']])) ? $motivos_map[$parsed['motivo']] : $parsed['motivo'];
                        $status_cor = getStatusColor($s['status'], $s['motivo']);
                        try {
                            $stmt_ucs = $conn->prepare("SELECT id_curricular, nome FROM unidade_curricular WHERE id_curso = ? ORDER BY nome");
                            $stmt_ucs->execute([$s['id_curso']]);
                            $ucs_turma = $stmt_ucs->fetchAll();
                        } catch (PDOException $e) {
                            $ucs_turma = [];
                        }
                    ?>
                        <div class="solicitacao-card solicitacao-card-instrutor"
                            data-turma-id="<?php echo $s['id_turma']; ?>"
                            style="border-left: 4px solid <?php echo $status_cor; ?>;">
                            <h4><?php echo htmlspecialchars($s['aluno_nome']); ?> (<?php echo htmlspecialchars($s['matricula']); ?>)</h4>
                            <p><strong>Turma:</strong> <?php echo htmlspecialchars($s['turma_nome']); ?></p>
                            <p><strong>Motivo:</strong> <?php echo htmlspecialchars($motivo_texto); ?></p>
                            <p><strong>Data Solicitação:</strong> <?php echo $s['data_solicitada_fmt']; ?></p>

                            <form method="POST" style="margin-top: 15px;">
                                <input type="hidden" name="id_solicitacao" value="<?php echo $s['id_solicitacao']; ?>">
                                <input type="hidden" name="id_aluno" value="<?php echo $s['id_aluno']; ?>">

                                <?php if (!empty($ucs_turma)): ?>
                                    <label style="display: block; margin-bottom: 8px;"><strong>Selecione a Unidade Curricular:</strong></label>
                                    <select name="id_uc" required style="width: 100%; padding: 8px; margin-bottom: 15px; border: 1px solid #ddd; border-radius: 4px;">
                                        <option value="">Selecione a UC</option>
                                        <?php foreach ($ucs_turma as $uc): ?>
                                            <option value="<?php echo $uc['id_curricular']; ?>">
                                                <?php echo htmlspecialchars($uc['nome']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php else: ?>
                                    <p style="color: #dc3545; margin-bottom: 15px;">⚠ Nenhuma UC cadastrada para esta turma!</p>
                                <?php endif; ?>

                                <div class="action-buttons">
                                    <button type="submit" name="instrutor_acao" value="autorizar"
                                        <?php echo empty($ucs_turma) ? 'disabled' : ''; ?>
                                        style="padding: 8px 16px; background: #28a745; color: white; border: none; border-radius: 5px; cursor: pointer;">
                                        Autorizar
                                    </button>
                                    <button type="submit" name="instrutor_acao" value="rejeitar"
                                        style="padding: 8px 16px; background: #dc3545; color: white; border: none; border-radius: 5px; cursor: pointer;">
                                        Rejeitar
                                    </button>
                                </div>
                            </form>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- ALUNOS LIBERADOS -->
        <div id="alunos_liberados_instrutor" class="section" style="display:none;">
            <div class="container">
                <h3>Alunos Liberados - Registro de Faltas</h3>

                <?php if (!empty($turmas_instrutor)): ?>
                    <div style="margin-bottom: 20px;">
                        <label for="filtro_turma_liberados">Filtrar por Turma:</label>
                        <select id="filtro_turma_liberados" onchange="filtrarLiberadosPorTurma()">
                            <option value="">Todas as Turmas</option>
                            <?php foreach ($turmas_instrutor as $turma): ?>
                                <option value="<?php echo $turma['id_turma']; ?>">
                                    <?php echo htmlspecialchars($turma['turma_nome']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>

                <?php
                try {
                    $alunos_liberados_instrutor = $conn->query("
                    SELECT s.*, a.nome as aluno_nome, a.matricula, 
                           t.id_turma, t.nome as turma_nome,
                           uc.nome as uc_nome,
                           DATE_FORMAT(s.data_solicitada, '%d/%m/%Y %H:%i') as data_solicitada_fmt,
                           DATE_FORMAT(s.data_autorizada, '%d/%m/%Y %H:%i') as data_autorizada_fmt,
                           DATE_FORMAT(s.data_saida, '%d/%m/%Y %H:%i') as data_saida_fmt
                    FROM solicitacao s 
                    JOIN aluno a ON s.id_aluno = a.id_aluno 
                    JOIN matricula m ON a.id_aluno = m.id_aluno
                    JOIN turma t ON m.id_turma = t.id_turma
                    LEFT JOIN unidade_curricular uc ON s.id_uc = uc.id_curricular
                    WHERE (s.status = 'liberado' OR s.status = 'concluido')
                    ORDER BY s.data_solicitada DESC
                ")->fetchAll();
                } catch (PDOException $e) {
                    error_log("Erro ao buscar alunos liberados: " . $e->getMessage());
                    $alunos_liberados_instrutor = [];
                }

                if (empty($alunos_liberados_instrutor)): ?>
                    <p>Nenhum aluno liberado no momento.</p>
                <?php else: ?>
                    <?php
                    foreach ($alunos_liberados_instrutor as $al):
                        $parsed = parseMotivo($al['motivo']);
                        $motivo_texto = (is_numeric($parsed['motivo']) && isset($motivos_map[$parsed['motivo']])) ? $motivos_map[$parsed['motivo']] : $parsed['motivo'];
                        $status_texto = getStatusText($al['status'], $al['motivo']);
                    ?>
                        <div class="solicitacao-card aluno-liberado-card"
                            data-turma-id="<?php echo $al['id_turma']; ?>"
                            style="border-left: 4px solid #28a745;">
                            <div class="solicitacao-header">
                                <h4><?php echo htmlspecialchars($al['aluno_nome']); ?> (<?php echo htmlspecialchars($al['matricula']); ?>)</h4>
                            </div>
                            <p><strong>Turma:</strong> <?php echo htmlspecialchars($al['turma_nome']); ?></p>
                            <p><strong>UC:</strong> <?php echo htmlspecialchars($al['uc_nome'] ?? 'Não informada'); ?></p>
                            <p><strong>Motivo:</strong> <?php echo htmlspecialchars($motivo_texto); ?></p>
                            <p><strong>Data Solicitação:</strong> <?php echo $al['data_solicitada_fmt']; ?></p>
                            <p><strong>Liberado em:</strong> <?php echo $al['data_autorizada_fmt'] ?? 'N/A'; ?></p>

                            <?php if ($al['status'] == 'concluido' && !empty($al['data_saida_fmt'])): ?>
                                <p><strong>Saída Registrada:</strong> <?php echo $al['data_saida_fmt']; ?></p>
                            <?php endif; ?>

                            <p><strong>Status:</strong> <span style="color: #28a745; font-weight: bold;"><?php echo $status_texto; ?></span></p>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- ALUNOS LIBERADOS -->
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
</body>

</html>