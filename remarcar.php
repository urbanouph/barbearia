<?php
require_once 'config/database.php';

if (!isset($_SESSION['cliente_id'])) {
    header('Location: cliente_login.php');
    exit;
}

$clienteId = (int)$_SESSION['cliente_id'];
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];
$agendamentoId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$erro = '';

$stmtBusca = $pdo->prepare("
    SELECT a.*, s.nome as servico_nome, s.preco, s.duracao_minutos 
    FROM agendamentos a 
    JOIN servicos s ON a.servico_id = s.id 
    WHERE a.id = ? AND a.cliente_id = ? AND a.status = 'pendente'
");
$stmtBusca->execute([$agendamentoId, $clienteId]);
$agendamento = $stmtBusca->fetch();

if (!$agendamento) {
    header('Location: meus_agendamentos.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['salvar_reagendamento']) && !hash_equals($csrfToken, (string)($_POST['csrf_token'] ?? ''))) {
    $erro = 'Sua sessão expirou. Atualize a página e tente novamente.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['salvar_reagendamento'])) {
    $novaData = $_POST['data_agendamento'] ?? '';
    $novoHorario = $_POST['horario'] ?? '';

    $dataValida = preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $novaData) === 1
        && checkdate((int)substr($novaData, 5, 2), (int)substr($novaData, 8, 2), (int)substr($novaData, 0, 4));
    $horaValida = preg_match('/^(?:[01]\\d|2[0-3]):[0-5]\\d$/', $novoHorario) === 1;
    if (!$dataValida || !$horaValida) {
        $erro = 'Por favor, selecione uma data e um horário disponível válido.';
    } else {
        $duracao = (int)$agendamento['duracao_minutos'];
        $novoInicio = strtotime("$novaData $novoHorario:00");
        $novoFim = $novoInicio + ($duracao * 60);

        $diaSemanaNum = (int)date('w', strtotime($novaData));
        $stmtTurnos = $pdo->prepare("
            SELECT * FROM turnos_atendimento
            WHERE (dia_semana = ? OR data_atendimento = ?)
              AND (profissional_id = ? OR profissional_id IS NULL)
        ");
        $stmtTurnos->execute([$diaSemanaNum, $novaData, $agendamento['profissional_id']]);
        $turnos = $stmtTurnos->fetchAll();

        $dentroDeTurno = false;
        $turnoOrdemChegada = false;
        foreach ($turnos as $t) {
            if (($t['tipo_atendimento'] ?? 'horario_marcado') === 'ordem_chegada') {
                $turnoOrdemChegada = true;
                continue;
            }
            $tIni = strtotime("$novaData {$t['hora_inicio']}");
            $tFim = strtotime("$novaData {$t['hora_fim']}");
            if ($novoInicio >= $tIni && $novoFim <= $tFim) {
                $dentroDeTurno = true;
                break;
            }
        }

        if ($turnoOrdemChegada) {
            $erro = 'Esta data está configurada como Ordem de Chegada. Não é possível reservar ou remarcar um horário nesse dia.';
        } elseif (!$dentroDeTurno) {
            $erro = 'O horário selecionado não está dentro do expediente desta data.';
        } else {
            $stmtConflito = $pdo->prepare("
                SELECT a.horario, s.duracao_minutos 
                FROM agendamentos a 
                JOIN servicos s ON a.servico_id = s.id 
                WHERE a.data_agendamento = ? 
                  AND a.id != ? 
                  AND a.status != 'cancelado'
            ");
            $stmtConflito->execute([$novaData, $agendamentoId]);
            $marcados = $stmtConflito->fetchAll();

            $colidiu = false;
            foreach ($marcados as $m) {
                $mIni = strtotime("$novaData {$m['horario']}");
                $mFim = $mIni + ($m['duracao_minutos'] * 60);
                if ($novoInicio < $mFim && $novoFim > $mIni) {
                    $colidiu = true;
                    break;
                }
            }

            if ($colidiu) {
                $erro = 'Este horário já está ocupado. Por favor, escolha outro.';
            } else {
                $stmtUpdate = $pdo->prepare("
                    UPDATE agendamentos 
                    SET data_agendamento = ?, horario = ? 
                    WHERE id = ? AND cliente_id = ?
                ");
                $stmtUpdate->execute([$novaData, $novoHorario, $agendamentoId, $clienteId]);

                header('Location: meus_agendamentos.php');
                exit;
            }
        }
    }
}

$diasDisponiveis = $pdo->query("
    SELECT DISTINCT data_atendimento 
    FROM turnos_atendimento 
    WHERE data_atendimento >= CURDATE() 
    ORDER BY data_atendimento ASC
")->fetchAll(PDO::FETCH_COLUMN);

$diasSemanaNomes = [0 => 'Dom', 1 => 'Seg', 2 => 'Ter', 3 => 'Qua', 4 => 'Qui', 5 => 'Sex', 6 => 'Sáb'];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Remarcar Horário - Barbearia</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="icon" type="image/png" href="assets/logo.png">
    <link rel="apple-touch-icon" href="assets/logo.png">
</head>
<body class="bg-zinc-950 text-zinc-100 min-h-screen flex items-center justify-center p-4">

    <div class="max-w-md w-full bg-zinc-900 border border-zinc-800 rounded-2xl p-6 sm:p-8 shadow-2xl">
        
        <div class="flex items-center justify-between mb-6 border-b border-zinc-800 pb-4">
            <div>
                <h1 class="text-xl font-bold text-white flex items-center gap-2">
                    <i class="fa-solid fa-clock-rotate-left text-amber-500"></i> Remarcar Atendimento
                </h1>
                <p class="text-xs text-zinc-400 mt-0.5">Selecione uma nova data e horário livre.</p>
            </div>
            <a href="meus_agendamentos.php" class="text-xs text-zinc-400 hover:text-white transition">
                <i class="fa-solid fa-xmark text-lg"></i>
            </a>
        </div>

        <div class="bg-zinc-800/60 border border-zinc-700/80 rounded-xl p-3.5 mb-5 text-xs">
            <span class="text-zinc-400 block mb-1">Serviço: <strong class="text-white"><?= htmlspecialchars($agendamento['servico_nome']) ?></strong> (<?= $agendamento['duracao_minutos'] ?> min)</span>
            <span class="text-zinc-400 block">Horário atual: <strong class="text-amber-400"><?= date('d/m/Y', strtotime($agendamento['data_agendamento'])) ?> às <?= substr($agendamento['horario'], 0, 5) ?></strong></span>
        </div>

        <?php if ($erro): ?>
            <div class="bg-red-500/10 border border-red-500/30 text-red-400 text-xs p-3 rounded-xl mb-4 text-center">
                <?= htmlspecialchars($erro) ?>
            </div>
        <?php endif; ?>

        <form method="POST" class="space-y-4">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" id="campoServico" value="<?= $agendamento['servico_id'] ?>">
            <input type="hidden" name="data_agendamento" id="campoData" required>
            <input type="hidden" name="horario" id="horarioSelecionado" required>

            <div>
                <label class="block text-xs font-semibold text-zinc-400 uppercase tracking-wider mb-2">Escolha o Novo Dia</label>
                <?php if (empty($diasDisponiveis)): ?>
                    <p class="text-xs text-zinc-500">Não há dias de atendimento cadastrados no momento.</p>
                <?php else: ?>
                    <div class="grid grid-cols-3 sm:grid-cols-4 gap-2 max-h-36 overflow-y-auto p-1">
                        <?php foreach ($diasDisponiveis as $dia): 
                            $timestamp = strtotime($dia);
                            $diaNum = date('d/m', $timestamp);
                            $semana = $diasSemanaNomes[(int)date('w', $timestamp)];
                        ?>
                            <button type="button" 
                                data-data="<?= $dia ?>"
                                class="botao-data py-2 px-2 text-center rounded-xl border border-zinc-700 bg-zinc-800 hover:border-amber-500 transition text-xs">
                                <span class="block text-[10px] text-zinc-400 uppercase font-semibold"><?= $semana ?></span>
                                <span class="font-bold text-white"><?= $diaNum ?></span>
                            </button>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <div>
                <label class="block text-xs font-semibold text-zinc-400 uppercase tracking-wider mb-1.5">Novo Horário Disponível</label>
                
                <div id="statusCarregamento" class="text-xs text-zinc-500 py-2">
                    Clique em um dos dias acima para carregar as vagas livres.
                </div>

                <div id="gradeHorarios" class="grid grid-cols-4 gap-2 hidden max-h-40 overflow-y-auto p-1"></div>
            </div>

            <div class="pt-2 flex gap-2">
                <a href="meus_agendamentos.php" class="w-1/3 py-2.5 rounded-xl border border-zinc-700 text-center text-xs text-zinc-400 hover:text-white transition">
                    Voltar
                </a>
                <button type="submit" id="botaoConfirmarRemarcacao" name="salvar_reagendamento" disabled class="w-2/3 bg-amber-500 hover:bg-amber-600 text-zinc-950 font-bold py-2.5 px-4 rounded-xl text-xs transition">
                    Confirmar Nova Data
                </button>
            </div>
        </form>
    </div>

<script>
const campoData = document.getElementById('campoData');
const campoServico = document.getElementById('campoServico');
const gradeHorarios = document.getElementById('gradeHorarios');
const statusCarregamento = document.getElementById('statusCarregamento');
const horarioSelecionado = document.getElementById('horarioSelecionado');
const botoesData = document.querySelectorAll('.botao-data');
const botaoConfirmarRemarcacao = document.getElementById('botaoConfirmarRemarcacao');
let bloqueioOrdemChegada = false;
function atualizarBotaoRemarcacao() {
    botaoConfirmarRemarcacao.disabled = !(campoData.value && horarioSelecionado.value && !bloqueioOrdemChegada);
}

botoesData.forEach(btn => {
    btn.addEventListener('click', () => {
        botoesData.forEach(b => {
            b.classList.remove('bg-amber-500', 'text-zinc-950', 'border-amber-500', 'font-bold');
            b.classList.add('bg-zinc-800', 'border-zinc-700');
            const span = b.querySelector('span:first-child');
            if (span) span.classList.replace('text-zinc-900', 'text-zinc-400');
            const span2 = b.querySelector('span:last-child');
            if (span2) span2.classList.replace('text-zinc-950', 'text-white');
        });

        btn.classList.add('bg-amber-500', 'border-amber-500');
        btn.classList.remove('bg-zinc-800', 'border-zinc-700');
        const span = btn.querySelector('span:first-child');
        if (span) span.classList.replace('text-zinc-400', 'text-zinc-900');
        const span2 = btn.querySelector('span:last-child');
        if (span2) span2.classList.replace('text-white', 'text-zinc-950');

        campoData.value = btn.dataset.data;
        carregarHorarios();
    });
});

async function carregarHorarios() {
    bloqueioOrdemChegada = false;
    horarioSelecionado.value = '';
    atualizarBotaoRemarcacao();
    const data = campoData.value;
    const servicoId = campoServico.value;

    if (!data) return;

    horarioSelecionado.value = '';
    gradeHorarios.innerHTML = '';
    gradeHorarios.classList.add('hidden');
    statusCarregamento.classList.remove('hidden');
    statusCarregamento.textContent = 'Buscando vagas disponíveis...';

    try {
        const resposta = await fetch(`horarios_disponiveis.php?data=${encodeURIComponent(data)}&servico_id=${encodeURIComponent(servicoId)}&profissional_id=<?= (int)$agendamento['profissional_id'] ?>`);
        const info = await resposta.json();

        if (info.fechado) {
            statusCarregamento.textContent = info.mensagem;
            return;
        }

        if (info.ordem_chegada) {
            bloqueioOrdemChegada = true;
            horarioSelecionado.value = '';
            atualizarBotaoRemarcacao();
            statusCarregamento.textContent = `Modalidade: Ordem de Chegada. ${info.mensagem_ordem || 'Não é possível remarcar para esta data.'}`;
            return;
        }

        if (!info.horarios || info.horarios.length === 0) {
            statusCarregamento.textContent = 'Não há horários suficientes nesta data.';
            return;
        }

        statusCarregamento.classList.add('hidden');
        gradeHorarios.classList.remove('hidden');

        info.horarios.forEach(hora => {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.textContent = hora;
            btn.className = 'botao-slot py-2 px-1 text-xs font-medium rounded-lg border border-zinc-700 bg-zinc-800 text-zinc-200 hover:border-amber-500 hover:text-amber-400 transition text-center';

            btn.addEventListener('click', () => {
                document.querySelectorAll('.botao-slot').forEach(b => {
                    b.classList.remove('bg-amber-500', 'text-zinc-950', 'border-amber-500', 'font-bold');
                    b.classList.add('bg-zinc-800', 'text-zinc-200', 'border-zinc-700');
                });

                btn.classList.add('bg-amber-500', 'text-zinc-950', 'border-amber-500', 'font-bold');
                btn.classList.remove('bg-zinc-800', 'text-zinc-200', 'border-zinc-700');

                horarioSelecionado.value = hora;
                atualizarBotaoRemarcacao();
            });

            gradeHorarios.appendChild(btn);
        });
    } catch (e) {
        statusCarregamento.textContent = 'Erro ao consultar horários disponíveis.';
    }
}
</script>

</body>
</html>
