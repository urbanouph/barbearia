<?php
require_once '../config/database.php';
verificarAutenticacao();

$mensagemSucesso = '';
$mensagemErro = '';

// Mensagens vindas por redirecionamento seguro (evita duplicação no F5)
if (isset($_GET['sucesso_turno'])) {
    $mensagemSucesso = "Turno de atendimento cadastrado com sucesso!";
} elseif (isset($_GET['erro_duplicado'])) {
    $mensagemErro = "Este profissional já possui um turno cadastrado com horário conflitante nesta data.";
} elseif (isset($_GET['sucesso_status'])) {
    $mensagemSucesso = "Status do agendamento atualizado com sucesso!";
}

// Atualizar status de agendamento (com PRG)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['atualizar_status'])) {
    $agendamentoId = (int)$_POST['agendamento_id'];
    $novoStatus = $_POST['status'];
    $stmt = $pdo->prepare("UPDATE agendamentos SET status = ? WHERE id = ?");
    $stmt->execute([$novoStatus, $agendamentoId]);
    header('Location: agendamentos.php?sucesso_status=1');
    exit;
}

// Cadastrar/Atualizar escala semanal do profissional
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cadastrar_turno'])) {
    $profissionalId = !empty($_POST['profissional_id']) ? (int)$_POST['profissional_id'] : null;
    $diasSelecionados = $_POST['dias_semana'] ?? [];
    $horaInicio = $_POST['hora_inicio'];
    $horaFim = $_POST['hora_fim'];
    $intervalo = (int)($_POST['intervalo_minutos'] ?? 30);
    $tipoAtendimento = $_POST['tipo_atendimento'] ?? 'horario_marcado';

    if (empty($diasSelecionados)) {
        $mensagemErro = "Selecione ao menos um dia da semana para o expediente do profissional.";
    } elseif ($horaInicio >= $horaFim) {
        $mensagemErro = "A hora inicial deve ser anterior à hora final.";
    } else {
        $stmtDel = $pdo->prepare("DELETE FROM turnos_atendimento WHERE (profissional_id = ? OR (profissional_id IS NULL AND ? IS NULL)) AND dia_semana = ?");
        $stmtIns = $pdo->prepare("
            INSERT INTO turnos_atendimento (profissional_id, dia_semana, hora_inicio, hora_fim, intervalo_minutos, tipo_atendimento) 
            VALUES (?, ?, ?, ?, ?, ?)
        ");

        $temPausa = !empty($_POST['ativar_pausa']);
        $pausaInicio = $temPausa && !empty($_POST['pausa_inicio']) ? $_POST['pausa_inicio'] : null;
        $pausaFim = $temPausa && !empty($_POST['pausa_fim']) ? $_POST['pausa_fim'] : null;

        $stmtIns = $pdo->prepare("
            INSERT INTO turnos_atendimento (profissional_id, dia_semana, hora_inicio, hora_fim, pausa_inicio, pausa_fim, intervalo_minutos, tipo_atendimento) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");

        foreach ($diasSelecionados as $dia) {
            $stmtDel->execute([$profissionalId, $profissionalId, (int)$dia]);
            $stmtIns->execute([$profissionalId, (int)$dia, $horaInicio, $horaFim, $pausaInicio, $pausaFim, $intervalo, $tipoAtendimento]);
        }
        
        header('Location: agendamentos.php?sucesso_turno=1');
        exit;
    }
}

// Excluir turno
if (isset($_GET['excluir_turno'])) {
    $turnoId = (int)$_GET['excluir_turno'];
    $stmt = $pdo->prepare("DELETE FROM turnos_atendimento WHERE id = ?");
    $stmt->execute([$turnoId]);
    header('Location: agendamentos.php');
    exit;
}

// Atualizar turno/escala diretamente pelo modal de edição
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['editar_turno_item'])) {
    $turnoId = (int)$_POST['turno_id'];
    $horaInicio = $_POST['hora_inicio'];
    $horaFim = $_POST['hora_fim'];
    $intervalo = (int)($_POST['intervalo_minutos'] ?? 30);
    $tipoAtendimento = $_POST['tipo_atendimento'] ?? 'horario_marcado';

    $temPausa = !empty($_POST['ativar_pausa_edit']);
    $pausaInicio = $temPausa && !empty($_POST['pausa_inicio']) ? $_POST['pausa_inicio'] : null;
    $pausaFim = $temPausa && !empty($_POST['pausa_fim']) ? $_POST['pausa_fim'] : null;

    if ($horaInicio < $horaFim) {
        $stmtUpd = $pdo->prepare("
            UPDATE turnos_atendimento 
            SET hora_inicio = ?, 
                hora_fim = ?, 
                intervalo_minutos = ?, 
                tipo_atendimento = ?, 
                pausa_inicio = ?, 
                pausa_fim = ? 
            WHERE id = ?
        ");
        $stmtUpd->execute([$horaInicio, $horaFim, $intervalo, $tipoAtendimento, $pausaInicio, $pausaFim, $turnoId]);
        header('Location: agendamentos.php?sucesso_edit_turno=1');
        exit;
    } else {
        $mensagemErro = "A hora de início deve ser anterior à hora de término.";
    }
}

// Buscar profissionais ativos para os selects
$profissionais = $pdo->query("SELECT * FROM profissionais WHERE status = 'ativo' ORDER BY nome ASC")->fetchAll();

// Filtros da tabela de agendamentos
$filtroStatus = $_GET['filtro_status'] ?? '';
$filtroData = $_GET['filtro_data'] ?? '';
$filtroProf = $_GET['filtro_prof'] ?? '';

$sqlAgendamentos = "
    SELECT a.*, s.nome as servico_nome, s.preco, p.nome as prof_nome 
    FROM agendamentos a 
    JOIN servicos s ON a.servico_id = s.id 
    LEFT JOIN profissionais p ON a.profissional_id = p.id
    WHERE 1=1
";
$paramsAg = [];

if ($filtroStatus) {
    $sqlAgendamentos .= " AND a.status = ?";
    $paramsAg[] = $filtroStatus;
}
if ($filtroData) {
    $sqlAgendamentos .= " AND a.data_agendamento = ?";
    $paramsAg[] = $filtroData;
}
if ($filtroProf) {
    $sqlAgendamentos .= " AND a.profissional_id = ?";
    $paramsAg[] = $filtroProf;
}

$sqlAgendamentos .= " ORDER BY a.data_agendamento DESC, a.horario DESC";
$stmtAg = $pdo->prepare($sqlAgendamentos);
$stmtAg->execute($paramsAg);
$agendamentos = $stmtAg->fetchAll();


// Processamento de criação de agendamento manual pelo Admin
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['criar_agendamento_admin'])) {
    $clienteNome = trim($_POST['cliente_nome']);
    $clienteTelefone = trim($_POST['cliente_telefone']);
    $profissionalId = !empty($_POST['profissional_id']) ? (int)$_POST['profissional_id'] : null;
    $servicoId = (int)$_POST['servico_id'];
    $dataAgendamento = $_POST['data_agendamento'];
    $horario = $_POST['horario'];
    $statusInicial = $_POST['status_inicial'] ?? 'confirmado';

    if (!empty($clienteNome) && !empty($dataAgendamento) && !empty($horario) && $servicoId > 0) {
        $stmtIns = $pdo->prepare("
            INSERT INTO agendamentos (profissional_id, cliente_nome, cliente_telefone, servico_id, data_agendamento, horario, status) 
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmtIns->execute([$profissionalId, $clienteNome, $clienteTelefone, $servicoId, $dataAgendamento, $horario, $statusInicial]);
        header('Location: index.php?sucesso_criado=1');
        exit;
    }
}

// Consultas para o modal
$listaProfissionais = $pdo->query("SELECT * FROM profissionais WHERE status = 'ativo' ORDER BY nome ASC")->fetchAll();
$listaServicos = $pdo->query("SELECT * FROM servicos WHERE status = 'ativo' ORDER BY nome ASC")->fetchAll();

// Buscar turnos cadastrados que ainda não expiraram (data futura ou dia de hoje com término posterior à hora atual)
$turnos = $pdo->query("
    SELECT t.*, p.nome as prof_nome 
    FROM turnos_atendimento t 
    LEFT JOIN profissionais p ON t.profissional_id = p.id 
    WHERE t.dia_semana IS NOT NULL OR (t.data_atendimento > CURDATE() OR (t.data_atendimento = CURDATE() AND t.hora_fim >= CURTIME()))
    ORDER BY t.data_atendimento ASC, t.hora_inicio ASC
")->fetchAll();

// Carrega o layout visual após todos os redirecionamentos
require_once 'header.php';
?>

<div class="max-w-6xl mx-auto space-y-8">
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <h2 class="text-2xl font-bold">Agendamentos & Turnos por Profissional</h2>
            <p class="text-zinc-400 text-sm">Gerencie horários de trabalho individuais e acompanhe os atendimentos da equipe.</p>
        </div>
        <button type="button" onclick="document.getElementById('modalNovoAgendamento').classList.remove('hidden')" 
                class="inline-flex items-center gap-2 bg-amber-500 hover:bg-amber-600 text-zinc-950 font-bold text-xs py-2.5 px-4 rounded-xl shadow-lg transition transform hover:-translate-y-0.5">
            <i class="fa-solid fa-calendar-plus text-sm"></i> + Novo Agendamento
        </button>
    </div>

    <?php if ($mensagemSucesso): ?>
        <div class="bg-emerald-500/10 border border-emerald-500/30 text-emerald-400 text-xs p-3.5 rounded-xl">
            <i class="fa-solid fa-circle-check mr-1.5"></i> <?= htmlspecialchars($mensagemSucesso) ?>
        </div>
    <?php endif; ?>

    <?php if ($mensagemErro): ?>
        <div class="bg-red-500/10 border border-red-500/30 text-red-400 text-xs p-3.5 rounded-xl">
            <i class="fa-solid fa-triangle-exclamation mr-1.5"></i> <?= htmlspecialchars($mensagemErro) ?>
        </div>
    <?php endif; ?>
    <!-- Barra de Ações Rápidas de Agendamentos -->
    <div class="bg-zinc-900 border border-zinc-800 p-4 rounded-2xl flex flex-col sm:flex-row sm:items-center justify-between gap-3 shadow">
        <div class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-xl bg-amber-500/10 border border-amber-500/20 text-amber-400 flex items-center justify-center text-lg">
                <i class="fa-solid fa-calendar-check"></i>
            </div>
            <div>
                <h3 class="font-bold text-white text-sm">Fila de Atendimentos & Comandas</h3>
                <p class="text-xs text-zinc-400">Acompanhe clientes agendados, confirme atendimentos e abra comandas para fechar o caixa.</p>
            </div>
        </div>
        <div class="flex items-center gap-2">
            <a href="escalas.php" class="bg-zinc-800 hover:bg-zinc-700 text-zinc-300 px-3 py-2 rounded-xl text-xs font-semibold transition border border-zinc-700">
                <i class="fa-solid fa-clock mr-1 text-amber-500"></i> Configurar Escalas
            </a>
            <a href="bloqueios.php" class="bg-zinc-800 hover:bg-zinc-700 text-zinc-300 px-3 py-2 rounded-xl text-xs font-semibold transition border border-zinc-700">
                <i class="fa-solid fa-calendar-xmark mr-1 text-amber-500"></i> Folgas
            </a>
        </div>
    </div>
    <div class="bg-zinc-900 border border-zinc-800 rounded-2xl overflow-hidden">
        <div class="p-4 sm:p-5 border-b border-zinc-800 flex flex-col md:flex-row md:items-center justify-between gap-4">
            <div>
                <h3 class="font-bold text-white text-base">Agendamentos Cadastrados</h3>
                <span class="text-xs text-zinc-400">Total encontrado: <?= count($agendamentos) ?></span>
            </div>

            <!-- Filtros Rápidos -->
            <form method="GET" class="flex flex-wrap items-center gap-2">
                <select name="filtro_prof" onchange="this.form.submit()" class="bg-zinc-800 text-xs text-zinc-300 border border-zinc-700 rounded-xl p-2 focus:outline-none focus:border-amber-500">
                    <option value="">Todos Profissionais</option>
                    <?php foreach ($profissionais as $p): ?>
                        <option value="<?= $p['id'] ?>" <?= $filtroProf == $p['id'] ? 'selected' : '' ?>><?= htmlspecialchars($p['nome']) ?></option>
                    <?php endforeach; ?>
                </select>

                <select name="filtro_status" onchange="this.form.submit()" class="bg-zinc-800 text-xs text-zinc-300 border border-zinc-700 rounded-xl p-2 focus:outline-none focus:border-amber-500">
                    <option value="">Todos Status</option>
                    <option value="pendente" <?= $filtroStatus === 'pendente' ? 'selected' : '' ?>>Pendente</option>
                    <option value="confirmado" <?= $filtroStatus === 'confirmado' ? 'selected' : '' ?>>Confirmado</option>
                    <option value="concluido" <?= $filtroStatus === 'concluido' ? 'selected' : '' ?>>Concluído</option>
                    <option value="cancelado" <?= $filtroStatus === 'cancelado' ? 'selected' : '' ?>>Cancelado</option>
                </select>

                <input type="date" name="filtro_data" value="<?= htmlspecialchars($filtroData) ?>" onchange="this.form.submit()" 
                       class="bg-zinc-800 text-xs text-zinc-300 border border-zinc-700 rounded-xl p-2 focus:outline-none focus:border-amber-500">

                <?php if ($filtroStatus || $filtroData || $filtroProf): ?>
                    <a href="agendamentos.php" class="text-xs text-zinc-400 hover:text-white underline ml-1">Limpar</a>
                <?php endif; ?>
            </form>
        </div>

        <!-- Wrapper com Rolagem Suave no Celular -->
        <div class="overflow-x-auto overflow-y-hidden w-full scroll-suave">
            <table class="w-full text-left border-collapse min-w-[750px]">
                <thead>
                    <tr class="border-b border-zinc-800 text-zinc-400 text-xs uppercase tracking-wider">
                        <th class="p-4 whitespace-nowrap">Data / Hora</th>
                        <th class="p-4 whitespace-nowrap">Barbeiro Designado</th>
                        <th class="p-4 whitespace-nowrap">Cliente</th>
                        <th class="p-4 whitespace-nowrap">Serviço</th>
                        <th class="p-4 whitespace-nowrap">Valor</th>
                        <th class="p-4 whitespace-nowrap">Status</th>
                        <th class="p-4 text-right whitespace-nowrap">Ação</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-800 text-sm">
                    <?php if (empty($agendamentos)): ?>
                        <tr>
                            <td colspan="7" class="p-8 text-center text-zinc-500 text-xs">
                                Nenhum agendamento encontrado para os filtros selecionados.
                            </td>
                        </tr>
                    <?php endif; ?>

                    <?php foreach ($agendamentos as $ag): ?>
                    <tr class="hover:bg-zinc-800/30 transition">
                        <td class="p-4 whitespace-nowrap font-medium text-white">
                            <?= date('d/m/Y', strtotime($ag['data_agendamento'])) ?><br>
                            <span class="text-xs text-amber-500 font-bold"><?= substr($ag['horario'], 0, 5) ?></span>
                        </td>
                        <td class="p-4 whitespace-nowrap">
                            <span class="text-xs font-semibold text-zinc-200 flex items-center gap-1.5">
                                <i class="fa-solid fa-user-tie text-amber-500"></i>
                                <?= htmlspecialchars($ag['prof_nome'] ?? 'Qualquer Barbeiro') ?>
                            </span>
                        </td>
                        <td class="p-4 whitespace-nowrap">
                            <div class="font-medium text-zinc-200"><?= htmlspecialchars($ag['cliente_nome']) ?></div>
                            <div class="text-xs text-zinc-500"><?= htmlspecialchars($ag['cliente_telefone']) ?></div>
                        </td>
                        <td class="p-4 text-zinc-300 whitespace-nowrap"><?= htmlspecialchars($ag['servico_nome']) ?></td>
                        <td class="p-4 font-semibold text-amber-400 whitespace-nowrap">R$ <?= number_format($ag['preco'], 2, ',', '.') ?></td>
                        <td class="p-4 whitespace-nowrap">
                            <?php 
                                $badgeColor = match($ag['status']) {
                                    'confirmado' => 'bg-blue-950 text-blue-400 border-blue-800',
                                    'concluido'  => 'bg-emerald-950 text-emerald-400 border-emerald-800',
                                    'cancelado'  => 'bg-red-950 text-red-400 border-red-800',
                                    default      => 'bg-amber-950 text-amber-400 border-amber-800'
                                };
                            ?>
                            <span class="px-2.5 py-1 text-xs rounded-full border <?= $badgeColor ?>">
                                <?= ucfirst($ag['status']) ?>
                            </span>
                        </td>
                        <td class="p-4 text-right whitespace-nowrap">
                            <?php if ($ag['status'] !== 'concluido' && $ag['status'] !== 'cancelado'): ?>
                                <a href="checkout.php?id=<?= $ag['id'] ?>" 
                                   class="inline-flex items-center gap-1.5 bg-amber-500 hover:bg-amber-600 text-zinc-950 font-bold text-xs py-2 px-3.5 rounded-xl shadow transition">
                                    <i class="fa-solid fa-cash-register"></i> Pagar
                                </a>
                            <?php else: ?>
                                <span class="text-xs text-zinc-500 italic">Finalizado</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- MODAL DE NOVO AGENDAMENTO -->
<div id="modalNovoAgendamento" class="fixed inset-0 bg-black/70 backdrop-blur-sm z-50 flex items-center justify-center p-4 hidden">
    <div class="bg-zinc-900 border border-zinc-800 rounded-2xl w-full max-w-lg p-6 shadow-2xl space-y-5 animate-in fade-in zoom-in duration-200">
        <div class="flex items-center justify-between pb-3 border-b border-zinc-800">
            <h3 class="text-lg font-bold text-white flex items-center gap-2">
                <i class="fa-solid fa-calendar-plus text-amber-500"></i> Criar Novo Agendamento
            </h3>
            <button type="button" onclick="document.getElementById('modalNovoAgendamento').classList.add('hidden')" class="text-zinc-400 hover:text-white text-lg">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <form method="POST" class="space-y-4">
            <input type="hidden" name="criar_agendamento_admin" value="1">
            
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3.5">
                <div>
                    <label class="block text-xs font-semibold text-zinc-400 mb-1">Nome do Cliente</label>
                    <input type="text" name="cliente_nome" placeholder="Ex: Carlos Silva" required 
                           class="w-full bg-zinc-800 border border-zinc-700 rounded-xl p-2.5 text-xs text-white focus:outline-none focus:border-amber-500">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-zinc-400 mb-1">WhatsApp / Telefone</label>
                    <input type="text" name="cliente_telefone" placeholder="(11) 99999-9999" required 
                           class="w-full bg-zinc-800 border border-zinc-700 rounded-xl p-2.5 text-xs text-white focus:outline-none focus:border-amber-500">
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3.5">
                <div>
                    <label class="block text-xs font-semibold text-zinc-400 mb-1">Barbeiro / Profissional</label>
                    <select name="profissional_id" class="w-full bg-zinc-800 border border-zinc-700 rounded-xl p-2.5 text-xs text-white focus:outline-none focus:border-amber-500">
                        <option value="">Qualquer Barbeiro</option>
                        <?php foreach ($listaProfissionais as $prof): ?>
                            <option value="<?= $prof['id'] ?>"><?= htmlspecialchars($prof['nome']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-zinc-400 mb-1">Serviço</label>
                    <select name="servico_id" required class="w-full bg-zinc-800 border border-zinc-700 rounded-xl p-2.5 text-xs text-white focus:outline-none focus:border-amber-500">
                        <option value="">Selecione o serviço...</option>
                        <?php foreach ($listaServicos as $serv): ?>
                            <option value="<?= $serv['id'] ?>"><?= htmlspecialchars($serv['nome']) ?> • R$ <?= number_format($serv['preco'], 2, ',', '.') ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3.5">
                <div>
                    <label class="block text-xs font-semibold text-zinc-400 mb-1">Data</label>
                    <input type="date" name="data_agendamento" value="<?= date('Y-m-d') ?>" required 
                           class="w-full bg-zinc-800 border border-zinc-700 rounded-xl p-2.5 text-xs text-white focus:outline-none focus:border-amber-500">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-zinc-400 mb-1">Horário</label>
                    <input type="time" name="horario" value="<?= date('H:i') ?>" required 
                           class="w-full bg-zinc-800 border border-zinc-700 rounded-xl p-2.5 text-xs text-white focus:outline-none focus:border-amber-500">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-zinc-400 mb-1">Status</label>
                    <select name="status_inicial" class="w-full bg-zinc-800 border border-zinc-700 rounded-xl p-2.5 text-xs text-white focus:outline-none focus:border-amber-500">
                        <option value="confirmado">Confirmado</option>
                        <option value="pendente">Pendente</option>
                    </select>
                </div>
            </div>

            <div class="flex items-center justify-end gap-2.5 pt-3 border-t border-zinc-800">
                <button type="button" onclick="document.getElementById('modalNovoAgendamento').classList.add('hidden')" 
                        class="bg-zinc-800 hover:bg-zinc-700 text-zinc-300 text-xs font-semibold py-2.5 px-4 rounded-xl transition">
                    Cancelar
                </button>
                <button type="submit" 
                        class="bg-amber-500 hover:bg-amber-600 text-zinc-950 text-xs font-bold py-2.5 px-5 rounded-xl transition flex items-center gap-1.5 shadow">
                    <i class="fa-solid fa-check"></i> Salvar Agendamento
                </button>
            </div>
        </form>
    </div>
</div>


<!-- MODAL PARA MODIFICAR HORÁRIO / TURNO SELECIONADO -->
<div id="modalEditarTurno" class="fixed inset-0 bg-black/80 backdrop-blur-sm z-50 flex items-center justify-center p-4 hidden">
    <div class="bg-zinc-900 border border-zinc-800 rounded-2xl w-full max-w-lg p-6 shadow-2xl space-y-4 animate-in fade-in zoom-in duration-200">
        <div class="flex items-center justify-between pb-3 border-b border-zinc-800">
            <div>
                <h3 class="text-base font-bold text-white flex items-center gap-2">
                    <i class="fa-solid fa-pen-to-square text-amber-500"></i> Modificar Horário do Barbeiro
                </h3>
                <p id="editProfDiaInfo" class="text-xs text-amber-400 mt-0.5 font-semibold"></p>
            </div>
            <button type="button" onclick="fecharModalEditarTurno()" class="text-zinc-400 hover:text-white text-lg p-1">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <form method="POST" class="space-y-4">
            <input type="hidden" name="editar_turno_item" value="1">
            <input type="hidden" name="turno_id" id="editTurnoId" value="">

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3.5">
                <div>
                    <label class="block text-xs font-semibold text-zinc-400 mb-1">Modalidade</label>
                    <select name="tipo_atendimento" id="editTipoAtendimento" onchange="alternarIntervaloEdit()" 
                            class="w-full bg-zinc-800 border border-zinc-700 rounded-xl p-2.5 text-xs text-white focus:outline-none focus:border-amber-500">
                        <option value="horario_marcado">⏰ Horário Marcado</option>
                        <option value="ordem_chegada">🚶 Ordem de Chegada</option>
                    </select>
                </div>
                <div id="editContainerIntervalo">
                    <label class="block text-xs font-semibold text-zinc-400 mb-1">Intervalo / Slots</label>
                    <select name="intervalo_minutos" id="editIntervaloMinutos" 
                            class="w-full bg-zinc-800 border border-zinc-700 rounded-xl p-2.5 text-xs text-amber-400 font-bold focus:outline-none focus:border-amber-500">
                        <option value="15">15 minutos</option>
                        <option value="20">20 minutos</option>
                        <option value="30">30 minutos</option>
                        <option value="40">40 minutos</option>
                        <option value="45">45 minutos</option>
                        <option value="60">60 minutos (1h)</option>
                    </select>
                </div>
            </div>

            <div class="grid grid-cols-2 gap-3.5">
                <div>
                    <label class="block text-xs font-semibold text-zinc-400 mb-1">Horário de Início</label>
                    <input type="time" name="hora_inicio" id="editHoraInicio" required 
                           class="w-full bg-zinc-800 border border-zinc-700 rounded-xl p-2.5 text-xs text-white focus:outline-none focus:border-amber-500 text-center font-bold">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-zinc-400 mb-1">Horário de Término</label>
                    <input type="time" name="hora_fim" id="editHoraFim" required 
                           class="w-full bg-zinc-800 border border-zinc-700 rounded-xl p-2.5 text-xs text-white focus:outline-none focus:border-amber-500 text-center font-bold">
                </div>
            </div>

            <!-- Pausa de Almoço Opcional no Modal -->
            <div class="bg-zinc-800/40 border border-zinc-800 p-3 rounded-xl space-y-2">
                <label class="flex items-center gap-2 cursor-pointer select-none text-xs text-zinc-200">
                    <input type="checkbox" name="ativar_pausa_edit" id="editCheckPausa" onchange="alternarCamposPausaEdit()" 
                           class="rounded text-amber-500 bg-zinc-900 border-zinc-700 focus:ring-0">
                    <span class="font-bold text-amber-400">🥪 Pausa / Almoço</span>
                </label>
                
                <div id="editDivCamposPausa" class="hidden flex items-center gap-3 pt-1">
                    <input type="time" name="pausa_inicio" id="editPausaInicio" 
                           class="bg-zinc-900 border border-zinc-700 rounded-xl p-2 text-xs text-white focus:outline-none focus:border-amber-500 text-center">
                    <span class="text-zinc-500 text-xs">até</span>
                    <input type="time" name="pausa_fim" id="editPausaFim" 
                           class="bg-zinc-900 border border-zinc-700 rounded-xl p-2 text-xs text-white focus:outline-none focus:border-amber-500 text-center">
                </div>
            </div>

            <div class="flex items-center justify-end gap-2.5 pt-3 border-t border-zinc-800">
                <button type="button" onclick="fecharModalEditarTurno()" 
                        class="bg-zinc-800 hover:bg-zinc-700 text-zinc-300 text-xs font-semibold py-2.5 px-4 rounded-xl transition">
                    Cancelar
                </button>
                <button type="submit" 
                        class="bg-amber-500 hover:bg-amber-600 text-zinc-950 text-xs font-bold py-2.5 px-5 rounded-xl transition flex items-center gap-1.5 shadow">
                    <i class="fa-solid fa-check"></i> Salvar Alterações
                </button>
            </div>
        </form>
    </div>
</div>

<script>
// Ouvinte universal de cliques nas linhas da tabela de escala
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.linha-turno-item').forEach(function(linha) {
        linha.addEventListener('click', function(e) {
            // Se clicou no link de excluir, não abre o modal
            if (e.target.closest('a') || e.target.closest('.fa-trash')) {
                return;
            }

            const dados = {
                id: this.dataset.id,
                prof_nome: this.dataset.prof,
                dia_nome: this.dataset.dia,
                tipo_atendimento: this.dataset.tipo,
                intervalo_minutos: this.dataset.intervalo,
                hora_inicio: this.dataset.inicio,
                hora_fim: this.dataset.fim,
                pausa_inicio: this.dataset.pausaIni,
                pausa_fim: this.dataset.pausaFim
            };

            abrirModalEditarTurno(dados);
        });
    });
});

