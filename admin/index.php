<?php
require_once 'header.php';

$hoje = date('Y-m-d');
$amanha = date('Y-m-d', strtotime('+1 day'));

// 1. Total Agendados para hoje (pendente ou confirmado)
$stmtHoje = $pdo->prepare("SELECT COUNT(*) FROM agendamentos WHERE data_agendamento = ? AND status IN ('pendente', 'confirmado')");
$stmtHoje->execute([$hoje]);
$totalHoje = $stmtHoje->fetchColumn();

// 2. Total aguardando confirmação (pendentes)
$stmtPendentes = $pdo->query("SELECT COUNT(*) FROM agendamentos WHERE status = 'pendente'");
$totalPendentes = $stmtPendentes->fetchColumn();

// 3. Faturamento Concluído
$stmtFaturamento = $pdo->query("SELECT COALESCE(SUM(s.preco), 0) FROM agendamentos a JOIN servicos s ON a.servico_id = s.id WHERE a.status = 'concluido'");
$faturamentoTotal = $stmtFaturamento->fetchColumn();

// 4. Serviços Ativos
$stmtTotalServicos = $pdo->query("SELECT COUNT(*) FROM servicos WHERE status = 'ativo'");
$totalServicos = $stmtTotalServicos->fetchColumn();

// 5. Atualizar status direto do dashboard
if (isset($_POST['atualizar_status_dashboard'])) {
    $agendamento_id = (int)$_POST['agendamento_id'];
    $novo_status = $_POST['status'];
    $stmt = $pdo->prepare("UPDATE agendamentos SET status = ? WHERE id = ?");
    $stmt->execute([$novo_status, $agendamento_id]);
}

// 6. Tratamento do filtro por data (Predefinido: 'hoje')
$filtroData = $_GET['periodo'] ?? 'hoje';

$sqlLista = "
    SELECT a.*, s.nome as servico_nome, s.preco, p.nome as prof_nome 
    FROM agendamentos a 
    JOIN servicos s ON a.servico_id = s.id 
    LEFT JOIN profissionais p ON a.profissional_id = p.id 
    WHERE a.status IN ('pendente', 'confirmado')
";
$paramsLista = [];

if ($filtroData === 'hoje') {
    $sqlLista .= " AND a.data_agendamento = ?";
    $paramsLista[] = $hoje;
} elseif ($filtroData === 'amanha') {
    $sqlLista .= " AND a.data_agendamento = ?";
    $paramsLista[] = $amanha;
} elseif ($filtroData === 'semana') {
    $iniSemana = date('Y-m-d', strtotime('monday this week'));
    $fimSemana = date('Y-m-d', strtotime('sunday this week'));
    $sqlLista .= " AND a.data_agendamento BETWEEN ? AND ?";
    $paramsLista[] = $iniSemana;
    $paramsLista[] = $fimSemana;
}

$sqlLista .= " ORDER BY a.data_agendamento ASC, a.horario ASC";

$stmtProximos = $pdo->prepare($sqlLista);
$stmtProximos->execute($paramsLista);
$proximos = $stmtProximos->fetchAll();

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

$listaProfissionais = $pdo->query("SELECT * FROM profissionais WHERE status = 'ativo' ORDER BY nome ASC")->fetchAll();
$listaServicos = $pdo->query("SELECT * FROM servicos WHERE status = 'ativo' ORDER BY nome ASC")->fetchAll();

require_once 'header.php';

$hoje = date('Y-m-d');
$amanha = date('Y-m-d', strtotime('+1 day'));

// Se for requisição AJAX para atualizar os gráficos em barras e dados sem recarregar a página
if (isset($_GET['ajax_dados_grafico']) && $_GET['ajax_dados_grafico'] == '1') {
    header('Content-Type: application/json; charset=utf-8');
    
    $filtroBarbeiroGrafico = !empty($_GET['filtro_barbeiro_grafico']) ? (int)$_GET['filtro_barbeiro_grafico'] : null;
    $dataGrafico = $_GET['data_grafico'] ?? $hoje;
    $mesGrafico = $_GET['mes_grafico'] ?? date('Y-m');
    $timestampDataG = strtotime($dataGrafico);

    $diasSemanaPt = [
        'Sunday' => 'Domingo', 'Monday' => 'Segunda-feira', 'Tuesday' => 'Terça-feira',
        'Wednesday' => 'Quarta-feira', 'Thursday' => 'Quinta-feira', 'Friday' => 'Sexta-feira', 'Saturday' => 'Sábado'
    ];
    $mesesPt = [
        '01' => 'Janeiro', '02' => 'Fevereiro', '03' => 'Março', '04' => 'Abril',
        '05' => 'Maio', '06' => 'Junho', '07' => 'Julho', '08' => 'Agosto',
        '09' => 'Setembro', '10' => 'Outubro', '11' => 'Novembro', '12' => 'Dezembro'
    ];

    $diaSemanaNome = $diasSemanaPt[date('l', $timestampDataG)] ?? 'Dia';
    $mesNome = $mesesPt[date('m', strtotime($mesGrafico . '-01'))] ?? 'Mês';

    // 1 & 2: Turnos Dia
    $sqlTurnosDia = "
        SELECT 
            CASE 
                WHEN a.horario < '12:00:00' THEN 'manha'
                WHEN a.horario < '18:00:00' THEN 'tarde'
                ELSE 'noite'
            END as turno,
            COUNT(*) as total_agendamentos,
            COALESCE(SUM(s.preco), 0) as total_faturamento
        FROM agendamentos a
        JOIN servicos s ON a.servico_id = s.id
        WHERE a.data_agendamento = ? AND a.status != 'cancelado'
    ";
    $paramsTurnosDia = [$dataGrafico];
    if ($filtroBarbeiroGrafico) {
        $sqlTurnosDia .= " AND a.profissional_id = ?";
        $paramsTurnosDia[] = $filtroBarbeiroGrafico;
    }
    $sqlTurnosDia .= " GROUP BY turno";
    $stmtTD = $pdo->prepare($sqlTurnosDia);
    $stmtTD->execute($paramsTurnosDia);
    $dadosTD = $stmtTD->fetchAll();

    $agendamentosTurnos = ['manha' => 0, 'tarde' => 0, 'noite' => 0];
    $faturamentoTurnos = ['manha' => 0.0, 'tarde' => 0.0, 'noite' => 0.0];
    foreach ($dadosTD as $d) {
        $k = $d['turno'];
        if (isset($agendamentosTurnos[$k])) {
            $agendamentosTurnos[$k] = (int)$d['total_agendamentos'];
            $faturamentoTurnos[$k] = (float)$d['total_faturamento'];
        }
    }

    // 3 & 4: Semanas Mês
    $anoMesBusca = $mesGrafico . '%';
    $sqlSemMes = "
        SELECT 
            CASE 
                WHEN DAY(a.data_agendamento) BETWEEN 1 AND 7 THEN 'S1'
                WHEN DAY(a.data_agendamento) BETWEEN 8 AND 14 THEN 'S2'
                WHEN DAY(a.data_agendamento) BETWEEN 15 AND 21 THEN 'S3'
                WHEN DAY(a.data_agendamento) BETWEEN 22 AND 28 THEN 'S4'
                ELSE 'S5'
            END as semana,
            COUNT(*) as total_agendamentos,
            COALESCE(SUM(s.preco), 0) as total_faturamento
        FROM agendamentos a
        JOIN servicos s ON a.servico_id = s.id
        WHERE a.data_agendamento LIKE ? AND a.status != 'cancelado'
    ";
    $paramsSemMes = [$anoMesBusca];
    if ($filtroBarbeiroGrafico) {
        $sqlSemMes .= " AND a.profissional_id = ?";
        $paramsSemMes[] = $filtroBarbeiroGrafico;
    }
    $sqlSemMes .= " GROUP BY semana ORDER BY semana ASC";
    $stmtSM = $pdo->prepare($sqlSemMes);
    $stmtSM->execute($paramsSemMes);
    $dadosSM = $stmtSM->fetchAll();

    $agendamentosSemanas = ['S1' => 0, 'S2' => 0, 'S3' => 0, 'S4' => 0, 'S5' => 0];
    $faturamentoSemanas = ['S1' => 0.0, 'S2' => 0.0, 'S3' => 0.0, 'S4' => 0.0, 'S5' => 0.0];
    foreach ($dadosSM as $d) {
        $k = $d['semana'];
        if (isset($agendamentosSemanas[$k])) {
            $agendamentosSemanas[$k] = (int)$d['total_agendamentos'];
            $faturamentoSemanas[$k] = (float)$d['total_faturamento'];
        }
    }

    // Tabela analítica
    $sqlListaAjax = "
        SELECT a.*, s.nome as servico_nome, s.preco, p.nome as prof_nome 
        FROM agendamentos a 
        JOIN servicos s ON a.servico_id = s.id 
        LEFT JOIN profissionais p ON a.profissional_id = p.id 
        WHERE a.status != 'cancelado' AND (a.data_agendamento = ? OR a.data_agendamento LIKE ?)
    ";
    $paramsListaAjax = [$dataGrafico, $anoMesBusca];
    if ($filtroBarbeiroGrafico) {
        $sqlListaAjax .= " AND a.profissional_id = ?";
        $paramsListaAjax[] = $filtroBarbeiroGrafico;
    }
    $sqlListaAjax .= " ORDER BY a.data_agendamento DESC, a.horario DESC LIMIT 20";
    $stmtL = $pdo->prepare($sqlListaAjax);
    $stmtL->execute($paramsListaAjax);
    $listaAtendimentos = $stmtL->fetchAll();

    $tabelaHtml = '';
    if (empty($listaAtendimentos)) {
        $tabelaHtml = '<tr><td colspan="6" class="p-4 text-center text-zinc-500">Nenhum atendimento encontrado para o filtro.</td></tr>';
    } else {
        foreach ($listaAtendimentos as $det) {
            $statusBadge = ($det['status'] === 'confirmado') ? 'bg-emerald-950 text-emerald-400 border border-emerald-800' : 'bg-amber-950 text-amber-400 border border-amber-800';
            $tabelaHtml .= '<tr class="hover:bg-zinc-800/30 transition">
                <td class="p-3 text-zinc-300 whitespace-nowrap">
                    <span class="text-white font-semibold">' . date('d/m/Y', strtotime($det['data_agendamento'])) . '</span>
                    <span class="text-[10px] text-amber-400 block">' . substr($det['horario'], 0, 5) . '</span>
                </td>
                <td class="p-3 font-bold text-white">' . htmlspecialchars($det['cliente_nome']) . '</td>
                <td class="p-3 text-zinc-300">' . htmlspecialchars($det['servico_nome']) . '</td>
                <td class="p-3 text-zinc-400">' . htmlspecialchars($det['prof_nome'] ?? 'Geral') . '</td>
                <td class="p-3 text-right font-bold text-amber-400">R$ ' . number_format($det['preco'], 2, ',', '.') . '</td>
                <td class="p-3 text-center">
                    <span class="px-2 py-0.5 rounded-full text-[10px] font-bold uppercase ' . $statusBadge . '">' . htmlspecialchars($det['status']) . '</span>
                </td>
            </tr>';
        }
    }

    echo json_encode([
        'sucesso' => true,
        'diaSemanaNome' => $diaSemanaNome,
        'mesNome' => $mesNome,
        'agendamentosTurnos' => $agendamentosTurnos,
        'faturamentoTurnos' => $faturamentoTurnos,
        'agendamentosSemanas' => $agendamentosSemanas,
        'faturamentoSemanas' => $faturamentoSemanas,
        'totalAgendamentosDia' => array_sum($agendamentosTurnos),
        'totalFaturamentoDia' => array_sum($faturamentoTurnos),
        'totalAgendamentosMes' => array_sum($agendamentosSemanas),
        'totalFaturamentoMes' => array_sum($faturamentoSemanas),
        'tabelaHtml' => $tabelaHtml
    ]);
    exit;
}



