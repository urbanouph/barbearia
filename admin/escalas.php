<?php
require_once '../config/database.php';
verificarAutenticacao();

$mensagemSucesso = '';
$mensagemErro = '';

if (isset($_GET['sucesso_escala'])) $mensagemSucesso = "Escala cadastrada com sucesso!";
if (isset($_GET['sucesso_alt'])) $mensagemSucesso = "Escala atualizada com sucesso!";
if (isset($_GET['sucesso_del'])) $mensagemSucesso = "Turno excluído com sucesso!";

// 1. Criar/Atualizar escala semanal do profissional
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cadastrar_turno'])) {
    $profissionalId = !empty($_POST['profissional_id']) ? (int)$_POST['profissional_id'] : null;
    $diasSelecionados = $_POST['dias_semana'] ?? [];
    $horaInicio = $_POST['hora_inicio'];
    $horaFim = $_POST['hora_fim'];
    $tipoAtendimento = $_POST['tipo_atendimento'] ?? 'horario_marcado';
    $intervalo = ($tipoAtendimento === 'ordem_chegada') ? 30 : (int)($_POST['intervalo_minutos'] ?? 30);

    $temPausaAlmoco = !empty($_POST['tem_pausa_almoco']);
    $pausaInicio = $temPausaAlmoco && !empty($_POST['pausa_inicio']) ? $_POST['pausa_inicio'] : null;
    $pausaFim = $temPausaAlmoco && !empty($_POST['pausa_fim']) ? $_POST['pausa_fim'] : null;

    if (empty($diasSelecionados)) {
        $mensagemErro = "Selecione pelo menos um dia da semana para o expediente do profissional.";
    } elseif ($horaInicio >= $horaFim) {
        $mensagemErro = "A hora inicial deve ser anterior à hora final.";
    } elseif ($temPausaAlmoco && ($pausaInicio >= $pausaFim || $pausaInicio < $horaInicio || $pausaFim > $horaFim)) {
        $mensagemErro = "O horário de intervalo/almoço deve estar compreendido entre o início e o fim do expediente.";
    } else {
        $stmtDel = $pdo->prepare("DELETE FROM turnos_atendimento WHERE (profissional_id = ? OR (profissional_id IS NULL AND ? IS NULL)) AND dia_semana = ?");
        $stmtIns = $pdo->prepare("
            INSERT INTO turnos_atendimento (profissional_id, dia_semana, hora_inicio, hora_fim, intervalo_minutos, tipo_atendimento, pausa_inicio, pausa_fim) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");

        foreach ($diasSelecionados as $diaSemana) {
            $diaSemanaInt = (int)$diaSemana;
            $stmtDel->execute([$profissionalId, $profissionalId, $diaSemanaInt]);
            $stmtIns->execute([$profissionalId, $diaSemanaInt, $horaInicio, $horaFim, $intervalo, $tipoAtendimento, $pausaInicio, $pausaFim]);
        }

        header('Location: escalas.php?sucesso_escala=1');
        exit;
    }
}

