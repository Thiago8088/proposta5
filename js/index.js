function showSection(section) {
    console.log('Mostrando seção:', section);

    document.querySelectorAll('.section').forEach(sec => {
        sec.style.display = 'none';
        sec.classList.remove('active');
    });

    const sectionElement = document.getElementById(section);
    if (sectionElement) {
        sectionElement.style.display = 'block';
        sectionElement.classList.add('active');
        console.log('Seção encontrada e exibida:', section);
    } else {
        console.error('Seção não encontrada:', section);
    }

    document.querySelectorAll('.navbar-menu a').forEach(link => {
        link.classList.remove('active');
    });

    if (event && event.target) {
        event.target.classList.add('active');
    }
}

function mostrarHistoricoTab(tipo) {
    console.log('Mostrando tab:', tipo);

    document.querySelectorAll('.tab-btn').forEach(btn => {
        btn.classList.remove('active');
    });

    if (event && event.target) {
        event.target.classList.add('active');
    }

    document.querySelectorAll('.historico-content').forEach(el => {
        el.style.display = 'none';
        el.classList.remove('active');
    });

    const elemento = document.getElementById('historico-' + tipo);
    if (elemento) {
        elemento.style.display = 'block';
        elemento.classList.add('active');
        console.log('Tab exibida:', tipo);
    } else {
        console.error('Tab não encontrada:', 'historico-' + tipo);
    }
}

window.addEventListener('DOMContentLoaded', function () {
    console.log('DOM carregado');

    const firstSection = document.querySelector('.navbar-menu a');
    if (firstSection) {
        firstSection.click();
        console.log('Primeira seção clicada');
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

window.addEventListener('DOMContentLoaded', function () {
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

function filtrarHistoricoSolicitacoes() {
    const turmaId = document.getElementById('filtro_turma_historico_sol').value;
    const cards = document.querySelectorAll('.historico-aluno-card');
    cards.forEach(card => {
        const cardTurma = card.getAttribute('data-turma-id');
        const mostrar = (turmaId === "" || cardTurma === turmaId);
        card.style.display = mostrar ? 'block' : 'none';
    });
}