$filtroBarbeiroGrafico = !empty($_GET['filtro_barbeiro_grafico']) ? (int)$_GET['filtro_barbeiro_grafico'] : null;
$dataGrafico = $_GET['data_grafico'] ?? $hoje;
$mesGrafico = $_GET['mes_grafico'] ?? date('Y-m');
$graficoAtivo = $_GET['grafico_ativo'] ?? '';
$timestampDataG = strtotime($dataGrafico);

$diasSemanaPt = [
    'Sunday' => 'Domingo', 'Monday' => 'Segunda-feira', 'Tuesday' => 'Terça-feira',
    'Wednesday' => 'Quarta-feira', 'Thursday' => 'Quinta-feira', 'Friday' => 'Sexta-feira', 'Saturday' => 'Sábado'
];
$mesesPt = [
    '01' => 'Janeiro', '02' => 'Fevereiro', '03' => 'Março', '04' => 'Abril',
    '05' => 'Maio', '06' => 'Junho', '07' => 'Julho', '08' => 'Agosto',
    '09' => 'Setembro', '10' => 'Outubro', '11' => 'Novembro', '12' => 'Dezembro'
];

$diaSemanaNome = $diasSemanaPt[date('l', $timestampDataG)] ?? 'Dia';
$mesNome = $mesesPt[date('m', strtotime($mesGrafico . '-01'))] ?? 'Mês';

$sqlTurnosDia = "
    SELECT 
        CASE 
            WHEN a.horario < '12:00:00' THEN 'manha'
            WHEN a.horario < '18:00:00' THEN 'tarde'
            ELSE 'noite'
        END as turno,
        COUNT(*) as total_agendamentos,
        COALESCE(SUM(s.preco), 0) as total_faturamento
    FROM agendamentos a
    JOIN servicos s ON a.servico_id = s.id
    WHERE a.data_agendamento = ? AND a.status != 'cancelado'
";
$paramsTurnosDia = [$dataGrafico];
if ($filtroBarbeiroGrafico) {
    $sqlTurnosDia .= " AND a.profissional_id = ?";
    $paramsTurnosDia[] = $filtroBarbeiroGrafico;
}
$sqlTurnosDia .= " GROUP BY turno";
$stmtTD = $pdo->prepare($sqlTurnosDia);
$stmtTD->execute($paramsTurnosDia);
$dadosTD = $stmtTD->fetchAll();

$agendamentosTurnos = ['manha' => 0, 'tarde' => 0, 'noite' => 0];
$faturamentoTurnos = ['manha' => 0.0, 'tarde' => 0.0, 'noite' => 0.0];
foreach ($dadosTD as $d) {
    $k = $d['turno'];
    if (isset($agendamentosTurnos[$k])) {
        $agendamentosTurnos[$k] = (int)$d['total_agendamentos'];
        $faturamentoTurnos[$k] = (float)$d['total_faturamento'];
    }
}

$totalAgendamentosDiaGrafico = array_sum($agendamentosTurnos);
$totalFaturamentoDiaGrafico = array_sum($faturamentoTurnos);

$anoMesBusca = $mesGrafico . '%';
$sqlSemMes = "
    SELECT 
        CASE 
            WHEN DAY(a.data_agendamento) BETWEEN 1 AND 7 THEN 'S1'
            WHEN DAY(a.data_agendamento) BETWEEN 8 AND 14 THEN 'S2'
            WHEN DAY(a.data_agendamento) BETWEEN 15 AND 21 THEN 'S3'
            WHEN DAY(a.data_agendamento) BETWEEN 22 AND 28 THEN 'S4'
            ELSE 'S5'
        END as semana,
        COUNT(*) as total_agendamentos,
        COALESCE(SUM(s.preco), 0) as total_faturamento
    FROM agendamentos a
    JOIN servicos s ON a.servico_id = s.id
    WHERE a.data_agendamento LIKE ? AND a.status != 'cancelado'
";
$paramsSemMes = [$anoMesBusca];
if ($filtroBarbeiroGrafico) {
    $sqlSemMes .= " AND a.profissional_id = ?";
    $paramsSemMes[] = $filtroBarbeiroGrafico;
}
$sqlSemMes .= " GROUP BY semana ORDER BY semana ASC";
$stmtSM = $pdo->prepare($sqlSemMes);
$stmtSM->execute($paramsSemMes);
$dadosSM = $stmtSM->fetchAll();

$agendamentosSemanas = ['S1' => 0, 'S2' => 0, 'S3' => 0, 'S4' => 0, 'S5' => 0];
$faturamentoSemanas = ['S1' => 0.0, 'S2' => 0.0, 'S3' => 0.0, 'S4' => 0.0, 'S5' => 0.0];
foreach ($dadosSM as $d) {
    $k = $d['semana'];
    if (isset($agendamentosSemanas[$k])) {
        $agendamentosSemanas[$k] = (int)$d['total_agendamentos'];
        $faturamentoSemanas[$k] = (float)$d['total_faturamento'];
    }
}

$totalAgendamentosMesGrafico = array_sum($agendamentosSemanas);
$totalFaturamentoMesGrafico = array_sum($faturamentoSemanas);

$chaoY = 76;
$tetoY = 16;
$alturaMax = $chaoY - $tetoY;

$maxAgDia = max(1, max($agendamentosTurnos));
$hM_ag = ($agendamentosTurnos['manha'] > 0) ? max(6, round(($agendamentosTurnos['manha'] / $maxAgDia) * $alturaMax)) : 0;
$hT_ag = ($agendamentosTurnos['tarde'] > 0) ? max(6, round(($agendamentosTurnos['tarde'] / $maxAgDia) * $alturaMax)) : 0;
$hN_ag = ($agendamentosTurnos['noite'] > 0) ? max(6, round(($agendamentosTurnos['noite'] / $maxAgDia) * $alturaMax)) : 0;
$yM_ag = $chaoY - $hM_ag; $yT_ag = $chaoY - $hT_ag; $yN_ag = $chaoY - $hN_ag;

