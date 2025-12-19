function showSection(section) {
    console.log('Mostrando seção:', section);

    document.querySelectorAll('.section').forEach(function (sec) {
        sec.style.display = 'none';
        sec.classList.remove('active');
    });

    var sectionElement = document.getElementById(section);
    if (sectionElement) {
        sectionElement.style.display = 'block';
        sectionElement.classList.add('active');
    }

    document.querySelectorAll('.navbar-menu a').forEach(function (link) {
        link.classList.remove('active');
    });

    if (typeof event !== 'undefined' && event.target) {
        event.target.classList.add('active');
    }
}

function showList(listId) {
    document.querySelectorAll('.list').forEach(function (l) {
        l.style.display = 'none';
    });
    var list = document.getElementById('list-' + listId);
    if (list) {
        list.style.display = 'block';
    }
}

// ========================================
// TABS (HISTÓRICO)
// ========================================

function mostrarHistoricoTab(tipo) {
    document.querySelectorAll('.tab-btn').forEach(function (btn) {
        btn.classList.remove('active');
    });

    if (typeof event !== 'undefined' && event.target) {
        event.target.classList.add('active');
    }

    document.querySelectorAll('.historico-content').forEach(function (el) {
        el.style.display = 'none';
        el.classList.remove('active');
    });

    var elemento = document.getElementById('historico-' + tipo);
    if (elemento) {
        elemento.style.display = 'block';
        elemento.classList.add('active');
    }
}

// ========================================
// FORMULÁRIOS
// ========================================

function mostrarEdicao(tipo, id) {
    document.getElementById('display-' + tipo + '-' + id).style.display = 'none';
    document.getElementById('form-editar-' + tipo + '-' + id).style.display = 'table-row';
}

function ocultarEdicao(tipo, id) {
    document.getElementById('display-' + tipo + '-' + id).style.display = 'table-row';
    document.getElementById('form-editar-' + tipo + '-' + id).style.display = 'none';
}

function toggleOutro() {
    var motivo = document.getElementById('motivo');
    var motivoOutroDiv = document.getElementById('motivo_outro_div');
    if (motivo && motivoOutroDiv) {
        motivoOutroDiv.style.display = (motivo.value === '10') ? 'block' : 'none';
    }
}

// ========================================
// FILTROS
// ========================================

function filtrarSolicitacoesPorTurma() {
    var turmaId = document.getElementById('filtro_turma_solicitacao').value;
    var cards = document.querySelectorAll('.solicitacao-card-instrutor');

    cards.forEach(function (card) {
        var mostrar = (turmaId === "" || card.getAttribute('data-turma-id') === turmaId);
        card.style.display = mostrar ? 'block' : 'none';
    });
}

function filtrarLiberadosPorTurma() {
    var turmaId = document.getElementById('filtro_turma_liberados').value;
    var cards = document.querySelectorAll('.aluno-liberado-card');

    cards.forEach(function (card) {
        var mostrar = (turmaId === "" || card.getAttribute('data-turma-id') === turmaId);
        card.style.display = mostrar ? 'block' : 'none';
    });
}

function filtrarHistoricoSolicitacoes() {
    var turmaId = document.getElementById('filtro_turma_historico_sol').value;
    var cards = document.querySelectorAll('.historico-aluno-card');

    cards.forEach(function (card) {
        var mostrar = (turmaId === '' || card.getAttribute('data-turma-id') === turmaId);
        card.style.display = mostrar ? 'block' : 'none';
    });
}

function filtrarHistoricoAlunos() {
    var turmaId = document.getElementById('filtro_turma_historico').value;
    var status = document.getElementById('filtro_status_historico').value;
    var cards = document.querySelectorAll('.historico-aluno-card');

    cards.forEach(function (card) {
        var cardTurma = card.getAttribute('data-turma-id');
        var cardStatus = card.getAttribute('data-status');

        var mostrarTurma = (turmaId === "" || cardTurma === turmaId);
        var mostrarStatus = true;

        if (status !== "") {
            var statusArray = status.split(',');
            mostrarStatus = statusArray.includes(cardStatus);
        }

        card.style.display = (mostrarTurma && mostrarStatus) ? 'block' : 'none';
    });
}

function mostrarSolicitacoes(status) {
    document.querySelectorAll('.solicitacoes-detalhadas').forEach(function (el) {
        el.style.display = 'none';
    });
    var elemento = document.getElementById('solicitacoes-' + status);
    if (elemento) {
        elemento.style.display = 'block';
    }
}

// ========================================
// AJAX
// ========================================

function carregarMatriculas(turmaId) {
    var listas = document.querySelectorAll('.lista-alunos-turma');
    listas.forEach(function (lista) {
        lista.style.display = 'none';
    });

    if (turmaId) {
        var lista = document.getElementById('alunos-turma-' + turmaId);
        if (lista) {
            lista.style.display = 'block';
        }
    }
}

function carregarTurmasPorCurso(cursoId) {
    var selectTurma = document.getElementById('turma_aluno');
    if (!selectTurma) return;

    selectTurma.innerHTML = '<option value="">Carregando...</option>';

    if (!cursoId) {
        selectTurma.innerHTML = '<option value="">Primeiro selecione o curso</option>';
        return;
    }

    fetch('index.php?ajax=turmas_curso&id_curso=' + cursoId)
        .then(function (response) { return response.json(); })
        .then(function (turmas) {
            selectTurma.innerHTML = '<option value="">Selecione a Turma</option>';
            turmas.forEach(function (turma) {
                var option = document.createElement('option');
                option.value = turma.id_turma;
                option.textContent = turma.nome;
                selectTurma.appendChild(option);
            });
        })
        .catch(function (error) {
            console.error('Erro ao carregar turmas:', error);
            selectTurma.innerHTML = '<option value="">Erro ao carregar turmas</option>';
        });
}

function calcularCargaTotal() {
    var cursoId = document.getElementById('id_curso_turma').value;
    var inputCarga = document.getElementById('carga_horaria_total');

    if (!cursoId || !inputCarga) return;

    fetch('index.php?ajax=carga_curso&id_curso=' + cursoId)
        .then(function (response) { return response.json(); })
        .then(function (data) {
            if (data.carga_total) {
                inputCarga.value = data.carga_total;
            }
        })
        .catch(function (error) {
            console.error('Erro ao calcular carga:', error);
        });
}

// ========================================
// INICIALIZAÇÃO
// ========================================

window.addEventListener('DOMContentLoaded', function () {
    console.log('Sistema carregado');

    // Só mostrar primeira seção se não houver estado salvo
    if (!localStorage.getItem('ultima_secao')) {
        var firstSection = document.querySelector('.navbar-menu a');
        if (firstSection) {
            firstSection.click();
        }
    }
});