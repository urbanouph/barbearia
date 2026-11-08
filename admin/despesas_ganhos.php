<?php
require_once '../config/database.php';
verificarAutenticacao();

$mensagemSucesso = '';
$mensagemErro = '';

if (isset($_GET['sucesso_cad'])) {
    $mensagemSucesso = "Lançamento cadastrado com sucesso!";
} elseif (isset($_GET['sucesso_del'])) {
    $mensagemSucesso = "Lançamento removido com sucesso!";
}

// 1. Cadastrar Lançamento
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cadastrar_movimentacao'])) {
    $tipo = in_array($_POST['tipo'], ['receita', 'despesa']) ? $_POST['tipo'] : 'despesa';
    $categoria = trim($_POST['categoria'] ?? 'Outros');
    $descricao = trim($_POST['descricao'] ?? '');
    $valorRaw = str_replace(',', '.', trim($_POST['valor'] ?? '0'));
    $valor = max(0.01, (float)$valorRaw);
    $dataMov = !empty($_POST['data_movimentacao']) ? $_POST['data_movimentacao'] : date('Y-m-d');
    $formaPag = $_POST['forma_pagamento'] ?? 'pix';

    if (empty($descricao)) {
        $mensagemErro = "Por favor, informe a descrição do lançamento.";
    } elseif ($valor <= 0) {
        $mensagemErro = "Por favor, informe um valor válido.";
    } else {
        $stmt = $pdo->prepare("
            INSERT INTO movimentacoes_financeiras (tipo, categoria, descricao, valor, data_movimentacao, forma_pagamento) 
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$tipo, $categoria, $descricao, $valor, $dataMov, $formaPag]);
        header("Location: despesas_ganhos.php?sucesso_cad=1");
        exit;
    }
}

// 2. Excluir Lançamento
if (isset($_GET['excluir_mov'])) {
    $id = (int)$_GET['excluir_mov'];
    $stmt = $pdo->prepare("DELETE FROM movimentacoes_financeiras WHERE id = ?");
    $stmt->execute([$id]);
    header("Location: despesas_ganhos.php?sucesso_del=1");
    exit;
}

// Filtro de Mês
$mesSelecionado = $_GET['mes'] ?? date('Y-m');

