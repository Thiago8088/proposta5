<?php
session_start();
if (!isset($_SESSION['id'])) {
    header("Location: login_cadastro.php");
    exit;
}

require("db/conexao.php");

$user_id = $_SESSION['id'];
$stmt = $conn->prepare("SELECT * FROM usuarios WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

$tipo_usuario = $user['tipo_usuario'];

if ($tipo_usuario == 'aluno') {
    $idade = date_diff(date_create($user['data_nascimento']), date_create('today'))->y;
    $menor = $idade < 18;

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
            $stmt = $conn->prepare("INSERT INTO solicitacoes (usuario_id, nome_aluno, curso, instrutor, turno, modalidade, turma, motivo) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$user['id'], $user['nome'], $curso, $instrutor, $turno, $modalidade, $turma, $motivo]);

            $msg = "Solicitação enviada com sucesso!";
        }
    }
}

if ($tipo_usuario == 'admin') {
    $total_solicitacoes = $conn->query("SELECT COUNT(*) as total FROM solicitacoes")->fetch()['total'];
    $solicitacoes_pendentes = $conn->query("SELECT COUNT(*) as total FROM solicitacoes WHERE status = 'pendente'")->fetch()['total'];
    $total_liberados = $conn->query("SELECT COUNT(*) as total FROM solicitacoes WHERE status = 'aprovada'")->fetch()['total'];

    if (isset($_POST['aprovar'])) {
        $solicitacao_id = $_POST['solicitacao_id'];
        $conn->prepare("UPDATE solicitacoes SET status = 'aprovada' WHERE id = ?")->execute([$solicitacao_id]);
        $msg = "Solicitação aprovada!";
        $total_solicitacoes = $conn->query("SELECT COUNT(*) as total FROM solicitacoes")->fetch()['total'];
        $solicitacoes_pendentes = $conn->query("SELECT COUNT(*) as total FROM solicitacoes WHERE status = 'pendente'")->fetch()['total'];
        $total_liberados = $conn->query("SELECT COUNT(*) as total FROM solicitacoes WHERE status = 'aprovada'")->fetch()['total'];
    } elseif (isset($_POST['rejeitar'])) {
        $solicitacao_id = $_POST['solicitacao_id'];
        $conn->prepare("UPDATE solicitacoes SET status = 'rejeitada' WHERE id = ?")->execute([$solicitacao_id]);
        $msg = "Solicitação rejeitada!";
        $total_solicitacoes = $conn->query("SELECT COUNT(*) as total FROM solicitacoes")->fetch()['total'];
        $solicitacoes_pendentes = $conn->query("SELECT COUNT(*) as total FROM solicitacoes WHERE status = 'pendente'")->fetch()['total'];
        $total_liberados = $conn->query("SELECT COUNT(*) as total FROM solicitacoes WHERE status = 'aprovada'")->fetch()['total'];
    }

    if (isset($_POST['adicionar_curso'])) {
        $nome_curso = LimpaPost($_POST['nome_curso']);
        if (!empty($nome_curso)) {
            try {
                $stmt = $conn->prepare("INSERT INTO cursos (nome) VALUES (?)");
                $stmt->execute([$nome_curso]);
                $msg = "Curso adicionado com sucesso!";
            } catch (PDOException $e) {
                if ($e->getCode() == 23000) {
                    $msg = "Curso já existe!";
                } else {
                    $msg = "Erro ao adicionar curso: " . $e->getMessage();
                }
            }
        } else {
            $msg = "Nome do curso não pode ser vazio!";
        }
    } elseif (isset($_POST['deletar_curso'])) {
        $curso_id = $_POST['curso_id'];
        $conn->prepare("DELETE FROM cursos WHERE id = ?")->execute([$curso_id]);
        $msg = "Curso deletado com sucesso!";
    }

    if (isset($_POST['adicionar_empresa'])) {
        $nome_empresa = LimpaPost($_POST['nome_empresa']);
        $contato_empresa = LimpaPost($_POST['contato_empresa']);
        if (!empty($nome_empresa) && !empty($contato_empresa)) {
            try {
                $stmt = $conn->prepare("INSERT INTO empresas (nome, contato) VALUES (?, ?)");
                $stmt->execute([$nome_empresa, $contato_empresa]);
                $msg = "Empresa adicionada com sucesso!";
            } catch (PDOException $e) {
                if ($e->getCode() == 23000) {
                    $msg = "Empresa já existe!";
                } else {
                    $msg = "Erro ao adicionar empresa: " . $e->getMessage();
                }
            }
        } else {
            $msg = "Nome e contato da empresa não podem ser vazios!";
        }
    } elseif (isset($_POST['deletar_empresa'])) {
        $empresa_id = $_POST['empresa_id'];
        $stmt = $conn->prepare("SELECT COUNT(*) as total FROM usuarios WHERE empresa = (SELECT nome FROM empresas WHERE id = ?)");
        $stmt->execute([$empresa_id]);
        $count = $stmt->fetch()['total'];
        if ($count > 0) {
            $msg = "Não é possível deletar empresa com alunos vinculados!";
        } else {
            $conn->prepare("DELETE FROM empresas WHERE id = ?")->execute([$empresa_id]);
            $msg = "Empresa deletada com sucesso!";
        }
    }

    $cursos = $conn->query("SELECT * FROM cursos ORDER BY nome")->fetchAll();

    $empresas = $conn->query("SELECT * FROM empresas ORDER BY nome")->fetchAll();
}
?>

