<?php
session_start();
date_default_timezone_set('America/Sao_Paulo');
require("db/conexao.php");

if (!isset($_SESSION['id'])) {
    header("Location: login_cadastro.php");
    exit;
}

$tipo_user = $_SESSION['tipo_user'];
$user_id = $_SESSION['id'];
$user = null;


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
                case 'aguardando_instrutor': return '#ffc107';
                case 'aguardando_pedagogico': return '#17a2b8';
                case 'aguardando_responsavel': return '#fd7e14';
                case 'aguardando_portaria': return '#28a745';
                case 'recusado_instrutor':
                case 'recusado_responsavel': return '#dc3545';
                case 'concluido': return '#28a745';
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
                case 'aguardando_instrutor': return 'Aguardando Instrutor';
                case 'aguardando_pedagogico': return 'Aguardando Pedagógico';
                case 'aguardando_responsavel': return 'Aguardando Responsável';
                case 'aguardando_portaria': return 'Aguardando Portaria';
                case 'recusado_instrutor': return 'Recusado pelo Instrutor';
                case 'recusado_responsavel': return 'Recusado pelo Responsável';
                case 'concluido': return 'Concluído';
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
                $stmt = $conn->prepare("UPDATE solicitacao SET status = 'liberado', motivo = ?, codigo_liberacao = ? WHERE id_solicitacao = ?");
                $stmt->execute([$new_motivo, $codigo, $id_solicitacao]);
                
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
            $stmt = $conn->prepare("SELECT s.*, a.nome, a.matricula FROM solicitacao s JOIN aluno a ON s.id_aluno = a.id_aluno WHERE s.codigo_liberacao = ? AND s.status = 'liberado'");
            $stmt->execute([$codigo]);
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
            document.querySelectorAll('.section').forEach(sec => sec.style.display = 'none');
            const sectionElement = document.getElementById(section);
            if (sectionElement) {
                sectionElement.style.display = 'block';
            }
            document.querySelectorAll('.navbar-menu a').forEach(link => link.classList.remove('active'));
            if (event && event.target) {
                event.target.classList.add('active');
            }
        }

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

        window.addEventListener('DOMContentLoaded', function() {
            const firstSection = document.querySelector('.navbar-menu a');
            if (firstSection) {
                firstSection.click();
            }
        });
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
                    <a href="#" onclick="showSection('aguardando_instrutor'); return false;">Aguardando Instrutor</a>
                    <a href="#" onclick="showSection('aguardando_portaria'); return false;">Aguardando Portaria</a>
                    <a href="#" onclick="showSection('solicitacoes_liberadas'); return false;">Solicitações Liberadas</a>
                    <a href="#" onclick="showSection('solicitacoes_recusadas'); return false;">Solicitações Recusadas</a>
                    <a href="#" onclick="showSection('alunos_liberados_turma'); return false;">Ver Alunos Liberados</a>
                    <a href="#" onclick="showSection('alunos_recusados_turma'); return false;">Ver Alunos Recusados</a>
                    <a href="#" onclick="showSection('cursos'); return false;">Gerenciar Cursos</a>
                    <a href="#" onclick="showSection('ucs'); return false;">Gerenciar UCs</a>
                    <a href="#" onclick="showSection('turmas'); return false;">Gerenciar Turmas</a>
                    <a href="#" onclick="showSection('alunos'); return false;">Gerenciar Alunos</a>
                <?php elseif ($tipo_user == 'instrutor'): ?>
                    <a href="#" onclick="showSection('turmas_instrutor'); return false;">Minhas Turmas</a>
                    <a href="#" onclick="showSection('solicitacoes_instrutor'); return false;">Solicitações</a>
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
                </div>
            </div>
        <?php endif; ?>

        <!-- PEDAGÓGICO -->
        <?php if ($tipo_user == 'pedagógico'): ?>
            <?php
            // Mapa de motivos para usar em todas as seções
            $motivos_map = [
                '1' => 'Consulta médica', '2' => 'Consulta odontológica', '3' => 'Exames médicos',
                '4' => 'Problemas de saúde', '5' => 'Solicitação da empresa', '6' => 'Solicitação da família',
                '7' => 'Viagem particular', '8' => 'Viagem a trabalho', '9' => 'Treinamento a trabalho'
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
                        WHERE s.status IN ('solicitado', 'autorizado', 'liberado')
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
                                    <p><strong>Decisão do Responsável:</strong></p>
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

            <!-- AGUARDANDO INSTRUTOR -->
            <div id="aguardando_instrutor" class="section" style="display:none;">
                <div class="container">
                    <h3>Aguardando Instrutor</h3>
                    <?php
                    $inst = $conn->query("
                        SELECT s.*, a.nome as aluno_nome, a.matricula, t.nome as turma_nome,
                               DATE_FORMAT(s.data_solicitada, '%d/%m/%Y %H:%i') as data_solicitada_fmt
                        FROM solicitacao s
                        JOIN aluno a ON s.id_aluno = a.id_aluno
                        LEFT JOIN matricula m ON a.id_aluno = m.id_aluno
                        LEFT JOIN turma t ON m.id_turma = t.id_turma
                        WHERE s.status = 'solicitado' AND s.motivo LIKE '%STATUS:aguardando_instrutor%'
                        ORDER BY s.data_solicitada DESC
                    ")->fetchAll();

                    if (empty($inst)): ?>
                        <p>Nenhuma solicitação aguardando instrutor.</p>
                    <?php else: ?>
                        <?php foreach ($inst as $s): 
                            $parsed = parseMotivo($s['motivo']);
                            $motivo_texto = (is_numeric($parsed['motivo']) && isset($motivos_map[$parsed['motivo']])) ? $motivos_map[$parsed['motivo']] : $parsed['motivo'];
                        ?>
                            <div class="solicitacao-card">
                                <h4><?php echo htmlspecialchars($s['aluno_nome']); ?> (<?php echo htmlspecialchars($s['matricula']); ?>)</h4>
                                <p><strong>Turma:</strong> <?php echo htmlspecialchars($s['turma_nome'] ?? 'N/A'); ?></p>
                                <p><strong>Motivo:</strong> <?php echo htmlspecialchars($motivo_texto); ?></p>
                                <p><strong>Data Solicitação:</strong> <?php echo $s['data_solicitada_fmt']; ?></p>
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
                    $port = $conn->query("
                        SELECT s.*, a.nome as aluno_nome, a.matricula, t.nome as turma_nome,
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
                                <p><strong>Código:</strong> <?php echo $s['codigo_liberacao']; ?></p>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- SOLICITAÇÕES LIBERADAS -->
            <div id="solicitacoes_liberadas" class="section" style="display:none;">
                <div class="container">
                    <h3>Solicitações Liberadas</h3>
                    <?php
                    $liberadas = $conn->query("
                        SELECT s.*, a.nome as aluno_nome, a.matricula, t.nome as turma_nome,
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
                        <p>Nenhuma solicitação liberada.</p>
                    <?php else: ?>
                        <?php foreach ($liberadas as $s): 
                            $parsed = parseMotivo($s['motivo']);
                            $motivo_texto = (is_numeric($parsed['motivo']) && isset($motivos_map[$parsed['motivo']])) ? $motivos_map[$parsed['motivo']] : $parsed['motivo'];
                            $status_texto = getStatusText($s['status'], $s['motivo']);
                        ?>
                            <div class="solicitacao-card" style="border-left: 4px solid #28a745;">
                                <h4><?php echo htmlspecialchars($s['aluno_nome']); ?> (<?php echo htmlspecialchars($s['matricula']); ?>)</h4>
                                <p><strong>Turma:</strong> <?php echo htmlspecialchars($s['turma_nome'] ?? 'N/A'); ?></p>
                                <p><strong>Motivo:</strong> <?php echo htmlspecialchars($motivo_texto); ?></p>
                                <p><strong>Data Solicitação:</strong> <?php echo $s['data_solicitada_fmt']; ?></p>
                                <?php if (!empty($s['codigo_liberacao'])): ?>
                                    <p><strong>Código:</strong> <?php echo $s['codigo_liberacao']; ?></p>
                                <?php endif; ?>
                                <?php if ($s['status'] == 'concluido' && !empty($s['data_saida_fmt'])): ?>
                                    <p><strong>Saída Registrada:</strong> <?php echo $s['data_saida_fmt']; ?></p>
                                <?php endif; ?>
                                <p><strong>Status:</strong> <span style="color: #28a745; font-weight: bold;"><?php echo $status_texto; ?></span></p>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- SOLICITAÇÕES RECUSADAS -->
            <div id="solicitacoes_recusadas" class="section" style="display:none;">
                <div class="container">
                    <h3>Solicitações Recusadas</h3>
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
                        <p>Nenhuma solicitação recusada.</p>
                    <?php else: ?>
                        <?php foreach ($recusadas as $s): 
                            $parsed = parseMotivo($s['motivo']);
                            $motivo_texto = (is_numeric($parsed['motivo']) && isset($motivos_map[$parsed['motivo']])) ? $motivos_map[$parsed['motivo']] : $parsed['motivo'];
                            $status_texto = getStatusText($s['status'], $s['motivo']);
                        ?>
                            <div class="solicitacao-card" style="border-left: 4px solid #dc3545;">
                                <h4><?php echo htmlspecialchars($s['aluno_nome']); ?> (<?php echo htmlspecialchars($s['matricula']); ?>)</h4>
                                <p><strong>Turma:</strong> <?php echo htmlspecialchars($s['turma_nome'] ?? 'N/A'); ?></p>
                                <p><strong>Motivo:</strong> <?php echo htmlspecialchars($motivo_texto); ?></p>
                                <p><strong>Data Solicitação:</strong> <?php echo $s['data_solicitada_fmt']; ?></p>
                                <p><strong>Status:</strong> <span style="color: #dc3545; font-weight: bold;"><?php echo $status_texto; ?></span></p>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- VER ALUNOS LIBERADOS POR TURMA -->
            <div id="alunos_liberados_turma" class="section" style="display:none;">
                <div class="container">
                    <h3>Ver Alunos Liberados (por turma)</h3>
                    <label>Selecione a Turma:</label>
                    <select id="select_turma_liberados" onchange="mostrarAlunosLiberados(this.value)">
                        <option value="">Selecione uma turma</option>
                        <?php foreach ($turmas as $t): ?>
                            <option value="<?php echo $t['id_turma']; ?>"><?php echo htmlspecialchars($t['nome']); ?> - <?php echo htmlspecialchars($t['curso_nome']); ?></option>
                        <?php endforeach; ?>
                    </select>

                    <script>
                        function mostrarAlunosLiberados(idTurma) {
                            document.querySelectorAll('.lista-liberados-turma').forEach(el => el.style.display = 'none');
                            if (idTurma) {
                                const lista = document.getElementById('liberados-turma-' + idTurma);
                                if (lista) lista.style.display = 'block';
                            }
                        }
                    </script>

                    <?php foreach ($turmas as $t): ?>
                        <div id="liberados-turma-<?php echo $t['id_turma']; ?>" class="lista-liberados-turma" style="display:none; margin-top:20px;">
                            <h4>Alunos Liberados - Turma: <?php echo htmlspecialchars($t['nome']); ?></h4>
                            <?php
                            $stmt_liberados = $conn->prepare("
                                SELECT s.*, a.nome as aluno_nome, a.matricula,
                                       f.nome as instrutor_nome,
                                       DATE_FORMAT(s.data_solicitada, '%d/%m/%Y %H:%i') as data_solicitada_fmt,
                                       DATE_FORMAT(s.data_autorizada, '%d/%m/%Y %H:%i') as hora_instrutor,
                                       DATE_FORMAT(s.data_saida, '%d/%m/%Y %H:%i') as hora_saida
                                FROM solicitacao s
                                JOIN aluno a ON s.id_aluno = a.id_aluno
                                JOIN matricula m ON a.id_aluno = m.id_aluno
                                LEFT JOIN funcionario f ON s.id_autorizacao = f.id_funcionario
                                WHERE m.id_turma = ? AND (s.status = 'liberado' OR s.status = 'concluido')
                                ORDER BY s.data_solicitada DESC
                            ");
                            $stmt_liberados->execute([$t['id_turma']]);
                            $alunos_liberados = $stmt_liberados->fetchAll();

                            if (empty($alunos_liberados)): ?>
                                <p>Nenhum aluno liberado nesta turma.</p>
                            <?php else: ?>
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Nome do Aluno</th>
                                            <th>Instrutor</th>
                                            <th>Motivo</th>
                                            <th>Hora Instrutor Liberou</th>
                                            <th>Código</th>
                                            <th>Hora Saída Portaria</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($alunos_liberados as $al): 
                                            $parsed = parseMotivo($al['motivo']);
                                            $motivo_texto = (is_numeric($parsed['motivo']) && isset($motivos_map[$parsed['motivo']])) ? $motivos_map[$parsed['motivo']] : $parsed['motivo'];
                                            $status_texto = getStatusText($al['status'], $al['motivo']);
                                        ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($al['aluno_nome']); ?></td>
                                                <td><?php echo htmlspecialchars($al['instrutor_nome'] ?? 'N/A'); ?></td>
                                                <td><?php echo htmlspecialchars($motivo_texto); ?></td>
                                                <td><?php echo $al['hora_instrutor'] ?? 'N/A'; ?></td>
                                                <td><?php echo $al['codigo_liberacao'] ?? 'N/A'; ?></td>
                                                <td><?php echo $al['hora_saida'] ?? 'Aguardando'; ?></td>
                                                <td><?php echo $status_texto; ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- VER ALUNOS RECUSADOS POR TURMA -->
            <div id="alunos_recusados_turma" class="section" style="display:none;">
                <div class="container">
                    <h3>Ver Alunos Recusados (por turma)</h3>
                    <label>Selecione a Turma:</label>
                    <select id="select_turma_recusados" onchange="mostrarAlunosRecusados(this.value)">
                        <option value="">Selecione uma turma</option>
                        <?php foreach ($turmas as $t): ?>
                            <option value="<?php echo $t['id_turma']; ?>"><?php echo htmlspecialchars($t['nome']); ?> - <?php echo htmlspecialchars($t['curso_nome']); ?></option>
                        <?php endforeach; ?>
                    </select>

                    <script>
                        function mostrarAlunosRecusados(idTurma) {
                            document.querySelectorAll('.lista-recusados-turma').forEach(el => el.style.display = 'none');
                            if (idTurma) {
                                const lista = document.getElementById('recusados-turma-' + idTurma);
                                if (lista) lista.style.display = 'block';
                            }
                        }
                    </script>

                    <?php foreach ($turmas as $t): ?>
                        <div id="recusados-turma-<?php echo $t['id_turma']; ?>" class="lista-recusados-turma" style="display:none; margin-top:20px;">
                            <h4>Alunos Recusados - Turma: <?php echo htmlspecialchars($t['nome']); ?></h4>
                            <?php
                            $stmt_recusados = $conn->prepare("
                                SELECT s.*, a.nome as aluno_nome, a.matricula,
                                       DATE_FORMAT(s.data_solicitada, '%d/%m/%Y %H:%i') as data_solicitada_fmt
                                FROM solicitacao s
                                JOIN aluno a ON s.id_aluno = a.id_aluno
                                JOIN matricula m ON a.id_aluno = m.id_aluno
                                WHERE m.id_turma = ? AND s.status = 'rejeitada'
                                ORDER BY s.data_solicitada DESC
                            ");
                            $stmt_recusados->execute([$t['id_turma']]);
                            $alunos_recusados = $stmt_recusados->fetchAll();

                            if (empty($alunos_recusados)): ?>
                                <p>Nenhum aluno recusado nesta turma.</p>
                            <?php else: ?>
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Nome</th>
                                            <th>Motivo</th>
                                            <th>Quem Recusou</th>
                                            <th>Hora da Recusa</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($alunos_recusados as $ar): 
                                            $parsed = parseMotivo($ar['motivo']);
                                            $motivo_texto = (is_numeric($parsed['motivo']) && isset($motivos_map[$parsed['motivo']])) ? $motivos_map[$parsed['motivo']] : $parsed['motivo'];
                                            $quem_recusou = 'N/A';
                                            if (strpos($ar['motivo'], 'recusado_instrutor') !== false) {
                                                $quem_recusou = 'Instrutor';
                                            } elseif (strpos($ar['motivo'], 'recusado_pedagogico') !== false) {
                                                $quem_recusou = 'Pedagógico';
                                            } elseif (strpos($ar['motivo'], 'recusado_responsavel') !== false) {
                                                $quem_recusou = 'Responsável';
                                            }
                                        ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($ar['aluno_nome']); ?></td>
                                                <td><?php echo htmlspecialchars($motivo_texto); ?></td>
                                                <td><?php echo $quem_recusou; ?></td>
                                                <td><?php echo $ar['data_solicitada_fmt']; ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
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
                        <h3>Gerenciar Turmas</h3>
                    <form method="POST" style="margin-bottom: 20px;">
                        <select name="id_curso_turma" id="id_curso_turma" required onchange="calcularCargaTotal()">
                            <option value="">Selecione o curso</option>
                            <?php foreach ($cursos as $c): ?>
                                <option value="<?php echo $c['id_curso']; ?>"><?php echo htmlspecialchars($c['nome']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <input type="text" name="nome_turma" placeholder="Nome da turma" required>
                        <input type="number" name="carga_horaria_total" id="carga_horaria_total" placeholder="Carga horária total" style="background:#f0f0f0;">
                        <button type="submit" name="cadastrar_turma">Cadastrar Turma</button>
                    </form>
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
                                    <td><?php echo $conn->query("SELECT COUNT(*) as total FROM matricula WHERE id_turma = " . $t['id_turma'])->fetch()['total']; ?></td>
                                    <td>
                                        <button onclick="mostrarEdicao('turma', <?php echo $t['id_turma']; ?>)" style="background: #007bff; color: white; padding: 5px 10px; border: none; border-radius: 3px; cursor: pointer;">Editar</button>
                                        <form method="POST" style="display:inline;">
                                            <input type="hidden" name="id_turma" value="<?php echo $t['id_turma']; ?>">
                                            <button type="submit" name="deletar_turma" onclick="return confirm('Deseja excluir esta turma?')" style="background: #dc3545; color: white; padding: 5px 10px; border: none; border-radius: 3px; cursor: pointer;">Excluir</button>
                                        </form>
                                    </td>
                                </tr>
                                <tr id="form-editar-turma-<?php echo $t['id_turma']; ?>" style="display:none;">
                                    <td colspan="5">
                                        <form method="POST" style="display:flex; gap:10px; align-items:center;">
                                            <input type="hidden" name="id_turma" value="<?php echo $t['id_turma']; ?>">
                                            <input type="text" style="color: black; width: 150px;border-radius: 3px; cursor: pointer;" name="nome_turma" value="<?php echo htmlspecialchars($t['nome']); ?>" placeholder="Rescreva o nome da Turma" required style="flex:2;">
                                            <input type="number" style="color: black;
                                            width: 150px; border-radius: 3px; cursor: pointer;" name="carga_horaria_total" value="<?php echo $t['carga_horaria_total'] ?? 0; ?>" placeholder="Rescreva a nova Carga horaria" required min="0" style="flex:1;">
                                            <button type="submit" name="editar_turma" style="background: #28a745; color: white; padding: 5px 10px; border: none; border-radius: 3px; cursor: pointer;">Salvar</button>
                                            <button type="button" onclick="ocultarEdicao('turma', <?php echo $t['id_turma']; ?>)" style="background: #6c757d; color: white; padding: 5px 10px; border: none; border-radius: 3px; cursor: pointer;">Cancelar</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div id="alunos" class="section" style="display:none;">
                <div class="container">
                    <h3>Gerenciar Alunos</h3>
                    <h4>Alunos com Cadastro Incompleto</h4>
                    <?php if (empty($alunos_incompletos)): ?>
                        <p>Todos os alunos têm cadastro completo.</p>
                    <?php else: ?>
                        <div class="alunos-incompletos-grid">
                            <?php foreach ($alunos_incompletos as $aluno): ?>
                                <div class="aluno-card-incompleto">
                                    <div style="text-align:center; margin-bottom:15px;">
                                        <img src="img/senai logo.png" alt="Foto do Aluno" style="width:80px; height:80px; border-radius:50%; object-fit:cover; border:3px solid #007bff;">
                                    </div>
                                    <h5 style="text-align:center; margin-bottom:10px;"><?php echo htmlspecialchars($aluno['nome']); ?></h5>
                                    <p><strong>Matrícula:</strong> <?php echo htmlspecialchars($aluno['matricula']); ?></p>
                                    <p><strong>Turma:</strong> <?php echo htmlspecialchars($aluno['turma_nome'] ?? 'Não matriculado'); ?></p>

                                    <div style="background:#fff3cd; padding:10px; margin:10px 0; border-radius:5px; border-left:4px solid #ffc107;">
                                        <strong>Campos Pendentes:</strong>
                                        <ul style="margin:5px 0; padding-left:20px;">
                                            <?php if (empty($aluno['contato_responsavel'])): ?>
                                                <li>Telefone do Responsável</li>
                                            <?php endif; ?>
                                            <?php if (empty($aluno['turma_nome'])): ?>
                                                <li>Turma</li>
                                            <?php endif; ?>
                                        </ul>
                                    </div>

                                    <form method="POST" style="margin-top:15px;">
                                        <input type="hidden" name="id_aluno" value="<?php echo $aluno['id_aluno']; ?>">

                                        <label>Telefone do Responsável:</label>
                                        <input type="text" name="contato_responsavel" value="<?php echo htmlspecialchars($user['contato_responsavel'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                            style="background:#f0f0f0;" placeholder="(00) 00000-0000" <?php echo !empty($aluno['contato_responsavel']) ? 'readonly style="background:#f0f0f0;"' : 'required'; ?>>


                                        <label>Turma:</label>
                                        <select name="id_turma" <?php echo !empty($aluno['turma_nome']) ? 'disabled style="background:#f0f0f0;"' : 'required'; ?>>
                                            <option value="">Selecione a turma</option>
                                            <?php foreach ($turmas as $t): ?>
                                                <option value="<?php echo $t['id_turma']; ?>" <?php echo ($aluno['turma_nome'] == $t['nome']) ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($t['nome']); ?> - <?php echo htmlspecialchars($t['curso_nome']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>

                                        <div style="display:flex; gap:10px; margin-top:10px;">
                                            <button type="submit" name="completar_aluno" style="flex:1; background:#28a745;">Salvar Dados</button>
                                            <input type="hidden" name="id_aluno_reset" value="<?php echo $aluno['id_aluno']; ?>">
                                            <button type="submit" name="resetar_senha_aluno" formnovalidate style="flex:1; background:#ffc107; color:#000;">Resetar Senha</button>
                                        </div>
                                    </form>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <h4 style="margin-top:40px;">Listar Matrículas por Turma</h4>
                    <select onchange="carregarMatriculas(this.value)" style="margin-bottom:20px;">
                        <option value="">Selecione uma turma</option>
                        <?php foreach ($turmas as $t): ?>
                            <option value="<?php echo $t['id_turma']; ?>"><?php echo htmlspecialchars($t['nome']); ?> - <?php echo htmlspecialchars($t['curso_nome']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div id="matriculas_turma">
                        <?php foreach ($turmas as $t): ?>
                            <div id="alunos-turma-<?php echo $t['id_turma']; ?>" class="lista-alunos-turma" style="display:none;">
                                <h5>Alunos da Turma: <?php echo htmlspecialchars($t['nome']); ?></h5>
                                <?php
                                $stmt_alunos = $conn->prepare("SELECT a.nome, a.matricula, a.celular FROM aluno a JOIN matricula m ON a.id_aluno = m.id_aluno WHERE m.id_turma = ? ORDER BY a.nome");
                                $stmt_alunos->execute([$t['id_turma']]);
                                $alunos_turma = $stmt_alunos->fetchAll();
                                
                                if (empty($alunos_turma)): ?>
                                    <p>Nenhum aluno matriculado nesta turma.</p>
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

        <?php endif; ?>

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

            <div id="solicitacoes_instrutor" class="section" style="display:none;">
                <div class="container">
                    <h3>Solicitações para Liberação</h3>
                    
                    <div style="margin-bottom: 20px;">
                        <label for="filtro_turma_solicitacao">Filtrar por Turma:</label>
                        <select id="filtro_turma_solicitacao" onchange="filtrarSolicitacoesPorTurma()">
                            <option value="">Todas as Turmas</option>
                            <?php foreach ($turmas_instrutor as $turma): ?>
                                <option value="<?php echo $turma['id_turma']; ?>"><?php echo htmlspecialchars($turma['turma_nome']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <script>
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
                    </script>

                    <?php
                    $solicitacoes_instrutor = $conn->query("
                        SELECT s.*, a.nome as aluno_nome, a.matricula, uc.nome as uc_nome, 
                               DATE_FORMAT(s.data_solicitada, '%d/%m/%Y %H:%i') as data_solicitada_fmt,
                               t.id_turma, t.nome as turma_nome
                        FROM solicitacao s 
                        JOIN aluno a ON s.id_aluno = a.id_aluno 
                        JOIN matricula m ON a.id_aluno = m.id_aluno
                        JOIN turma t ON m.id_turma = t.id_turma
                        LEFT JOIN unidade_curricular uc ON s.id_curricular = uc.id_curricular 
                        WHERE s.status = 'solicitado' AND s.motivo LIKE '%STATUS:aguardando_instrutor%'
                        ORDER BY s.data_solicitada DESC
                    ")->fetchAll();

                    if (empty($solicitacoes_instrutor)): ?>
                        <p>Nenhuma solicitação pendente.</p>
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
                        foreach ($solicitacoes_instrutor as $s): 
                            $parsed = parseMotivo($s['motivo']);
                            $motivo_raw = $parsed['motivo'];
                            $motivo_texto = (is_numeric($motivo_raw) && isset($motivos_map[$motivo_raw])) ? $motivos_map[$motivo_raw] : $motivo_raw;
                        ?>
                            <div class="solicitacao-card solicitacao-card-instrutor" data-turma-id="<?php echo $s['id_turma']; ?>">
                                <div class="solicitacao-header">
                                    <h4><?php echo htmlspecialchars($s['aluno_nome']); ?> (<?php echo htmlspecialchars($s['matricula']); ?>)</h4>
                                </div>
                                <p><strong>Turma:</strong> <?php echo htmlspecialchars($s['turma_nome']); ?></p>
                                <p><strong>UC:</strong> <?php echo htmlspecialchars($s['uc_nome']); ?></p>
                                <p><strong>Motivo:</strong> <?php echo htmlspecialchars($motivo_texto); ?></p>
                                <p><strong>Data Solicitação:</strong> <?php echo $s['data_solicitada_fmt']; ?></p>

                                <div class="action-buttons" style="margin-top:15px;">
                                    <form method="POST" style="display:inline;">
                                        <input type="hidden" name="id_solicitacao" value="<?php echo $s['id_solicitacao']; ?>">
                                        <input type="hidden" name="acao_instrutor" value="autorizar">
                                        <button type="submit" name="instrutor_acao" style="background:#28a745; color:white; padding:10px 20px; border:none; border-radius:5px; cursor:pointer;">Liberar Saída</button>
                                    </form>
                                    <form method="POST" style="display:inline; margin-left:10px;">
                                        <input type="hidden" name="id_solicitacao" value="<?php echo $s['id_solicitacao']; ?>">
                                        <input type="hidden" name="acao_instrutor" value="rejeitar">
                                        <button type="submit" name="instrutor_acao" onclick="return confirm('Deseja realmente rejeitar esta solicitação?')" style="background:#dc3545; color:white; padding:10px 20px; border:none; border-radius:5px; cursor:pointer;">Rejeitar</button>
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