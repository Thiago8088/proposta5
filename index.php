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

// Carregar dados do usuário
if ($tipo_user == 'aluno') {
    $stmt = $conn->prepare("SELECT * FROM aluno WHERE id_aluno = ?");
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

// --- LÓGICA ALUNO ---
if ($tipo_user == 'aluno') {
    if (isset($_POST['enviar'])) {
        $curso = LimpaPost($_POST['curso']);
        $instrutor = LimpaPost($_POST['instrutor']);
        $turno = LimpaPost($_POST['turno']);
        $modalidade = LimpaPost($_POST['modalidade']);
        $turma = LimpaPost($_POST['turma']);
        $motivo = LimpaPost(($_POST['motivo'] == "Outros")) ? LimpaPost($_POST['motivo_outro']) : $_POST['motivo'];

        if (empty($curso) || empty($instrutor) || empty($turno) || empty($modalidade) || empty($turma) || empty($motivo)) {
            $msg = "Preencha todos os campos!";
        } else {
            try {
                $stmt = $conn->prepare("INSERT INTO solicitacoes (usuario_id, nome_aluno, curso, instrutor, turno, modalidade, turma, motivo, status, data_solicitacao) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pendente', NOW())");
                $stmt->execute([$user['id_aluno'], $user['nome'], $curso, $instrutor, $turno, $modalidade, $turma, $motivo]);
                $msg = "Solicitação enviada com sucesso!";
            } catch (PDOException $e) {
                $msg = "Erro ao enviar: " . $e->getMessage();
            }
        }
    }
}

// --- LÓGICA PEDAGÓGICO (ADMIN) ---
if ($tipo_user == 'pedagógico') {
    if (isset($_POST['aprovar'])) {
        $solicitacao_id = $_POST['solicitacao_id'];
        $conn->prepare("UPDATE solicitacoes SET status = 'aprovada' WHERE id = ?")->execute([$solicitacao_id]);
        $msg = "Solicitação aprovada!";
    } elseif (isset($_POST['rejeitar'])) {
        $solicitacao_id = $_POST['solicitacao_id'];
        $conn->prepare("UPDATE solicitacoes SET status = 'rejeitada' WHERE id = ?")->execute([$solicitacao_id]);
        $msg = "Solicitação rejeitada!";
    }

    if (isset($_POST['adicionar_curso'])) {
        $nome_curso = LimpaPost($_POST['nome_curso']);
        if (!empty($nome_curso)) {
            try {
                $stmt = $conn->prepare("INSERT INTO cursos (nome) VALUES (?)");
                $stmt->execute([$nome_curso]);
                $msg = "Curso adicionado!";
            } catch (PDOException $e) {
                $msg = "Erro: " . $e->getMessage();
            }
        }
    } elseif (isset($_POST['deletar_curso'])) {
        $curso_id = $_POST['curso_id'];
        $conn->prepare("DELETE FROM cursos WHERE id = ?")->execute([$curso_id]);
        $msg = "Curso deletado!";
    }

    if (isset($_POST['adicionar_empresa'])) {
        $nome_empresa = LimpaPost($_POST['nome_empresa']);
        $contato_empresa = LimpaPost($_POST['contato_empresa']);
        if (!empty($nome_empresa)) {
            try {
                $stmt = $conn->prepare("INSERT INTO empresas (nome, contato) VALUES (?, ?)");
                $stmt->execute([$nome_empresa, $contato_empresa]);
                $msg = "Empresa adicionada!";
            } catch (PDOException $e) {
                $msg = "Erro: " . $e->getMessage();
            }
        }
    } elseif (isset($_POST['deletar_empresa'])) {
        $empresa_id = $_POST['empresa_id'];
        $conn->prepare("DELETE FROM empresas WHERE id = ?")->execute([$empresa_id]);
        $msg = "Empresa deletada!";
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
        function toggleMenu() {
            var menu = document.getElementById('menu');
            menu.style.display = (menu.style.display === 'block') ? 'none' : 'block';
        }
        function showSection(section) {
            document.querySelectorAll('.section').forEach(sec => sec.style.display = 'none');
            document.getElementById(section).style.display = 'block';
        }
        function showList(type) {
            document.querySelectorAll('.list').forEach(l => l.style.display = 'none');
            document.getElementById('list-' + type).style.display = 'block';
        }
    </script>
</head>
<body>
    <nav class="navbar">
        <div class="navbar-left">
            <span>
                <?php 
                if ($tipo_user == 'aluno') echo "Aluno: ";
                elseif ($tipo_user == 'pedagógico') echo "Pedagógico: ";
                elseif ($tipo_user == 'instrutor') echo "Instrutor: ";
                elseif ($tipo_user == 'portaria') echo "Portaria: ";
                echo htmlspecialchars($user['nome']); 
                ?>
            </span>
        </div>
        <div class="navbar-right">
            <?php if ($tipo_user == 'aluno' || $tipo_user == 'pedagógico'): ?>
                <button onclick="toggleMenu()">Menu</button>
                <div id="menu" class="dropdown-menu" style="display:none;">
                    <?php if ($tipo_user == 'aluno'): ?>
                        <a href="#" onclick="showSection('info')">Informações</a>
                        <a href="#" onclick="showSection('solicitacao')">Nova Solicitação</a>
                    <?php elseif ($tipo_user == 'pedagógico'): ?>
                        <a href="#" onclick="showSection('dashboard')">Dashboard</a>
                        <a href="#" onclick="showSection('cursos')">Gerenciar Cursos</a>
                        <a href="#" onclick="showSection('empresas')">Gerenciar Empresas</a>
                    <?php endif; ?>
                    <a href="sair.php">Sair</a>
                </div>
            <?php else: ?>
                <a href="sair.php" style="color: white; text-decoration: none;">Sair</a>
            <?php endif; ?>
        </div>
    </nav>

    <div class="container-content" style="padding: 20px;">
        
        <!-- PAINEL ALUNO -->
        <?php if ($tipo_user == 'aluno'): ?>
            <div id="info" class="section" style="display:none;">
                <h3>Informações Pessoais</h3>
                <p><strong>Nome:</strong> <?php echo htmlspecialchars($user['nome']); ?></p>
                <p><strong>Matrícula:</strong> <?php echo htmlspecialchars($user['matricula']); ?></p>
                <p><strong>CPF:</strong> <?php echo htmlspecialchars($user['cpf']); ?></p>
                <p><strong>Celular:</strong> <?php echo htmlspecialchars($user['celular']); ?></p>
            </div>

            <div id="solicitacao" class="section">
                <div class="container">
                    <h3>Nova Solicitação</h3>
                    <div class="msg">
                        <?php if (!empty($msg)) echo "<p>$msg</p>"; ?>
                    </div>
                    <form method="POST">
                        <b>Curso:</b><br>
                        <select name="curso" required>
                            <option value="">Selecione um curso</option>
                            <?php
                            try {
                                $cursos_aluno = $conn->query("SELECT * FROM cursos ORDER BY nome")->fetchAll();
                                foreach ($cursos_aluno as $c) {
                                    echo "<option value='" . htmlspecialchars($c['nome']) . "'>" . htmlspecialchars($c['nome']) . "</option>";
                                }
                            } catch (PDOException $e) {
                                echo "<option value=''>Erro ao carregar cursos</option>";
                            }
                            ?>
                        </select><br><br>
                        
                        <b>Instrutor:</b><br>
                        <input type="text" name="instrutor" required><br><br>
                        
                        <b>Turno:</b><br>
                        <select name="turno" required>
                            <option>Matutino</option>
                            <option>Vespertino</option>
                            <option>Noturno</option>
                        </select><br><br>
                        
                        <b>Modalidade:</b><br>
                        <select name="modalidade" required>
                            <option>Presencial</option>
                            <option>EAD</option>
                            <option>Híbrido</option>
                        </select><br><br>
                        
                        <b>Turma:</b><br>
                        <input type="text" name="turma" required><br><br>
                        
                        <b>Motivo da Liberação:</b><br>
                        <input type="radio" name="motivo" value="Consulta médica" required> Consulta médica<br>
                        <input type="radio" name="motivo" value="Consulta odontológica"> Consulta odontológica<br>
                        <input type="radio" name="motivo" value="Exames médicos"> Exames médicos<br>
                        <input type="radio" name="motivo" value="Problemas de saúde"> Problemas de saúde<br>
                        <input type="radio" name="motivo" value="Solicitação da empresa"> Solicitação da empresa<br>
                        <input type="radio" name="motivo" value="Solicitação da família"> Solicitação da família<br>
                        <input type="radio" name="motivo" value="Viagem particular"> Viagem particular<br>
                        <input type="radio" name="motivo" value="Viagem a trabalho"> Viagem a trabalho<br>
                        <input type="radio" name="motivo" value="Treinamento a trabalho"> Treinamento a trabalho<br>
                        <input type="radio" name="motivo" value="Outros" onclick="document.getElementById('outro').style.display='block'"> Outros<br>
                        <div id="outro" style="display:none;">
                            <input type="text" name="motivo_outro" placeholder="Descreva o motivo">
                        </div>
                        <br><br>
                        <button type="submit" name="enviar">Enviar Solicitação</button>
                    </form>
                </div>
            </div>
        <?php endif; ?>

        <!-- PAINEL PEDAGÓGICO -->
        <?php if ($tipo_user == 'pedagógico'): ?>
            <?php
            $total_solicitacoes = $conn->query("SELECT COUNT(*) as total FROM solicitacoes")->fetch()['total'];
            $solicitacoes_pendentes = $conn->query("SELECT COUNT(*) as total FROM solicitacoes WHERE status = 'pendente'")->fetch()['total'];
            $total_liberados = $conn->query("SELECT COUNT(*) as total FROM solicitacoes WHERE status = 'aprovada'")->fetch()['total'];
            $cursos = $conn->query("SELECT * FROM cursos ORDER BY nome")->fetchAll();
            $empresas = $conn->query("SELECT * FROM empresas ORDER BY nome")->fetchAll();
            ?>
            <div id="dashboard" class="section">
                <div class="container">
                    <h1 style="text-align: center;">Dashboard</h1>
                    <div class="msg"><?php if (!empty($msg)) echo "<p>$msg</p>"; ?></div>
                    
                    <div class="cards">
                        <div class="card" onclick="showList('pendentes')">
                            <h3>Pendentes</h3>
                            <p><?php echo $solicitacoes_pendentes; ?></p>
                        </div>
                        <div class="card" onclick="showList('total')">
                            <h3>Total</h3>
                            <p><?php echo $total_solicitacoes; ?></p>
                        </div>
                        <div class="card" onclick="showList('liberados')">
                            <h3>Liberados</h3>
                            <p><?php echo $total_liberados; ?></p>
                        </div>
                    </div>

                    <div id="list-pendentes" class="list">
                        <h4>Solicitações Pendentes</h4>
                        <?php
                        $pendentes = $conn->query("SELECT s.*, u.nome as aluno_nome, u.contato_empresa, u.celular FROM solicitacoes s JOIN aluno u ON s.usuario_id = u.id_aluno WHERE s.status = 'pendente' ORDER BY s.data_solicitacao DESC")->fetchAll();
                        if (empty($pendentes)): ?>
                            <p>Nenhuma pendência.</p>
                        <?php else: ?>
                            <table>
                                <tr>
                                    <th>Aluno</th>
                                    <th>Curso</th>
                                    <th>Motivo</th>
                                    <th>Ações</th>
                                </tr>
                                <?php foreach ($pendentes as $s): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($s['aluno_nome']); ?></td>
                                        <td><?php echo htmlspecialchars($s['curso']); ?></td>
                                        <td><?php echo htmlspecialchars($s['motivo']); ?></td>
                                        <td>
                                            <form method="POST" style="display:inline;">
                                                <input type="hidden" name="solicitacao_id" value="<?php echo $s['id']; ?>">
                                                <button name="aprovar">Aprovar</button>
                                                <button name="rejeitar">Rejeitar</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </table>
                        <?php endif; ?>
                    </div>

                    <div id="list-total" class="list" style="display:none;">
                        <h4>Últimas Solicitações</h4>
                        <?php
                        $todas = $conn->query("SELECT s.*, u.nome as aluno_nome FROM solicitacoes s JOIN aluno u ON s.usuario_id = u.id_aluno ORDER BY s.data_solicitacao DESC LIMIT 20")->fetchAll();
                        ?>
                        <table>
                            <tr><th>Aluno</th><th>Status</th></tr>
                            <?php foreach ($todas as $s): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($s['aluno_nome']); ?></td>
                                    <td><?php echo htmlspecialchars($s['status']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </table>
                    </div>
                    
                     <div id="list-liberados" class="list" style="display:none;">
                        <h4>Alunos Liberados</h4>
                        <?php
                        $liberados = $conn->query("SELECT s.*, u.nome as aluno_nome FROM solicitacoes s JOIN aluno u ON s.usuario_id = u.id_aluno WHERE s.status = 'aprovada' ORDER BY s.data_solicitacao DESC LIMIT 20")->fetchAll();
                        ?>
                        <table>
                            <tr><th>Aluno</th><th>Curso</th></tr>
                            <?php foreach ($liberados as $s): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($s['aluno_nome']); ?></td>
                                    <td><?php echo htmlspecialchars($s['curso']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </table>
                    </div>
                </div>
            </div>

            <div id="cursos" class="section" style="display:none;">
                <div class="container">
                    <h3>Gerenciar Cursos</h3>
                    <form method="POST">
                        <input type="text" name="nome_curso" placeholder="Nome do curso" required>
                        <button name="adicionar_curso">Adicionar</button>
                    </form>
                    <br>
                    <ul>
                        <?php foreach ($cursos as $c): ?>
                            <li>
                                <?php echo htmlspecialchars($c['nome']); ?>
                                <form method="POST" style="display:inline; float:right;">
                                    <input type="hidden" name="curso_id" value="<?php echo $c['id']; ?>">
                                    <button name="deletar_curso">X</button>
                                </form>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>

            <div id="empresas" class="section" style="display:none;">
                <div class="container">
                    <h3>Gerenciar Empresas</h3>
                    <form method="POST">
                        <input type="text" name="nome_empresa" placeholder="Nome" required>
                        <input type="text" name="contato_empresa" placeholder="Contato" required>
                        <button name="adicionar_empresa">Adicionar</button>
                    </form>
                    <br>
                    <ul>
                        <?php foreach ($empresas as $e): ?>
                            <li>
                                <?php echo htmlspecialchars($e['nome']); ?>
                                <form method="POST" style="display:inline; float:right;">
                                    <input type="hidden" name="empresa_id" value="<?php echo $e['id']; ?>">
                                    <button name="deletar_empresa">X</button>
                                </form>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        <?php endif; ?>

        <!-- PAINEL INSTRUTOR -->
        <?php if ($tipo_user == 'instrutor'): ?>
            <div class="container">
                <h1>Bem-vindo, Instrutor!</h1>
                <p>Aqui você pode visualizar suas turmas e alunos.</p>
            </div>
        <?php endif; ?>

        <!-- PAINEL PORTARIA -->
        <?php if ($tipo_user == 'portaria'): ?>
            <div class="container">
                <h1>Controle de Acesso</h1>
                <p>Verifique a liberação de alunos.</p>
                
                <h3>Alunos Liberados Recentemente</h3>
                <?php
                $liberados = $conn->query("SELECT s.*, u.nome as aluno_nome, u.matricula FROM solicitacoes s JOIN aluno u ON s.usuario_id = u.id_aluno WHERE s.status = 'aprovada' ORDER BY s.data_solicitacao DESC LIMIT 20")->fetchAll();
                ?>
                <table>
                    <tr>
                        <th>Aluno</th>
                        <th>Matrícula</th>
                        <th>Curso</th>
                        <th>Data</th>
                    </tr>
                    <?php foreach ($liberados as $s): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($s['aluno_nome']); ?></td>
                            <td><?php echo htmlspecialchars($s['matricula']); ?></td>
                            <td><?php echo htmlspecialchars($s['curso']); ?></td>
                            <td><?php echo date('d/m/Y H:i', strtotime($s['data_solicitacao'])); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </table>
            </div>
        <?php endif; ?>

    </div>
</body>
</html>