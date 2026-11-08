<?php
require_once '../config/database.php';
verificarAutenticacao();

// Mês e Ano selecionados para o filtro (padrão: mês atual)
$mesSelecionado = $_GET['mes'] ?? date('Y-m');
$profissionalFiltro = !empty($_GET['profissional_id']) ? (int)$_GET['profissional_id'] : null;

// Lista de meses disponíveis nos agendamentos
$mesesDisponiveis = $pdo->query("
    SELECT DISTINCT DATE_FORMAT(data_agendamento, '%Y-%m') as mes_ano 
    FROM agendamentos 
    ORDER BY mes_ano DESC
")->fetchAll(PDO::FETCH_COLUMN);

if (!in_array(date('Y-m'), $mesesDisponiveis)) {
    array_unshift($mesesDisponiveis, date('Y-m'));
    $mesesDisponiveis = array_unique($mesesDisponiveis);
}

// Lista de profissionais ativos
$profissionais = $pdo->query("SELECT * FROM profissionais ORDER BY nome ASC")->fetchAll();

// 1. Total faturado em Serviços/Cortes concluídos no mês
$sqlServMes = "
    SELECT COALESCE(SUM(s.preco), 0)
    FROM agendamentos a
    JOIN servicos s ON a.servico_id = s.id
    WHERE a.status = 'concluido'
      AND DATE_FORMAT(a.data_agendamento, '%Y-%m') = ?
";
$stmtServMes = $pdo->prepare($sqlServMes);
$stmtServMes->execute([$mesSelecionado]);
$faturamentoServicosMes = (float)$stmtServMes->fetchColumn();

// 2. Total faturado em Vendas de Produtos / Balcão (PDV) no mês
$sqlProdMes = "
    SELECT COALESCE(SUM(total), 0)
    FROM vendas_produtos
    WHERE DATE_FORMAT(data_venda, '%Y-%m') = ?
";
$stmtProdMes = $pdo->prepare($sqlProdMes);
$stmtProdMes->execute([$mesSelecionado]);
$faturamentoProdutosMes = (float)$stmtProdMes->fetchColumn();

// Faturamento Bruto Total
$faturamentoTotalMes = $faturamentoServicosMes + $faturamentoProdutosMes;

// 2.1. Comissões sobre Produtos Vendidos pelos Profissionais no Mês
$sqlComissoesProd = "
    SELECT 
        v.profissional_id,
        COALESCE(SUM((iv.subtotal * COALESCE(pr.comissao_percentual, 10.00)) / 100), 0) as total_comissao_prod,
        COALESCE(SUM(iv.subtotal), 0) as total_vendas_prod_prof
    FROM vendas_produtos v
    JOIN itens_venda_produtos iv ON v.id = iv.venda_id
    JOIN produtos pr ON iv.produto_id = pr.id
    WHERE v.profissional_id IS NOT NULL 
      AND DATE_FORMAT(v.data_venda, '%Y-%m') = ?
    GROUP BY v.profissional_id
";
$stmtComissoesProd = $pdo->prepare($sqlComissoesProd);
$stmtComissoesProd->execute([$mesSelecionado]);
$dadosComissoesProdutos = [];
while ($row = $stmtComissoesProd->fetch()) {
    $dadosComissoesProdutos[$row['profissional_id']] = [
        'comissao' => (float)$row['total_comissao_prod'],
        'vendas'   => (float)$row['total_vendas_prod_prof']
    ];
}

// 3. Relatório Detalhado de Comissões por Profissional no Mês (Cortes + Produtos)
$sqlComissoes = "
    SELECT 
        p.id as profissional_id,
        p.nome as profissional_nome,
        p.especialidade,
        COUNT(a.id) as total_atendimentos,
        COALESCE(SUM(s.preco), 0) as faturamento_bruto_servicos,
        COALESCE(SUM((s.preco * COALESCE(ps.comissao_percentual, s.comissao_percentual, 50.00)) / 100), 0) as comissao_cortes,
        COALESCE(SUM(s.preco - ((s.preco * COALESCE(ps.comissao_percentual, s.comissao_percentual, 50.00)) / 100)), 0) as retencao_cortes
    FROM profissionais p
    LEFT JOIN agendamentos a ON a.profissional_id = p.id 
        AND a.status = 'concluido' 
        AND DATE_FORMAT(a.data_agendamento, '%Y-%m') = ?
    LEFT JOIN servicos s ON a.servico_id = s.id
    LEFT JOIN profissional_servicos ps ON ps.profissional_id = p.id AND ps.servico_id = s.id
    GROUP BY p.id, p.nome, p.especialidade
    ORDER BY comissao_cortes DESC
";
$stmtComissoes = $pdo->prepare($sqlComissoes);
$stmtComissoes->execute([$mesSelecionado]);
$relatorioComissoes = $stmtComissoes->fetchAll();

// Totais somados das comissões
$totalComissoesEquipa = 0;
$totalRetencaoCortes = 0;
$totalComissoesProdutosEquipa = 0;

foreach ($relatorioComissoes as &$rc) {
    $pId = $rc['profissional_id'];
    $comissaoProdProf = $dadosComissoesProdutos[$pId]['comissao'] ?? 0;
    $vendasProdProf   = $dadosComissoesProdutos[$pId]['vendas'] ?? 0;

    $rc['comissao_produtos'] = $comissaoProdProf;
    $rc['vendas_produtos']   = $vendasProdProf;
    $rc['total_comissao_pagar'] = (float)$rc['comissao_cortes'] + $comissaoProdProf;

    $totalComissoesEquipa += $rc['total_comissao_pagar'];
    $totalRetencaoCortes += (float)$rc['retencao_cortes'];
    $totalComissoesProdutosEquipa += $comissaoProdProf;
}
unset($rc);

// 2.2. Ganhos Extras e Despesas Lançadas no Mês Selecionado
$stMovFin = $pdo->prepare("
    SELECT 
        COALESCE(SUM(CASE WHEN tipo = 'receita' THEN valor ELSE 0 END), 0) as total_ganhos_extras,
        COALESCE(SUM(CASE WHEN tipo = 'despesa' THEN valor ELSE 0 END), 0) as total_despesas_mes
    FROM movimentacoes_financeiras 
    WHERE DATE_FORMAT(data_movimentacao, '%Y-%m') = ?
");
$stMovFin->execute([$mesSelecionado]);
$totaisMovFin = $stMovFin->fetch();

$totalGanhosExtrasMes = (float)$totaisMovFin['total_ganhos_extras'];
$totalDespesasMes = (float)$totaisMovFin['total_despesas_mes'];

// Buscar extrato de despesas e ganhos do mês para exibir na aba do Financeiro
$stListaMovMes = $pdo->prepare("
    SELECT * FROM movimentacoes_financeiras 
    WHERE DATE_FORMAT(data_movimentacao, '%Y-%m') = ?
    ORDER BY data_movimentacao DESC, id DESC
");
$stListaMovMes->execute([$mesSelecionado]);
$movimentacoesFinanceirasMes = $stListaMovMes->fetchAll();

// Lucro Líquido Real da Barbearia = Retenção Cortes + Lucro Produtos + Ganhos Extras - Despesas
$lucroLiquidoTotalBarbearia = ($totalRetencaoCortes + ($faturamentoProdutosMes - $totalComissoesProdutosEquipa) + $totalGanhosExtrasMes) - $totalDespesasMes;

// 3.1. Consolidação de Produtos Vendidos no Mês Selecionado
$sqlProdConsolidado = "
    SELECT 
        pr.nome as produto_nome,
        pr.preco_custo,
        SUM(iv.quantidade) as qtd_total_vendida,
        SUM(iv.subtotal) as total_faturado_produto,
        SUM(iv.subtotal - (pr.preco_custo * iv.quantidade)) as lucro_estimado_produto
    FROM itens_venda_produtos iv
    JOIN vendas_produtos v ON iv.venda_id = v.id
    JOIN produtos pr ON iv.produto_id = pr.id
    WHERE DATE_FORMAT(v.data_venda, '%Y-%m') = ?
    GROUP BY pr.id, pr.nome, pr.preco_custo
    ORDER BY qtd_total_vendida DESC
";
$stmtProdConsolidado = $pdo->prepare($sqlProdConsolidado);
$stmtProdConsolidado->execute([$mesSelecionado]);
$produtosVendidosConsolidado = $stmtProdConsolidado->fetchAll();

// 3.2. Extrato Detalhado de Vendas de Produtos no Mês
$sqlVendasProdDetalhado = "
    SELECT 
        v.id,
        v.data_venda,
        c.nome as cliente_nome,
        p.nome as prof_nome,
        pr.nome as produto_nome,
        iv.quantidade,
        iv.preco_unitario,
        iv.subtotal,
        v.forma_pagamento
    FROM vendas_produtos v
    JOIN itens_venda_produtos iv ON v.id = iv.venda_id
    JOIN produtos pr ON iv.produto_id = pr.id
    LEFT JOIN clientes c ON v.cliente_id = c.id
    LEFT JOIN profissionais p ON v.profissional_id = p.id
    WHERE DATE_FORMAT(v.data_venda, '%Y-%m') = ?
    ORDER BY v.data_venda DESC
";
$stmtVendasProdDetalhado = $pdo->prepare($sqlVendasProdDetalhado);
$stmtVendasProdDetalhado->execute([$mesSelecionado]);
$extratoVendasProdutos = $stmtVendasProdDetalhado->fetchAll();

// 4. Histórico detalhado dos atendimentos do mês (extrato individual)
$sqlExtrato = "
    SELECT 
        a.id,
        a.data_agendamento,
        a.horario,
        a.cliente_nome,
        s.nome as servico_nome,
        s.preco,
        COALESCE(ps.comissao_percentual, s.comissao_percentual, 50.00) as comissao_percentual,
        ((s.preco * COALESCE(ps.comissao_percentual, s.comissao_percentual, 50.00)) / 100) as valor_comissao,
        (s.preco - ((s.preco * COALESCE(ps.comissao_percentual, s.comissao_percentual, 50.00)) / 100)) as valor_casa,
        p.nome as prof_nome,
        a.forma_pagamento
    FROM agendamentos a
    JOIN servicos s ON a.servico_id = s.id
    LEFT JOIN profissionais p ON a.profissional_id = p.id
    LEFT JOIN profissional_servicos ps ON ps.profissional_id = p.id AND ps.servico_id = s.id
    WHERE a.status = 'concluido'
      AND DATE_FORMAT(a.data_agendamento, '%Y-%m') = ?
";
$paramsExtrato = [$mesSelecionado];

if ($profissionalFiltro) {
    $sqlExtrato .= " AND a.profissional_id = ?";
    $paramsExtrato[] = $profissionalFiltro;
}

$sqlExtrato .= " ORDER BY a.data_agendamento DESC, a.horario DESC";
$stmtExtrato = $pdo->prepare($sqlExtrato);
$stmtExtrato->execute($paramsExtrato);
$extratoDetalhado = $stmtExtrato->fetchAll();

require_once 'header.php';
?>

<div class="max-w-6xl mx-auto space-y-6">
    <!-- CABEÇALHO & FILTRO DO MÊS -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <h2 class="text-2xl font-bold flex items-center gap-2">
                <i class="fa-solid fa-wallet text-amber-500"></i> Painel Financeiro & Comissões
            </h2>
            <p class="text-zinc-400 text-sm">Fechamento do mês de <?= date('m/Y', strtotime($mesSelecionado . '-01')) ?> com separação de serviços, produtos e comissões.</p>
        </div>

        <form method="GET" class="flex items-center gap-2">
            <label class="text-xs text-zinc-400 font-semibold whitespace-nowrap">Mês de Referência:</label>
            <input type="month" name="mes" value="<?= htmlspecialchars($mesSelecionado) ?>" onchange="this.form.submit()" 
                   class="bg-zinc-900 border border-zinc-700 text-white text-xs rounded-xl p-2.5 focus:outline-none focus:border-amber-500 font-bold">
        </form>
    </div>

    <!-- CARDS DE RESUMO FINANCEIRO TRANSPARENTE -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <!-- 1. FATURAMENTO TOTAL BRUTO -->
        <div class="bg-zinc-900 border border-zinc-800 p-5 rounded-2xl flex flex-col justify-between shadow">
            <div>
                <span class="text-[11px] text-zinc-400 uppercase tracking-wider block mb-1">Faturamento Total (Bruto)</span>
                <div class="text-2xl font-extrabold text-white">R$ <?= number_format($faturamentoTotalMes + $totalGanhosExtrasMes, 2, ',', '.') ?></div>
            </div>
            <div class="text-[11px] text-zinc-400 border-t border-zinc-800 pt-2 mt-3 space-y-0.5">
                <div class="flex justify-between">
                    <span>✂️ Cortes & Serviços:</span>
                    <strong class="text-zinc-200">R$ <?= number_format($faturamentoServicosMes, 2, ',', '.') ?></strong>
                </div>
                <div class="flex justify-between">
                    <span>🛍️ Vendas de Produtos:</span>
                    <strong class="text-zinc-200">R$ <?= number_format($faturamentoProdutosMes, 2, ',', '.') ?></strong>
                </div>
                <?php if ($totalGanhosExtrasMes > 0): ?>
                <div class="flex justify-between text-emerald-400">
                    <span>🟢 Ganhos Extras:</span>
                    <strong>+ R$ <?= number_format($totalGanhosExtrasMes, 2, ',', '.') ?></strong>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- 2. DESPESAS E CUSTOS FIXOS -->
        <div class="bg-zinc-900 border border-zinc-800 p-5 rounded-2xl flex flex-col justify-between shadow">
            <div>
                <div class="flex items-center justify-between">
                    <span class="text-[11px] text-red-400 uppercase tracking-wider block mb-1">Despesas & Custos (Mês)</span>
                    <a href="despesas_ganhos.php" class="text-[10px] text-amber-400 hover:underline font-bold">+ Lançar</a>
                </div>
                <div class="text-2xl font-extrabold text-red-400">- R$ <?= number_format($totalDespesasMes, 2, ',', '.') ?></div>
            </div>
            <div class="text-[11px] text-zinc-400 border-t border-zinc-800 pt-2 mt-3 space-y-0.5">
                <div class="flex justify-between">
                    <span>Contas, insumos & aluguel:</span>
                    <strong class="text-red-400 font-semibold"><?= count($movimentacoesFinanceirasMes) ?> lançamentos</strong>
                </div>
                <div class="flex justify-between">
                    <span>Ganhos extras avulsos:</span>
                    <strong class="text-emerald-400">+ R$ <?= number_format($totalGanhosExtrasMes, 2, ',', '.') ?></strong>
                </div>
            </div>
        </div>

        <!-- 3. TOTAL COMISSÕES A PAGAR -->
        <div class="bg-zinc-900 border border-zinc-800 p-5 rounded-2xl flex flex-col justify-between shadow">
            <div>
                <span class="text-[11px] text-amber-400 uppercase tracking-wider block mb-1">Comissões da Equipe</span>
                <div class="text-2xl font-extrabold text-amber-400">R$ <?= number_format($totalComissoesEquipa, 2, ',', '.') ?></div>
            </div>
            <div class="text-[11px] text-zinc-400 border-t border-zinc-800 pt-2 mt-3 space-y-0.5">
                <div class="flex justify-between">
                    <span>✂️ Em Cortes:</span>
                    <strong class="text-zinc-200">R$ <?= number_format($totalComissoesEquipa - $totalComissoesProdutosEquipa, 2, ',', '.') ?></strong>
                </div>
                <div class="flex justify-between">
                    <span>🛍️ Em Produtos:</span>
                    <strong class="text-zinc-200">R$ <?= number_format($totalComissoesProdutosEquipa, 2, ',', '.') ?></strong>
                </div>
            </div>
        </div>

        <!-- 4. LUCRO LÍQUIDO REAL DA BARBEARIA -->
        <div class="bg-zinc-900 border border-zinc-800 p-5 rounded-2xl flex flex-col justify-between shadow border-l-4 border-l-emerald-500">
            <div>
                <span class="text-[11px] text-emerald-400 uppercase tracking-wider block mb-1 font-bold">Lucro Líquido Real</span>
                <div class="text-2xl font-extrabold <?= $lucroLiquidoTotalBarbearia >= 0 ? 'text-emerald-400' : 'text-red-400' ?>">
                    R$ <?= number_format($lucroLiquidoTotalBarbearia, 2, ',', '.') ?>
                </div>
            </div>
            <div class="text-[11px] text-zinc-400 border-t border-zinc-800 pt-2 mt-3 space-y-0.5">
                <div class="flex justify-between">
                    <span>Receitas líquidas:</span>
                    <strong class="text-zinc-200">R$ <?= number_format($totalRetencaoCortes + ($faturamentoProdutosMes - $totalComissoesProdutosEquipa) + $totalGanhosExtrasMes, 2, ',', '.') ?></strong>
                </div>
                <div class="flex justify-between">
                    <span>Menos Despesas:</span>
                    <strong class="text-red-400">- R$ <?= number_format($totalDespesasMes, 2, ',', '.') ?></strong>
                </div>
            </div>
        </div>
    </div>

    <!-- TABELA 1: QUANTO PAGAR A CADA PROFISSIONAL NO MÊS -->
    <div class="bg-zinc-900 border border-zinc-800 rounded-2xl overflow-hidden">
        <div class="p-4 sm:p-5 border-b border-zinc-800 flex justify-between items-center">
            <div>
                <h3 class="font-bold text-white text-base">Relatório de Repasse de Comissões</h3>
                <p class="text-xs text-zinc-400">Demonstrativo por profissional para pagamento no mês de <?= date('m/Y', strtotime($mesSelecionado . '-01')) ?></p>
            </div>
            <button onclick="window.print()" class="text-xs bg-zinc-800 hover:bg-zinc-700 text-zinc-300 border border-zinc-700 px-3 py-1.5 rounded-lg transition flex items-center gap-1.5">
                <i class="fa-solid fa-print"></i> Imprimir Folha
            </button>
        </div>

        <div class="overflow-x-auto overflow-y-hidden w-full scroll-suave">
            <table class="w-full text-left border-collapse min-w-[700px]">
                <thead>
                    <tr class="border-b border-zinc-800 text-zinc-400 text-xs uppercase tracking-wider">
                        <th class="p-3.5">Profissional</th>
                        <th class="p-3.5 text-center">Atendimentos</th>
                        <th class="p-3.5">Comissão Cortes</th>
                        <th class="p-3.5">Comissão Produtos</th>
                        <th class="p-3.5 font-bold text-amber-400">Total a Pagar</th>
                        <th class="p-3.5 text-emerald-400">Retenção Barbearia</th>
                        <th class="p-3.5 text-right">Extrato</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-800 text-xs">
                    <?php if (empty($relatorioComissoes)): ?>
                        <tr><td colspan="6" class="p-6 text-center text-zinc-500">Nenhum profissional cadastrado.</td></tr>
                    <?php endif; ?>

                    <?php foreach ($relatorioComissoes as $r): ?>
                    <tr class="hover:bg-zinc-800/30 transition">
                        <td class="p-3.5">
                            <span class="font-bold text-white text-sm flex items-center gap-2">
                                <i class="fa-solid fa-user-tie text-amber-500"></i>
                                <?= htmlspecialchars($r['profissional_nome']) ?>
                            </span>
                            <span class="text-[11px] text-zinc-500"><?= htmlspecialchars($r['especialidade']) ?></span>
                        </td>
                        <td class="p-3.5 text-center font-bold text-zinc-300 text-sm">
                            <?= $r['total_atendimentos'] ?>
                        </td>
                        <td class="p-3.5 text-zinc-300 font-semibold">
                            R$ <?= number_format($r['comissao_cortes'], 2, ',', '.') ?>
                            <span class="text-[10px] text-zinc-500 block">De R$ <?= number_format($r['faturamento_bruto_servicos'], 2, ',', '.') ?></span>
                        </td>
                        <td class="p-3.5 text-zinc-300 font-semibold">
                            R$ <?= number_format($r['comissao_produtos'], 2, ',', '.') ?>
                            <span class="text-[10px] text-zinc-500 block">Vendeu R$ <?= number_format($r['vendas_produtos'], 2, ',', '.') ?></span>
                        </td>
                        <td class="p-3.5 font-extrabold text-amber-400 text-sm">
                            R$ <?= number_format($r['total_comissao_pagar'], 2, ',', '.') ?>
                        </td>
                        <td class="p-3.5 font-bold text-emerald-400">
                            R$ <?= number_format($r['retencao_cortes'], 2, ',', '.') ?>
                        </td>
                        <td class="p-3.5 text-right">
                            <a href="financeiro.php?mes=<?= $mesSelecionado ?>&profissional_id=<?= $r['profissional_id'] ?>" 
                               class="text-[11px] bg-zinc-800 hover:bg-amber-500 hover:text-zinc-950 text-zinc-300 border border-zinc-700 px-2.5 py-1 rounded-lg transition font-medium">
                                Ver Cortes
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- TABELA 2: EXTRATO CORTE A CORTE DO MÊS -->
    <div class="bg-zinc-900 border border-zinc-800 rounded-2xl overflow-hidden">
        <div class="p-4 sm:p-5 border-b border-zinc-800 flex justify-between items-center">
            <div>
                <h3 class="font-bold text-white text-base">Extrato Individual de Atendimentos</h3>
                <span class="text-xs text-zinc-400">
                    <?= $profissionalFiltro ? 'A filtrar apenas o profissional selecionado' : 'Todos os cortes concluídos no mês' ?>
                </span>
            </div>
            <?php if ($profissionalFiltro): ?>
                <a href="financeiro.php?mes=<?= $mesSelecionado ?>" class="text-xs text-amber-400 hover:underline">
                    Ver Todos os Barbeiros
                </a>
            <?php endif; ?>
        </div>

        <div class="overflow-x-auto overflow-y-hidden w-full scroll-suave">
            <table class="w-full text-left border-collapse min-w-[750px]">
                <thead>
                    <tr class="border-b border-zinc-800 text-zinc-400 text-xs uppercase tracking-wider">
                        <th class="p-3.5">Data</th>
                        <th class="p-3.5">Profissional</th>
                        <th class="p-3.5">Cliente</th>
                        <th class="p-3.5">Serviço Realizado</th>
                        <th class="p-3.5">Valor Corte</th>
                        <th class="p-3.5 text-center">% Comissão</th>
                        <th class="p-3.5 font-bold text-amber-400">Comissão Barbeiro</th>
                        <th class="p-3.5 font-bold text-emerald-400 text-right">Fica com a Barbearia</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-800 text-xs">
                    <?php if (empty($extratoDetalhado)): ?>
                        <tr><td colspan="8" class="p-6 text-center text-zinc-500">Nenhum atendimento concluído encontrado para este mês.</td></tr>
                    <?php endif; ?>

                    <?php foreach ($extratoDetalhado as $ex): ?>
                    <tr class="hover:bg-zinc-800/30 transition">
                        <td class="p-3.5 text-zinc-300">
                            <?= date('d/m/Y', strtotime($ex['data_agendamento'])) ?><br>
                            <span class="text-[10px] text-zinc-500"><?= substr($ex['horario'], 0, 5) ?></span>
                        </td>
                        <td class="p-3.5 font-semibold text-white">
                            <?= htmlspecialchars($ex['prof_nome'] ?? 'Equipa') ?>
                        </td>
                        <td class="p-3.5 text-zinc-300">
                            <?= htmlspecialchars($ex['cliente_nome']) ?>
                        </td>
                        <td class="p-3.5 text-zinc-200">
                            <?= htmlspecialchars($ex['servico_nome']) ?>
                        </td>
                        <td class="p-3.5 font-medium text-zinc-300">
                            R$ <?= number_format($ex['preco'], 2, ',', '.') ?>
                        </td>
                        <td class="p-3.5 text-center">
                            <span class="bg-zinc-800 text-zinc-300 border border-zinc-700 px-2 py-0.5 rounded text-[10px] font-bold">
                                <?= number_format($ex['comissao_percentual'], 1, ',', '.') ?>%
                            </span>
                        </td>
                        <td class="p-3.5 font-bold text-amber-400 text-sm">
                            R$ <?= number_format($ex['valor_comissao'], 2, ',', '.') ?>
                        </td>
                        <td class="p-3.5 font-bold text-emerald-400 text-right text-sm">
                            R$ <?= number_format($ex['valor_casa'], 2, ',', '.') ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- TABELA 3: LISTA DE PRODUTOS VENDIDOS NO MÊS -->
    <div class="bg-zinc-900 border border-zinc-800 rounded-2xl overflow-hidden space-y-4">
        <div class="p-4 sm:p-5 border-b border-zinc-800 flex flex-col sm:flex-row justify-between items-start sm:items-center gap-2">
            <div>
                <h3 class="font-bold text-white text-base flex items-center gap-2">
                    <i class="fa-solid fa-boxes-stacked text-amber-500"></i> Produtos Vendidos no Mês (PDV)
                </h3>
                <p class="text-xs text-zinc-400">Total de saídas de mercadorias registadas em <?= date('m/Y', strtotime($mesSelecionado . '-01')) ?></p>
            </div>
            <div class="bg-zinc-800/80 px-3 py-1.5 rounded-xl border border-zinc-700/60 text-xs">
                <span class="text-zinc-400">Receita em Produtos:</span>
                <strong class="text-emerald-400 ml-1">R$ <?= number_format($faturamentoProdutosMes, 2, ',', '.') ?></strong>
            </div>
        </div>

        <!-- Resumo Consolidado por Produto -->
        <div class="p-4 sm:p-5 pt-0">
            <span class="text-xs font-semibold uppercase tracking-wider text-zinc-400 block mb-2.5">
                Consolidado por Artigo
            </span>
            <div class="overflow-x-auto overflow-y-hidden w-full scroll-suave">
                <table class="w-full text-left border-collapse min-w-[650px]">
                    <thead>
                        <tr class="border-b border-zinc-800 text-zinc-400 text-xs uppercase tracking-wider">
                            <th class="p-3">Artigo / Produto</th>
                            <th class="p-3 text-center">Unidades Vendidas</th>
                            <th class="p-3">Preço Custo Médio</th>
                            <th class="p-3">Total Faturado</th>
                            <th class="p-3 text-emerald-400 text-right">Margem / Lucro Estimado</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-800 text-xs">
                        <?php if (empty($produtosVendidosConsolidado)): ?>
                            <tr>
                                <td colspan="5" class="p-5 text-center text-zinc-500">
                                    Nenhum produto vendido no mês selecionado.
                                </td>
                            </tr>
                        <?php endif; ?>

                        <?php foreach ($produtosVendidosConsolidado as $pvc): ?>
                        <tr class="hover:bg-zinc-800/30 transition">
                            <td class="p-3 font-bold text-white">
                                <i class="fa-solid fa-tag text-amber-500 mr-1.5 text-[10px]"></i>
                                <?= htmlspecialchars($pvc['produto_nome']) ?>
                            </td>
                            <td class="p-3 text-center font-extrabold text-amber-400 text-sm">
                                <?= $pvc['qtd_total_vendida'] ?> un
                            </td>
                            <td class="p-3 text-zinc-400">
                                R$ <?= number_format($pvc['preco_custo'], 2, ',', '.') ?>
                            </td>
                            <td class="p-3 font-semibold text-zinc-200">
                                R$ <?= number_format($pvc['total_faturado_produto'], 2, ',', '.') ?>
                            </td>
                            <td class="p-3 font-bold text-emerald-400 text-right text-sm">
                                R$ <?= number_format($pvc['lucro_estimado_produto'], 2, ',', '.') ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Extrato Linha a Linha de Vendas de Produtos -->
        <div class="border-t border-zinc-800 p-4 sm:p-5">
            <span class="text-xs font-semibold uppercase tracking-wider text-zinc-400 block mb-2.5">
                Histórico Detalhado das Vendas
            </span>
            <div class="overflow-x-auto overflow-y-hidden w-full scroll-suave">
                <table class="w-full text-left border-collapse min-w-[700px]">
                    <thead>
                        <tr class="border-b border-zinc-800 text-zinc-400 text-xs uppercase tracking-wider">
                            <th class="p-3">Data/Hora</th>
                            <th class="p-3">Cliente</th>
                            <th class="p-3">Produto</th>
                            <th class="p-3 text-center">Qtd</th>
                            <th class="p-3">Total</th>
                            <th class="p-3">Pagamento</th>
                            <th class="p-3 text-right">Vendedor</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-800 text-xs">
                        <?php if (empty($extratoVendasProdutos)): ?>
                            <tr>
                                <td colspan="7" class="p-5 text-center text-zinc-500">
                                    Nenhuma saída detalhada encontrada.
                                </td>
                            </tr>
                        <?php endif; ?>

                        <?php foreach ($extratoVendasProdutos as $evp): ?>
                        <tr class="hover:bg-zinc-800/30 transition">
                            <td class="p-3 text-zinc-300">
                                <?= date('d/m/Y H:i', strtotime($evp['data_venda'])) ?>
                            </td>
                            <td class="p-3 font-medium text-white">
                                <?= htmlspecialchars($evp['cliente_nome'] ?? 'Cliente Anónimo / Balcão') ?>
                            </td>
                            <td class="p-3 text-zinc-200 font-semibold">
                                <?= htmlspecialchars($evp['produto_nome']) ?>
                            </td>
                            <td class="p-3 text-center text-zinc-300">
                                <?= $evp['quantidade'] ?> un
                            </td>
                            <td class="p-3 font-bold text-emerald-400">
                                R$ <?= number_format($evp['subtotal'], 2, ',', '.') ?>
                            </td>
                            <td class="p-3 uppercase text-zinc-400">
                                <?= str_replace('_', ' ', $evp['forma_pagamento']) ?>
                            </td>
                            <td class="p-3 text-right text-amber-400 font-medium">
                                <?= htmlspecialchars($evp['prof_nome'] ?? 'Balcão / Casa') ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</div>

    <!-- TABELA: DESPESAS E GANHOS EXTRAS DO MÊS -->
    <div class="bg-zinc-900 border border-zinc-800 rounded-2xl overflow-hidden shadow">
        <div class="p-4 sm:p-5 border-b border-zinc-800 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
            <div>
                <h3 class="font-bold text-white text-base flex items-center gap-2">
                    <i class="fa-solid fa-money-bill-transfer text-amber-500"></i> Despesas & Ganhos Extras (<?= count($movimentacoesFinanceirasMes) ?>)
                </h3>
                <p class="text-xs text-zinc-400">Custos fixos, despesas e receitas avulsas registradas no mês selecionado.</p>
            </div>
            <a href="despesas_ganhos.php" class="bg-amber-500 hover:bg-amber-600 text-zinc-950 font-bold px-3 py-1.5 rounded-xl text-xs transition inline-flex items-center gap-1.5 shrink-0 shadow">
                <i class="fa-solid fa-plus"></i> Gerenciar Despesas & Ganhos
            </a>
        </div>

        <div class="overflow-x-auto w-full">
            <table class="w-full text-left border-collapse text-xs">
                <thead>
                    <tr class="border-b border-zinc-800 text-zinc-400 uppercase tracking-wider text-[11px]">
                        <th class="p-3.5">Data</th>
                        <th class="p-3.5">Tipo</th>
                        <th class="p-3.5">Categoria</th>
                        <th class="p-3.5">Descrição</th>
                        <th class="p-3.5">Pagamento</th>
                        <th class="p-3.5 text-right">Valor</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-800">
                    <?php if (empty($movimentacoesFinanceirasMes)): ?>
                        <tr>
                            <td colspan="6" class="p-6 text-center text-zinc-500">
                                Nenhuma despesa ou ganho extra lançado para este mês.
                            </td>
                        </tr>
                    <?php endif; ?>

                    <?php foreach ($movimentacoesFinanceirasMes as $mov): 
                        $ehReceita = ($mov['tipo'] === 'receita');
                    ?>
                    <tr class="hover:bg-zinc-800/30 transition">
                        <td class="p-3.5 text-zinc-300 whitespace-nowrap">
                            <?= date('d/m/Y', strtotime($mov['data_movimentacao'])) ?>
                        </td>
                        <td class="p-3.5 whitespace-nowrap">
                            <?php if ($ehReceita): ?>
                                <span class="bg-emerald-950/80 text-emerald-400 border border-emerald-800/60 px-2 py-0.5 rounded-full text-[10px] font-bold uppercase">
                                    🟢 Ganho
                                </span>
                            <?php else: ?>
                                <span class="bg-red-950/80 text-red-400 border border-red-800/60 px-2 py-0.5 rounded-full text-[10px] font-bold uppercase">
                                    🔴 Despesa
                                </span>
                            <?php endif; ?>
                        </td>
                        <td class="p-3.5 text-zinc-300 font-medium">
                            <?= htmlspecialchars($mov['categoria']) ?>
                        </td>
                        <td class="p-3.5 text-white font-semibold">
                            <?= htmlspecialchars($mov['descricao']) ?>
                        </td>
                        <td class="p-3.5 text-zinc-400 uppercase text-[11px]">
                            <?= htmlspecialchars($mov['forma_pagamento'] ?? 'pix') ?>
                        </td>
                        <td class="p-3.5 text-right font-extrabold whitespace-nowrap <?= $ehReceita ? 'text-emerald-400' : 'text-red-400' ?>">
                            <?= $ehReceita ? '+ ' : '- ' ?>R$ <?= number_format($mov['valor'], 2, ',', '.') ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

</main>
</body>
</html>