// 2. Atualizar turno específico via modal
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['atualizar_turno_modal'])) {
    $turnoId = (int)$_POST['turno_id'];
    $horaInicio = $_POST['modal_hora_inicio'];
    $horaFim = $_POST['modal_hora_fim'];
    $tipoAtendimento = $_POST['modal_tipo_atendimento'] ?? 'horario_marcado';
    $intervalo = ($tipoAtendimento === 'ordem_chegada') ? 30 : (int)$_POST['modal_intervalo_minutos'];

    $temPausa = !empty($_POST['modal_tem_pausa']);
    $pausaInicio = $temPausa && !empty($_POST['modal_pausa_inicio']) ? $_POST['modal_pausa_inicio'] : null;
    $pausaFim = $temPausa && !empty($_POST['modal_pausa_fim']) ? $_POST['modal_pausa_fim'] : null;

    if ($horaInicio >= $horaFim) {
        $mensagemErro = "A hora inicial deve ser anterior à hora final.";
    } else {
        $stmtUpd = $pdo->prepare("
            UPDATE turnos_atendimento 
            SET hora_inicio = ?, hora_fim = ?, intervalo_minutos = ?, tipo_atendimento = ?, pausa_inicio = ?, pausa_fim = ? 
            WHERE id = ?
        ");
        $stmtUpd->execute([$horaInicio, $horaFim, $intervalo, $tipoAtendimento, $pausaInicio, $pausaFim, $turnoId]);
        header('Location: escalas.php?sucesso_alt=1');
        exit;
    }
}

// 3. Excluir turno
if (isset($_GET['excluir_turno'])) {
    $turnoId = (int)$_GET['excluir_turno'];
    $pdo->prepare("DELETE FROM turnos_atendimento WHERE id = ?")->execute([$turnoId]);
    header('Location: escalas.php?sucesso_del=1');
    exit;
}

// Buscar profissionais ativos
$profissionais = $pdo->query("SELECT * FROM profissionais WHERE status = 'ativo' ORDER BY nome ASC")->fetchAll();

// Buscar todos os turnos cadastrados
$sqlTurnos = "
    SELECT t.*, p.nome as profissional_nome
    FROM turnos_atendimento t
    LEFT JOIN profissionais p ON t.profissional_id = p.id
    ORDER BY p.nome ASC, t.dia_semana ASC, t.hora_inicio ASC
";
$turnos = $pdo->query($sqlTurnos)->fetchAll();

$diasNomes = [
    0 => 'Domingo',
    1 => 'Segunda-feira',
    2 => 'Terça-feira',
    3 => 'Quarta-feira',
    4 => 'Quinta-feira',
    5 => 'Sexta-feira',
    6 => 'Sábado'
];

require_once 'header.php';
?>

<div class="max-w-6xl mx-auto space-y-6">
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <h2 class="text-2xl font-bold flex items-center gap-2">
                <i class="fa-solid fa-calendar-days text-amber-500"></i> Gestão de Escalas & Horários
            </h2>
            <p class="text-zinc-400 text-sm">Defina e edite os horários de trabalho, modalidade (horário marcado ou ordem de chegada) e pausas para almoço.</p>
        </div>
        <a href="profissionais.php" class="bg-zinc-800 hover:bg-zinc-700 text-zinc-200 font-bold px-4 py-2 rounded-xl text-xs transition inline-flex items-center gap-2 border border-zinc-700">
            <i class="fa-solid fa-users text-amber-500"></i> Ver Barbeiros
        </a>
    </div>

    <?php if ($mensagemSucesso): ?>
        <div class="bg-emerald-500/10 border border-emerald-500/30 text-emerald-400 text-xs p-3.5 rounded-xl flex items-center justify-between">
            <span><i class="fa-solid fa-circle-check mr-2"></i> <?= htmlspecialchars($mensagemSucesso) ?></span>
            <a href="escalas.php" class="text-zinc-400 hover:text-white">&times;</a>
        </div>
    <?php endif; ?>

    <?php if ($mensagemErro): ?>
        <div class="bg-red-500/10 border border-red-500/30 text-red-400 text-xs p-3.5 rounded-xl flex items-center justify-between">
            <span><i class="fa-solid fa-circle-exclamation mr-2"></i> <?= htmlspecialchars($mensagemErro) ?></span>
            <a href="escalas.php" class="text-zinc-400 hover:text-white">&times;</a>
        </div>
    <?php endif; ?>

    <!-- FORMULÁRIO DE ESCALA SEMANAL COM OPÇÃO ORDEM DE CHEGADA -->
    <div class="bg-zinc-900 border border-zinc-800 rounded-2xl p-6 shadow-xl space-y-5">
        <div class="flex items-center gap-2.5 border-b border-zinc-800 pb-4">
            <i class="fa-solid fa-clock text-amber-500 text-xl"></i>
            <div>
                <h3 class="font-bold text-base text-white">Cadastrar / Atualizar Escala do Barbeiro</h3>
                <p class="text-xs text-zinc-400">Marque os dias da semana, a modalidade de atendimento e o expediente de trabalho.</p>
            </div>
        </div>

        <form method="POST" class="space-y-4">
            <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-5 gap-3.5">
                <div>
                    <label class="block text-xs font-semibold text-zinc-400 mb-1">Barbeiro</label>
                    <select name="profissional_id" required 
                            class="w-full bg-zinc-800 border border-zinc-700 rounded-xl p-2.5 text-xs text-amber-400 font-bold focus:outline-none focus:border-amber-500">
                        <option value="">Selecione o profissional...</option>
                        <?php foreach ($profissionais as $p): ?>
                            <option value="<?= $p['id'] ?>">✂️ <?= htmlspecialchars($p['nome']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-zinc-400 mb-1">Modalidade de Atendimento</label>
                    <select name="tipo_atendimento" id="selectTipoAtendimento" onchange="atualizarCampoIntervalo(this.value)" required
                            class="w-full bg-zinc-800 border border-zinc-700 rounded-xl p-2.5 text-xs text-white font-semibold focus:outline-none focus:border-amber-500">
                        <option value="horario_marcado">📅 Horário Marcado</option>
                        <option value="ordem_chegada">🚶 Ordem de Chegada</option>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-zinc-400 mb-1">Início do Expediente</label>
                    <input type="time" name="hora_inicio" value="09:00" required 
                           class="w-full bg-zinc-800 border border-zinc-700 rounded-xl p-2.5 text-xs text-white focus:outline-none focus:border-amber-500">
                </div>

                <div>
                    <label class="block text-xs font-semibold text-zinc-400 mb-1">Término do Expediente</label>
                    <input type="time" name="hora_fim" value="19:00" required 
                           class="w-full bg-zinc-800 border border-zinc-700 rounded-xl p-2.5 text-xs text-white focus:outline-none focus:border-amber-500">
                </div>

                <div id="containerCampoIntervalo">
                    <label class="block text-xs font-semibold text-zinc-400 mb-1" id="labelIntervalo">Duração dos Horários</label>
                    <select name="intervalo_minutos" id="selectIntervalo"
                            class="w-full bg-zinc-800 border border-zinc-700 rounded-xl p-2.5 text-xs text-white focus:outline-none focus:border-amber-500">
                        <option value="30">A cada 30 minutos</option>
                        <option value="40">A cada 40 minutos</option>
                        <option value="45">A cada 45 minutos</option>
                        <option value="60">A cada 60 minutos</option>
                    </select>
                </div>
            </div>

            <!-- Dias da Semana -->
            <div>
                <div class="flex items-center justify-between mb-2">
                    <label class="block text-xs font-semibold text-zinc-400">Dias da Semana Trabalhados:</label>
                    <div class="flex items-center gap-2">
                        <button type="button" onclick="marcarTodosDias(true)" class="text-[11px] font-bold text-amber-400 hover:text-amber-300 bg-amber-500/10 hover:bg-amber-500/20 px-2 py-0.5 rounded border border-amber-500/30 transition">
                            <i class="fa-solid fa-check-double mr-1"></i> Selecionar Tudo
                        </button>
                        <button type="button" onclick="marcarTodosDias(false)" class="text-[11px] font-semibold text-zinc-400 hover:text-zinc-200 bg-zinc-800 px-2 py-0.5 rounded border border-zinc-700 transition">
                            Desmarcar Tudo
                        </button>
                    </div>
                </div>
                <div class="grid grid-cols-2 sm:grid-cols-4 md:grid-cols-7 gap-2">
                    <?php 
                    $diasPreDefinidos = [1, 2, 3, 4, 5, 6];
                    foreach ($diasNomes as $num => $nome): 
                    ?>
                        <label class="flex items-center gap-2 p-2 bg-zinc-800/80 border border-zinc-700/80 rounded-xl cursor-pointer hover:border-amber-500/50 transition">
                            <input type="checkbox" name="dias_semana[]" value="<?= $num ?>" 
                                   <?= in_array($num, $diasPreDefinidos) ? 'checked' : '' ?>
                                   class="rounded text-amber-500 focus:ring-amber-500 bg-zinc-900 border-zinc-700">
                            <span class="text-xs text-zinc-200 font-medium"><?= $nome ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Pausa de Almoço -->
            <div class="bg-zinc-800/50 border border-zinc-800 p-3.5 rounded-xl space-y-3">
                <label class="flex items-center gap-2 cursor-pointer text-xs font-bold text-zinc-300">
                    <input type="checkbox" name="tem_pausa_almoco" id="checkPausaAlmoco" onchange="togglePausaAlmoco(this.checked)" 
                           class="rounded text-amber-500 focus:ring-amber-500 bg-zinc-900 border-zinc-700">
                    <span>Configurar Pausa / Horário de Almoço (Intervalo sem agendamento)</span>
                </label>

                <div id="containerPausaAlmoco" class="hidden grid grid-cols-1 sm:grid-cols-2 gap-4 pt-2 border-t border-zinc-700/60">
                    <div>
                        <label class="block text-[11px] font-semibold text-zinc-400 mb-1">Início da Pausa</label>
                        <input type="time" name="pausa_inicio" value="12:00" 
                               class="w-full bg-zinc-800 border border-zinc-700 rounded-xl p-2 text-xs text-white">
                    </div>
                    <div>
                        <label class="block text-[11px] font-semibold text-zinc-400 mb-1">Retorno da Pausa</label>
                        <input type="time" name="pausa_fim" value="13:00" 
                               class="w-full bg-zinc-800 border border-zinc-700 rounded-xl p-2 text-xs text-white">
                    </div>
                </div>
            </div>

            <div class="flex justify-end pt-2">
                <button type="submit" name="cadastrar_turno" 
                        class="bg-amber-500 hover:bg-amber-600 text-zinc-950 font-bold px-6 py-2.5 rounded-xl text-xs transition flex items-center gap-2 shadow">
                    <i class="fa-solid fa-floppy-disk"></i> Gravar Escala Semanal
                </button>
            </div>
        </form>
    </div>

    <!-- TABELA DE ESCALAS REGISTRADAS -->
    <div class="bg-zinc-900 border border-zinc-800 rounded-2xl overflow-hidden shadow">
        <div class="p-4 border-b border-zinc-800 flex justify-between items-center">
            <h3 class="font-bold text-white text-sm">Escalas Ativas (<?= count($turnos) ?> turnos)</h3>
            <span class="text-xs text-zinc-500">Clique na linha para editar o horário</span>
        </div>

        <div class="overflow-x-auto w-full">
            <table class="w-full text-left border-collapse text-xs">
                <thead>
                    <tr class="border-b border-zinc-800 text-zinc-400 uppercase tracking-wider text-[11px]">
                        <th class="p-3.5">Barbeiro</th>
                        <th class="p-3.5">Modalidade</th>
                        <th class="p-3.5">Dia da Semana</th>
                        <th class="p-3.5">Expediente</th>
                        <th class="p-3.5">Pausa Almoço</th>
                        <th class="p-3.5">Intervalo</th>
                        <th class="p-3.5 text-right">Ação</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-800">
                    <?php if (empty($turnos)): ?>
                        <tr><td colspan="7" class="p-6 text-center text-zinc-500">Nenhuma escala semanal cadastrada.</td></tr>
                    <?php endif; ?>

                    <?php foreach ($turnos as $t): 
                        $diaNome = $diasNomes[(int)$t['dia_semana']] ?? 'Dia Indefinido';
                        $temPausa = !empty($t['pausa_inicio']) && !empty($t['pausa_fim']);
                        $ehOrdemChegada = (($t['tipo_atendimento'] ?? 'horario_marcado') === 'ordem_chegada');
                    ?>
                    <tr class="hover:bg-zinc-800/40 transition cursor-pointer" 
                        onclick="abrirModalEdicao(<?= htmlspecialchars(json_encode($t)) ?>)">
                        <td class="p-3.5 font-bold text-white flex items-center gap-2">
                            <i class="fa-solid fa-user-tie text-amber-500 text-xs"></i>
                            <?= htmlspecialchars($t['profissional_nome'] ?? 'Geral') ?>
                        </td>
                        <td class="p-3.5">
                            <?php if ($ehOrdemChegada): ?>
                                <span class="bg-amber-500/10 text-amber-400 border border-amber-500/30 px-2 py-0.5 rounded text-[10px] font-bold uppercase inline-flex items-center gap-1">
                                    <i class="fa-solid fa-person-walking-arrow-right"></i> Ordem de Chegada
                                </span>
                            <?php else: ?>
                                <span class="bg-blue-500/10 text-blue-400 border border-blue-500/20 px-2 py-0.5 rounded text-[10px] font-semibold uppercase inline-flex items-center gap-1">
                                    <i class="fa-solid fa-clock"></i> Horário Marcado
                                </span>
                            <?php endif; ?>
                        </td>
                        <td class="p-3.5 text-amber-400 font-semibold"><?= $diaNome ?></td>
                        <td class="p-3.5 text-zinc-200">
                            <?= substr($t['hora_inicio'], 0, 5) ?> às <?= substr($t['hora_fim'], 0, 5) ?>
                        </td>
                        <td class="p-3.5">
                            <?php if ($temPausa): ?>
                                <span class="bg-amber-500/10 text-amber-400 border border-amber-500/20 px-2 py-0.5 rounded text-[11px]">
                                    <?= substr($t['pausa_inicio'], 0, 5) ?> às <?= substr($t['pausa_fim'], 0, 5) ?>
                                </span>
                            <?php else: ?>
                                <span class="text-zinc-500 text-[11px]">Sem pausa</span>
                            <?php endif; ?>
                        </td>
                        <td class="p-3.5 text-zinc-400">
                            <?= $ehOrdemChegada ? '<span class="text-zinc-500 italic">Fila</span>' : ($t['intervalo_minutos'] . ' min') ?>
                        </td>
                        <td class="p-3.5 text-right whitespace-nowrap" onclick="event.stopPropagation()">
                            <a href="escalas.php?excluir_turno=<?= $t['id'] ?>" onclick="return confirm('Excluir este turno?');" 
                               class="text-zinc-500 hover:text-red-400 p-1.5 rounded-lg transition" title="Excluir">
                                <i class="fa-solid fa-trash"></i>
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- MODAL DE EDIÇÃO RÁPIDA COM MODALIDADE DE ATENDIMENTO -->
<div id="modalEdicaoTurno" class="fixed inset-0 bg-black/70 backdrop-blur-sm z-50 hidden flex items-center justify-center p-4">
    <div class="bg-zinc-900 border border-zinc-800 rounded-2xl p-6 max-w-md w-full shadow-2xl space-y-4">
        <div class="flex items-center justify-between border-b border-zinc-800 pb-3">
            <h3 class="font-bold text-white text-base flex items-center gap-2">
                <i class="fa-solid fa-pen-to-square text-amber-500"></i> Editar Horário do Barbeiro
            </h3>
            <button onclick="fecharModalEdicao()" class="text-zinc-400 hover:text-white">&times;</button>
        </div>

        <form method="POST" class="space-y-3.5">
            <input type="hidden" name="turno_id" id="modalTurnoId">

            <div>
                <span class="text-xs text-zinc-400">Profissional & Dia:</span>
                <p id="modalProfissionalDia" class="font-bold text-white text-sm mt-0.5"></p>
            </div>

            <!-- Seleção da Modalidade no Modal -->
            <div>
                <label class="block text-xs font-semibold text-zinc-400 mb-1">Modalidade de Atendimento</label>
                <select name="modal_tipo_atendimento" id="modalTipoAtendimento" onchange="atualizarModalIntervalo(this.value)" required
                        class="w-full bg-zinc-800 border border-zinc-700 rounded-xl p-2.5 text-xs text-white font-semibold">
                    <option value="horario_marcado">📅 Horário Marcado (Horas Fixas)</option>
                    <option value="ordem_chegada">🚶 Ordem de Chegada (Fila)</option>
                </select>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-semibold text-zinc-400 mb-1">Início</label>
                    <input type="time" name="modal_hora_inicio" id="modalHoraInicio" required 
                           class="w-full bg-zinc-800 border border-zinc-700 rounded-xl p-2.5 text-xs text-white">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-zinc-400 mb-1">Fim</label>
                    <input type="time" name="modal_hora_fim" id="modalHoraFim" required 
                           class="w-full bg-zinc-800 border border-zinc-700 rounded-xl p-2.5 text-xs text-white">
                </div>
            </div>

            <div id="modalContainerIntervalo">
                <label class="block text-xs font-semibold text-zinc-400 mb-1">Intervalo de Corte</label>
                <select name="modal_intervalo_minutos" id="modalIntervalo" 
                        class="w-full bg-zinc-800 border border-zinc-700 rounded-xl p-2.5 text-xs text-white">
                    <option value="30">30 minutos</option>
                    <option value="40">40 minutos</option>
                    <option value="45">45 minutos</option>
                    <option value="60">60 minutos</option>
                </select>
            </div>

            <div class="pt-2 border-t border-zinc-800">
                <label class="flex items-center gap-2 cursor-pointer text-xs font-bold text-zinc-300 mb-2">
                    <input type="checkbox" name="modal_tem_pausa" id="modalCheckPausa" onchange="toggleModalPausa(this.checked)">
                    <span>Pausa de Almoço</span>
                </label>
                <div id="modalContainerPausa" class="hidden grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-[11px] text-zinc-400 mb-1">Início Almoço</label>
                        <input type="time" name="modal_pausa_inicio" id="modalPausaInicio" class="w-full bg-zinc-800 border border-zinc-700 rounded-xl p-2 text-xs text-white">
                    </div>
                    <div>
                        <label class="block text-[11px] text-zinc-400 mb-1">Retorno</label>
                        <input type="time" name="modal_pausa_fim" id="modalPausaFim" class="w-full bg-zinc-800 border border-zinc-700 rounded-xl p-2 text-xs text-white">
                    </div>
                </div>
            </div>

            <div class="flex justify-end gap-2 pt-3 border-t border-zinc-800">
                <button type="button" onclick="fecharModalEdicao()" class="px-4 py-2 rounded-xl text-xs bg-zinc-800 text-zinc-300 hover:text-white">Cancelar</button>
                <button type="submit" name="atualizar_turno_modal" class="px-5 py-2 rounded-xl text-xs bg-amber-500 hover:bg-amber-600 text-zinc-950 font-bold">Salvar Alterações</button>
            </div>
        </form>
    </div>
</div>

<script>
function atualizarCampoIntervalo(tipo) {
    const container = document.getElementById('containerCampoIntervalo');
    const select = document.getElementById('selectIntervalo');
    const label = document.getElementById('labelIntervalo');
    if (tipo === 'ordem_chegada') {
        select.disabled = true;
        label.classList.add('text-zinc-600');
        select.classList.add('opacity-40');
    } else {
        select.disabled = false;
        label.classList.remove('text-zinc-600');
        select.classList.remove('opacity-40');
    }
}

function atualizarModalIntervalo(tipo) {
    const container = document.getElementById('modalContainerIntervalo');
    const select = document.getElementById('modalIntervalo');
    if (tipo === 'ordem_chegada') {
        container.classList.add('opacity-40');
        select.disabled = true;
    } else {
        container.classList.remove('opacity-40');
        select.disabled = false;
    }
}

function togglePausaAlmoco(ativo) {
    document.getElementById('containerPausaAlmoco').classList.toggle('hidden', !ativo);
}

function toggleModalPausa(ativo) {
    document.getElementById('modalContainerPausa').classList.toggle('hidden', !ativo);
}

function abrirModalEdicao(turno) {
    document.getElementById('modalTurnoId').value = turno.id;
    const dias = ['Domingo', 'Segunda-feira', 'Terça-feira', 'Quarta-feira', 'Quinta-feira', 'Sexta-feira', 'Sábado'];
    document.getElementById('modalProfissionalDia').textContent = (turno.profissional_nome || 'Geral') + ' • ' + (dias[parseInt(turno.dia_semana)] || '');
    
    const tipo = turno.tipo_atendimento || 'horario_marcado';
    document.getElementById('modalTipoAtendimento').value = tipo;
    atualizarModalIntervalo(tipo);

    document.getElementById('modalHoraInicio').value = turno.hora_inicio.substring(0, 5);
    document.getElementById('modalHoraFim').value = turno.hora_fim.substring(0, 5);
    document.getElementById('modalIntervalo').value = turno.intervalo_minutos;

    const temPausa = turno.pausa_inicio && turno.pausa_fim;
    document.getElementById('modalCheckPausa').checked = !!temPausa;
    if (temPausa) {
        document.getElementById('modalPausaInicio').value = turno.pausa_inicio.substring(0, 5);
        document.getElementById('modalPausaFim').value = turno.pausa_fim.substring(0, 5);
        document.getElementById('modalContainerPausa').classList.remove('hidden');
    } else {
        document.getElementById('modalContainerPausa').classList.add('hidden');
    }

    document.getElementById('modalEdicaoTurno').classList.remove('hidden');
}

function fecharModalEdicao() {
    document.getElementById('modalEdicaoTurno').classList.add('hidden');
}

function marcarTodosDias(marcar) {
    document.querySelectorAll('input[name="dias_semana[]"]').forEach(chk => {
        chk.checked = marcar;
    });
}
</script>

</main>
</body>
</html>
