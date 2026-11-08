<?php
require_once 'config/database.php';
header('Content-Type: application/json; charset=utf-8');

$data = $_GET['data'] ?? '';
$servicoId = (int)($_GET['servico_id'] ?? 0);
$profissionalId = !empty($_GET['profissional_id']) ? (int)$_GET['profissional_id'] : null;

if (empty($data) || empty($servicoId)) {
    echo json_encode(['fechado' => true, 'mensagem' => 'Data e serviço são obrigatórios.']);
    exit;
}

// 1. Busca a duração combinada dos serviços escolhidos (suporta múltiplos serviços)
$paramServicos = $_GET['servicos_ids'] ?? $_GET['servico_id'] ?? '';
$idsLista = array_filter(array_map('intval', explode(',', strval($paramServicos))));

if (empty($idsLista)) {
    echo json_encode(['fechado' => true, 'mensagem' => 'Nenhum serviço válido informado.']);
    exit;
}

$placeholders = implode(',', array_fill(0, count($idsLista), '?'));
$stmtServ = $pdo->prepare("SELECT SUM(duracao_minutos) as duracao_total FROM servicos WHERE id IN ($placeholders) AND status = 'ativo'");
$stmtServ->execute($idsLista);
$duracaoTotal = (int)$stmtServ->fetchColumn();

if ($duracaoTotal <= 0) {
    echo json_encode(['fechado' => true, 'mensagem' => 'Serviços não encontrados ou inativos.']);
    exit;
}
$duracaoMinutos = $duracaoTotal;

// 1.1. Verifica se a data está bloqueada (Geral da barbearia OU folga específica do barbeiro)
$sqlBloqueio = "
    SELECT motivo, profissional_id FROM bloqueios_agenda 
    WHERE data_bloqueio = ? 
      AND (profissional_id IS NULL OR (profissional_id = ? AND ? IS NOT NULL))
    LIMIT 1
";
$stmtBloqueio = $pdo->prepare($sqlBloqueio);
$stmtBloqueio->execute([$data, $profissionalId, $profissionalId]);
$bloqueio = $stmtBloqueio->fetch();

if ($bloqueio) {
    $msgBloqueio = !empty($bloqueio['profissional_id']) 
        ? "Este profissional está de folga nesta data (" . htmlspecialchars($bloqueio['motivo']) . ")."
        : "Barbearia Fechada nesta data (" . htmlspecialchars($bloqueio['motivo']) . ").";

    echo json_encode([
        'fechado' => true,
        'mensagem' => $msgBloqueio
    ]);
    exit;
}

// 2. Busca turnos ativos (por dia da semana fixo OU por data pontual)
$diaSemanaNum = (int)date('w', strtotime($data));

$sqlTurnos = "
    SELECT * FROM turnos_atendimento 
    WHERE (
        (dia_semana = ? AND (? > CURDATE() OR (? = CURDATE() AND hora_fim >= CURTIME())))
        OR 
        (data_atendimento = ? AND (data_atendimento > CURDATE() OR hora_fim >= CURTIME()))
    )
";
$paramsTurnos = [$diaSemanaNum, $data, $data, $data];

if ($profissionalId) {
    $sqlTurnos .= " AND (profissional_id = ? OR profissional_id IS NULL)";
    $paramsTurnos[] = $profissionalId;
}

$sqlTurnos .= " ORDER BY hora_inicio ASC";
$stmtTurnos = $pdo->prepare($sqlTurnos);
$stmtTurnos->execute($paramsTurnos);
$turnos = $stmtTurnos->fetchAll();

if (empty($turnos)) {
    echo json_encode(['fechado' => true, 'mensagem' => 'Não existem turnos disponíveis para esta data (ou o expediente já encerrou).']);
    exit;
}

// 3. Se o turno cadastrado for "Ordem de Chegada"
$temOrdemChegada = false;
$faixaAtendimento = '';
foreach ($turnos as $t) {
    if (($t['tipo_atendimento'] ?? 'horario_marcado') === 'ordem_chegada') {
        $temOrdemChegada = true;
        $faixaAtendimento = substr($t['hora_inicio'], 0, 5) . ' às ' . substr($t['hora_fim'], 0, 5);
        break;
    }
}

if ($temOrdemChegada) {
    echo json_encode([
        'fechado' => false,
        'ordem_chegada' => true,
        'mensagem_ordem' => "Atendimento por Ordem de Chegada ({$faixaAtendimento})",
        'horario_padrao' => substr($turnos[0]['hora_inicio'], 0, 5)
    ]);
    exit;
}

// 4. Busca agendamentos ocupados para esse profissional específico na data
$sqlOcupados = "
    SELECT a.horario, s.duracao_minutos 
    FROM agendamentos a 
    JOIN servicos s ON a.servico_id = s.id 
    WHERE a.data_agendamento = ? AND a.status != 'cancelado'
";
$paramsOcupados = [$data];

if ($profissionalId) {
    $sqlOcupados .= " AND (a.profissional_id = ? OR a.profissional_id IS NULL)";
    $paramsOcupados[] = $profissionalId;
}

$stmtOcupados = $pdo->prepare($sqlOcupados);
$stmtOcupados->execute($paramsOcupados);
$agendamentosOcupados = $stmtOcupados->fetchAll();

// 5. Gera os slots disponíveis omitindo horários já passados se a data for hoje
$horariosDisponiveis = [];
$agoraTimestamp = time();

foreach ($turnos as $turno) {
    $inicio = strtotime("$data {$turno['hora_inicio']}");
    $fim    = strtotime("$data {$turno['hora_fim']}");
    $intervaloVal = (int)($turno['intervalo_minutos'] ?? 30);
    $passo = max(5, $intervaloVal > 0 ? $intervaloVal : 30) * 60;

    for ($tempo = $inicio; $tempo < $fim; $tempo += $passo) {
        $horarioFimSlot = $tempo + ($duracaoMinutos * 60);

        // Se a data for hoje e o horário de início já passou, remove
        if ($data === date('Y-m-d') && $tempo <= $agoraTimestamp) {
            continue;
        }

        // Se o serviço ultrapassa o término do turno, não cabe
        if ($horarioFimSlot > $fim) {
            continue;
        }

        // Verifica colisão com pausa de almoço/descanso se configurada
        if (!empty($turno['pausa_inicio']) && !empty($turno['pausa_fim'])) {
            $pInicio = strtotime("$data {$turno['pausa_inicio']}");
            $pFim    = strtotime("$data {$turno['pausa_fim']}");
            if ($tempo < $pFim && $horarioFimSlot > $pInicio) {
                continue; // Pula horário que colide com o almoço
            }
        }

        // Verifica colisão com outros agendamentos do mesmo profissional
        $ocupado = false;
        foreach ($agendamentosOcupados as $ag) {
            $agInicio = strtotime("$data {$ag['horario']}");
            $agFim    = $agInicio + ((int)$ag['duracao_minutos'] * 60);

            if ($tempo < $agFim && $horarioFimSlot > $agInicio) {
                $ocupado = true;
                break;
            }
        }

        if (!$ocupado) {
            $horariosDisponiveis[] = date('H:i', $tempo);
        }
    }
}

$horariosDisponiveis = array_values(array_unique($horariosDisponiveis));
sort($horariosDisponiveis);

if (empty($horariosDisponiveis)) {
    echo json_encode(['fechado' => true, 'mensagem' => 'Sem vagas disponíveis para a duração deste corte nesta data.']);
    exit;
}

echo json_encode([
    'fechado' => false,
    'ordem_chegada' => false,
    'horarios' => $horariosDisponiveis
]);
exit;
