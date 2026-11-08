<?php
require_once '../config/database.php';
verificarAutenticacao();

$mensagemSucesso = '';
$mensagemErro = '';

if (isset($_GET['sucesso_venda'])) {
    $mensagemSucesso = "Venda realizada com sucesso no PDV!";
}

// 1. Processar Venda no Balcão (PDV)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['registrar_venda'])) {
    $produtoId = (int)$_POST['venda_produto_id'];
    $quantidade = (int)$_POST['venda_quantidade'];
    $formaPagamento = $_POST['forma_pagamento'] ?? 'pix';
    $profissionalId = !empty($_POST['venda_profissional_id']) ? (int)$_POST['venda_profissional_id'] : null;
    $clienteId = !empty($_POST['venda_cliente_id']) ? (int)$_POST['venda_cliente_id'] : null;

    if ($quantidade <= 0) {
        $mensagemErro = "Selecione uma quantidade maior que zero.";
    } else {
        $stmtProd = $pdo->prepare("SELECT * FROM produtos WHERE id = ? AND status = 'ativo'");
        $stmtProd->execute([$produtoId]);
        $prod = $stmtProd->fetch();

        if (!$prod) {
            $mensagemErro = "Produto não encontrado.";
        } elseif ($prod['quantidade_estoque'] < $quantidade) {
            $mensagemErro = "Estoque insuficiente! Disponível: {$prod['quantidade_estoque']} unidade(s).";
        } else {
            $totalVenda = $prod['preco_venda'] * $quantidade;

            $stmtVenda = $pdo->prepare("
                INSERT INTO vendas_produtos (cliente_id, profissional_id, total, forma_pagamento, data_venda) 
                VALUES (?, ?, ?, ?, NOW())
            ");
            $stmtVenda->execute([$clienteId, $profissionalId, $totalVenda, $formaPagamento]);
            $vendaId = $pdo->lastInsertId();

            $stmtItem = $pdo->prepare("
                INSERT INTO itens_venda_produtos (venda_id, produto_id, quantidade, preco_unitario, subtotal) 
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmtItem->execute([$vendaId, $produtoId, $quantidade, $prod['preco_venda'], $totalVenda]);

            $stmtEstoque = $pdo->prepare("
                UPDATE produtos 
                SET quantidade_estoque = quantidade_estoque - ? 
                WHERE id = ?
            ");
            $stmtEstoque->execute([$quantidade, $produtoId]);

            header("Location: pdv.php?sucesso_venda=1");
            exit;
        }
    }
}

// Buscar produtos ativos com estoque > 0
$produtosDisponiveis = $pdo->query("SELECT * FROM produtos WHERE status = 'ativo' AND quantidade_estoque > 0 ORDER BY nome ASC")->fetchAll();

// Buscar todos os clientes
$clientes = $pdo->query("SELECT id, nome, telefone FROM clientes ORDER BY nome ASC")->fetchAll();

// Buscar todos os profissionais ativos
$profissionais = $pdo->query("SELECT id, nome FROM profissionais WHERE status = 'ativo' ORDER BY nome ASC")->fetchAll();

// Buscar histórico de vendas recentes do PDV
$vendasRecentes = $pdo->query("
    SELECT v.*, 
           c.nome as cliente_nome, 
           p.nome as vendedor_nome,
           pr.nome as produto_nome,
           iv.quantidade,
           iv.preco_unitario
    FROM vendas_produtos v
    LEFT JOIN clientes c ON v.cliente_id = c.id
    LEFT JOIN profissionais p ON v.profissional_id = p.id
    LEFT JOIN itens_venda_produtos iv ON iv.venda_id = v.id
    LEFT JOIN produtos pr ON iv.produto_id = pr.id
    ORDER BY v.data_venda DESC, v.id DESC
    LIMIT 15
")->fetchAll();

require_once 'header.php';
?>

<div class="max-w-6xl mx-auto space-y-6">
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <h2 class="text-2xl font-bold flex items-center gap-2">
                <i class="fa-solid fa-cart-shopping text-amber-500"></i> Frente de Caixa & Venda Balcão (PDV)
            </h2>
            <p class="text-zinc-400 text-sm">Venda de produtos avulsos, pomadas, bebidas e itens da barbearia com baixa automática no estoque.</p>
        </div>
        <div class="flex gap-2">
            <a href="produtos.php" class="bg-zinc-800 hover:bg-zinc-700 text-zinc-300 font-bold px-3.5 py-2 rounded-xl text-xs transition border border-zinc-700 inline-flex items-center gap-1.5">
                <i class="fa-solid fa-boxes-stacked text-amber-500"></i> Gerenciar Produtos & Estoque
            </a>
        </div>
    </div>

    <?php if ($mensagemSucesso): ?>
        <div class="bg-emerald-500/10 border border-emerald-500/30 text-emerald-400 text-xs p-3.5 rounded-xl flex items-center justify-between">
            <span><i class="fa-solid fa-circle-check mr-2"></i> <?= htmlspecialchars($mensagemSucesso) ?></span>
            <a href="pdv.php" class="text-zinc-400 hover:text-white">&times;</a>
        </div>
    <?php endif; ?>

    <?php if ($mensagemErro): ?>
        <div class="bg-red-500/10 border border-red-500/30 text-red-400 text-xs p-3.5 rounded-xl flex items-center justify-between">
            <span><i class="fa-solid fa-circle-exclamation mr-2"></i> <?= htmlspecialchars($mensagemErro) ?></span>
            <a href="pdv.php" class="text-zinc-400 hover:text-white">&times;</a>
        </div>
    <?php endif; ?>

    <!-- FORMULÁRIO DE REGISTRO DE VENDA (PDV) -->
    <div class="bg-zinc-900 border border-zinc-800 p-6 rounded-2xl shadow-xl space-y-5">
        <div class="flex items-center gap-2.5 pb-3 border-b border-zinc-800">
            <i class="fa-solid fa-cash-register text-amber-500 text-xl"></i>
            <div>
                <h3 class="font-bold text-base text-white">Registrar Venda no Balcão (PDV)</h3>
                <p class="text-xs text-zinc-400">Busque o cliente ou marque como anônimo, localize o produto e selecione quem vendeu.</p>
            </div>
        </div>

        <form method="POST" class="space-y-4">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <!-- Cliente Comprador -->
                <div class="bg-zinc-800/40 border border-zinc-800 p-4 rounded-xl space-y-2">
                    <div class="flex items-center justify-between">
                        <label class="block text-xs font-bold text-zinc-300 flex items-center gap-1.5">
                            <i class="fa-solid fa-user text-amber-500"></i> Cliente Comprador
                        </label>
                        <button type="button" onclick="selecionarAnonimoCliente()" class="text-[10px] font-bold text-amber-400 hover:underline bg-amber-500/10 px-2 py-0.5 rounded border border-amber-500/20">
                            Cliente Anônimo
                        </button>
                    </div>
                    <input type="text" id="filtroCliente" placeholder="🔍 Digite para pesquisar cliente por nome ou telefone..." 
                           oninput="filtrarOpcoesCliente(this.value)" 
                           class="w-full bg-zinc-800 border border-zinc-700 rounded-xl p-2 text-xs text-white focus:outline-none focus:border-amber-500">
                    <select name="venda_cliente_id" id="selectCliente" 
                            class="w-full bg-zinc-800 border border-zinc-700 rounded-xl p-2.5 text-xs text-white focus:outline-none focus:border-amber-500">
                        <option value="">👤 Cliente Anônimo / Venda Avulsa</option>
                        <?php foreach ($clientes as $cli): ?>
                            <option value="<?= $cli['id'] ?>" data-texto="<?= strtolower(htmlspecialchars($cli['nome'] . ' ' . $cli['telefone'])) ?>">
                                <?= htmlspecialchars($cli['nome']) ?> (<?= htmlspecialchars($cli['telefone']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Produto Vendido -->
                <div class="bg-zinc-800/40 border border-zinc-800 p-4 rounded-xl space-y-2">
                    <label class="block text-xs font-bold text-zinc-300 flex items-center gap-1.5">
                        <i class="fa-solid fa-barcode text-amber-500"></i> Produto
                    </label>
                    <input type="text" id="filtroProduto" placeholder="🔍 Digite para pesquisar produto..." 
                           oninput="filtrarOpcoesProduto(this.value)" 
                           class="w-full bg-zinc-800 border border-zinc-700 rounded-xl p-2 text-xs text-white focus:outline-none focus:border-amber-500">
                    <select name="venda_produto_id" id="selectProduto" required 
                            class="w-full bg-zinc-800 border border-zinc-700 rounded-xl p-2.5 text-xs text-white focus:outline-none focus:border-amber-500">
                        <option value="">Selecione o produto...</option>
                        <?php foreach ($produtosDisponiveis as $prod): ?>
                            <option value="<?= $prod['id'] ?>" data-preco="<?= $prod['preco_venda'] ?>" data-texto="<?= strtolower(htmlspecialchars($prod['nome'])) ?>">
                                <?= htmlspecialchars($prod['nome']) ?> — R$ <?= number_format($prod['preco_venda'], 2, ',', '.') ?> (Estoque: <?= $prod['quantidade_estoque'] ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <!-- Quantidade, Vendedor, Forma de Pagamento e Botão -->
            <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-4 items-end pt-1">
                <div>
                    <label class="block text-xs font-semibold text-zinc-400 mb-1">Quantidade</label>
                    <input type="number" min="1" name="venda_quantidade" value="1" required 
                           class="w-full bg-zinc-800 border border-zinc-700 rounded-xl p-2.5 text-xs text-white font-bold focus:outline-none focus:border-amber-500 text-center">
                </div>

                <div>
                    <label class="block text-xs font-semibold text-zinc-400 mb-1">Quem Vendeu?</label>
                    <select name="venda_profissional_id" 
                            class="w-full bg-zinc-800 border border-zinc-700 rounded-xl p-2.5 text-xs text-white focus:outline-none focus:border-amber-500">
                        <option value="">👤 Anônimo / Casa (Sem Comissão)</option>
                        <?php foreach ($profissionais as $prof): ?>
                            <option value="<?= $prof['id'] ?>">✂️ <?= htmlspecialchars($prof['nome']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-zinc-400 mb-1">Forma de Pagamento</label>
                    <select name="forma_pagamento" 
                            class="w-full bg-zinc-800 border border-zinc-700 rounded-xl p-2.5 text-xs text-white focus:outline-none focus:border-amber-500">
                        <option value="pix">PIX</option>
                        <option value="dinheiro">Dinheiro</option>
                        <option value="cartao_debito">Cartão Débito</option>
                        <option value="cartao_credito">Cartão Crédito</option>
                    </select>
                </div>

                <div>
                    <button type="submit" name="registrar_venda" 
                            class="w-full bg-amber-500 hover:bg-amber-600 text-zinc-950 font-bold py-2.5 px-4 rounded-xl text-xs transition flex items-center justify-center gap-2 shadow">
                        <i class="fa-solid fa-check"></i> Concluir Venda (PDV)
                    </button>
                </div>
            </div>
        </form>
    </div>

    <!-- HISTÓRICO DE VENDAS RECENTES -->
    <div class="bg-zinc-900 border border-zinc-800 rounded-2xl overflow-hidden shadow">
        <div class="p-4 border-b border-zinc-800 flex justify-between items-center">
            <h3 class="font-bold text-white text-sm">Vendas Recentes do Balcão</h3>
            <span class="text-xs text-zinc-500">Últimas 15 operações</span>
        </div>

        <div class="overflow-x-auto w-full">
            <table class="w-full text-left border-collapse text-xs">
                <thead>
                    <tr class="border-b border-zinc-800 text-zinc-400 uppercase tracking-wider text-[11px]">
                        <th class="p-3.5">Data / Hora</th>
                        <th class="p-3.5">Produto</th>
                        <th class="p-3.5 text-center">Qtd</th>
                        <th class="p-3.5">Cliente</th>
                        <th class="p-3.5">Vendedor</th>
                        <th class="p-3.5">Pagamento</th>
                        <th class="p-3.5 text-right">Total</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-800">
                    <?php if (empty($vendasRecentes)): ?>
                        <tr><td colspan="7" class="p-6 text-center text-zinc-500">Nenhuma venda registrada ainda no balcão.</td></tr>
                    <?php endif; ?>

                    <?php foreach ($vendasRecentes as $v): ?>
                    <tr class="hover:bg-zinc-800/30 transition">
                        <td class="p-3.5 text-zinc-400 whitespace-nowrap">
                            <?= date('d/m/Y H:i', strtotime($v['data_venda'])) ?>
                        </td>
                        <td class="p-3.5 font-bold text-white">
                            <?= htmlspecialchars($v['produto_nome'] ?? 'Produto') ?>
                        </td>
                        <td class="p-3.5 text-center text-zinc-300 font-bold">
                            <?= $v['quantidade'] ?? 1 ?>
                        </td>
                        <td class="p-3.5 text-zinc-300">
                            <?= htmlspecialchars($v['cliente_nome'] ?? 'Cliente Avulso') ?>
                        </td>
                        <td class="p-3.5 text-zinc-400">
                            <?= htmlspecialchars($v['vendedor_nome'] ?? 'Casa (Sem Vendedor)') ?>
                        </td>
                        <td class="p-3.5 text-zinc-300 uppercase text-[11px]">
                            <?= htmlspecialchars($v['forma_pagamento']) ?>
                        </td>
                        <td class="p-3.5 text-right font-extrabold text-amber-400 whitespace-nowrap">
                            R$ <?= number_format($v['total'], 2, ',', '.') ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
function selecionarAnonimoCliente() {
    document.getElementById('selectCliente').value = '';
    document.getElementById('filtroCliente').value = '';
    filtrarOpcoesCliente('');
}

function filtrarOpcoesCliente(termo) {
    termo = termo.toLowerCase().trim();
    const select = document.getElementById('selectCliente');
    for (let i = 0; i < select.options.length; i++) {
        const opt = select.options[i];
        if (opt.value === '') {
            opt.hidden = false;
            continue;
        }
        const texto = opt.getAttribute('data-texto') || '';
        opt.hidden = (termo !== '' && !texto.includes(termo));
    }
}

function filtrarOpcoesProduto(termo) {
    termo = termo.toLowerCase().trim();
    const select = document.getElementById('selectProduto');
    for (let i = 0; i < select.options.length; i++) {
        const opt = select.options[i];
        if (opt.value === '') {
            opt.hidden = false;
            continue;
        }
        const texto = opt.getAttribute('data-texto') || '';
        opt.hidden = (termo !== '' && !texto.includes(termo));
    }
}
</script>

</main>
</body>
</html>