$maxFatDia = max(1.0, max($faturamentoTurnos));
$hM_fat = ($faturamentoTurnos['manha'] > 0) ? max(6, round(($faturamentoTurnos['manha'] / $maxFatDia) * $alturaMax)) : 0;
$hT_fat = ($faturamentoTurnos['tarde'] > 0) ? max(6, round(($faturamentoTurnos['tarde'] / $maxFatDia) * $alturaMax)) : 0;
$hN_fat = ($faturamentoTurnos['noite'] > 0) ? max(6, round(($faturamentoTurnos['noite'] / $maxFatDia) * $alturaMax)) : 0;
$yM_fat = $chaoY - $hM_fat; $yT_fat = $chaoY - $hT_fat; $yN_fat = $chaoY - $hN_fat;

$maxAgMes = max(1, max($agendamentosSemanas));
$alturasAgSemanas = []; $yAgSemanas = [];
foreach (['S1', 'S2', 'S3', 'S4', 'S5'] as $s) {
    $val = $agendamentosSemanas[$s];
    $h = ($val > 0) ? max(6, round(($val / $maxAgMes) * $alturaMax)) : 0;
    $alturasAgSemanas[$s] = $h; $yAgSemanas[$s] = $chaoY - $h;
}

$maxFatMes = max(1.0, max($faturamentoSemanas));
$alturasFatSemanas = []; $yFatSemanas = [];
foreach (['S1', 'S2', 'S3', 'S4', 'S5'] as $s) {
    $val = $faturamentoSemanas[$s];
    $h = ($val > 0) ? max(6, round(($val / $maxFatMes) * $alturaMax)) : 0;
    $alturasFatSemanas[$s] = $h; $yFatSemanas[$s] = $chaoY - $h;
}

?>

