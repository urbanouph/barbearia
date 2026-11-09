<?php
require_once '../config/database.php';
verificarAutenticacao();

function rc_h($valor) {
    return htmlspecialchars((string)$valor, ENT_QUOTES, 'UTF-8');
}
function rc_data($valor) {
    if (!$valor) return '—';
    $ts = strtotime($valor);
    return $ts ? date('d/m/Y', $ts) : '—';
}
function rc_dinheiro($valor) {
    return 'R$ ' . number_format((float)$valor, 2, ',', '.');
}
function rc_status($status) {
    $nomes = [
        'concluido' => 'Concluído',
        'confirmado' => 'Confirmado',
        'pendente' => 'Pendente',
        'cancelado' => 'Cancelado'
    ];
    return $nomes[$status] ?? ucfirst(str_replace('_', ' ', (string)$status));
}
function rc_data_valida($valor) {
    if ($valor === '') return true;
    $dt = DateTime::createFromFormat('!Y-m-d', $valor);
    return $dt && $dt->format('Y-m-d') === $valor;
}

$clienteId = filter_var($_GET['cliente_id'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($clienteId === false) $clienteId = null;
$dataInicio = trim($_GET['data_inicio'] ?? '');
$dataFim = trim($_GET['data_fim'] ?? '');
$erroFiltro = '';
if (!rc_data_valida($dataInicio) || !rc_data_valida($dataFim)) {
    $erroFiltro = 'Confira as datas do período informado.';
    $dataInicio = '';
    $dataFim = '';
}
if ($dataInicio !== '' && $dataFim !== '' && $dataInicio > $dataFim) {
    $erroFiltro = 'A data inicial não pode ser posterior à data final.';
    $dataInicio = '';
    $dataFim = '';
}

$clientes = $pdo->query("SELECT c.id, c.nome, c.telefone,
        COUNT(CASE WHEN a.status = 'concluido' THEN a.id END) AS total_visitas,
        MAX(CASE WHEN a.status = 'concluido' THEN a.data_agendamento END) AS ultimo_atendimento
    FROM clientes c
    LEFT JOIN agendamentos a ON a.cliente_id = c.id
    GROUP BY c.id, c.nome, c.telefone
    ORDER BY c.nome ASC")->fetchAll();

$cliente = null;
$resumo = ['visitas' => 0, 'cancelados' => 0, 'abertos' => 0, 'gasto' => 0];
$servicos = [];
$historico = [];
$intervaloMedio = null;
$diasDesdeUltimo = null;
$proximoAgendamento = null;

if ($clienteId !== null) {
    $stmtCliente = $pdo->prepare('SELECT id, nome, telefone FROM clientes WHERE id = ? LIMIT 1');
    $stmtCliente->execute([$clienteId]);
    $cliente = $stmtCliente->fetch() ?: null;

    if ($cliente) {
        $where = ['a.cliente_id = ?'];
        $params = [$clienteId];
        if ($dataInicio !== '') { $where[] = 'a.data_agendamento >= ?'; $params[] = $dataInicio; }
        if ($dataFim !== '') { $where[] = 'a.data_agendamento <= ?'; $params[] = $dataFim; }
        $whereSql = implode(' AND ', $where);

        $sqlResumo = "SELECT
                SUM(CASE WHEN a.status = 'concluido' THEN 1 ELSE 0 END) AS visitas,
                SUM(CASE WHEN a.status = 'cancelado' THEN 1 ELSE 0 END) AS cancelados,
                SUM(CASE WHEN a.status IN ('confirmado','pendente') THEN 1 ELSE 0 END) AS abertos,
                COALESCE(SUM(CASE WHEN a.status = 'concluido' THEN COALESCE(a.valor_total, s.preco, 0) ELSE 0 END), 0) AS gasto
            FROM agendamentos a
            LEFT JOIN servicos s ON s.id = a.servico_id
            WHERE $whereSql";
        $stmtResumo = $pdo->prepare($sqlResumo);
        $stmtResumo->execute($params);
        $rowResumo = $stmtResumo->fetch() ?: [];
        $resumo = [
            'visitas' => (int)($rowResumo['visitas'] ?? 0),
            'cancelados' => (int)($rowResumo['cancelados'] ?? 0),
            'abertos' => (int)($rowResumo['abertos'] ?? 0),
            'gasto' => (float)($rowResumo['gasto'] ?? 0)
        ];

        // Frequência por tipo de serviço: serviço principal e serviços extras da comanda.
        $sqlServicos = "SELECT historico_servicos.servico_nome,
                COUNT(*) AS usos,
                MAX(historico_servicos.data_agendamento) AS ultimo_uso
            FROM (
                SELECT s.nome AS servico_nome, a.data_agendamento
                FROM agendamentos a
                JOIN servicos s ON s.id = a.servico_id
                WHERE a.cliente_id = ? AND a.status = 'concluido'" .
                ($dataInicio !== '' ? ' AND a.data_agendamento >= ?' : '') .
                ($dataFim !== '' ? ' AND a.data_agendamento <= ?' : '') . "
                UNION ALL
                SELECT ai.nome_item AS servico_nome, a.data_agendamento
                FROM agendamento_itens ai
                JOIN agendamentos a ON a.id = ai.agendamento_id
                WHERE a.cliente_id = ? AND a.status = 'concluido' AND ai.tipo = 'servico'" .
                ($dataInicio !== '' ? ' AND a.data_agendamento >= ?' : '') .
                ($dataFim !== '' ? ' AND a.data_agendamento <= ?' : '') . "
            ) AS historico_servicos
            GROUP BY historico_servicos.servico_nome
            ORDER BY usos DESC, historico_servicos.servico_nome ASC";
        $paramsServicos = [$clienteId];
        if ($dataInicio !== '') $paramsServicos[] = $dataInicio;
        if ($dataFim !== '') $paramsServicos[] = $dataFim;
        $paramsServicos[] = $clienteId;
        if ($dataInicio !== '') $paramsServicos[] = $dataInicio;
        if ($dataFim !== '') $paramsServicos[] = $dataFim;
        $stmtServicos = $pdo->prepare($sqlServicos);
        $stmtServicos->execute($paramsServicos);
        $servicos = $stmtServicos->fetchAll();
        $totalUsosServicos = array_sum(array_map(function ($linha) { return (int)$linha['usos']; }, $servicos));

        $sqlHistorico = "SELECT a.id, a.data_agendamento, a.horario, a.status,
                a.valor_total, a.forma_pagamento, s.nome AS servico_nome, s.preco AS servico_preco,
                p.nome AS profissional_nome,
                extras.servicos_extras
            FROM agendamentos a
            LEFT JOIN servicos s ON s.id = a.servico_id
            LEFT JOIN profissionais p ON p.id = a.profissional_id
            LEFT JOIN (
                SELECT agendamento_id, GROUP_CONCAT(nome_item ORDER BY id SEPARATOR ', ') AS servicos_extras
                FROM agendamento_itens
                WHERE tipo = 'servico'
                GROUP BY agendamento_id
            ) extras ON extras.agendamento_id = a.id
            WHERE $whereSql
            ORDER BY a.data_agendamento DESC, a.horario DESC, a.id DESC";
        $stmtHistorico = $pdo->prepare($sqlHistorico);
        $stmtHistorico->execute($params);
        $historico = $stmtHistorico->fetchAll();

        $datasVisitas = [];
        foreach ($historico as $item) {
            if ($item['status'] === 'concluido') $datasVisitas[] = $item['data_agendamento'];
        }
        $datasOrdenadas = array_reverse($datasVisitas);
        if (count($datasOrdenadas) > 1) {
            $intervalos = [];
            for ($i = 1; $i < count($datasOrdenadas); $i++) {
                $dias = (int)((strtotime($datasOrdenadas[$i]) - strtotime($datasOrdenadas[$i - 1])) / 86400);
                if ($dias >= 0) $intervalos[] = $dias;
            }
            if ($intervalos) $intervaloMedio = array_sum($intervalos) / count($intervalos);
        }
        if ($datasVisitas) {
            $ultimaData = max($datasVisitas);
            $diasDesdeUltimo = max(0, (int)((strtotime(date('Y-m-d')) - strtotime($ultimaData)) / 86400));
        }

        $paramsProximo = [$clienteId, date('Y-m-d')];
        $sqlProximo = "SELECT a.data_agendamento, a.horario, s.nome AS servico_nome
            FROM agendamentos a LEFT JOIN servicos s ON s.id = a.servico_id
            WHERE a.cliente_id = ? AND a.status IN ('confirmado','pendente') AND a.data_agendamento >= ?";
        if ($dataInicio !== '') { $sqlProximo .= ' AND a.data_agendamento >= ?'; $paramsProximo[] = $dataInicio; }
        if ($dataFim !== '') { $sqlProximo .= ' AND a.data_agendamento <= ?'; $paramsProximo[] = $dataFim; }
        $sqlProximo .= ' ORDER BY a.data_agendamento ASC, a.horario ASC LIMIT 1';
        $stmtProximo = $pdo->prepare($sqlProximo);
        $stmtProximo->execute($paramsProximo);
        $proximoAgendamento = $stmtProximo->fetch() ?: null;
    }
}

require_once 'header.php';
?>

<div class="max-w-7xl mx-auto space-y-6">
    <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-3">
        <div>
            <h2 class="text-2xl font-bold text-white"><i class="fa-solid fa-chart-column text-amber-500 mr-2"></i>Relatório de Clientes</h2>
            <p class="text-sm text-zinc-400 mt-1">Histórico de serviços, frequência, atendimentos e valores por cliente.</p>
        </div>
        <a href="clientes.php" class="inline-flex items-center gap-2 text-sm text-zinc-200 bg-zinc-800 hover:bg-zinc-700 rounded-xl px-4 py-2.5"><i class="fa-solid fa-address-book text-amber-400"></i> Gerenciar clientes</a>
    </div>

    <?php if ($erroFiltro !== ''): ?>
        <div class="rounded-xl border border-red-500/30 bg-red-500/10 text-red-300 p-3 text-sm" role="alert"><?= rc_h($erroFiltro) ?></div>
    <?php endif; ?>

    <section class="rounded-2xl border border-zinc-800 bg-zinc-900 p-4 sm:p-5">
        <form method="GET" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 items-end">
            <div class="sm:col-span-2 lg:col-span-2">
                <label for="clienteId" class="block text-xs text-zinc-400 mb-1.5">Cliente</label>
                <select id="clienteId" name="cliente_id" required class="w-full bg-zinc-800 border border-zinc-700 rounded-xl p-3 text-sm text-white focus:outline-none focus:border-amber-500">
                    <option value="">Selecione um cliente</option>
                    <?php foreach ($clientes as $opcao): ?>
                        <option value="<?= (int)$opcao['id'] ?>" <?= $clienteId === (int)$opcao['id'] ? 'selected' : '' ?>><?= rc_h($opcao['nome']) ?><?= !empty($opcao['telefone']) ? ' — ' . rc_h($opcao['telefone']) : '' ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="dataInicio" class="block text-xs text-zinc-400 mb-1.5">De</label>
                <input id="dataInicio" type="date" name="data_inicio" value="<?= rc_h($dataInicio) ?>" class="w-full bg-zinc-800 border border-zinc-700 rounded-xl p-3 text-sm text-white focus:outline-none focus:border-amber-500">
            </div>
            <div>
                <label for="dataFim" class="block text-xs text-zinc-400 mb-1.5">Até</label>
                <input id="dataFim" type="date" name="data_fim" value="<?= rc_h($dataFim) ?>" class="w-full bg-zinc-800 border border-zinc-700 rounded-xl p-3 text-sm text-white focus:outline-none focus:border-amber-500">
            </div>
            <div class="sm:col-span-2 lg:col-span-4 flex flex-wrap gap-2">
                <button type="submit" class="rounded-xl bg-amber-500 hover:bg-amber-600 px-5 py-2.5 text-sm font-bold text-zinc-950"><i class="fa-solid fa-filter mr-1"></i> Gerar relatório</button>
                <?php if ($clienteId !== null): ?><a href="relatorio_clientes.php?cliente_id=<?= (int)$clienteId ?>" class="rounded-xl bg-zinc-800 hover:bg-zinc-700 px-4 py-2.5 text-sm text-zinc-200">Limpar período</a><?php endif; ?>
            </div>
        </form>
    </section>

    <?php if ($clienteId === null): ?>
        <section class="rounded-2xl border border-zinc-800 bg-zinc-900 overflow-hidden">
            <div class="p-5 border-b border-zinc-800">
                <h3 class="font-bold text-white">Clientes disponíveis</h3>
                <p class="text-xs text-zinc-500 mt-1">Selecione um cliente acima ou abra o histórico diretamente pela lista.</p>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[620px] text-left text-sm">
                    <thead><tr class="border-b border-zinc-800 text-xs uppercase text-zinc-500"><th class="p-4">Cliente</th><th class="p-4">Telefone</th><th class="p-4">Atendimentos concluídos</th><th class="p-4">Último atendimento</th><th class="p-4 text-right">Relatório</th></tr></thead>
                    <tbody class="divide-y divide-zinc-800">
                    <?php if (!$clientes): ?>
                        <tr><td colspan="5" class="p-8 text-center text-zinc-500">Ainda não há clientes cadastrados.</td></tr>
                    <?php else: foreach ($clientes as $linha): ?>
                        <tr class="hover:bg-zinc-800/40">
                            <td class="p-4 font-semibold text-white"><?= rc_h($linha['nome']) ?></td>
                            <td class="p-4 text-zinc-300"><?= rc_h($linha['telefone'] ?: '—') ?></td>
                            <td class="p-4 text-amber-400 font-bold"><?= (int)$linha['total_visitas'] ?></td>
                            <td class="p-4 text-zinc-400"><?= rc_data($linha['ultimo_atendimento']) ?></td>
                            <td class="p-4 text-right"><a href="relatorio_clientes.php?cliente_id=<?= (int)$linha['id'] ?>" class="inline-flex items-center gap-2 rounded-lg bg-amber-500/10 hover:bg-amber-500/20 px-3 py-2 text-xs font-bold text-amber-300"><i class="fa-solid fa-chart-line"></i> Ver histórico</a></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php elseif (!$cliente): ?>
        <div class="rounded-xl border border-amber-500/30 bg-amber-500/10 p-4 text-sm text-amber-200">Cliente não encontrado. Escolha um cadastro válido para gerar o relatório.</div>
    <?php else: ?>
        <section class="rounded-2xl border border-zinc-800 bg-zinc-900 p-5 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div>
                <p class="text-xs font-bold uppercase tracking-wider text-amber-400">Histórico do cliente</p>
                <h3 class="text-xl font-bold text-white mt-1"><?= rc_h($cliente['nome']) ?></h3>
                <p class="text-sm text-zinc-400 mt-1"><i class="fa-solid fa-phone mr-1"></i><?= rc_h($cliente['telefone'] ?: 'Telefone não cadastrado') ?></p>
            </div>
            <div class="rounded-xl bg-zinc-800/80 p-4 min-w-[220px]">
                <p class="text-xs text-zinc-400">Próximo agendamento</p>
                <?php if ($proximoAgendamento): ?>
                    <p class="text-sm font-bold text-white mt-1"><?= rc_data($proximoAgendamento['data_agendamento']) ?> às <?= rc_h(substr((string)$proximoAgendamento['horario'], 0, 5)) ?></p>
                    <p class="text-xs text-amber-300 mt-1"><?= rc_h($proximoAgendamento['servico_nome'] ?? 'Serviço') ?></p>
                <?php else: ?><p class="text-sm text-zinc-500 mt-1">Nenhum agendamento futuro<?= ($dataInicio !== '' || $dataFim !== '') ? ' no período' : '' ?>.</p><?php endif; ?>
            </div>
        </section>

        <section class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-3">
            <div class="rounded-2xl border border-zinc-800 bg-zinc-900 p-5"><p class="text-xs uppercase tracking-wider text-zinc-500">Atendimentos concluídos</p><p class="text-2xl font-extrabold text-white mt-2"><?= $resumo['visitas'] ?></p><p class="text-xs text-zinc-500 mt-1">No período selecionado</p></div>
            <div class="rounded-2xl border border-zinc-800 bg-zinc-900 p-5"><p class="text-xs uppercase tracking-wider text-zinc-500">Valor em atendimentos</p><p class="text-2xl font-extrabold text-emerald-400 mt-2"><?= rc_dinheiro($resumo['gasto']) ?></p><p class="text-xs text-zinc-500 mt-1">Comandas concluídas</p></div>
            <div class="rounded-2xl border border-zinc-800 bg-zinc-900 p-5"><p class="text-xs uppercase tracking-wider text-zinc-500">Intervalo médio</p><p class="text-2xl font-extrabold text-amber-400 mt-2"><?= $intervaloMedio === null ? '—' : number_format($intervaloMedio, 0, ',', '.') . ' dias' ?></p><p class="text-xs text-zinc-500 mt-1">Entre atendimentos concluídos</p></div>
            <div class="rounded-2xl border border-zinc-800 bg-zinc-900 p-5"><p class="text-xs uppercase tracking-wider text-zinc-500">Último atendimento</p><p class="text-2xl font-extrabold text-white mt-2"><?= rc_data($ultimaData) ?></p><p class="text-xs text-zinc-500 mt-1"><?= $diasDesdeUltimo === null ? 'Nenhum concluído no período' : ($diasDesdeUltimo === 0 ? 'Hoje' : $diasDesdeUltimo . ' dia(s) atrás') ?></p></div>
        </section>

        <section class="rounded-2xl border border-zinc-800 bg-zinc-900 overflow-hidden">
            <div class="p-5 border-b border-zinc-800">
                <h3 class="font-bold text-white"><i class="fa-solid fa-scissors text-amber-500 mr-2"></i>Tipos de serviços e frequência</h3>
                <p class="text-xs text-zinc-500 mt-1">Inclui o serviço principal e serviços extras lançados na comanda; considera apenas atendimentos concluídos. A participação é sobre os usos registrados neste período.</p>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[650px] text-left text-sm">
                    <thead><tr class="border-b border-zinc-800 text-xs uppercase text-zinc-500"><th class="p-4">Serviço</th><th class="p-4">Usos</th><th class="p-4">Participação</th><th class="p-4">Uso mais recente</th></tr></thead>
                    <tbody class="divide-y divide-zinc-800">
                    <?php if (!$servicos): ?>
                        <tr><td colspan="4" class="p-8 text-center text-zinc-500">Não há serviços concluídos neste período.</td></tr>
                    <?php else: foreach ($servicos as $itemServico): $pct = $totalUsosServicos > 0 ? ((int)$itemServico['usos'] / $totalUsosServicos) * 100 : 0; ?>
                        <tr>
                            <td class="p-4 font-semibold text-zinc-100"><?= rc_h($itemServico['servico_nome']) ?></td>
                            <td class="p-4 text-amber-300 font-bold"><?= (int)$itemServico['usos'] ?></td>
                            <td class="p-4"><div class="flex items-center gap-2"><div class="h-2 w-28 rounded-full bg-zinc-800 overflow-hidden"><div class="h-full rounded-full bg-amber-500" style="width: <?= number_format($pct, 1, '.', '') ?>%"></div></div><span class="text-xs text-zinc-400"><?= number_format($pct, 0, ',', '.') ?>%</span></div></td>
                            <td class="p-4 text-zinc-400"><?= rc_data($itemServico['ultimo_uso']) ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="rounded-2xl border border-zinc-800 bg-zinc-900 overflow-hidden">
            <div class="p-5 border-b border-zinc-800 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
                <div><h3 class="font-bold text-white">Histórico detalhado</h3><p class="text-xs text-zinc-500 mt-1"><?= count($historico) ?> agendamento(s) no período, incluindo cancelados e pendentes.</p></div>
                <div class="flex gap-4 text-xs"><span class="text-amber-300">Pendentes: <?= $resumo['abertos'] ?></span><span class="text-red-300">Cancelados: <?= $resumo['cancelados'] ?></span></div>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[900px] text-left text-sm">
                    <thead><tr class="border-b border-zinc-800 text-xs uppercase text-zinc-500"><th class="p-4">Data</th><th class="p-4">Serviço</th><th class="p-4">Extras</th><th class="p-4">Profissional</th><th class="p-4">Status</th><th class="p-4">Pagamento</th><th class="p-4 text-right">Valor</th></tr></thead>
                    <tbody class="divide-y divide-zinc-800">
                    <?php if (!$historico): ?>
                        <tr><td colspan="7" class="p-8 text-center text-zinc-500">Nenhum agendamento encontrado para este cliente.</td></tr>
                    <?php else: foreach ($historico as $item):
                        $statusClasse = $item['status'] === 'concluido' ? 'text-emerald-300 bg-emerald-500/10' : ($item['status'] === 'cancelado' ? 'text-red-300 bg-red-500/10' : 'text-amber-300 bg-amber-500/10');
                        $valor = $item['valor_total'] !== null ? $item['valor_total'] : $item['servico_preco'];
                    ?>
                        <tr class="hover:bg-zinc-800/30">
                            <td class="p-4 whitespace-nowrap text-zinc-300"><?= rc_data($item['data_agendamento']) ?><?php if (!empty($item['horario'])): ?> <span class="text-zinc-500">às <?= rc_h(substr((string)$item['horario'], 0, 5)) ?></span><?php endif; ?></td>
                            <td class="p-4 font-semibold text-zinc-100"><?= rc_h($item['servico_nome'] ?? 'Serviço indisponível') ?></td>
                            <td class="p-4 text-zinc-400"><?= rc_h($item['servicos_extras'] ?: '—') ?></td>
                            <td class="p-4 text-zinc-300"><?= rc_h($item['profissional_nome'] ?: '—') ?></td>
                            <td class="p-4"><span class="rounded-full px-2.5 py-1 text-xs font-semibold <?= $statusClasse ?>"><?= rc_h(rc_status($item['status'])) ?></span></td>
                            <td class="p-4 text-zinc-400"><?= rc_h($item['forma_pagamento'] ?: '—') ?></td>
                            <td class="p-4 text-right font-semibold text-zinc-200"><?= $item['status'] === 'concluido' ? rc_dinheiro($valor) : '—' ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endif; ?>
</div>

</main>
</body>
</html>
