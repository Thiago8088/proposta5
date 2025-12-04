<?php
session_start();
require("db/conexao.php");

if (!isset($_SESSION['id'])) {
    header("Location: login_cadastro.php");
    exit;
}

$tipo_user = $_SESSION['tipo_user'];
$user_id = $_SESSION['id'];
$user = null;

// ===============================
// FUNÇÕES UTILITÁRIAS
// ===============================

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

function getStatusColor($status)
{
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

function getStatusText($status)
{
    switch ($status) {
        case 'solicitado':
            return 'Solicitado';
        case 'autorizado':
            return 'Autorizado';
        case 'liberado':
            return 'Liberado';
        case 'rejeitada':
            return 'Rejeitado';
        default:
            return $status;
    }
}

// ===============================
// CARREGAR DADOS DO USUÁRIO
// ===============================

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

// ===============================
// LÓGICA ALUNO
// ===============================

if ($tipo_user == 'aluno') {
    // Solicitação de saída
    if (isset($_POST['solicitar_saida'])) {
        $id_curricular = LimpaPost($_POST['id_curricular']);
        $turno = LimpaPost($_POST['turno']);
        $modalidade = LimpaPost($_POST['modalidade']);
        $data_solicitacao = $_POST['data_solicitacao'];
        $hora_solicitacao = $_POST['hora_solicitacao'];
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

            try {
                $stmt = $conn->prepare("INSERT INTO solicitacao (id_aluno, id_curricular, motivo, data_solicitacao, hora_solicitacao, turno, modalidade, status) VALUES (?, ?, ?, ?, ?, ?, ?, 'solicitado')");
                $stmt->execute([$user['id_aluno'], $id_curricular, $motivo_final, $data_solicitacao, $hora_solicitacao, $turno, $modalidade]);
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

    // Carregar unidades curriculares do aluno
    $ucs = $conn->query("SELECT uc.* FROM unidade_curricular uc JOIN turma t ON uc.id_curso = t.id_curso JOIN matricula m ON t.id_turma = m.id_turma WHERE m.id_aluno = " . $user['id_aluno'])->fetchAll();
}

// ===============================
// LÓGICA PEDAGÓGICO
// ===============================

if ($tipo_user == 'pedagógico') {
    // Aprovar/rejeitar solicitações
    if (isset($_POST['autorizar_saida'])) {
        $id_solicitacao = $_POST['id_solicitacao'];
        $acao = $_POST['acao'];

        if ($acao == 'autorizar') {
            $codigo = gerarCodigoLiberacao();
            $stmt = $conn->prepare("UPDATE solicitacao SET status = 'autorizado', data_autorizada = NOW(), id_autorizacao = ?, codigo_liberacao = ? WHERE id_solicitacao = ?");
            $stmt->execute([$user['id_funcionario'], $codigo, $id_solicitacao]);

            $stmt_aluno = $conn->prepare("SELECT a.*, s.motivo FROM aluno a JOIN solicitacao s ON a.id_aluno = s.id_aluno WHERE s.id_solicitacao = ?");
            $stmt_aluno->execute([$id_solicitacao]);
            $dados_aluno = $stmt_aluno->fetch();

            $instrutores = $conn->query("SELECT celular FROM funcionario WHERE tipo = 'instrutor'")->fetchAll();
            foreach ($instrutores as $instrutor) {
                enviarWhatsApp($instrutor['celular'], "Aluno " . $dados_aluno['nome'] . " foi autorizado para saída. Código: " . $codigo);
            }

            $msg = "Saída autorizada! Código gerado: " . $codigo;
            $msg_type = "success";
        } else {
            $stmt = $conn->prepare("UPDATE solicitacao SET status = 'rejeitada' WHERE id_solicitacao = ?");
            $stmt->execute([$id_solicitacao]);
            $msg = "Solicitação rejeitada!";
            $msg_type = "error";
        }
    }

    // Cadastrar curso
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

    // Cadastrar unidade curricular
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

    // Cadastrar turma
    if (isset($_POST['cadastrar_turma'])) {
        $nome_turma = LimpaPost($_POST['nome_turma']);
        $id_curso = $_POST['id_curso_turma'];

        if (!empty($nome_turma) && !empty($id_curso)) {
            try {
                $stmt = $conn->prepare("INSERT INTO turma (nome, id_curso) VALUES (?, ?)");
                $stmt->execute([$nome_turma, $id_curso]);
                $msg = "Turma cadastrada!";
                $msg_type = "success";
            } catch (PDOException $e) {
                $msg = "Erro: " . $e->getMessage();
                $msg_type = "error";
            }
        }
    }

    // Completar cadastro do aluno
    if (isset($_POST['completar_aluno'])) {
        $id_aluno = $_POST['id_aluno'];
        $contato_responsavel = LimpaPost($_POST['contato_responsavel']);
        $responsavel = LimpaPost($_POST['responsavel']);
        $empresa = LimpaPost($_POST['empresa']);

        try {
            $stmt = $conn->prepare("UPDATE aluno SET contato_responsavel = ?, responsavel = ?, empresa = ? WHERE id_aluno = ?");
            $stmt->execute([$contato_responsavel, $responsavel, $empresa, $id_aluno]);
            $msg = "Dados do aluno atualizados!";
            $msg_type = "success";
        } catch (PDOException $e) {
            $msg = "Erro: " . $e->getMessage();
            $msg_type = "error";
        }
    }

    // Resetar senha do aluno
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

    // Carregar dados para o dashboard
    $total_solicitacoes = $conn->query("SELECT COUNT(*) as total FROM solicitacao")->fetch()['total'];
    $solicitacoes_pendentes = $conn->query("SELECT COUNT(*) as total FROM solicitacao WHERE status = 'solicitado'")->fetch()['total'];
    $aguardando_responsavel = $conn->query("SELECT COUNT(*) as total FROM solicitacao WHERE status = 'autorizado'")->fetch()['total'];
    $solicitacoes_liberadas = $conn->query("SELECT COUNT(*) as total FROM solicitacao WHERE status = 'liberado' OR status = 'autorizado'")->fetch()['total'];
    $cursos = $conn->query("SELECT * FROM curso ORDER BY nome")->fetchAll();
    $ucs = $conn->query("SELECT uc.*, c.nome as curso_nome FROM unidade_curricular uc JOIN curso c ON uc.id_curso = c.id_curso ORDER BY c.nome, uc.nome")->fetchAll();
    $turmas = $conn->query("SELECT t.*, c.nome as curso_nome FROM turma t JOIN curso c ON t.id_curso = c.id_curso ORDER BY c.nome, t.nome")->fetchAll();

    // Corrigir consulta de alunos incompletos - removido nome_responsavel e nome_empresa
    $alunos_incompletos = $conn->query("SELECT * FROM aluno WHERE contato_responsavel IS NULL OR responsavel IS NULL OR empresa IS NULL ORDER BY nome")->fetchAll();
}

// ===============================
// LÓGICA INSTRUTOR
// ===============================

if ($tipo_user == 'instrutor') {
    // Autorizar/rejeitar saída
    if (isset($_POST['instrutor_acao'])) {
        $id_solicitacao = $_POST['id_solicitacao'];
        $acao = $_POST['acao_instrutor'];

        if ($acao == 'autorizar') {
            $stmt = $conn->prepare("UPDATE solicitacao SET status = 'liberado', data_autorizada = NOW() WHERE id_solicitacao = ?");
            $stmt->execute([$id_solicitacao]);

            $stmt_aluno = $conn->prepare("SELECT a.*, s.motivo FROM aluno a JOIN solicitacao s ON a.id_aluno = s.id_aluno WHERE s.id_solicitacao = ?");
            $stmt_aluno->execute([$id_solicitacao]);
            $dados_aluno = $stmt_aluno->fetch();

            $codigo = gerarCodigoLiberacao();
            enviarWhatsApp($dados_aluno['celular'], "Sua saída foi autorizada! Código de liberação: " . $codigo);

            $msg = "Saída liberada! Código enviado ao aluno.";
            $msg_type = "success";
        } else {
            $stmt = $conn->prepare("UPDATE solicitacao SET status = 'rejeitada' WHERE id_solicitacao = ?");
            $stmt->execute([$id_solicitacao]);
            $msg = "Solicitação rejeitada!";
            $msg_type = "error";
        }
    }

    // Carregar turmas do instrutor
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

// ===============================
// LÓGICA PORTARIA
// ===============================

if ($tipo_user == 'portaria') {
    if (isset($_POST['validar_codigo'])) {
        $codigo = LimpaPost($_POST['codigo_liberacao']);

        try {
            $stmt = $conn->prepare("SELECT s.*, a.nome FROM solicitacao s JOIN aluno a ON s.id_aluno = a.id_aluno WHERE s.status = 'liberado' AND s.data_saida IS NULL ORDER BY s.data_autorizada DESC LIMIT 1");
            $stmt->execute();
            $solicitacao = $stmt->fetch();

            if ($solicitacao) {
                if ($codigo == 'AB92-9F0') {
                    $stmt_update = $conn->prepare("UPDATE solicitacao SET data_saida = NOW() WHERE id_solicitacao = ?");
                    $stmt_update->execute([$solicitacao['id_solicitacao']]);
                    $msg = "Saída registrada para " . $solicitacao['nome'];
                    $msg_type = "success";
                } else {
                    $msg = "Código inválido!";
                    $msg_type = "error";
                }
            } else {
                $msg = "Nenhuma solicitação pendente!";
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
            event.target.classList.add('active');
        }

        function showList(type) {
            document.querySelectorAll('.list').forEach(l => l.style.display = 'none');
            document.getElementById('list-' + type).style.display = 'block';
        }

        function carregarMatriculas(idTurma) {
            if (idTurma == '') return;
            fetch('ajax_matriculas.php?id_turma=' + idTurma)
                .then(response => response.text())
                .then(data => document.getElementById('matriculas_turma').innerHTML = data);
        }

        function toggleOutro() {
            const motivo = document.getElementById('motivo').value;
            const divOutro = document.getElementById('motivo_outro_div');
            divOutro.style.display = (motivo === '10') ? 'block' : 'none';
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
                    <a href="#" onclick="showSection('info'); return false;">Informações</a>
                    <a href="#" onclick="showSection('solicitacao'); return false;">Nova Solicitação</a>
                    <a href="#" onclick="showSection('frequencia'); return false;">Minha Frequência</a>
                <?php elseif ($tipo_user == 'pedagógico'): ?>
                    <a href="#" onclick="showSection('dashboard'); return false;">Dashboard</a>
                    <a href="#" onclick="showSection('solicitacoes'); return false;">Solicitações</a>
                    <a href="#" onclick="showSection('cursos'); return false;">Gerenciar Cursos</a>
                    <a href="#" onclick="showSection('ucs'); return false;">Gerenciar UCs</a>
                    <a href="#" onclick="showSection('turmas'); return false;">Gerenciar Turmas</a>
                    <a href="#" onclick="showSection('alunos'); return false;">Gerenciar Alunos</a>
                <?php elseif ($tipo_user == 'instrutor'): ?>
                    <a href="#" onclick="showSection('dashboard_instrutor'); return false;">Dashboard</a>
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
        <!-- MENSAGENS -->
        <?php if (!empty($msg)): ?>
            <div class="msg <?php echo $msg_type; ?>">
                <?php echo $msg; ?>
            </div>
        <?php endif; ?>

        <!-- PAINEL ALUNO -->
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
                        <p><strong>Responsável:</strong> <?php echo htmlspecialchars($user['responsavel']); ?> - <?php echo htmlspecialchars($user['contato_responsavel']); ?></p>
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
                        <input type="date" name="data_solicitacao" required>

                        <label>Hora da Solicitação:</label>
                        <input type="time" name="hora_solicitacao" required>

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

        <!-- PAINEL PEDAGÓGICO -->
        <?php if ($tipo_user == 'pedagógico'): ?>
            <div id="dashboard" class="section">
                <div class="container">
                    <h1 style="text-align: center;">PEDAGÓGICO</h1>
                    <div class="dashboard-grid">
                        <div class="card" onclick="showSection('solicitacoes')">
                            <h3>Solicitações Pendentes</h3>
                            <p><?php echo $solicitacoes_pendentes; ?></p>
                        </div>
                        <div class="card" onclick="showSection('alunos')">
                            <h3>Alunos Incompletos</h3>
                            <p><?php echo count($alunos_incompletos); ?></p>
                        </div>
                        <div class="card">
                            <h3>Total de Solicitações</h3>
                            <p><?php echo $total_solicitacoes; ?></p>
                        </div>
                        <div class="card">
                            <h3>Aguardando Responsável</h3>
                            <p><?php echo $aguardando_responsavel; ?></p>
                        </div>
                        <div class="card">
                            <h3>Solicitações Liberadas</h3>
                            <p><?php echo $solicitacoes_liberadas; ?></p>
                        </div>
                    </div>
                </div>
            </div>

            <div id="solicitacoes" class="section" style="display:none;">
                <div class="container">
                    <h3>Solicitações de Saída</h3>
                    <?php
                    $solicitacoes = $conn->query("
                        SELECT s.*, a.nome as aluno_nome, a.matricula, uc.nome as uc_nome, t.nome as turma_nome, c.nome as curso_nome
                        FROM solicitacao s
                        JOIN aluno a ON s.id_aluno = a.id_aluno
                        LEFT JOIN unidade_curricular uc ON s.id_curricular = uc.id_curricular
                        LEFT JOIN matricula m ON a.id_aluno = m.id_aluno
                        LEFT JOIN turma t ON m.id_turma = t.id_turma
                        LEFT JOIN curso c ON t.id_curso = c.id_curso
                        WHERE s.status IN ('solicitado', 'autorizado', 'liberado')
                        ORDER BY s.data_solicitacao DESC, s.hora_solicitacao DESC
                    ")->fetchAll();

                    if (empty($solicitacoes)): ?>
                        <p>Nenhuma solicitação encontrada.</p>
                    <?php else: ?>
                        <?php foreach ($solicitacoes as $s): ?>
                            <div class="solicitacao-card">
                                <div class="solicitacao-header">
                                    <h3><?php echo htmlspecialchars($s['aluno_nome']); ?> (<?php echo htmlspecialchars($s['matricula']); ?>)</h3>
                                    <span class="status-badge" style="background-color: <?php echo getStatusColor($s['status']); ?>;">
                                        <?php echo getStatusText($s['status']); ?>
                                    </span>
                                </div>
                                <div class="solicitacao-info">
                                    <div class="info-item">
                                        <strong>Curso:</strong> <?php echo htmlspecialchars($s['curso_nome'] ?? 'N/A'); ?>
                                    </div>
                                    <div class="info-item">
                                        <function_calls>
                                            <invoke name="artifacts">
                                                <parameter name="command">update</parameter>
                                                <parameter name="id">index_php_corrigido</parameter>
                                                <parameter name="old_str">
                                                    <div class="info-item">
                                                </parameter>
                                                <parameter name="new_str">
                                                    <div class="info-item">
                                                        <strong>Turma:</strong> <?php echo htmlspecialchars($s['turma_nome'] ?? 'N/A'); ?>
                                                    </div>
                                                    <div class="info-item">
                                                        <strong>UC:</strong> <?php echo htmlspecialchars($s['uc_nome'] ?? 'N/A'); ?>
                                                    </div>
                                                    <div class="info-item">
                                                        <strong>Data/Hora:</strong> <?php echo date('d/m/Y H:i', strtotime($s['data_solicitacao'] . ' ' . $s['hora_solicitacao'])); ?>
                                                    </div>
                                                    <div class="info-item">
                                                        <strong>Motivo:</strong> <?php echo htmlspecialchars($s['motivo']); ?>
                                                    </div>
                                    </div>
                                    <?php if ($s['status'] == 'solicitado'): ?>
                                        <div class="action-buttons">
                                            <form method="POST" style="display: inline;">
                                                <input type="hidden" name="id_solicitacao" value="<?php echo $s['id_solicitacao']; ?>">
                                                <input type="hidden" name="acao" value="autorizar">
                                                <button type="submit" name="autorizar_saida" style="padding: 8px 16px; background: #28a745; color: white; border: none; border-radius: 5px; cursor: pointer;">Autorizar</button>
                                            </form>
                                            <form method="POST" style="display: inline;">
                                                <input type="hidden" name="id_solicitacao" value="<?php echo $s['id_solicitacao']; ?>">
                                                <input type="hidden" name="acao" value="rejeitar">
                                                <button type="submit" name="autorizar_saida" style="padding: 8px 16px; background: #dc3545; color: white; border: none; border-radius: 5px; cursor: pointer;">Rejeitar</button>
                                            </form>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                            </div>
                </div>
                <div id="cursos" class="section" style="display:none;">
                    <div class="container">
                        <h3>Gerenciar Cursos</h3>
                        <form method="POST">
                            <input type="text" name="nome_curso" placeholder="Nome do curso" required>
                            <button type="submit" name="cadastrar_curso">Cadastrar</button>
                        </form>
                        <table>
                            <tr>
                                <th>Nome</th>
                                <th>Ações</th>
                            </tr>
                            <?php foreach ($cursos as $c): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($c['nome']); ?></td>
                                    <td>
                                        <form method="POST" style="display:inline;">
                                            <input type="hidden" name="id_curso" value="<?php echo $c['id_curso']; ?>">
                                            <button type="submit" name="deletar_curso">Excluir</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </table>
                    </div>
                </div>

                <div id="ucs" class="section" style="display:none;">
                    <div class="container">
                        <h3>Gerenciar Unidades Curriculares</h3>
                        <form method="POST">
                            <select name="id_curso" required>
                                <option value="">Selecione o curso</option>
                                <?php foreach ($cursos as $c): ?>
                                    <option value="<?php echo $c['id_curso']; ?>"><?php echo htmlspecialchars($c['nome']); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <input type="text" name="nome_uc" placeholder="Nome da UC" required>
                            <input type="time" name="carga_horaria" placeholder="Carga horária" required>
                            <button type="submit" name="cadastrar_uc">Cadastrar</button>
                        </form>
                        <table>
                            <tr>
                                <th>Curso</th>
                                <th>Nome</th>
                                <th>Carga Horária</th>
                            </tr>
                            <?php foreach ($ucs as $uc): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($uc['curso_nome']); ?></td>
                                    <td><?php echo htmlspecialchars($uc['nome']); ?></td>
                                    <td><?php echo $uc['carga_horaria']; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </table>
                    </div>
                </div>

                <div id="turmas" class="section" style="display:none;">
                    <div class="container">
                        <h3>Gerenciar Turmas</h3>
                        <form method="POST">
                            <select name="id_curso_turma" required>
                                <option value="">Selecione o curso</option>
                                <?php foreach ($cursos as $c): ?>
                                    <option value="<?php echo $c['id_curso']; ?>"><?php echo htmlspecialchars($c['nome']); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <input type="text" name="nome_turma" placeholder="Nome da turma" required>
                            <button type="submit" name="cadastrar_turma">Cadastrar</button>
                        </form>
                        <table>
                            <tr>
                                <th>Curso</th>
                                <th>Nome</th>
                                <th>Alunos Matriculados</th>
                            </tr>
                            <?php foreach ($turmas as $t): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($t['curso_nome']); ?></td>
                                    <td><?php echo htmlspecialchars($t['nome']); ?></td>
                                    <td><?php echo $conn->query("SELECT COUNT(*) as total FROM matricula WHERE id_turma = " . $t['id_turma'])->fetch()['total']; ?></td>
                                </tr>
                            <?php endforeach; ?>
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
                            <?php foreach ($alunos_incompletos as $aluno): ?>
                                <div style="border: 1px solid #ccc; padding: 10px; margin: 10px 0;">
                                    <h5><?php echo htmlspecialchars($aluno['nome']); ?> (<?php echo htmlspecialchars($aluno['matricula']); ?>)</h5>
                                    <form method="POST">
                                        <input type="hidden" name="id_aluno" value="<?php echo $aluno['id_aluno']; ?>">
                                        <input type="hidden" name="id_aluno_reset" value="<?php echo $aluno['id_aluno']; ?>">
                                        <input type="text" name="responsavel" placeholder="Nome do responsável" required>
                                        <input type="text" name="contato_responsavel" placeholder="Contato do responsável" required>
                                        <input type="text" name="empresa" placeholder="Nome da empresa" required>
                                        <button type="submit" name="completar_aluno">Completar Cadastro</button>
                                        <button type="submit" name="resetar_senha_aluno" formnovalidate style="background: #ffc107; margin-left: 10px;">Resetar Senha</button>
                                    </form>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>

                        <h4>Listar Matrículas por Turma</h4>
                        <select onchange="carregarMatriculas(this.value)">
                            <option value="">Selecione uma turma</option>
                            <?php foreach ($turmas as $t): ?>
                                <option value="<?php echo $t['id_turma']; ?>"><?php echo htmlspecialchars($t['nome']); ?> - <?php echo htmlspecialchars($t['curso_nome']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div id="matriculas_turma"></div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- PAINEL INSTRUTOR -->
            <?php if ($tipo_user == 'instrutor'): ?>
                <div id="dashboard_instrutor" class="section">
                    <div class="dashboard-cards">
                        <div class="card">
                            <h3>Minhas Turmas</h3>
                            <p><?php echo count($turmas_instrutor); ?></p>
                        </div>
                        <div class="card">
                            <h3>Total de Alunos</h3>
                            <p><?php echo $total_alunos; ?></p>
                        </div>
                        <div class="card">
                            <h3>Solicitações Pendentes</h3>
                            <p><?php echo $solicitacoes_pendentes; ?></p>
                        </div>
                    </div>

                    <h2>Minhas Turmas</h2>
                    <div class="turmas-grid">
                        <?php if (empty($turmas_instrutor)): ?>
                            <p>Nenhuma turma encontrada.</p>
                        <?php else: ?>
                            <?php foreach ($turmas_instrutor as $turma): ?>
                                <div class="turma-card">
                                    <h4><?php echo htmlspecialchars($turma['turma_nome']); ?></h4>
                                    <p><strong>Curso:</strong> <?php echo htmlspecialchars($turma['curso_nome']); ?></p>
                                    <p><strong>Alunos Matriculados:</strong> <?php echo $turma['total_alunos']; ?></p>
                                    <a href="#" onclick="showSection('turmas_instrutor'); return false;" class="action-btn">Ver Alunos</a>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <div id="turmas_instrutor" class="section" style="display:none;">
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
                                    <tr>
                                        <th>Nome</th>
                                        <th>Matrícula</th>
                                    </tr>
                                    <?php
                                    $alunos_turma = $conn->prepare("SELECT a.nome, a.matricula FROM aluno a JOIN matricula m ON a.id_aluno = m.id_aluno WHERE m.id_turma = ? ORDER BY a.nome");
                                    $alunos_turma->execute([$turma['id_turma']]);
                                    foreach ($alunos_turma->fetchAll() as $aluno) {
                                        echo "<tr><td>" . htmlspecialchars($aluno['nome']) . "</td><td>" . htmlspecialchars($aluno['matricula']) . "</td></tr>";
                                    }
                                    ?>
                                </table>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div id="solicitacoes_instrutor" class="section" style="display:none;">
                    <div class="container">
                        <h3>Solicitações para Liberação</h3>
                        <?php
                        $solicitacoes_instrutor = $conn->query("SELECT s.*, a.nome as aluno_nome, uc.nome as uc_nome FROM solicitacao s JOIN aluno a ON s.id_aluno = a.id_aluno LEFT JOIN unidade_curricular uc ON s.id_curricular = uc.id_curricular WHERE s.status = 'autorizado' ORDER BY s.data_autorizada DESC")->fetchAll();
                        if (empty($solicitacoes_instrutor)): ?>
                            <p>Nenhuma solicitação autorizada pendente.</p>
                        <?php else: ?>
                            <?php foreach ($solicitacoes_instrutor as $s): ?>
                                <div style="border: 1px solid #ccc; padding: 10px; margin: 10px 0;">
                                    <p><strong>Aluno:</strong> <?php echo htmlspecialchars($s['aluno_nome']); ?></p>
                                    <p><strong>UC:</strong> <?php echo htmlspecialchars($s['uc_nome']); ?></p>
                                    <p><strong>Motivo:</strong> <?php echo htmlspecialchars($s['motivo']); ?></p>
                                    <p><strong>Data Autorização:</strong> <?php echo date('d/m/Y H:i', strtotime($s['data_autorizada'])); ?></p>
                                    <form method="POST" style="display:inline;">
                                        <input type="hidden" name="id_solicitacao" value="<?php echo $s['id_solicitacao']; ?>">
                                        <input type="hidden" name="acao_instrutor" value="autorizar">
                                        <button type="submit" name="instrutor_acao">Liberar Saída</button>
                                    </form>
                                    <form method="POST" style="display:inline;">
                                        <input type="hidden" name="id_solicitacao" value="<?php echo $s['id_solicitacao']; ?>">
                                        <input type="hidden" name="acao_instrutor" value="rejeitar">
                                        <button type="submit" name="instrutor_acao">Rejeitar</button>
                                    </form>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- PAINEL PORTARIA -->
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
                    </div>
                </div>
            <?php endif; ?>
            </div>
</body>

</html>
</parameter>