// Meses disponíveis
$mesesDisponiveis = $pdo->query("
    SELECT DISTINCT DATE_FORMAT(data_movimentacao, '%Y-%m') as mes_ano 
    FROM movimentacoes_financeiras 
    ORDER BY mes_ano DESC
")->fetchAll(PDO::FETCH_COLUMN);

if (!in_array(date('Y-m'), $mesesDisponiveis)) {
    array_unshift($mesesDisponiveis, date('Y-m'));
    $mesesDisponiveis = array_unique($mesesDisponiveis);
}

// Buscar totais do mês
$stTotais = $pdo->prepare("
    SELECT 
        COALESCE(SUM(CASE WHEN tipo = 'receita' THEN valor ELSE 0 END), 0) as total_receitas,
        COALESCE(SUM(CASE WHEN tipo = 'despesa' THEN valor ELSE 0 END), 0) as total_despesas
    FROM movimentacoes_financeiras 
    WHERE DATE_FORMAT(data_movimentacao, '%Y-%m') = ?
");
$stTotais->execute([$mesSelecionado]);
$totaisMes = $stTotais->fetch();

$totalReceitasMes = (float)$totaisMes['total_receitas'];
$totalDespesasMes = (float)$totaisMes['total_despesas'];
$saldoLiquidoMes  = $totalReceitasMes - $totalDespesasMes;

// Buscar lançamentos do mês
$stmtLista = $pdo->prepare("
    SELECT * FROM movimentacoes_financeiras 
    WHERE DATE_FORMAT(data_movimentacao, '%Y-%m') = ? 
    ORDER BY data_movimentacao DESC, id DESC
");
$stmtLista->execute([$mesSelecionado]);
$movimentacoes = $stmtLista->fetchAll();

require_once 'header.php';
?>

<div class="max-w-6xl mx-auto space-y-6">
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <h2 class="text-2xl font-bold flex items-center gap-2">
                <i class="fa-solid fa-money-bill-transfer text-amber-500"></i> Despesas & Ganhos Extras
            </h2>
            <p class="text-zinc-400 text-sm">Controle contas a pagar, custos fixos, insumos e receitas extras da barbearia.</p>
        </div>

        <!-- Filtro por Mês -->
        <form method="GET" class="flex items-center gap-2">
            <label class="text-xs text-zinc-400 font-semibold">Mês:</label>
            <select name="mes" onchange="this.form.submit()" 
                    class="bg-zinc-900 border border-zinc-700 rounded-xl px-3 py-2 text-xs text-white focus:outline-none focus:border-amber-500 font-semibold">
                <?php foreach ($mesesDisponiveis as $m): 
                    $dataObj = DateTime::createFromFormat('Y-m', $m);
                    $nomeMes = $dataObj ? $dataObj->format('m/Y') : $m;
                ?>
                    <option value="<?= $m ?>" <?= $m === $mesSelecionado ? 'selected' : '' ?>>
                        <?= $nomeMes ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>

    <?php if ($mensagemSucesso): ?>
        <div class="bg-emerald-500/10 border border-emerald-500/30 text-emerald-400 text-xs p-3.5 rounded-xl flex items-center justify-between">
            <span><i class="fa-solid fa-circle-check mr-2"></i> <?= htmlspecialchars($mensagemSucesso) ?></span>
            <a href="despesas_ganhos.php" class="text-zinc-400 hover:text-white">&times;</a>
        </div>
    <?php endif; ?>

    <?php if ($mensagemErro): ?>
        <div class="bg-red-500/10 border border-red-500/30 text-red-400 text-xs p-3.5 rounded-xl flex items-center justify-between">
            <span><i class="fa-solid fa-circle-exclamation mr-2"></i> <?= htmlspecialchars($mensagemErro) ?></span>
            <a href="despesas_ganhos.php" class="text-zinc-400 hover:text-white">&times;</a>
        </div>
    <?php endif; ?>

    <!-- CARDS DE RESUMO DO MÊS -->
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <div class="bg-zinc-900 border border-zinc-800 p-4 rounded-2xl">
            <span class="text-xs text-zinc-400 uppercase tracking-wider block">Ganhos Extras (Mês)</span>
            <span class="text-2xl font-bold text-emerald-400 block mt-1">+ R$ <?= number_format($totalReceitasMes, 2, ',', '.') ?></span>
            <span class="text-[11px] text-zinc-500">Receitas avulsas e extras</span>
        </div>

        <div class="bg-zinc-900 border border-zinc-800 p-4 rounded-2xl">
            <span class="text-xs text-zinc-400 uppercase tracking-wider block">Despesas & Custos (Mês)</span>
            <span class="text-2xl font-bold text-red-400 block mt-1">- R$ <?= number_format($totalDespesasMes, 2, ',', '.') ?></span>
            <span class="text-[11px] text-zinc-500">Contas, insumos e custos fixos</span>
        </div>

        <div class="bg-zinc-900 border border-zinc-800 p-4 rounded-2xl">
            <span class="text-xs text-zinc-400 uppercase tracking-wider block">Balanço das Despesas</span>
            <span class="text-2xl font-bold <?= $saldoLiquidoMes >= 0 ? 'text-emerald-400' : 'text-red-400' ?> block mt-1">
                R$ <?= number_format($saldoLiquidoMes, 2, ',', '.') ?>
            </span>
            <span class="text-[11px] text-zinc-500">Ganhos Extras menos Despesas</span>
        </div>
    </div>

    <!-- FORMULÁRIO DE LANÇAMENTO RÁPIDO -->
    <div class="bg-zinc-900 border border-zinc-800 rounded-2xl p-5 shadow-xl space-y-4">
        <h3 class="font-bold text-white text-sm flex items-center gap-2">
            <i class="fa-solid fa-plus-circle text-amber-500"></i> Novo Lançamento Financeiro
        </h3>

        <form method="POST" class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-6 gap-3.5">
            <div>
                <label class="block text-xs font-semibold text-zinc-400 mb-1">Tipo</label>
                <select name="tipo" id="campoTipo" onchange="ajustarCategorias()" 
                        class="w-full bg-zinc-800 border border-zinc-700 rounded-xl p-2.5 text-xs text-white font-bold focus:outline-none focus:border-amber-500">
                    <option value="despesa" selected>🔴 Despesa (Custo/Gasto)</option>
                    <option value="receita">🟢 Ganho (Receita Extra)</option>
                </select>
            </div>

            <div>
                <label class="block text-xs font-semibold text-zinc-400 mb-1">Categoria</label>
                <select name="categoria" id="campoCategoria" 
                        class="w-full bg-zinc-800 border border-zinc-700 rounded-xl p-2.5 text-xs text-white focus:outline-none focus:border-amber-500">
                    <option value="Aluguel">Aluguel do Ponto</option>
                    <option value="Energia / Luz">Energia / Luz</option>
                    <option value="Água / Esgoto">Água / Esgoto</option>
                    <option value="Internet / Telefone">Internet / Telefone</option>
                    <option value="Produtos & Insumos">Produtos & Insumos</option>
                    <option value="Equipamentos & Ferramentas">Equipamentos & Ferramentas</option>
                    <option value="Manutenção & Limpeza">Manutenção & Limpeza</option>
                    <option value="Marketing & Anúncios">Marketing & Anúncios</option>
                    <option value="Impostos & Taxas">Impostos & Taxas</option>
                    <option value="Outros Custos">Outros Custos</option>
                </select>
            </div>

            <div class="md:col-span-2">
                <label class="block text-xs font-semibold text-zinc-400 mb-1">Descrição</label>
                <input type="text" name="descricao" placeholder="Ex: Conta de luz referente a Setembro" required 
                       class="w-full bg-zinc-800 border border-zinc-700 rounded-xl p-2.5 text-xs text-white focus:outline-none focus:border-amber-500">
            </div>

            <div>
                <label class="block text-xs font-semibold text-zinc-400 mb-1">Valor (R$)</label>
                <input type="text" inputmode="decimal" name="valor" placeholder="0,00" required 
                       class="w-full bg-zinc-800 border border-zinc-700 rounded-xl p-2.5 text-xs text-white font-bold focus:outline-none focus:border-amber-500 text-right">
            </div>

            <div>
                <label class="block text-xs font-semibold text-zinc-400 mb-1">Data</label>
                <input type="date" name="data_movimentacao" value="<?= date('Y-m-d') ?>" required 
                       class="w-full bg-zinc-800 border border-zinc-700 rounded-xl p-2.5 text-xs text-white focus:outline-none focus:border-amber-500">
            </div>

            <div>
                <label class="block text-xs font-semibold text-zinc-400 mb-1">Pagamento</label>
                <select name="forma_pagamento" 
                        class="w-full bg-zinc-800 border border-zinc-700 rounded-xl p-2.5 text-xs text-white focus:outline-none focus:border-amber-500">
                    <option value="pix">PIX</option>
                    <option value="dinheiro">Dinheiro</option>
                    <option value="cartao_debito">Cartão Débito</option>
                    <option value="cartao_credito">Cartão Crédito</option>
                    <option value="boleto">Boleto</option>
                    <option value="transferencia">Transferência</option>
                </select>
            </div>

            <div class="md:col-span-5 flex items-end justify-end">
                <button type="submit" name="cadastrar_movimentacao" 
                        class="bg-amber-500 hover:bg-amber-600 text-zinc-950 font-bold py-2.5 px-6 rounded-xl text-xs transition flex items-center gap-1.5 shadow">
                    <i class="fa-solid fa-check"></i> Salvar Lançamento
                </button>
            </div>
        </form>
    </div>

    <!-- TABELA DE EXTRATO DE MOVIMENTAÇÕES -->
    <div class="bg-zinc-900 border border-zinc-800 rounded-2xl overflow-hidden shadow-xl">
        <div class="p-4 border-b border-zinc-800 flex justify-between items-center">
            <h3 class="font-bold text-white text-sm">Extrato de Despesas e Ganhos (<?= count($movimentacoes) ?> lançamentos)</h3>
            <span class="text-xs text-zinc-500">Mês: <?= $mesSelecionado ?></span>
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
                        <th class="p-3.5 text-right">Ação</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-800">
                    <?php if (empty($movimentacoes)): ?>
                        <tr>
                            <td colspan="7" class="p-6 text-center text-zinc-500">
                                Nenhuma despesa ou ganho lançado neste mês.
                            </td>
                        </tr>
                    <?php endif; ?>

                    <?php foreach ($movimentacoes as $mov): 
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
                        <td class="p-3.5 text-right whitespace-nowrap">
                            <a href="despesas_ganhos.php?excluir_mov=<?= $mov['id'] ?>" onclick="return confirm('Deseja excluir este lançamento financeiro?');" 
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

<script>
function ajustarCategorias() {
    const tipo = document.getElementById('campoTipo').value;
    const cat = document.getElementById('campoCategoria');
    cat.innerHTML = '';

    if (tipo === 'despesa') {
        const despesas = [
            'Aluguel', 'Energia / Luz', 'Água / Esgoto', 'Internet / Telefone',
            'Produtos & Insumos', 'Equipamentos & Ferramentas', 'Manutenção & Limpeza',
            'Marketing & Anúncios', 'Impostos & Taxas', 'Outros Custos'
        ];
        despesas.forEach(d => {
            const opt = document.createElement('option');
            opt.value = d;
            opt.textContent = d;
            cat.appendChild(opt);
        });
    } else {
        const receitas = [
            'Rendimentos / Aplicações', 'Venda de Móveis / Máquinas',
            'Eventos / Parcerias', 'Cursos / Workshops', 'Outros Ganhos Extras'
        ];
        receitas.forEach(r => {
            const opt = document.createElement('option');
            opt.value = r;
            opt.textContent = r;
            cat.appendChild(opt);
        });
    }
}
</script>

</main>
</body>
</html>