function abrirModalEditarTurno(dados) {
    document.getElementById('editTurnoId').value = dados.id;
    document.getElementById('editProfDiaInfo').textContent = dados.prof_nome + ' • ' + dados.dia_nome;
    document.getElementById('editTipoAtendimento').value = dados.tipo_atendimento || 'horario_marcado';
    document.getElementById('editIntervaloMinutos').value = dados.intervalo_minutos || 30;
    document.getElementById('editHoraInicio').value = dados.hora_inicio || '09:00';
    document.getElementById('editHoraFim').value = dados.hora_fim || '18:00';

    const temPausa = dados.pausa_inicio && dados.pausa_fim;
    document.getElementById('editCheckPausa').checked = !!temPausa;
    document.getElementById('editPausaInicio').value = dados.pausa_inicio || '12:00';
    document.getElementById('editPausaFim').value = dados.pausa_fim || '13:00';

    alternarIntervaloEdit();
    alternarCamposPausaEdit();

    document.getElementById('modalEditarTurno').classList.remove('hidden');
}

function fecharModalEditarTurno() {
    document.getElementById('modalEditarTurno').classList.add('hidden');
}

function alternarIntervaloEdit() {
    const tipo = document.getElementById('editTipoAtendimento').value;
    const container = document.getElementById('editContainerIntervalo');
    if (tipo === 'ordem_chegada') {
        container.style.opacity = '0.35';
        container.style.pointerEvents = 'none';
    } else {
        container.style.opacity = '1';
        container.style.pointerEvents = 'auto';
    }
}

function alternarCamposPausaEdit() {
    const ativo = document.getElementById('editCheckPausa').checked;
    document.getElementById('editDivCamposPausa').classList.toggle('hidden', !ativo);
}
</script>

</main>
</body>
</html>