<!DOCTYPE html>
<html>

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="">
    <meta name="keywords" content="">
    <title>Sistema SENAI</title>
    <link rel="stylesheet" href="css/estilo.css">
    <script>
        function toggleMenu() {
            var menu = document.getElementById('menu');
            if (menu.style.display === 'block') {
                menu.style.display = 'none';
            } else {
                menu.style.display = 'block';
            }
        }

        function showSection(section) {
            var sections = document.querySelectorAll('.section');
            sections.forEach(function(sec) {
                sec.style.display = 'none';
            });
            document.getElementById(section).style.display = 'block';
        }

        function showList(type) {
            var lists = document.querySelectorAll('.list');
            lists.forEach(function(list) {
                list.style.display = 'none';
            });
            document.getElementById('list-' + type).style.display = 'block';
        }
    </script>
</head>

<body>
    <?php if ($tipo_usuario == 'aluno'): ?>
        <nav class="navbar">
            <div class="navbar-left">
                <span><?php echo $user['nome']; ?></span>
            </div>
            <div class="navbar-right">
                <button onclick="toggleMenu()">Menu</button>
                <div id="menu" class="dropdown-menu" style="display:none;">
                    <a href="#" onclick="showSection('info')">Informações</a>
                    <a href="sair.php">Sair</a>
                </div>
            </div>
        </nav>
        <div id="info" class="section" style="display:none;">
            <h3>Informações Pessoais</h3>
            <p><strong>Nome:</strong> <?php echo $user['nome']; ?></p>
            <p><strong>Email:</strong> <?php echo $user['email']; ?></p>
            <p><strong>Curso:</strong> <?php echo $user['curso_tipo']; ?></p>
            <?php if ($user['curso_tipo'] == 'aprendiz' && !empty($user['empresa'])): ?>
                <p><strong>Empresa:</strong> <?php echo $user['empresa']; ?></p>
            <?php endif; ?>
        </div>

        <div class="container">
            <div class="msg">
                <?php if (isset($msg)) echo "<p>$msg</p>"; ?>
            </div>
            <form method="POST">
                <b>Curso:</b><br>
                <select name="curso" required>
                    <option value="">Selecione um curso</option>
                    <?php
                    $cursos_aluno = $conn->query("SELECT * FROM cursos ORDER BY nome")->fetchAll();
                    foreach ($cursos_aluno as $curso) {
                        echo "<option value='" . $curso['nome'] . "'>" . $curso['nome'] . "</option>";
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
                <button name="enviar">Enviar Solicitação</button>
            </form>
        </div>

    <?php elseif ($tipo_usuario == 'admin'): ?>
        <nav class="navbar">
            <div class="navbar-left">
                <span><?php echo $user['nome']; ?></span>
            </div>
            <div class="navbar-right">
                <button onclick="toggleMenu()">Menu</button>
                <div id="menu" class="dropdown-menu" style="display:none;">
                    <a href="#" onclick="showSection('info')">Informações</a>
                    <a href="#" onclick="showSection('cursos')">Gerenciar Cursos</a>
                    <a href="#" onclick="showSection('empresas')">Gerenciar Empresas</a>
                    <a href="sair.php">Sair</a>
                </div>
            </div>
        </nav>
        <div id="info" class="section" style="display:none;">
            <h3>Informações Pessoais</h3>
            <p><strong>Nome:</strong> <?php echo $user['nome']; ?></p>
            <p><strong>Email:</strong> <?php echo $user['email']; ?></p>
        </div>
        <div id="cursos" class="section" style="display:none;">
            <h3>Gerenciar Cursos</h3>
            <form method="POST">
                <b>Adicionar Novo Curso:</b><br>
                <input type="text" name="nome_curso" placeholder="Nome do curso" required>
                <button name="adicionar_curso">Adicionar</button>
            </form>
            <br>
            <h4>Cursos Existentes</h4>
            <?php if (empty($cursos)): ?>
                <p>Nenhum curso cadastrado.</p>
            <?php else: ?>
                <table>
                    <tr>
                        <th>Nome do Curso</th>
                        <th>Ações</th>
                    </tr>
                    <?php foreach ($cursos as $curso): ?>
                        <tr>
                            <td><?php echo $curso['nome']; ?></td>
                            <td>
                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="curso_id" value="<?php echo $curso['id']; ?>">
                                    <button name="deletar_curso">Deletar</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </table>
            <?php endif; ?>
        </div>
        <div id="empresas" class="section" style="display:none;">
            <h3>Gerenciar Empresas</h3>
            <form method="POST">
                <b>Adicionar Nova Empresa:</b><br>
                <input type="text" name="nome_empresa" placeholder="Nome da empresa" required>
                <input type="text" name="contato_empresa" placeholder="Contato (WhatsApp)" required>
                <button name="adicionar_empresa">Adicionar</button>
            </form>
            <br>
            <h4>Empresas Existentes</h4>
            <?php if (empty($empresas)): ?>
                <p>Nenhuma empresa cadastrada.</p>
            <?php else: ?>
                <table>
                    <tr>
                        <th>Nome da Empresa</th>
                        <th>Contato</th>
                        <th>Ações</th>
                    </tr>
                    <?php foreach ($empresas as $empresa): ?>
                        <tr>
                            <td><?php echo $empresa['nome']; ?></td>
                            <td><?php echo $empresa['contato']; ?></td>
                            <td>
                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="empresa_id" value="<?php echo $empresa['id']; ?>">
                                    <button name="deletar_empresa">Deletar</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </table>
            <?php endif; ?>
        </div>
        <div class="container">
            <h1 style="text-align: center;">Administrativo</h1>
            <div class="msg">
                <?php if (isset($msg)) echo "<p>$msg</p>"; ?>
            </div>
            <div class="cards">
                <div class="card" onclick="showList('pendentes')">
                    <h3>Solicitações Pendentes</h3>
                    <p><?php echo $solicitacoes_pendentes ?: 0; ?></p>
                </div>
                <div class="card" onclick="showList('total')">
                    <h3>Total de Solicitações</h3>
                    <p><?php echo $total_solicitacoes ?: 0; ?></p>
                </div>
                <div class="card" onclick="showList('liberados')">
                    <h3>Total de Alunos Liberados</h3>
                    <p><?php echo $total_liberados ?: 0; ?></p>
                </div>
            </div>
            <div id="list-pendentes" class="list" style="display:none;">
                <h4>Solicitações Pendentes</h4>
                <?php
                $pendentes = $conn->query("SELECT s.*, u.nome as aluno_nome, u.empresa, u.telefone, e.contato as empresa_contato FROM solicitacoes s JOIN usuarios u ON s.usuario_id = u.id LEFT JOIN empresas e ON u.empresa = e.nome WHERE s.status = 'pendente' ORDER BY s.data_solicitacao DESC")->fetchAll();
                if (empty($pendentes)): ?>
                    <p>Nenhuma solicitação pendente.</p>
                <?php else: ?>
                    <table>
                        <tr>
                            <th>Nome do Aluno</th>
                            <th>Empresa</th>
                            <th>WhatsApp</th>
                            <th>Curso</th>
                            <th>Horário / Modalidade</th>
                            <th>Motivo</th>
                            <th>Data/Hora</th>
                            <th>Ações</th>
                        </tr>
                        <?php foreach ($pendentes as $s): ?>
                            <tr>
                                <td><?php echo $s['aluno_nome']; ?></td>
                                <td><?php echo $s['empresa'] ?: '-'; ?></td>
                                <td><?php if (!empty($s['empresa_contato'])): ?><a href="https://wa.me/55<?php echo preg_replace('/\D/', '', $s['empresa_contato']); ?>" target="_blank"><?php echo $s['empresa_contato']; ?></a><?php else: ?>-<?php endif; ?></td>
                                <td><?php echo $s['curso']; ?></td>
                                <td><?php echo $s['turno'] . ' / ' . $s['modalidade']; ?></td>
                                <td><?php echo $s['motivo']; ?></td>
                                <td><?php echo date('d/m/Y H:i', strtotime($s['data_solicitacao'])); ?></td>
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
                <h4>Todas as Solicitações</h4>
                <?php
                $todas = $conn->query("SELECT s.*, u.nome as aluno_nome FROM solicitacoes s JOIN usuarios u ON s.usuario_id = u.id ORDER BY s.data_solicitacao DESC LIMIT 50")->fetchAll();
                if (empty($todas)): ?>
                    <p>Nenhuma solicitação.</p>
                <?php else: ?>
                    <table>
                        <tr>
                            <th>Nome do Aluno</th>
                            <th>Curso</th>
                            <th>Status</th>
                            <th>Ações</th>
                        </tr>
                        <?php foreach ($todas as $s): ?>
                            <tr>
                                <td><?php echo $s['aluno_nome']; ?></td>
                                <td><?php echo $s['curso']; ?></td>
                                <td><?php echo ucfirst($s['status']); ?></td>
                                <td>
                                    <?php if ($s['status'] == 'aprovada'): ?>
                                        <a href="gerar_pdf.php?id=<?php echo $s['id']; ?>" target="_blank">Baixar Formulário</a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </table>
                <?php endif; ?>
            </div>
            <div id="list-liberados" class="list" style="display:none;">
                <h4>Alunos Liberados</h4>
                <?php
                $liberados = $conn->query("SELECT s.*, u.nome as aluno_nome FROM solicitacoes s JOIN usuarios u ON s.usuario_id = u.id WHERE s.status = 'aprovada' ORDER BY s.data_solicitacao DESC LIMIT 50")->fetchAll();
                if (empty($liberados)): ?>
                    <p>Nenhum aluno liberado.</p>
                <?php else: ?>
                    <table>
                        <tr>
                            <th>Nome do Aluno</th>
                            <th>Curso</th>
                            <th>Data/Hora</th>
                        </tr>
                        <?php foreach ($liberados as $s): ?>
                            <tr>
                                <td><?php echo $s['aluno_nome']; ?></td>
                                <td><?php echo $s['curso']; ?></td>
                                <td><?php echo date('d/m/Y H:i', strtotime($s['data_solicitacao'])); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</body>

</html>