<div class="max-w-6xl mx-auto space-y-6">

    <!-- CABEÇALHO DO DASHBOARD COM AÇÕES RÁPIDAS -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 bg-zinc-900 border border-zinc-800 p-5 rounded-2xl shadow">
        <div>
            <h2 class="text-2xl font-bold flex items-center gap-2 text-white">
                <i class="fa-solid fa-chart-line text-amber-500"></i> Painel Principal
            </h2>
            <p class="text-zinc-400 text-xs mt-1">Visão geral da barbearia e acesso rápido a todas as funções.</p>
        </div>
        <div class="flex flex-wrap items-center gap-2.5">
            <a href="pdv.php" 
               class="inline-flex items-center gap-2 bg-emerald-600 hover:bg-emerald-500 text-white font-bold text-xs py-2.5 px-4 rounded-xl shadow-lg shadow-emerald-950/40 transition transform hover:-translate-y-0.5">
                <i class="fa-solid fa-cart-shopping text-sm"></i> Vender Produto (PDV)
            </a>
            <button type="button" onclick="document.getElementById('modalNovoAgendamento').classList.remove('hidden')" 
                    class="inline-flex items-center gap-2 bg-amber-500 hover:bg-amber-600 text-zinc-950 font-bold text-xs py-2.5 px-4 rounded-xl shadow-lg shadow-amber-950/40 transition transform hover:-translate-y-0.5">
                <i class="fa-solid fa-calendar-plus text-sm"></i> + Novo Agendamento
            </button>
        </div>
    </div>

    <!-- MENSAGENS DE NOTIFICAÇÃO -->
    <?php if (isset($_GET['sucesso_criado'])): ?>
        <div class="bg-emerald-500/10 border border-emerald-500/30 text-emerald-400 text-xs p-3.5 rounded-xl flex items-center justify-between shadow">
            <span><i class="fa-solid fa-circle-check mr-2"></i> Agendamento cadastrado com sucesso!</span>
            <a href="index.php" class="text-zinc-400 hover:text-white">&times;</a>
        </div>
    <?php endif; ?>

    <?php if (isset($_GET['sucesso_pagamento'])): ?>
        <div class="bg-emerald-500/10 border border-emerald-500/30 text-emerald-400 text-xs p-3.5 rounded-xl flex items-center justify-between shadow">
            <span><i class="fa-solid fa-circle-check mr-2"></i> Atendimento finalizado e pagamento registrado com sucesso!</span>
            <a href="index.php" class="text-zinc-400 hover:text-white">&times;</a>
        </div>
    <?php endif; ?>

    <!-- 4 CARDS DE MÉTRICAS OPERACIONAIS -->
    <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-4">
        <a href="agendamentos.php?atalho=hoje" class="bg-zinc-900 border border-zinc-800 p-5 rounded-2xl hover:border-amber-500 transition transform hover:-translate-y-0.5 block group shadow">
            <div class="flex items-center justify-between text-zinc-400 mb-2 group-hover:text-zinc-300">
                <span class="text-xs uppercase tracking-wider font-semibold">Agendamentos Hoje</span>
                <i class="fa-solid fa-calendar text-amber-500 text-lg"></i>
            </div>
            <div class="text-2xl font-bold text-white"><?= $totalHoje ?></div>
            <span class="text-[11px] text-amber-500/80 group-hover:underline mt-1 block">Ver agendamentos &rarr;</span>
        </a>

        <a href="agendamentos.php?filtro_status=pendente" class="bg-zinc-900 border border-zinc-800 p-5 rounded-2xl hover:border-amber-500 transition transform hover:-translate-y-0.5 block group shadow">
            <div class="flex items-center justify-between text-zinc-400 mb-2 group-hover:text-zinc-300">
                <span class="text-xs uppercase tracking-wider font-semibold">Aguardando Confirmação</span>
                <i class="fa-solid fa-clock text-amber-500 text-lg"></i>
            </div>
            <div class="text-2xl font-bold text-white"><?= $totalPendentes ?></div>
            <span class="text-[11px] text-amber-500/80 group-hover:underline mt-1 block">Confirmar horários &rarr;</span>
        </a>

        <a href="financeiro.php" class="bg-zinc-900 border border-zinc-800 p-5 rounded-2xl hover:border-emerald-500 transition transform hover:-translate-y-0.5 block group shadow">
            <div class="flex items-center justify-between text-zinc-400 mb-2 group-hover:text-zinc-300">
                <span class="text-xs uppercase tracking-wider font-semibold">Faturamento Concluído</span>
                <i class="fa-solid fa-wallet text-emerald-400 text-lg"></i>
            </div>
            <div class="text-2xl font-bold text-emerald-400">R$ <?= number_format($faturamentoTotal, 2, ',', '.') ?></div>
            <span class="text-[11px] text-emerald-400/80 group-hover:underline mt-1 block">Abrir financeiro &rarr;</span>
        </a>

        <a href="servicos.php" class="bg-zinc-900 border border-zinc-800 p-5 rounded-2xl hover:border-amber-500 transition transform hover:-translate-y-0.5 block group shadow">
            <div class="flex items-center justify-between text-zinc-400 mb-2 group-hover:text-zinc-300">
                <span class="text-xs uppercase tracking-wider font-semibold">Serviços Ativos</span>
                <i class="fa-solid fa-scissors text-amber-500 text-lg"></i>
            </div>
            <div class="text-2xl font-bold text-white"><?= $totalServicos ?></div>
            <span class="text-[11px] text-amber-500/80 group-hover:underline mt-1 block">Gerenciar serviços &rarr;</span>
        </a>
    </div>

                <!-- 4 GRÁFICOS ANALÍTICOS EM BARRAS VERTICAIS (DINÂMICOS & AJAX) -->
    <div class="space-y-3">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2">
            <div>
                <h3 class="text-xs font-extrabold uppercase tracking-wider text-zinc-400 flex items-center gap-2">
                    <i class="fa-solid fa-chart-column text-amber-500"></i> Painel Analítico • Gráficos em Barras
                </h3>
                <span class="text-[11px] text-zinc-500">
                    Data: <strong class="text-white"><?= date('d/m/Y', strtotime($dataGrafico)) ?></strong> • Mês: <strong class="text-white"><?= $mesNome ?></strong>
                    <?php if ($filtroBarbeiroGrafico): ?>
                        • Barbeiro ID: <strong class="text-amber-400">#<?= $filtroBarbeiroGrafico ?></strong>
                    <?php else: ?>
                        • <strong class="text-zinc-400">Toda a Equipe</strong>
                    <?php endif; ?>
                </span>
            </div>
            <span class="text-[11px] text-amber-400 font-semibold bg-amber-500/10 px-3 py-1 rounded-xl border border-amber-500/20 inline-flex items-center gap-1.5 w-fit">
                <i class="fa-solid fa-arrow-up-right-and-down-left-from-center"></i> Clique no gráfico para expandir e filtrar
            </span>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            
            <!-- GRÁFICO 1: AGENDAMENTOS • DIA (BARRAS VERTICAIS) -->
            <div onclick="abrirModalGraficoExpandido('agendamentos_dia')" 
                 class="bg-zinc-950/80 hover:bg-zinc-900 border border-zinc-800 hover:border-amber-500/60 rounded-3xl p-5 shadow-2xl transition-all duration-300 cursor-pointer group flex flex-col justify-between relative overflow-hidden">
                <div class="flex items-start justify-between">
                    <div>
                        <span class="text-xs font-semibold text-zinc-400 flex items-center gap-1.5">
                            Agendamentos • <strong class="text-white"><?= htmlspecialchars($diaSemanaNome) ?></strong>
                        </span>
                        <div class="text-3xl font-extrabold text-white mt-1 group-hover:text-amber-400 transition">
                            <?= $totalAgendamentosDiaGrafico ?>
                        </div>
                        <span class="text-[11px] text-zinc-500">distribuição do dia</span>
                    </div>
                    <div class="w-10 h-10 rounded-2xl bg-zinc-900 border border-zinc-800 flex items-center justify-center text-zinc-400 group-hover:text-amber-400 group-hover:border-amber-500/40 transition">
                        <i class="fa-regular fa-calendar text-base"></i>
                    </div>
                </div>

                <!-- Gráfico de Barras Verticais SVG -->
                <div class="mt-4 pt-2">
                    <svg viewBox="0 0 300 90" class="w-full h-20 overflow-visible">
                        <defs>
                            <linearGradient id="gradBarV1" x1="0" y1="0" x2="0" y2="1">
                                <stop offset="0%" stop-color="#f59e0b" stop-opacity="0.95"/>
                                <stop offset="100%" stop-color="#b45309" stop-opacity="0.5"/>
                            </linearGradient>
                        </defs>
                        <!-- Linha de base -->
                        <line x1="20" y1="<?= $chaoY ?>" x2="280" y2="<?= $chaoY ?>" stroke="#3f3f46" stroke-width="1.5" stroke-dasharray="3,3"/>
                        
                        <!-- 3 Barras Verticais (Manhã, Tarde, Noite) -->
                        <rect x="55" y="<?= $yM_ag ?>" width="35" height="<?= $hM_ag ?>" rx="6" fill="url(#gradBarV1)" />
                        <rect x="132" y="<?= $yT_ag ?>" width="35" height="<?= $hT_ag ?>" rx="6" fill="url(#gradBarV1)" />
                        <rect x="210" y="<?= $yN_ag ?>" width="35" height="<?= $hN_ag ?>" rx="6" fill="url(#gradBarV1)" />
                    </svg>
                    <div class="flex justify-between text-[11px] font-semibold text-zinc-400 px-6 pt-1 border-t border-zinc-800/80">
                        <span>Manhã (<?= $agendamentosTurnos['manha'] ?>)</span>
                        <span>Tarde (<?= $agendamentosTurnos['tarde'] ?>)</span>
                        <span>Noite (<?= $agendamentosTurnos['noite'] ?>)</span>
                    </div>
                </div>
            </div>

            <!-- GRÁFICO 2: FATURAMENTO • DIA (BARRAS VERTICAIS) -->
            <div onclick="abrirModalGraficoExpandido('faturamento_dia')" 
                 class="bg-zinc-950/80 hover:bg-zinc-900 border border-zinc-800 hover:border-emerald-500/60 rounded-3xl p-5 shadow-2xl transition-all duration-300 cursor-pointer group flex flex-col justify-between relative overflow-hidden">
                <div class="flex items-start justify-between">
                    <div>
                        <span class="text-xs font-semibold text-zinc-400 flex items-center gap-1.5">
                            Faturamento • <strong class="text-white"><?= htmlspecialchars($diaSemanaNome) ?></strong>
                        </span>
                        <div class="text-3xl font-extrabold text-white mt-1 group-hover:text-emerald-400 transition">
                            R$ <?= number_format($totalFaturamentoDiaGrafico, 2, ',', '.') ?>
                        </div>
                        <span class="text-[11px] text-zinc-500">valores do dia</span>
                    </div>
                    <div class="w-10 h-10 rounded-2xl bg-zinc-900 border border-zinc-800 flex items-center justify-center text-zinc-400 group-hover:text-emerald-400 group-hover:border-emerald-500/40 transition">
                        <i class="fa-solid fa-dollar-sign text-base"></i>
                    </div>
                </div>

                <!-- Gráfico de Barras Verticais Faturamento Dia SVG -->
                <div class="mt-4 pt-2">
                    <svg viewBox="0 0 300 90" class="w-full h-20 overflow-visible">
                        <defs>
                            <linearGradient id="gradBarV2" x1="0" y1="0" x2="0" y2="1">
                                <stop offset="0%" stop-color="#10b981" stop-opacity="0.95"/>
                                <stop offset="100%" stop-color="#047857" stop-opacity="0.5"/>
                            </linearGradient>
                        </defs>
                        <line x1="20" y1="<?= $chaoY ?>" x2="280" y2="<?= $chaoY ?>" stroke="#3f3f46" stroke-width="1.5" stroke-dasharray="3,3"/>
                        
                        <rect x="55" y="<?= $yM_fat ?>" width="35" height="<?= $hM_fat ?>" rx="6" fill="url(#gradBarV2)" />
                        <rect x="132" y="<?= $yT_fat ?>" width="35" height="<?= $hT_fat ?>" rx="6" fill="url(#gradBarV2)" />
                        <rect x="210" y="<?= $yN_fat ?>" width="35" height="<?= $hN_fat ?>" rx="6" fill="url(#gradBarV2)" />
                    </svg>
                    <div class="flex justify-between text-[11px] font-semibold text-zinc-400 px-6 pt-1 border-t border-zinc-800/80">
                        <span>Manhã (R$ <?= number_format($faturamentoTurnos['manha'], 0, ',', '.') ?>)</span>
                        <span>Tarde (R$ <?= number_format($faturamentoTurnos['tarde'], 0, ',', '.') ?>)</span>
                        <span>Noite (R$ <?= number_format($faturamentoTurnos['noite'], 0, ',', '.') ?>)</span>
                    </div>
                </div>
            </div>

            <!-- GRÁFICO 3: AGENDAMENTOS • MÊS POR SEMANA (5 BARRAS VERTICAIS) -->
            <div onclick="abrirModalGraficoExpandido('agendamentos_mes')" 
                 class="bg-zinc-950/80 hover:bg-zinc-900 border border-zinc-800 hover:border-amber-500/60 rounded-3xl p-5 shadow-2xl transition-all duration-300 cursor-pointer group flex flex-col justify-between relative overflow-hidden">
                <div class="flex items-start justify-between">
                    <div>
                        <span class="text-xs font-semibold text-zinc-400 flex items-center gap-1.5">
                            Agendamentos • <strong class="text-white"><?= htmlspecialchars($mesNome) ?></strong>
                        </span>
                        <div class="text-3xl font-extrabold text-white mt-1 group-hover:text-amber-400 transition">
                            <?= $totalAgendamentosMesGrafico ?>
                        </div>
                        <span class="text-[11px] text-zinc-500">por semana</span>
                    </div>
                    <div class="w-10 h-10 rounded-2xl bg-zinc-900 border border-zinc-800 flex items-center justify-center text-zinc-400 group-hover:text-amber-400 group-hover:border-amber-500/40 transition">
                        <i class="fa-solid fa-calendar-week text-base"></i>
                    </div>
                </div>

                <!-- 5 Barras Verticais S1..S5 -->
                <div class="mt-4 pt-2">
                    <svg viewBox="0 0 300 90" class="w-full h-20 overflow-visible">
                        <defs>
                            <linearGradient id="gradBarV3" x1="0" y1="0" x2="0" y2="1">
                                <stop offset="0%" stop-color="#f59e0b" stop-opacity="0.95"/>
                                <stop offset="100%" stop-color="#b45309" stop-opacity="0.5"/>
                            </linearGradient>
                        </defs>
                        <line x1="15" y1="<?= $chaoY ?>" x2="285" y2="<?= $chaoY ?>" stroke="#3f3f46" stroke-width="1.5" stroke-dasharray="3,3"/>
                        
                        <rect x="24" y="<?= $yAgSemanas['S1'] ?>" width="42" height="<?= $alturasAgSemanas['S1'] ?>" rx="5" fill="url(#gradBarV3)" />
                        <rect x="76" y="<?= $yAgSemanas['S2'] ?>" width="42" height="<?= $alturasAgSemanas['S2'] ?>" rx="5" fill="url(#gradBarV3)" />
                        <rect x="128" y="<?= $yAgSemanas['S3'] ?>" width="42" height="<?= $alturasAgSemanas['S3'] ?>" rx="5" fill="url(#gradBarV3)" />
                        <rect x="180" y="<?= $yAgSemanas['S4'] ?>" width="42" height="<?= $alturasAgSemanas['S4'] ?>" rx="5" fill="url(#gradBarV3)" />
                        <rect x="232" y="<?= $yAgSemanas['S5'] ?>" width="42" height="<?= $alturasAgSemanas['S5'] ?>" rx="5" fill="url(#gradBarV3)" />
                    </svg>
                    <div class="flex justify-between text-[11px] font-semibold text-zinc-400 px-3 pt-1 border-t border-zinc-800/80">
                        <span>S1 (<?= $agendamentosSemanas['S1'] ?>)</span>
                        <span>S2 (<?= $agendamentosSemanas['S2'] ?>)</span>
                        <span>S3 (<?= $agendamentosSemanas['S3'] ?>)</span>
                        <span>S4 (<?= $agendamentosSemanas['S4'] ?>)</span>
                        <span>S5 (<?= $agendamentosSemanas['S5'] ?>)</span>
                    </div>
                </div>
            </div>

            <!-- GRÁFICO 4: FATURAMENTO • MÊS POR SEMANA (5 BARRAS VERTICAIS) -->
            <div onclick="abrirModalGraficoExpandido('faturamento_mes')" 
                 class="bg-zinc-950/80 hover:bg-zinc-900 border border-zinc-800 hover:border-emerald-500/60 rounded-3xl p-5 shadow-2xl transition-all duration-300 cursor-pointer group flex flex-col justify-between relative overflow-hidden">
                <div class="flex items-start justify-between">
                    <div>
                        <span class="text-xs font-semibold text-zinc-400 flex items-center gap-1.5">
                            Faturamento • <strong class="text-white"><?= htmlspecialchars($mesNome) ?></strong>
                        </span>
                        <div class="text-3xl font-extrabold text-white mt-1 group-hover:text-emerald-400 transition">
                            R$ <?= number_format($totalFaturamentoMesGrafico, 2, ',', '.') ?>
                        </div>
                        <span class="text-[11px] text-zinc-500">por semana</span>
                    </div>
                    <div class="w-10 h-10 rounded-2xl bg-zinc-900 border border-zinc-800 flex items-center justify-center text-zinc-400 group-hover:text-emerald-400 group-hover:border-emerald-500/40 transition">
                        <i class="fa-solid fa-chart-line text-base"></i>
                    </div>
                </div>

                <!-- 5 Barras Verticais Faturamento S1..S5 -->
                <div class="mt-4 pt-2">
                    <svg viewBox="0 0 300 90" class="w-full h-20 overflow-visible">
                        <defs>
                            <linearGradient id="gradBarV4" x1="0" y1="0" x2="0" y2="1">
                                <stop offset="0%" stop-color="#10b981" stop-opacity="0.95"/>
                                <stop offset="100%" stop-color="#047857" stop-opacity="0.5"/>
                            </linearGradient>
                        </defs>
                        <line x1="15" y1="<?= $chaoY ?>" x2="285" y2="<?= $chaoY ?>" stroke="#3f3f46" stroke-width="1.5" stroke-dasharray="3,3"/>
                        
                        <rect x="24" y="<?= $yFatSemanas['S1'] ?>" width="42" height="<?= $alturasFatSemanas['S1'] ?>" rx="5" fill="url(#gradBarV4)" />
                        <rect x="76" y="<?= $yFatSemanas['S2'] ?>" width="42" height="<?= $alturasFatSemanas['S2'] ?>" rx="5" fill="url(#gradBarV4)" />
                        <rect x="128" y="<?= $yFatSemanas['S3'] ?>" width="42" height="<?= $alturasFatSemanas['S3'] ?>" rx="5" fill="url(#gradBarV4)" />
                        <rect x="180" y="<?= $yFatSemanas['S4'] ?>" width="42" height="<?= $alturasFatSemanas['S4'] ?>" rx="5" fill="url(#gradBarV4)" />
                        <rect x="232" y="<?= $yFatSemanas['S5'] ?>" width="42" height="<?= $alturasFatSemanas['S5'] ?>" rx="5" fill="url(#gradBarV4)" />
                    </svg>
                    <div class="flex justify-between text-[11px] font-semibold text-zinc-400 px-3 pt-1 border-t border-zinc-800/80">
                        <span>S1 (R$ <?= number_format($faturamentoSemanas['S1'], 0, ',', '.') ?>)</span>
                        <span>S2 (R$ <?= number_format($faturamentoSemanas['S2'], 0, ',', '.') ?>)</span>
                        <span>S3 (R$ <?= number_format($faturamentoSemanas['S3'], 0, ',', '.') ?>)</span>
                        <span>S4 (R$ <?= number_format($faturamentoSemanas['S4'], 0, ',', '.') ?>)</span>
                        <span>S5 (R$ <?= number_format($faturamentoSemanas['S5'], 0, ',', '.') ?>)</span>
                    </div>
                </div>
            </div>

        </div>
    </div>

    <!-- TABELA GERAL DE AGENDAMENTOS ATIVOS COM FILTROS -->
    <div class="bg-zinc-900 border border-zinc-800 rounded-2xl overflow-hidden shadow">
        <div class="p-4 sm:p-5 border-b border-zinc-800 flex flex-col md:flex-row md:items-center justify-between gap-4">
            <div>
                <h3 class="font-bold text-base text-white">Agendamentos Ativos (Pendentes e Confirmados)</h3>
                <p class="text-xs text-zinc-400">Horários de atendimento filtrados por período.</p>
            </div>

            <!-- Botões de Filtro -->
            <div class="flex items-center gap-1.5 flex-wrap">
                <a href="index.php?periodo=hoje" 
                   class="px-3 py-1.5 rounded-xl border text-xs font-semibold transition <?= $filtroData === 'hoje' ? 'bg-amber-500 text-zinc-950 border-amber-500 shadow-sm' : 'bg-zinc-800 text-zinc-300 border-zinc-700 hover:border-amber-500' ?>">
                    <i class="fa-solid fa-calendar-day mr-1"></i> Hoje
                </a>
                <a href="index.php?periodo=amanha" 
                   class="px-3 py-1.5 rounded-xl border text-xs font-semibold transition <?= $filtroData === 'amanha' ? 'bg-amber-500 text-zinc-950 border-amber-500 shadow-sm' : 'bg-zinc-800 text-zinc-300 border-zinc-700 hover:border-amber-500' ?>">
                    Amanhã
                </a>
                <a href="index.php?periodo=semana" 
                   class="px-3 py-1.5 rounded-xl border text-xs font-semibold transition <?= $filtroData === 'semana' ? 'bg-amber-500 text-zinc-950 border-amber-500 shadow-sm' : 'bg-zinc-800 text-zinc-300 border-zinc-700 hover:border-amber-500' ?>">
                    Esta Semana
                </a>
                <a href="index.php?periodo=todos" 
                   class="px-3 py-1.5 rounded-xl border text-xs font-semibold transition <?= $filtroData === 'todos' ? 'bg-amber-500 text-zinc-950 border-amber-500 shadow-sm' : 'bg-zinc-800 text-zinc-300 border-zinc-700 hover:border-amber-500' ?>">
                    Todos Ativos
                </a>
            </div>
        </div>
        
        <div class="overflow-x-auto w-full">
            <table class="w-full text-left border-collapse text-xs">
                <thead>
                    <tr class="border-b border-zinc-800 text-zinc-400 uppercase tracking-wider text-[11px]">
                        <th class="p-3.5 whitespace-nowrap">Data / Hora</th>
                        <th class="p-3.5 whitespace-nowrap">Cliente</th>
                        <th class="p-3.5 whitespace-nowrap">Serviço</th>
                        <th class="p-3.5 whitespace-nowrap">Barbeiro</th>
                        <th class="p-3.5 whitespace-nowrap">Valor</th>
                        <th class="p-3.5 whitespace-nowrap text-center">Status</th>
                        <th class="p-3.5 whitespace-nowrap text-right">Ação Rápida</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-800">
                    <?php if (empty($proximos)): ?>
                        <tr>
                            <td colspan="7" class="p-8 text-center text-zinc-500">
                                <i class="fa-regular fa-calendar-xmark text-2xl mb-2 block"></i>
                                Nenhum atendimento encontrado para o período selecionado.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($proximos as $ag): ?>
                            <tr class="hover:bg-zinc-800/30 transition">
                                <td class="p-3.5 text-zinc-300 whitespace-nowrap font-medium">
                                    <span class="text-white font-bold block"><?= date('d/m/Y', strtotime($ag['data_agendamento'])) ?></span>
                                    <span class="text-[11px] text-amber-500"><?= substr($ag['horario'], 0, 5) ?></span>
                                </td>
                                <td class="p-3.5 text-zinc-100 font-bold whitespace-nowrap">
                                    <?= htmlspecialchars($ag['cliente_nome']) ?>
                                    <?php if ($ag['cliente_telefone']): ?>
                                        <span class="block text-[11px] font-normal text-zinc-400"><?= htmlspecialchars($ag['cliente_telefone']) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="p-3.5 text-zinc-300 whitespace-nowrap">
                                    <?= htmlspecialchars($ag['servico_nome']) ?>
                                </td>
                                <td class="p-3.5 text-zinc-300 whitespace-nowrap">
                                    <?= htmlspecialchars($ag['prof_nome'] ?? 'Geral') ?>
                                </td>
                                <td class="p-3.5 font-bold text-amber-400 whitespace-nowrap">
                                    R$ <?= number_format($ag['preco'], 2, ',', '.') ?>
                                </td>
                                <td class="p-3.5 text-center whitespace-nowrap">
                                    <?php if ($ag['status'] === 'confirmado'): ?>
                                        <span class="bg-emerald-950/80 text-emerald-400 border border-emerald-800/60 px-2 py-0.5 rounded-full text-[10px] font-bold uppercase">
                                            Confirmado
                                        </span>
                                    <?php else: ?>
                                        <span class="bg-amber-950/80 text-amber-400 border border-amber-800/60 px-2 py-0.5 rounded-full text-[10px] font-bold uppercase">
                                            Pendente
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="p-3.5 text-right whitespace-nowrap">
                                    <a href="checkout.php?id=<?= $ag['id'] ?>" 
                                       class="inline-flex items-center gap-1.5 bg-amber-500 hover:bg-amber-600 text-zinc-950 font-bold px-3 py-1.5 rounded-xl text-xs transition shadow">
                                        <i class="fa-solid fa-cash-register"></i> Fechar Comanda
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- MODAL NOVO AGENDAMENTO MANUAL -->
<div id="modalNovoAgendamento" class="fixed inset-0 bg-black/70 backdrop-blur-sm z-50 flex items-center justify-center p-4 hidden">
    <div class="bg-zinc-900 border border-zinc-800 rounded-2xl max-w-md w-full p-6 space-y-4 shadow-2xl">
        <div class="flex items-center justify-between border-b border-zinc-800 pb-3">
            <h3 class="text-base font-bold text-white flex items-center gap-2">
                <i class="fa-solid fa-calendar-plus text-amber-500"></i> Novo Agendamento
            </h3>
            <button type="button" onclick="document.getElementById('modalNovoAgendamento').classList.add('hidden')" class="text-zinc-400 hover:text-white">&times;</button>
        </div>

        <form method="POST" class="space-y-3.5">
            <div>
                <label class="block text-xs font-semibold text-zinc-400 mb-1">Nome do Cliente</label>
                <input type="text" name="cliente_nome" placeholder="Ex: Carlos Eduardo" required 
                       class="w-full bg-zinc-800 border border-zinc-700 rounded-xl p-2.5 text-xs text-white focus:outline-none focus:border-amber-500">
            </div>

            <div>
                <label class="block text-xs font-semibold text-zinc-400 mb-1">Telefone / WhatsApp</label>
                <input type="text" name="cliente_telefone" placeholder="(14) 99999-8888" 
                       class="w-full bg-zinc-800 border border-zinc-700 rounded-xl p-2.5 text-xs text-white focus:outline-none focus:border-amber-500">
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-semibold text-zinc-400 mb-1">Barbeiro</label>
                    <select name="profissional_id" 
                            class="w-full bg-zinc-800 border border-zinc-700 rounded-xl p-2.5 text-xs text-white focus:outline-none focus:border-amber-500">
                        <option value="">Qualquer Disponível</option>
                        <?php foreach ($listaProfissionais as $p): ?>
                            <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['nome']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-zinc-400 mb-1">Serviço</label>
                    <select name="servico_id" required 
                            class="w-full bg-zinc-800 border border-zinc-700 rounded-xl p-2.5 text-xs text-white focus:outline-none focus:border-amber-500">
                        <?php foreach ($listaServicos as $s): ?>
                            <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['nome']) ?> (R$ <?= number_format($s['preco'], 2, ',', '.') ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-semibold text-zinc-400 mb-1">Data</label>
                    <input type="date" name="data_agendamento" value="<?= date('Y-m-d') ?>" required 
                           class="w-full bg-zinc-800 border border-zinc-700 rounded-xl p-2.5 text-xs text-white focus:outline-none focus:border-amber-500">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-zinc-400 mb-1">Horário</label>
                    <input type="time" name="horario" value="10:00" required 
                           class="w-full bg-zinc-800 border border-zinc-700 rounded-xl p-2.5 text-xs text-white focus:outline-none focus:border-amber-500">
                </div>
            </div>

            <div class="flex justify-end gap-2 pt-3 border-t border-zinc-800">
                <button type="button" onclick="document.getElementById('modalNovoAgendamento').classList.add('hidden')" 
                        class="px-4 py-2 rounded-xl text-xs bg-zinc-800 text-zinc-300 hover:text-white">Cancelar</button>
                <button type="submit" name="criar_agendamento_admin" 
                        class="px-5 py-2 rounded-xl text-xs bg-amber-500 hover:bg-amber-600 text-zinc-950 font-bold">Salvar Agendamento</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL DE EXPANSÃO E FILTRO DOS GRÁFICOS ANALÍTICOS -->
<div id="modalGraficoExpandido" class="fixed inset-0 bg-black/80 backdrop-blur-md z-50 flex items-center justify-center p-4 hidden">
    <div class="bg-zinc-950 border border-zinc-800 rounded-3xl max-w-4xl w-full p-6 space-y-5 shadow-2xl overflow-y-auto max-h-[92vh]">
        
        <!-- Topo do Modal -->
        <div class="flex items-center justify-between border-b border-zinc-800 pb-4">
            <div>
                <span class="text-[10px] font-bold text-amber-500 uppercase tracking-widest block">Análise Detalhada & Filtros Dinâmicos</span>
                <h3 id="modalGraficoTitulo" class="text-xl font-bold text-white flex items-center gap-2 mt-0.5">
                    <i class="fa-solid fa-chart-column text-amber-500"></i> Relatório Analítico em Barras
                </h3>
            </div>
            <button type="button" onclick="fecharModalGraficoExpandido()" class="text-zinc-400 hover:text-white text-2xl transition">&times;</button>
        </div>

        <!-- Barra de Filtros Interativos (AJAX) -->
        <form method="GET" action="index.php" id="formFiltroGrafico" class="bg-zinc-900 border border-zinc-800 p-4 rounded-2xl grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-3 items-end">
            <input type="hidden" name="grafico_ativo" id="modalGraficoAtivoInput" value="<?= htmlspecialchars($graficoAtivo) ?>">

            <div>
                <label class="block text-xs font-semibold text-zinc-400 mb-1">Filtrar por Barbeiro</label>
                <select name="filtro_barbeiro_grafico" id="modalFiltroBarbeiro" class="w-full bg-zinc-800 border border-zinc-700 rounded-xl p-2.5 text-xs text-white focus:outline-none focus:border-amber-500">
                    <option value="">✂️ Todos os Barbeiros</option>
                    <?php foreach ($listaProfissionais as $p): ?>
                        <option value="<?= $p['id'] ?>" <?= ($filtroBarbeiroGrafico == $p['id']) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($p['nome']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div id="divFiltroData">
                <label class="block text-xs font-semibold text-zinc-400 mb-1">Data Específica</label>
                <input type="date" name="data_grafico" id="modalFiltroData" value="<?= htmlspecialchars($dataGrafico) ?>" 
                       class="w-full bg-zinc-800 border border-zinc-700 rounded-xl p-2.5 text-xs text-white focus:outline-none focus:border-amber-500">
            </div>

            <div id="divFiltroMes">
                <label class="block text-xs font-semibold text-zinc-400 mb-1">Mês de Referência</label>
                <input type="month" name="mes_grafico" id="modalFiltroMes" value="<?= htmlspecialchars($mesGrafico) ?>" 
                       class="w-full bg-zinc-800 border border-zinc-700 rounded-xl p-2.5 text-xs text-white focus:outline-none focus:border-amber-500">
            </div>

            <div>
                <button type="submit" class="w-full bg-amber-500 hover:bg-amber-600 text-zinc-950 font-bold py-2.5 px-4 rounded-xl text-xs transition flex items-center justify-center gap-2 shadow">
                    <i class="fa-solid fa-filter"></i> Filtrar Gráficos
                </button>
            </div>
        </form>

        <!-- Área do Gráfico Ampliado (Barras) -->
        <div class="bg-zinc-900/60 border border-zinc-800 p-5 rounded-2xl space-y-3">
            <div class="flex items-center justify-between">
                <span id="modalGraficoSubtitulo" class="text-xs font-bold text-zinc-300">Distribuição Expandida</span>
                <span id="modalGraficoMetricaDestaque" class="text-sm font-extrabold text-amber-400"></span>
            </div>
            <div id="modalGraficoVisualContainer" class="w-full h-56 flex items-center justify-center bg-zinc-950/80 rounded-2xl border border-zinc-800/80 p-4">
                <!-- Conteúdo SVG Renderizado Dinamicamente -->
            </div>
        </div>

        <!-- Tabela com Detalhamento dos Registros -->
        <div class="bg-zinc-900 border border-zinc-800 rounded-2xl overflow-hidden shadow">
            <div class="p-3.5 border-b border-zinc-800 flex justify-between items-center">
                <h4 class="font-bold text-white text-xs flex items-center gap-2">
                    <i class="fa-solid fa-list-ul text-amber-500"></i> Detalhamento dos Atendimentos no Período
                </h4>
                <span class="text-[11px] text-zinc-500">Exibição analítica</span>
            </div>
            <div class="overflow-x-auto max-h-60 overflow-y-auto">
                <table class="w-full text-left border-collapse text-xs">
                    <thead>
                        <tr class="border-b border-zinc-800 text-zinc-400 uppercase tracking-wider text-[10px]">
                            <th class="p-3">Data / Hora</th>
                            <th class="p-3">Cliente</th>
                            <th class="p-3">Serviço</th>
                            <th class="p-3">Barbeiro</th>
                            <th class="p-3 text-right">Valor</th>
                            <th class="p-3 text-center">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-800" id="tabelaAnaliticaCorpo">
                        <?php if (empty($proximos)): ?>
                            <tr><td colspan="6" class="p-4 text-center text-zinc-500">Nenhum atendimento encontrado para o filtro.</td></tr>
                        <?php else: ?>
                            <?php foreach (array_slice($proximos, 0, 15) as $det): ?>
                            <tr class="hover:bg-zinc-800/30 transition">
                                <td class="p-3 text-zinc-300 whitespace-nowrap">
                                    <span class="text-white font-semibold"><?= date('d/m/Y', strtotime($det['data_agendamento'])) ?></span>
                                    <span class="text-[10px] text-amber-400 block"><?= substr($det['horario'], 0, 5) ?></span>
                                </td>
                                <td class="p-3 font-bold text-white"><?= htmlspecialchars($det['cliente_nome']) ?></td>
                                <td class="p-3 text-zinc-300"><?= htmlspecialchars($det['servico_nome']) ?></td>
                                <td class="p-3 text-zinc-400"><?= htmlspecialchars($det['prof_nome'] ?? 'Geral') ?></td>
                                <td class="p-3 text-right font-bold text-amber-400">R$ <?= number_format($det['preco'], 2, ',', '.') ?></td>
                                <td class="p-3 text-center">
                                    <span class="px-2 py-0.5 rounded-full text-[10px] font-bold uppercase <?= $det['status'] === 'confirmado' ? 'bg-emerald-950 text-emerald-400 border border-emerald-800' : 'bg-amber-950 text-amber-400 border border-amber-800' ?>">
                                        <?= htmlspecialchars($det['status']) ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>
</div>


<script>
let graficoAtivoAtual = 'agendamentos_dia';

const dadosGraficosDashboard = {
    agendamentos_dia: {
        titulo: 'Agendamentos • <span id="lblDiaNome"></span>',
        subtitulo: 'Distribuição dos agendamentos ao longo do dia (Manhã, Tarde e Noite)',
        destaqueId: 'valTotAgDia',
        getSvg: (d) => {
            const maxVal = Math.max(1, d.agendamentosTurnos.manha, d.agendamentosTurnos.tarde, d.agendamentosTurnos.noite);
            const hM = Math.max(10, Math.round((d.agendamentosTurnos.manha / maxVal) * 110));
            const hT = Math.max(10, Math.round((d.agendamentosTurnos.tarde / maxVal) * 110));
            const hN = Math.max(10, Math.round((d.agendamentosTurnos.noite / maxVal) * 110));
            const yM = 145 - hM, yT = 145 - hT, yN = 145 - hN;
            return `<svg viewBox="0 0 600 200" class="w-full h-full overflow-visible">
                <defs><linearGradient id="gradExp1" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stop-color="#f59e0b" stop-opacity="0.95"/><stop offset="100%" stop-color="#b45309" stop-opacity="0.5"/></linearGradient></defs>
                <line x1="50" y1="145" x2="550" y2="145" stroke="#3f3f46" stroke-width="2" stroke-dasharray="4,4"/>
                <rect x="90" y="${yM}" width="90" height="${hM}" rx="10" fill="url(#gradExp1)"/>
                <rect x="255" y="${yT}" width="90" height="${hT}" rx="10" fill="url(#gradExp1)"/>
                <rect x="420" y="${yN}" width="90" height="${hN}" rx="10" fill="url(#gradExp1)"/>
                <text x="135" y="180" fill="#a1a1aa" font-size="14" text-anchor="middle" font-weight="bold">Manhã (${d.agendamentosTurnos.manha})</text>
                <text x="300" y="180" fill="#a1a1aa" font-size="14" text-anchor="middle" font-weight="bold">Tarde (${d.agendamentosTurnos.tarde})</text>
                <text x="465" y="180" fill="#a1a1aa" font-size="14" text-anchor="middle" font-weight="bold">Noite (${d.agendamentosTurnos.noite})</text>
            </svg>`;
        }
    },
    faturamento_dia: {
        titulo: 'Faturamento • <span id="lblDiaNomeFat"></span>',
        subtitulo: 'Receita apurada no dia dividida por períodos de atendimento',
        destaqueId: 'valTotFatDia',
        getSvg: (d) => {
            const maxVal = Math.max(1.0, d.faturamentoTurnos.manha, d.faturamentoTurnos.tarde, d.faturamentoTurnos.noite);
            const hM = Math.max(10, Math.round((d.faturamentoTurnos.manha / maxVal) * 110));
            const hT = Math.max(10, Math.round((d.faturamentoTurnos.tarde / maxVal) * 110));
            const hN = Math.max(10, Math.round((d.faturamentoTurnos.noite / maxVal) * 110));
            const yM = 145 - hM, yT = 145 - hT, yN = 145 - hN;
            return `<svg viewBox="0 0 600 200" class="w-full h-full overflow-visible">
                <defs><linearGradient id="gradExp2" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stop-color="#10b981" stop-opacity="0.95"/><stop offset="100%" stop-color="#047857" stop-opacity="0.5"/></linearGradient></defs>
                <line x1="50" y1="145" x2="550" y2="145" stroke="#3f3f46" stroke-width="2" stroke-dasharray="4,4"/>
                <rect x="90" y="${yM}" width="90" height="${hM}" rx="10" fill="url(#gradExp2)"/>
                <rect x="255" y="${yT}" width="90" height="${hT}" rx="10" fill="url(#gradExp2)"/>
                <rect x="420" y="${yN}" width="90" height="${hN}" rx="10" fill="url(#gradExp2)"/>
                <text x="135" y="180" fill="#a1a1aa" font-size="13" text-anchor="middle" font-weight="bold">Manhã (R$ ${Number(d.faturamentoTurnos.manha).toFixed(0)})</text>
                <text x="300" y="180" fill="#a1a1aa" font-size="13" text-anchor="middle" font-weight="bold">Tarde (R$ ${Number(d.faturamentoTurnos.tarde).toFixed(0)})</text>
                <text x="465" y="180" fill="#a1a1aa" font-size="13" text-anchor="middle" font-weight="bold">Noite (R$ ${Number(d.faturamentoTurnos.noite).toFixed(0)})</text>
            </svg>`;
        }
    },
    agendamentos_mes: {
        titulo: 'Agendamentos • <span id="lblMesNome"></span>',
        subtitulo: 'Volume total de clientes atendidos distribuídos semana a semana',
        destaqueId: 'valTotAgMes',
        getSvg: (d) => {
            const maxVal = Math.max(1, d.agendamentosSemanas.S1, d.agendamentosSemanas.S2, d.agendamentosSemanas.S3, d.agendamentosSemanas.S4, d.agendamentosSemanas.S5);
            const h1 = Math.max(10, Math.round((d.agendamentosSemanas.S1 / maxVal) * 110));
            const h2 = Math.max(10, Math.round((d.agendamentosSemanas.S2 / maxVal) * 110));
            const h3 = Math.max(10, Math.round((d.agendamentosSemanas.S3 / maxVal) * 110));
            const h4 = Math.max(10, Math.round((d.agendamentosSemanas.S4 / maxVal) * 110));
            const h5 = Math.max(10, Math.round((d.agendamentosSemanas.S5 / maxVal) * 110));
            const y1 = 145 - h1, y2 = 145 - h2, y3 = 145 - h3, y4 = 145 - h4, y5 = 145 - h5;
            return `<svg viewBox="0 0 600 200" class="w-full h-full overflow-visible">
                <defs><linearGradient id="gradExp3" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stop-color="#f59e0b" stop-opacity="0.95"/><stop offset="100%" stop-color="#b45309" stop-opacity="0.5"/></linearGradient></defs>
                <line x1="30" y1="145" x2="570" y2="145" stroke="#3f3f46" stroke-width="2" stroke-dasharray="4,4"/>
                <rect x="45" y="${y1}" width="80" height="${h1}" rx="8" fill="url(#gradExp3)"/>
                <rect x="150" y="${y2}" width="80" height="${h2}" rx="8" fill="url(#gradExp3)"/>
                <rect x="255" y="${y3}" width="80" height="${h3}" rx="8" fill="url(#gradExp3)"/>
                <rect x="360" y="${y4}" width="80" height="${h4}" rx="8" fill="url(#gradExp3)"/>
                <rect x="465" y="${y5}" width="80" height="${h5}" rx="8" fill="url(#gradExp3)"/>
                <text x="85" y="180" fill="#a1a1aa" font-size="13" text-anchor="middle" font-weight="bold">S1 (${d.agendamentosSemanas.S1})</text>
                <text x="190" y="180" fill="#a1a1aa" font-size="13" text-anchor="middle" font-weight="bold">S2 (${d.agendamentosSemanas.S2})</text>
                <text x="295" y="180" fill="#a1a1aa" font-size="13" text-anchor="middle" font-weight="bold">S3 (${d.agendamentosSemanas.S3})</text>
                <text x="400" y="180" fill="#a1a1aa" font-size="13" text-anchor="middle" font-weight="bold">S4 (${d.agendamentosSemanas.S4})</text>
                <text x="505" y="180" fill="#a1a1aa" font-size="13" text-anchor="middle" font-weight="bold">S5 (${d.agendamentosSemanas.S5})</text>
            </svg>`;
        }
    },
    faturamento_mes: {
        titulo: 'Faturamento • <span id="lblMesNomeFat"></span>',
        subtitulo: 'Evolução e receita acumulada por semana',
        destaqueId: 'valTotFatMes',
        getSvg: (d) => {
            const maxVal = Math.max(1.0, d.faturamentoSemanas.S1, d.faturamentoSemanas.S2, d.faturamentoSemanas.S3, d.faturamentoSemanas.S4, d.faturamentoSemanas.S5);
            const h1 = Math.max(10, Math.round((d.faturamentoSemanas.S1 / maxVal) * 110));
            const h2 = Math.max(10, Math.round((d.faturamentoSemanas.S2 / maxVal) * 110));
            const h3 = Math.max(10, Math.round((d.faturamentoSemanas.S3 / maxVal) * 110));
            const h4 = Math.max(10, Math.round((d.faturamentoSemanas.S4 / maxVal) * 110));
            const h5 = Math.max(10, Math.round((d.faturamentoSemanas.S5 / maxVal) * 110));
            const y1 = 145 - h1, y2 = 145 - h2, y3 = 145 - h3, y4 = 145 - h4, y5 = 145 - h5;
            return `<svg viewBox="0 0 600 200" class="w-full h-full overflow-visible">
                <defs><linearGradient id="gradExp4" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stop-color="#10b981" stop-opacity="0.95"/><stop offset="100%" stop-color="#047857" stop-opacity="0.5"/></linearGradient></defs>
                <line x1="30" y1="145" x2="570" y2="145" stroke="#3f3f46" stroke-width="2" stroke-dasharray="4,4"/>
                <rect x="45" y="${y1}" width="80" height="${h1}" rx="8" fill="url(#gradExp4)"/>
                <rect x="150" y="${y2}" width="80" height="${h2}" rx="8" fill="url(#gradExp4)"/>
                <rect x="255" y="${y3}" width="80" height="${h3}" rx="8" fill="url(#gradExp4)"/>
                <rect x="360" y="${y4}" width="80" height="${h4}" rx="8" fill="url(#gradExp4)"/>
                <rect x="465" y="${y5}" width="80" height="${h5}" rx="8" fill="url(#gradExp4)"/>
                <text x="85" y="180" fill="#a1a1aa" font-size="12" text-anchor="middle" font-weight="bold">S1 (R$ ${Number(d.faturamentoSemanas.S1).toFixed(0)})</text>
                <text x="190" y="180" fill="#a1a1aa" font-size="12" text-anchor="middle" font-weight="bold">S2 (R$ ${Number(d.faturamentoSemanas.S2).toFixed(0)})</text>
                <text x="295" y="180" fill="#a1a1aa" font-size="12" text-anchor="middle" font-weight="bold">S3 (R$ ${Number(d.faturamentoSemanas.S3).toFixed(0)})</text>
                <text x="400" y="180" fill="#a1a1aa" font-size="12" text-anchor="middle" font-weight="bold">S4 (R$ ${Number(d.faturamentoSemanas.S4).toFixed(0)})</text>
                <text x="505" y="180" fill="#a1a1aa" font-size="12" text-anchor="middle" font-weight="bold">S5 (R$ ${Number(d.faturamentoSemanas.S5).toFixed(0)})</text>
            </svg>`;
        }
    }
};

let cacheDadosGraficos = {
    agendamentosTurnos: <?= json_encode($agendamentosTurnos) ?>,
    faturamentoTurnos: <?= json_encode($faturamentoTurnos) ?>,
    agendamentosSemanas: <?= json_encode($agendamentosSemanas) ?>,
    faturamentoSemanas: <?= json_encode($faturamentoSemanas) ?>,
    totalAgendamentosDia: <?= $totalAgendamentosDiaGrafico ?>,
    totalFaturamentoDia: <?= $totalFaturamentoDiaGrafico ?>,
    totalAgendamentosMes: <?= $totalAgendamentosMesGrafico ?>,
    totalFaturamentoMes: <?= $totalFaturamentoMesGrafico ?>,
    diaSemanaNome: '<?= $diaSemanaNome ?>',
    mesNome: '<?= $mesNome ?>'
};

function abrirModalGraficoExpandido(tipo) {
    graficoAtivoAtual = tipo;
    document.getElementById('modalGraficoAtivoInput').value = tipo;
    
    const divData = document.getElementById('divFiltroData');
    const divMes = document.getElementById('divFiltroMes');
    
    if (tipo.includes('mes')) {
        if (divData) divData.style.display = 'none';
        if (divMes) divMes.style.display = 'block';
    } else {
        if (divData) divData.style.display = 'block';
        if (divMes) divMes.style.display = 'none';
    }

    renderizarModalAtual();
    document.getElementById('modalGraficoExpandido').classList.remove('hidden');
}

function renderizarModalAtual() {
    const config = dadosGraficosDashboard[graficoAtivoAtual];
    if (!config) return;

    document.getElementById('modalGraficoTitulo').innerHTML = '<i class="fa-solid fa-chart-column text-amber-500"></i> ' + config.titulo;
    document.getElementById('modalGraficoSubtitulo').textContent = config.subtitulo;
    
    let metricaTxt = '';
    if (graficoAtivoAtual === 'agendamentos_dia') metricaTxt = cacheDadosGraficos.totalAgendamentosDia + ' Agendamentos';
    else if (graficoAtivoAtual === 'faturamento_dia') metricaTxt = 'R$ ' + Number(cacheDadosGraficos.totalFaturamentoDia).toLocaleString('pt-BR', {minimumFractionDigits: 2});
    else if (graficoAtivoAtual === 'agendamentos_mes') metricaTxt = cacheDadosGraficos.totalAgendamentosMes + ' Agendamentos no Mês';
    else if (graficoAtivoAtual === 'faturamento_mes') metricaTxt = 'R$ ' + Number(cacheDadosGraficos.totalFaturamentoMes).toLocaleString('pt-BR', {minimumFractionDigits: 2});

    document.getElementById('modalGraficoMetricaDestaque').textContent = metricaTxt;
    document.getElementById('modalGraficoVisualContainer').innerHTML = config.getSvg(cacheDadosGraficos);

    setTimeout(() => {
        document.querySelectorAll('#lblDiaNome, #lblDiaNomeFat').forEach(el => el.textContent = cacheDadosGraficos.diaSemanaNome);
        document.querySelectorAll('#lblMesNome, #lblMesNomeFat').forEach(el => el.textContent = cacheDadosGraficos.mesNome);
    }, 50);
}

function fecharModalGraficoExpandido() {
    document.getElementById('modalGraficoExpandido').classList.add('hidden');
}

document.getElementById('formFiltroGrafico').addEventListener('submit', async function(e) {
    e.preventDefault();
    const formData = new FormData(this);
    const params = new URLSearchParams(formData);
    params.set('ajax_dados_grafico', '1');

    const inputMes = document.getElementById('modalFiltroMes');
    if (inputMes && inputMes.value) {
        params.set('mes_grafico', inputMes.value);
    }

    try {
        const resp = await fetch('index.php?' + params.toString());
        const res = await resp.json();

        if (res.sucesso) {
            cacheDadosGraficos.agendamentosTurnos = res.agendamentosTurnos;
            cacheDadosGraficos.faturamentoTurnos = res.faturamentoTurnos;
            cacheDadosGraficos.agendamentosSemanas = res.agendamentosSemanas;
            cacheDadosGraficos.faturamentoSemanas = res.faturamentoSemanas;
            cacheDadosGraficos.totalAgendamentosDia = res.totalAgendamentosDia;
            cacheDadosGraficos.totalFaturamentoDia = res.totalFaturamentoDia;
            cacheDadosGraficos.totalAgendamentosMes = res.totalAgendamentosMes;
            cacheDadosGraficos.totalFaturamentoMes = res.totalFaturamentoMes;
            cacheDadosGraficos.diaSemanaNome = res.diaSemanaNome;
            cacheDadosGraficos.mesNome = res.mesNome;

            document.getElementById('tabelaAnaliticaCorpo').innerHTML = res.tabelaHtml;
            renderizarModalAtual();
        }
    } catch (err) {
        console.error('Erro AJAX:', err);
    }
});
</script>

</main>
</body>
</html>
